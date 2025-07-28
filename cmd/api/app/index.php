<?php

use Hyperf\Nano\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface;

use Hyperf\Redis\RedisFactory;
use Hyperf\HttpMessage\Server\Response;

use function Hyperf\Support\env;
use function Hyperf\Coroutine\parallel;

require_once __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create('0.0.0.0', 8080);

$app->config([
	'redis.default' => [
		'host' => env('REDIS_HOST', 'localhost'),
		'auth' => env('REDIS_AUTH', null),
		'port' => (int) env('REDIS_PORT', 6379),
		'db' => (int) env('REDIS_DB', 0),
		'timeout' => 0.0,
		'reserved' => null,
		'retry_interval' => 0,
		'read_timeout' => 0.0,
		'pool' => [
			'min_connections' => 5,
			'max_connections' => 100,
			'connect_timeout' => 1,
			'wait_timeout' => 0.2,
			'heartbeat' => -1,
			'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
		],
		'options' => [
			'prefix' => env('REDIS_PREFIX', ''),
		],
		'event' => [
			'enable' => (bool) env('REDIS_EVENT_ENABLE', false),
		],
	],
	'server.settings' => [
		'worker_num' => 1,
		'enable_coroutine' => true,
		'max_conn' => 1024,         // Aceita até 1024 conexões simultâneas
		//'max_request' => 20000,     // Respawn do worker só após X requests
	],
]);

$app->post('/purge-payments', function (ServerRequestInterface $request) use ($app) {
	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	$redis->flushAll();
});

$app->get('/payments-summary', function (ServerRequestInterface $request) use ($app) {
	$fromDate = $request->getQueryParams()['from'] ?? null;
	$toDate = $request->getQueryParams()['to'] ?? null;

	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	$toTimestampString = function (?string $dateString): ?string {
		if (!$dateString) {
			return null;
		}

		$date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString)
			?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);

		if (!$date) {
			return null;
		}

		return $date->format('U.u');
	};

	$fromTs = $toTimestampString($fromDate) ?? '-inf';
	$toTs = $toTimestampString($toDate) ?? '+inf';

	$processProcessor = function ($processorName) use ($redis, $fromTs, $toTs) {
		try {
			$results = $redis->zRangeByScore("payments:{$processorName}", $fromTs, $toTs);

			$totalRequests = count($results);
			$totalAmount = array_sum(array_map(function ($item) {
				// Desserializa o valor
				// TO DO: verificar pq está salvando no redis serializado
				$value = @unserialize($item);

				if ($value === false) {
					// fallback: se não conseguir desserializar, ignora ou trata como zero
					return 0.0;
				}
				// Extrai o amount antes do ':'
				return floatval(explode(':', $value)[0]);
			}, $results));

			return [
				'totalRequests' => $totalRequests,
				'totalAmount' => round($totalAmount, 2)
			];
		} catch (Exception $e) {
			echo "[ERROR] Erro ao processar {$processorName}: " . $e->getMessage() . "\n";
			return [
				'totalRequests' => 0,
				'totalAmount' => 0.0,
			];
		}
	};

	$parallelResults = parallel([
		'default' => fn() => $processProcessor('default'),
		'fallback' => fn() => $processProcessor('fallback')
	]);

	$responsePayload = [
		'default' => $parallelResults['default'],
		'fallback' => $parallelResults['fallback']
	];

	return (new \Hyperf\HttpServer\Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode($responsePayload)));
});

$app->post('/payments', function (ServerRequestInterface $request) use ($app) {
	$body = $request->getParsedBody();
	$correlationId = $body['correlationId'] ?? null;
	$amount = $body['amount'] ?? null;

	try {
		/** @var \Hyperf\Redis\RedisProxy $redis */
		$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

		$paymentData = [
			'correlationId' => $correlationId,
			'amount' => $amount
		];

		$redis->lpush('payment_queue', json_encode($paymentData));

		return (new Response())
			->withStatus(202);
	} catch (Exception $e) {
		error_log("💥 [{$correlationId}] ERRO: " . $e->getMessage());
		error_log("📚 [{$correlationId}] Stack trace: " . $e->getTraceAsString());
		return (new Response())
			->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'error' => 'Internal server error',
				'requestId' => $correlationId
			])));
	}
});

$app->run();
