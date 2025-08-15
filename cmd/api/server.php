<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class RedisConnectionPool
{
	private Channel $pool;
	private string $host;
	private int $port;
	private ?string $auth;
	private int $db;
	private int $maxConnections;

	public function __construct(string $host, int $port, ?string $auth, int $db, int $minConnections = 10, int $maxConnections = 100)
	{
		$this->host = $host;
		$this->port = $port;
		$this->auth = $auth;
		$this->db = $db;
		$this->maxConnections = $maxConnections;

		$this->pool = new Channel($maxConnections);

		for ($i = 0; $i < $minConnections; $i++) {
			$this->put($this->createConnection());
		}
	}

	private function createConnection(): ?\Redis
	{
		try {
			$redis = new \Redis();
			$redis->connect($this->host, $this->port, 0.5); // Timeout de conexão curto
			if ($this->auth) {
				$redis->auth($this->auth);
			}
			if ($this->db > 0) {
				$redis->select($this->db);
			}

			$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
			$redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
			return $redis;
		} catch (\RedisException $e) {
			error_log("Falha ao conectar com Redis: " . $e->getMessage());
			return null;
		}
	}

	public function get(): ?\Redis
	{
		if ($this->pool->isEmpty() && $this->pool->length() < $this->maxConnections) {
			return $this->createConnection();
		}
		return $this->pool->pop(0.2); // Aguarda até 200ms por uma conexão
	}

	public function put(?\Redis $redis): void
	{
		if ($redis instanceof \Redis && !$this->pool->isFull()) {
			$this->pool->push($redis);
		}
	}
}

$server = new Server("0.0.0.0", 8080, SWOOLE_PROCESS);

$server->set([
	'worker_num' => 1,
	'enable_coroutine' => true,
	'open_tcp_nodelay' => true,
	'max_conn' => 2048,
	'max_request' => 0,
	'dispatch_mode' => 2,
	'log_level' => SWOOLE_LOG_ERROR,
	'log_file' => '/dev/null',
]);

$redisPool = null;

$server->on('start', function (Server $server) {
	echo "Swoole HTTP server está de pé em http://0.0.0.0:8080\n";
});

$server->on('workerStart', function (Server $server, int $workerId) {
	// Inicializa o pool de conexões no processo do worker
	global $redisPool;
	$redisPool = new RedisConnectionPool(
		getenv('REDIS_HOST') ?: 'localhost',
		(int) (getenv('REDIS_PORT') ?: 6379),
		getenv('REDIS_AUTH') ?: null,
		(int) (getenv('REDIS_DB') ?: 0)
	);
	echo "Worker {$workerId} iniciado e pool de conexões Redis pronto.\n";
});

$server->on('request', function (Request $request, Response $response) {
	go(function () use ($request, $response) {
		global $redisPool;
		$method = $request->server['request_method'];
		$uri = $request->server['request_uri'];

		switch ($method) {
			case 'POST':
				if ($uri === '/payments') {
					try {
						$rawBody = $request->getContent();
						$idStartPos = 18;
						$idEndPos = strpos($rawBody, '"', $idStartPos);
						$correlationId = substr($rawBody, $idStartPos, $idEndPos - $idStartPos);

						$amountStartPos = strpos($rawBody, ':', $idEndPos) + 1;
						$amount = substr($rawBody, $amountStartPos, -1);

						$payload = '{"correlationId":"' . $correlationId . '","amount":' . $amount . '}';

						$redis = $redisPool->get();
						if ($redis) {
							$redis->lpush('payment_queue', $payload);
							$redisPool->put($redis);
						} else {
							throw new \Exception("Não foi possível obter conexão com o Redis.");
						}

						$response->status(202);
						$response->header('Content-Type', '-');
						$response->end();
					} catch (Exception $e) {
						error_log("💥 ERRO [/payments]: " . $e->getMessage());
						$response->status(500);
						$response->header('Content-Type', 'application/json');
						$response->end(json_encode(['error' => 'Internal server error']));
					}
				} elseif ($uri === '/purge-payments') {
					$redis = $redisPool->get();
					if ($redis) {
						$redis->flushAll();
						$redisPool->put($redis);
					}
					$response->status(204);
					$response->end();
				}
				break;

			case 'GET':
				if ($uri === '/payments-summary') {
					$toTimestampString = function (?string $dateString): ?string {
						if (!$dateString) return null;
						$date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString)
							?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);
						return $date ? $date->format('U.u') : null;
					};

					$fromTs = $toTimestampString($request->get['from'] ?? null) ?? '-inf';
					$toTs = $toTimestampString($request->get['to'] ?? null) ?? '+inf';

					$processProcessor = function ($processorName) use ($redisPool, $fromTs, $toTs) {
						$redis = $redisPool->get();
						if (!$redis) return ['totalRequests' => 0, 'totalAmount' => 0.0];

						try {
							$results = $redis->zRangeByScore("payments:{$processorName}", $fromTs, $toTs);
							$totalAmount = 0.0;
							foreach ($results as $item) {
								$value = @unserialize($item);
								if ($value !== false) {
									$totalAmount += floatval(explode(':', $value)[0]);
								}
							}
							return [
								'totalRequests' => count($results),
								'totalAmount' => round($totalAmount, 2)
							];
						} catch (Exception $e) {
							error_log("[ERROR] Erro ao processar {$processorName}: " . $e->getMessage());
							return ['totalRequests' => 0, 'totalAmount' => 0.0];
						} finally {
							$redisPool->put($redis);
						}
					};

					$channel = new Coroutine\Channel(2);
					go(function () use ($channel, $processProcessor) {
						$channel->push(['default' => $processProcessor('default')]);
					});
					go(function () use ($channel, $processProcessor) {
						$channel->push(['fallback' => $processProcessor('fallback')]);
					});

					$parallelResults = [];
					$parallelResults += $channel->pop();
					$parallelResults += $channel->pop();

					$response->status(200);
					$response->header('Content-Type', 'application/json');
					$response->end(json_encode($parallelResults));
				}
				break;
		}

		if (!$response->isWritable()) {
			$response->status(404);
			$response->end();
		}
	});
});

$server->start();
