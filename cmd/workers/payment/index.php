<?php
error_reporting(E_ALL & ~E_WARNING);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

class RedisPool
{
	private Channel $pool;
	private string $host;
	private int $port;
	private int $size;

	public function __construct(string $host, int $port, int $size = 5)
	{
		$this->host = $host;
		$this->port = $port;
		$this->size = $size;
		$this->pool = new Channel($size);
	}

	public function init()
	{
		for ($i = 0; $i < $this->size; $i++) {
			$conn = $this->createConnection();
			if ($conn) {
				$this->pool->push($conn);
			}
		}
	}

	private function createConnection(): ?Redis
	{
		$redis = new Redis();
		if (!$redis->connect($this->host, $this->port, 2)) {
			return null;
		}
		return $redis;
	}

	public function get(): ?Redis
	{
		return $this->pool->pop(0.5);
	}

	public function put(Redis $redis): void
	{
		$this->pool->push($redis);
	}
}

class HttpClientPool
{
	private Channel $pool;
	private string $host;
	private int $port;
	private int $size;

	public function __construct(string $host, int $port, int $size = 20)
	{
		$this->host = $host;
		$this->port = $port;
		$this->size = $size;
		$this->pool = new Channel($size);
	}

	public function init()
	{
		for ($i = 0; $i < $this->size; $i++) {
			$client = $this->createClient();
			if ($client) {
				$this->pool->push($client);
			}
		}
	}

	private function createClient(): ?Client
	{
		$client = new Client($this->host, $this->port);
		$client->setHeaders([
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		]);
		return $client;
	}

	public function get(): ?Client
	{
		return $this->pool->pop(1.0);
	}

	public function put(Client $client): void
	{
		$this->pool->push($client);
	}
}

class PaymentWorker
{
	private string $defaultHost;
	private int $defaultPort;
	private string $fallbackHost;
	private int $fallbackPort;
	private int $maxConcurrentPayments;
	private bool $running = true;
	private int $totalFailed = 0;

	private RedisPool $redisPool;
	private HttpClientPool $defaultHttpPool;
	private HttpClientPool $fallbackHttpPool;

	public function __construct()
	{
		$this->defaultHost = getenv('PROCESSOR_DEFAULT_HOST') ?: 'payment-processor-default';
		$this->defaultPort = (int)(getenv('PROCESSOR_DEFAULT_PORT') ?: 8080);
		$this->fallbackHost = getenv('PROCESSOR_FALLBACK_HOST') ?: 'payment-processor-fallback';
		$this->fallbackPort = (int)(getenv('PROCESSOR_FALLBACK_PORT') ?: 8080);
		$this->maxConcurrentPayments = (int)(getenv('MAX_CONCURRENT_PAYMENTS') ?: 20);

		$redisHost = getenv('REDIS_HOST') ?: 'redis';
		$redisPort = (int)(getenv('REDIS_PORT') ?: 6379);

		$this->redisPool = new RedisPool($redisHost, $redisPort, $this->maxConcurrentPayments);
		$this->defaultHttpPool = new HttpClientPool($this->defaultHost, $this->defaultPort, $this->maxConcurrentPayments);
		$this->fallbackHttpPool = new HttpClientPool($this->fallbackHost, $this->fallbackPort, $this->maxConcurrentPayments);
	}

	public function start(): void
	{
		Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
		echo "🚀 Payment Worker iniciado com {$this->maxConcurrentPayments} corrotinas concorrentes\n";

		Coroutine::create(function () {
			$this->redisPool->init();
			$this->defaultHttpPool->init();
			$this->fallbackHttpPool->init();

			for ($i = 0; $i < $this->maxConcurrentPayments; $i++) {
				Coroutine::create(function () {
					while ($this->running) {
						$this->processPayment();
					}
				});
			}
		});

		Swoole\Event::wait();
	}

	private function processPayment(): void
	{
		$redis = $this->redisPool->get();
		if (!$redis) {
			echo "[ERRO] Não conseguiu obter conexão Redis\n";
			return;
		}

		$data = $redis->brPop(['payment_queue'], 2);
		$this->redisPool->put($redis);

		if (!$data) {
			return;
		}

		$payloadString = $data[1];
		$payload = json_decode($payloadString, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			echo "[ERRO] Falha ao decodificar JSON: " . json_last_error_msg() . "\n";
			$this->totalFailed++;
			return;
		}

		$redisForHost = $this->redisPool->get();
		// TODO: health-checker + circuit breaker
		//$bestHost = (int)($redisForHost->get('best-host-processor') ?? 1);
		$bestHost = 1;
		$this->redisPool->put($redisForHost);

		$httpPool = $bestHost === 1 ? $this->defaultHttpPool : $this->fallbackHttpPool;

		$client = $httpPool->get();
		if (!$client) {
			echo "[ERRO] Não conseguiu obter cliente HTTP\n";
			$this->totalFailed++;
			return;
		}

		$preciseTimestamp = microtime(true);
		$date = DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
		$requestedAtString = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');

		$dataToSend = [
			'correlationId' => $payload['correlationId'],
			'amount' => (float) $payload['amount'],
			'requestedAt' => $requestedAtString,
		];

		$client->setMethod('POST');
		$client->setData(json_encode($dataToSend));

		try {
			$client->execute('/payments');

			if ($client->statusCode === 200) {
				$this->handleSuccessfulPayment($payload, $bestHost, $preciseTimestamp);
			} elseif ($client->statusCode != 422) {
				$this->handleFailedPayment($payload);
			}
		} catch (Throwable $e) {
			$this->handleFailedPayment($payload);
		} finally {
			$httpPool->put($client);
		}
	}

	private function handleSuccessfulPayment(array $payload, int $processorId, float $timestamp): void
	{
		$processor = $processorId === 1 ? 'default' : 'fallback';
		$member = $payload['amount'] . ':' . $payload['correlationId'];
		$key = "payments:{$processor}";

		$redis = $this->redisPool->get();
		if ($redis) {
			$result = $redis->zAdd($key, $timestamp, (string)$member);
			if ($result != 1) {
				echo "[ERRO] Falha ao persistir no Redis para correlationId: {$payload['correlationId']}\n";
				$this->totalFailed++;
			}
			$this->redisPool->put($redis);
		}
	}

	private function handleFailedPayment(array $payload): void
	{
		Coroutine::sleep(0.1);

		$redis = $this->redisPool->get();
		if ($redis) {
			$redis->lPush('payment_queue', json_encode($payload));
			$this->redisPool->put($redis);
		}
	}

	public function stop(): void
	{
		echo "🛑 Sinal de parada recebido. Encerrando workers...\n";
		$this->running = false;
	}

	public function getTotalFailedPayments(): int
	{
		return $this->totalFailed;
	}
}

if (extension_loaded('swoole') && method_exists(Swoole\Process::class, 'signal')) {
	$worker = new PaymentWorker();

	Swoole\Process::signal(SIGTERM, function () use ($worker) {
		$worker->stop();
	});
	Swoole\Process::signal(SIGINT, function () use ($worker) {
		$worker->stop();
	});

	$worker->start();
} else {
	echo "Swoole extension not loaded or signals not supported.\n";
}
