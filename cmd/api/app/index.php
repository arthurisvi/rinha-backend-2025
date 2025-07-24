<?php

use Hyperf\Nano\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface;

use Hyperf\Redis\RedisFactory;
use Hyperf\HttpMessage\Server\Response;

use function Hyperf\Support\env;
use function Hyperf\Coroutine\parallel;

require_once __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create('0.0.0.0', 8080);

// https://medium.com/@anil.goyal0057/distributed-locking-mechanism-using-redis-26c17d9f3d5f

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
			'connect_timeout' => 3.0,
			'wait_timeout' => 2.0,
			'heartbeat' => -1,
			'max_idle_time' => (float) env('REDIS_MAX_IDLE_TIME', 60),
		],
		'options' => [
			'prefix' => env('REDIS_PREFIX', ''),
		],
		'event' => [
			'enable' => (bool) env('REDIS_EVENT_ENABLE', false),
		],
	]
]);

$app->post('/purge-payments', function (ServerRequestInterface $request) use ($app) {
	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	$redis->flushAll();
});

$app->get('/payments-summary', function (ServerRequestInterface $request) use ($app) {
	$fromDate = $request->getQueryParams()['from'] ?? null;
	$toDate = $request->getQueryParams()['to'] ?? null;

	echo "[DEBUG] Recebida requisição para /payments-summary\n";
	echo "[DEBUG] Parâmetros recebidos: from = " . var_export($fromDate, true) . ", to = " . var_export($toDate, true) . "\n";

	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	$toFloatTimestamp = function (?string $dateString): ?float {
		if (!$dateString) {
			return null;
		}

		$date = DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $dateString)
			?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateString);

		if (!$date) {
			return null;
		}

		return (float) $date->format('U.u');
	};

	$fromTs = (string) $toFloatTimestamp($fromDate) ?? '-inf';
	$toTs = (string) $toFloatTimestamp($toDate) ?? '+inf';

	echo "[DEBUG] Intervalo de busca (timestamp): from = $fromTs, to = $toTs\n";

	$processProcessor = function ($processorName) use ($redis, $fromTs, $toTs) {
		try {
			$results = $redis->zRangeByScore("payments:{$processorName}", $fromTs, $toTs);
			echo "[DEBUG] payments:{$processorName} - totalRequests = " . count($results) . ", valores = " . json_encode($results) . "\n";

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

	echo "[DEBUG] Resposta: " . json_encode($responsePayload) . "\n";

	return (new \Hyperf\HttpServer\Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode($responsePayload)));
});

