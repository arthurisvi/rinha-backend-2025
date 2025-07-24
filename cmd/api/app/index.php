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

$app->get('/payments-summary', function (ServerRequestInterface $request) use ($app) {
	$fromDate = $request->getQueryParams()['from'] ?? null;
	$toDate = $request->getQueryParams()['to'] ?? null;

	echo "[DEBUG] Recebida requisição para /payments-summary\n";
	echo "[DEBUG] Parâmetros recebidos: from = " . var_export($fromDate, true) . ", to = " . var_export($toDate, true) . "\n";

	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	echo "[DEBUG] Intervalo de busca: from = $fromDate, to = $toDate\n";

	// Função para processar um processador específico
	$processProcessor = function ($processorName) use ($redis, $fromDate, $toDate) {
		try {
			$key = "payments:{$processorName}:list";

			$items = $redis->lRange($key, 0, -1);

			$totalRequests = 0;
			$totalAmount = 0.0;

			foreach ($items as $jsonItem) {
				$raw = @unserialize($jsonItem);
				if (!is_string($raw)) {
					$raw = $jsonItem;
				}

				$item = json_decode($raw, true);
				if (!$item) continue;

				$requestedAt = $item['requestedAt'] ?? null;
				if ($requestedAt === null) continue;

				if (($fromDate === null || $requestedAt >= $fromDate) && ($toDate === null || $requestedAt <= $toDate)) {
					$totalRequests++;
					$totalAmount += (float) ($item['amount'] ?? 0);
				}
			}
			return [
				'totalRequests' => $totalRequests,
				'totalAmount' => $totalAmount,
			];
		} catch (Exception $e) {
			echo "[ERROR] Erro ao processar {$processorName}: " . $e->getMessage() . "\n";
			return [
				'totalRequests' => 0,
				'totalAmount' => 0.0,
			];
		}
	};

	// Executar consultas em paralelo usando Hyperf Parallel com chaves nomeadas
	$parallelResults = parallel([
		'default' => function () use ($processProcessor) {
			return $processProcessor('default');
		},
		'fallback' => function () use ($processProcessor) {
			return $processProcessor('fallback');
		}
	]);

	$responsePayload = [
		'default' => $parallelResults['default'],
		'fallback' => $parallelResults['fallback']
	];

	echo "[DEBUG] Resposta: " . json_encode($responsePayload) . "\n";

	return (new Response())
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

$app->get('/payments-duplicates', function () use ($app) {
	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	// Troque 'payments:default' pelo ZSET que deseja consultar
	$members = $redis->zRange('payments:default', 0, -1);

	$ids = [];
	foreach ($members as $item) {
		$val = @unserialize($item);
		if ($val !== false) {
			$parts = explode(':', $val);
			if (isset($parts[1])) {
				$ids[] = $parts[1];
			}
		}
	}
	$counts = array_count_values($ids);
	$duplicates = array_filter($counts, function ($count) {
		return $count > 1;
	});

	return (new Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
			'duplicates' => $duplicates
		])));
});

$app->get('/payments-exists/{correlationId}', function ($correlationId) use ($app) {
	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');

	// Verifica no backend (Redis)
	$members = $redis->zRange('payments:default', 0, -1);
	$found = false;
	$amount = null;
	foreach ($members as $item) {
		$val = @unserialize($item);
		if ($val !== false) {
			$parts = explode(':', $val);
			if (isset($parts[1]) && $parts[1] === $correlationId) {
				$found = true;
				$amount = $parts[0];
				break;
			}
		}
	}

	// Verifica no processor default
	$defaultHost = getenv('PROCESSOR_DEFAULT_HOST') ?: 'payment-processor-default';
	$defaultPort = getenv('PROCESSOR_DEFAULT_PORT') ?: 8080;
	$defaultUrl = "http://{$defaultHost}:{$defaultPort}/payments/{$correlationId}";
	$existsDefault = false;
	try {
		$resp = file_get_contents($defaultUrl);
		$http_response_header = $http_response_header ?? [];
		$statusLine = $http_response_header[0] ?? '';
		if (strpos($statusLine, '200') !== false) {
			$existsDefault = true;
		}
	} catch (Exception $e) {
		$existsDefault = false;
	}

	// Verifica no processor fallback
	$fallbackHost = getenv('PROCESSOR_FALLBACK_HOST') ?: 'payment-processor-fallback';
	$fallbackPort = getenv('PROCESSOR_FALLBACK_PORT') ?: 8080;
	$fallbackUrl = "http://{$fallbackHost}:{$fallbackPort}/payments/{$correlationId}";
	$existsFallback = false;
	try {
		$resp = file_get_contents($fallbackUrl);
		$http_response_header = $http_response_header ?? [];
		$statusLine = $http_response_header[0] ?? '';
		if (strpos($statusLine, '200') !== false) {
			$existsFallback = true;
		}
	} catch (Exception $e) {
		$existsFallback = false;
	}

	return (new Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
			'exists_backend' => $found,
			'exists_default' => $existsDefault,
			'exists_fallback' => $existsFallback,
			'amount' => $amount,
			'correlationId' => $correlationId
		])));
});

