<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Redis;

// Habilitar corrotinas para operações I/O (inclui Redis e HTTP)
Runtime::enableCoroutine();

function getHealthStatusProcessor(string $processorName, string $url): array|bool
{
	try {
		echo "Iniciando health check do $processorName...\n";

		// Parse da URL para extrair host e porta
		$parsedUrl = parse_url($url);
		$host = $parsedUrl['host'] ?? 'localhost';
		$port = $parsedUrl['port'] ?? 443;
		$path = $parsedUrl['path'] ?? '/';

		// HTTP Client assíncrono do Swoole
		$client = new Swoole\Coroutine\Http\Client($host, $port);
		$client->set(['timeout' => 3]); // Timeout de 3 segundos

		$success = $client->get($path);

		if ($success && $client->statusCode === 200) {
			echo "✅ $processorName: Healthy (Response: {$client->statusCode} - Body: " . $client->getBody() . ")\n";
			return [
				$processorName => json_decode($client->getBody(), true)
			];
		}

		echo "❌ $processorName: Unhealthy (Status: {$client->statusCode})\n";

		$client->close();

		return false;
	} catch (Exception $e) {
		echo "🚨 Erro no health check $processorName: " . $e->getMessage() . "\n";
		return false;
	}
}

function saveBestHostProcessor(Redis $redis, int $bestHost)
{
	try {
		// Verifica se a conexão está ativa, senão reconecta
		if (!$redis->connected) {
			$redisHost = getenv('REDIS_HOST') ?: 'redis';
			$redisPort = getenv('REDIS_PORT') ?: 6379;
			$connected = $redis->connect($redisHost, $redisPort, 2);
			if (!$connected) {
				throw new Exception("Falha ao conectar no Redis em saveBestHostProcessor");
			}
		}

		$key = "best-host-processor";
		$redis->setex($key, 8, $bestHost); // TTL de 8 segundos
		echo "📝 Melhor host salvo: $bestHost\n";
	} catch (Exception $e) {
		echo "❌ Erro ao salvar status no Redis: " . $e->getMessage() . "\n";
	}
}

function getBestHostProcessor(array $hosts): int
{
	$defaultStatus = $hosts['default'] ?? ['failing' => true, 'minResponseTime' => 9999];
	$fallbackStatus = $hosts['fallback'] ?? ['failing' => true, 'minResponseTime' => 9999];

	$defaultIsFailing = $defaultStatus['failing'];
	$fallbackIsFailing = $fallbackStatus['failing'];

	if ($fallbackIsFailing) {
		return 1;
	}

	$defaultIsSlow = isset($defaultStatus['minResponseTime'], $fallbackStatus['minResponseTime'])
		&& $fallbackStatus['minResponseTime'] > 0
		&& $defaultStatus['minResponseTime'] > ($fallbackStatus['minResponseTime'] * 3);

	if (!$fallbackIsFailing && ($defaultIsFailing || $defaultIsSlow)) {
		return 2; // Usa fallback
	}

	return 1; // Usa default
}

Coroutine::create(function () {
	// Conexão Redis criada uma vez e reutilizada
	try {
		$redis = new Redis();
		$redisHost = getenv('REDIS_HOST') ?: 'redis';
		$redisPort = getenv('REDIS_PORT') ?: 6379;
		$connected = $redis->connect($redisHost, $redisPort, 2);
		if (!$connected) {
			throw new Exception("Falha ao conectar no Redis");
		}
	} catch (Exception $e) {
		echo "💥 Erro ao conectar no Redis: " . $e->getMessage() . "\n";
		return;
	}

	while (true) {
		try {
			echo "\n🔄 Iniciando novo ciclo de health checks...\n";

			$endpointPath = '/payments/service-health';

			// Canal para sincronizar resultados
			$channel = new Channel(2);

			// Corrotina para health check do host default
			Coroutine::create(function () use ($channel, $endpointPath) {
				$url = getenv('PROCESSOR_DEFAULT_URL') ?: 'http://payment-processor-default:8080';
				$healthStatus = getHealthStatusProcessor('default', $url . $endpointPath);
				$channel->push($healthStatus);
			});

			// Corrotina para health check do host fallback
			Coroutine::create(function () use ($channel, $endpointPath) {
				$url = getenv('PROCESSOR_FALLBACK_URL') ?: 'http://payment-processor-fallback:8080';
				$healthStatus = getHealthStatusProcessor('fallback', $url . $endpointPath);
				$channel->push($healthStatus);
			});

			// Aguardar resultados de ambas as corrotinas
			$results = [];
			for ($i = 0; $i < 2; $i++) {
				$result = $channel->pop();
				if ($result !== false && is_array($result)) {
					$results += $result;
				}
			}

			$channel->close();

			if (empty($results)) {
				throw new \RuntimeException("Falha ao coletar resultado do health check: ambos falharam");
			}

			echo "Health checks concluídos, analisando resultados...\n";

			$bestHost = getBestHostProcessor($results);

			if ($bestHost) {
				saveBestHostProcessor($redis, $bestHost);
			}

			echo "Aguardando próximo ciclo...\n";

			Coroutine::sleep(5);
		} catch (Exception $e) {
			echo "💥 Erro no loop principal: " . $e->getMessage() . "\n";
			Coroutine::sleep(5);
		}
	}
});

echo "🚀 Worker de Health Check rodando...\n";
Swoole\Event::wait();