$app->post('/payments', function (ServerRequestInterface $request) use ($app) {
	$requestId = uniqid('req_');
	$startTime = microtime(true);

	echo "\n�� [{$requestId}] === NOVA REQUISIÇÃO ===\n";
	echo "📅 Timestamp: " . date('Y-m-d H:i:s') . "\n";

	$body = $request->getParsedBody();
	$correlationId = $body['correlationId'] ?? null;
	$amount = $body['amount'] ?? null;

	echo "📋 [{$requestId}] Dados recebidos:\n";
	echo "   - correlationId: {$correlationId}\n";
	echo "   - amount: {$amount}\n";
	echo "   - Body completo: " . json_encode($body) . "\n";

	// Validação
	if (!$correlationId || !$amount) {
		echo "❌ [{$requestId}] Validação falhou - dados incompletos\n";
		return (new Response())
			->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'error' => 'correlationId and amount are required',
				'requestId' => $requestId
			])));
	}

	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	echo "�� [{$requestId}] Conectando ao Redis...\n";

	// Verificar se já foi processado
	$processedKey = "payment_processed:{$correlationId}";
	$alreadyProcessed = $redis->get($processedKey);

	echo "�� [{$requestId}] Verificando se já foi processado:\n";
	echo "   - Key: {$processedKey}\n";
	echo "   - Valor: " . ($alreadyProcessed ?: 'null') . "\n";

	if ($alreadyProcessed) {
		echo "✅ [{$requestId}] Pagamento já processado anteriormente\n";
		$duration = round((microtime(true) - $startTime) * 1000, 2);
		echo "⏱️ [{$requestId}] Duração: {$duration}ms\n";

		return (new Response())
			->withStatus(200)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'message' => 'Payment already processed successfully',
				'correlationId' => $correlationId,
				'requestId' => $requestId,
				'duration' => $duration
			])));
	}

	// Tentar adquirir lock
	$lockKey = "payment_lock:{$correlationId}";
	$lockValue = uniqid('lock_');
	$lockTTL = 60;

	echo "🔒 [{$requestId}] Tentando adquirir lock:\n";
	echo "   - Lock Key: {$lockKey}\n";
	echo "   - Lock Value: {$lockValue}\n";
	echo "   - TTL: {$lockTTL}s\n";

	$acquiredLock = $redis->set($lockKey, $lockValue, ['NX', 'EX' => $lockTTL]);

	echo "🔒 [{$requestId}] Resultado do lock: " . ($acquiredLock ? 'ADQUIRIDO' : 'FALHOU') . "\n";

	if (!$acquiredLock) {
		// Verificar se existe lock
		$existingLock = $redis->get($lockKey);
		$lockTTL = $redis->ttl($lockKey);

		echo "⚠️ [{$requestId}] Lock já existe:\n";
		echo "   - Valor atual: {$existingLock}\n";
		echo "   - TTL restante: {$lockTTL}s\n";

		$duration = round((microtime(true) - $startTime) * 1000, 2);
		echo "⏱️ [{$requestId}] Duração: {$duration}ms\n";

		return (new Response())
			->withStatus(409)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'error' => 'Payment already being processed',
				'correlationId' => $correlationId,
				'requestId' => $requestId,
				'duration' => $duration,
				'lockInfo' => [
					'value' => $existingLock,
					'ttl' => $lockTTL
				]
			])));
	}

	try {
		echo "✅ [{$requestId}] Lock adquirido com sucesso\n";

		// Enfileirar para processamento
		$paymentData = [
			'correlationId' => $correlationId,
			'amount' => $amount,
			'requestedAt' => date('c'),
			'lockValue' => $lockValue,
			'requestId' => $requestId
		];

		echo "📤 [{$requestId}] Enfileirando pagamento:\n";
		echo "   - Queue: payment_queue\n";
		echo "   - Data: " . json_encode($paymentData) . "\n";

		$queueResult = $redis->lpush('payment_queue', json_encode($paymentData));

		echo "📤 [{$requestId}] Resultado do enfileiramento: {$queueResult}\n";

		$duration = round((microtime(true) - $startTime) * 1000, 2);
		echo "⏱️ [{$requestId}] Duração: {$duration}ms\n";
		echo "✅ [{$requestId}] === REQUISIÇÃO FINALIZADA ===\n";

		return (new Response())
			->withStatus(202)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'message' => 'Payment accepted for processing',
				'correlationId' => $correlationId,
				'requestId' => $requestId,
				'duration' => $duration
			])));
	} catch (Exception $e) {
		echo "💥 [{$requestId}] ERRO: " . $e->getMessage() . "\n";
		echo "📚 [{$requestId}] Stack trace: " . $e->getTraceAsString() . "\n";

		// Liberar lock em caso de erro
		if ($redis->get($lockKey) === $lockValue) {
			$redis->del($lockKey);
			echo "🔓 [{$requestId}] Lock liberado devido ao erro\n";
		}

		$duration = round((microtime(true) - $startTime) * 1000, 2);
		echo "⏱️ [{$requestId}] Duração: {$duration}ms\n";
		echo "❌ [{$requestId}] === REQUISIÇÃO COM ERRO ===\n";

		return (new Response())
			->withStatus(500)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'error' => 'Internal server error',
				'requestId' => $requestId,
				'duration' => $duration
			])));
	}
});

$app->run();