$app->get('/payments-diff', function (ServerRequestInterface $request) use ($app) {
	$from = $request->getQueryParams()['from'] ?? null;
	$to = $request->getQueryParams()['to'] ?? null;

	if (!$from || !$to) {
		return (new Response())
			->withStatus(400)
			->withHeader('Content-Type', 'application/json')
			->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
				'error' => 'from and to are required in ISO format'
			])));
	}

	$fromTimestamp = (string)(new DateTimeImmutable($from))->getTimestamp();
	$toTimestamp = (string)(new DateTimeImmutable($to))->getTimestamp();

	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');
	$members = $redis->zRangeByScore('payments:default', $fromTimestamp, $toTimestamp);

	$correlationIds = [];
	foreach ($members as $item) {
		$val = @unserialize($item);
		if ($val !== false) {
			$parts = explode(':', $val);
			if (isset($parts[1])) {
				$correlationIds[$parts[1]] = [
					'amount' => $parts[0],
					'raw' => $val
				];
			}
		}
	}

	$defaultHost = getenv('PROCESSOR_DEFAULT_HOST') ?: 'payment-processor-default';
	$defaultPort = getenv('PROCESSOR_DEFAULT_PORT') ?: 8080;
	$fallbackHost = getenv('PROCESSOR_FALLBACK_HOST') ?: 'payment-processor-fallback';
	$fallbackPort = getenv('PROCESSOR_FALLBACK_PORT') ?: 8080;

	$only_backend = [];
	$only_default = [];
	$only_fallback = [];
	$in_both = [];
	$details = [];

	$batchSize = 20;
	$allCids = array_keys($correlationIds);
	$total = count($allCids);
	$batches = array_chunk($allCids, $batchSize);
	$batchNum = 0;
	$startAll = microtime(true);

	foreach ($batches as $batch) {
		$batchNum++;
		$startBatch = microtime(true);
		error_log("[payments-diff] Iniciando batch {$batchNum}/" . count($batches) . " (" . count($batch) . " correlationIds)");

		$resultsBatch = [];
		$wg = new Swoole\Coroutine\WaitGroup();
		foreach ($batch as $cid) {
			$wg->add();
			Swoole\Coroutine::create(function () use (
				$cid,
				$defaultHost,
				$defaultPort,
				$fallbackHost,
				$fallbackPort,
				&$resultsBatch,
				$correlationIds,
				$wg
			) {
				$existsDefault = false;
				$existsFallback = false;
				$defaultData = null;
				$fallbackData = null;

				// Default processor
				$cli = new Swoole\Coroutine\Http\Client($defaultHost, $defaultPort);
				$cli->set(['timeout' => 2]);
				$cli->get("/payments/{$cid}");
				if ($cli->statusCode === 200) {
					$existsDefault = true;
					$defaultData = json_decode($cli->body, true);
				}
				$cli->close();

				// Fallback processor
				$cli2 = new Swoole\Coroutine\Http\Client($fallbackHost, $fallbackPort);
				$cli2->set(['timeout' => 2]);
				$cli2->get("/payments/{$cid}");
				if ($cli2->statusCode === 200) {
					$existsFallback = true;
					$fallbackData = json_decode($cli2->body, true);
				}
				$cli2->close();

				$resultsBatch[$cid] = [
					'exists_backend' => true,
					'exists_default' => $existsDefault,
					'exists_fallback' => $existsFallback,
					'amount' => $correlationIds[$cid]['amount'],
					'default_data' => $defaultData,
					'fallback_data' => $fallbackData
				];
				$wg->done();
			});
		}
		$wg->wait();

		// Classificação dos resultados
		foreach ($resultsBatch as $cid => $info) {
			$details[$cid] = $info;
			if ($info['exists_default'] && $info['exists_fallback']) {
				$in_both[] = $cid;
			} elseif ($info['exists_default']) {
				$only_default[] = $cid;
			} elseif ($info['exists_fallback']) {
				$only_fallback[] = $cid;
			} else {
				$only_backend[] = $cid;
			}
		}
		$endBatch = microtime(true);
		error_log("[payments-diff] Batch {$batchNum} finalizado em " . round($endBatch - $startBatch, 2) . "s");
	}
	$endAll = microtime(true);
	error_log("[payments-diff] Processamento finalizado. Total correlationIds: {$total}. Tempo total: " . round($endAll - $startAll, 2) . "s");

	return (new Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
			'only_backend' => $only_backend,
			'only_default' => $only_default,
			'only_fallback' => $only_fallback,
			'in_both' => $in_both,
			'details' => $details,
			'total' => $total,
			'batch_size' => $batchSize,
			'batches' => count($batches)
		])));
});

$app->get('/payments-missing-in-processor', function () use ($app) {
	/** @var \Hyperf\Redis\RedisProxy $redis */
	$redis = $app->getContainer()->get(RedisFactory::class)->get('default');
	$missing = $redis->sMembers('payments:missing-in-processor');
	return (new Response())
		->withStatus(200)
		->withHeader('Content-Type', 'application/json')
		->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(json_encode([
			'missing_in_processor' => $missing,
			'total' => count($missing)
		])));
});

$app->run();
