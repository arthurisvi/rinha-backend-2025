<?php
error_reporting(E_ALL & ~E_WARNING); // Mantém a supressão de warnings

require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\Http\Client;

Runtime::enableCoroutine();

class PaymentWorker
{
	private string $defaultHost;
	private int $defaultPort;
	private string $fallbackHost;
	private int $fallbackPort;
	private int $maxConcurrentPayments;
	private bool $running = true;
	private int $totalFailed = 0;

	public function __construct()
	{
		$this->defaultHost = getenv('PROCESSOR_DEFAULT_HOST') ?: 'payment-processor-default';
		$this->defaultPort = (int) (getenv('PROCESSOR_DEFAULT_PORT') ?: 8080);
		$this->fallbackHost = getenv('PROCESSOR_FALLBACK_HOST') ?: 'payment-processor-fallback';
		$this->fallbackPort = (int) (getenv('PROCESSOR_FALLBACK_PORT') ?: 8080);
		$this->maxConcurrentPayments = (int) (getenv('MAX_CONCURRENT_PAYMENTS') ?: 20);
	}

	public function start(): void
	{
		echo "🚀 Payment Worker iniciado com {$this->maxConcurrentPayments} workers concorrentes\n";

		Coroutine::create(function () {
			$this->startPaymentWorkers();
		});

		Swoole\Event::wait();
	}

	/**
	 * Inicia o pool de corrotinas (workers) para processamento de pagamentos.
	 */
	private function startPaymentWorkers(): void
	{
		for ($i = 0; $i < $this->maxConcurrentPayments; $i++) {
			Coroutine::create(function () use ($i) {
				$this->paymentWorker($i);
			});
		}
		echo "👥 {$this->maxConcurrentPayments} workers de pagamento iniciados\n";
	}

	/**
	 * Lógica principal de cada worker individual.
	 * Consome da fila, processa o pagamento e gerencia retries.
	 */
	private function paymentWorker(int $workerId): void
	{
		$redis = $this->_connectRedis();

		echo "🧵 Worker {$workerId} iniciado\n";

		while ($this->running) {
			try {
				$data = $redis->brPop(['payment_queue'], 2);

				if ($data) {
					$payloadString = $data[1];
					$payload = json_decode($payloadString, true);

					if (json_last_error() !== JSON_ERROR_NONE) {
						echo "[ERRO] Worker {$workerId} - Falha ao decodificar payload JSON: " . json_last_error_msg() . ". Payload: {$payloadString}\n";
						$this->totalFailed++;
						continue;
					}

					// Processa o pagamento e lida com o resultado
					$this->_processPaymentRequest($workerId, $redis, $payload);
				}
			} catch (Exception $e) {
				echo "💥 Erro no worker {$workerId}: " . $e->getMessage() . "\n";
				$this->totalFailed++;
				Coroutine::sleep(0.1);
			}
		}
	}

	/**
	 * Conecta a uma instância do Redis.
	 * Cada corrotina deve ter sua própria conexão para evitar problemas de concorrência.
	 */
	private function _connectRedis(): Redis
	{
		$redis = new Redis();
		$redisHost = getenv('REDIS_HOST') ?: 'redis';
		$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);

		// Tenta conectar, com timeout de 2 segundos
		$connected = $redis->connect($redisHost, $redisPort, 2);
		if (!$connected) {
			throw new Exception("Falha ao conectar no Redis em {$redisHost}:{$redisPort}");
		}
		return $redis;
	}

	/**
	 * Envia a requisição de pagamento para o processador e gerencia a resposta.
	 */
	private function _processPaymentRequest(int $workerId, Redis $redis, array $payload): void
	{
		$bestHost = (int) ($redis->get('best-host-processor') ?? 1); // Padrão para 1 (default)

		$host = ($bestHost === 1) ? $this->defaultHost : $this->fallbackHost;
		$port = ($bestHost === 1) ? $this->defaultPort : $this->fallbackPort;
		$uri = '/payments';

		$preciseTimestamp = microtime(true);
		$date = DateTime::createFromFormat('U.u', sprintf('%.6f', (string) $preciseTimestamp));
		$requestedAtString = $date
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d\TH:i:s.u\Z');
		$dataToSend = [
			'correlationId' => $payload['correlationId'],
			'amount' => (float) $payload['amount'],
			'requestedAt' => $requestedAtString
		];

		$httpClient = new Client($host, $port);
		//$httpClient->set(['timeout' => 1.5]);
		$httpClient->setHeaders([
			'Content-Type' => 'application/json',
			'Accept' => 'application/json',
		]);
		$httpClient->setMethod('POST');
		$httpClient->setData(json_encode($dataToSend));

		try {
			$httpClient->execute($uri);

			if ($httpClient->statusCode === 200) {
				$this->_handleSuccessfulPayment($redis, $payload, $bestHost, $preciseTimestamp);
			} elseif ($httpClient->statusCode != 422) {
				$this->_handleFailedPayment($redis, $payload);
			}
		} catch (Exception $e) {
			$this->_handleFailedPayment($redis, $payload);
		} finally {
			$httpClient->close();
		}
	}

	/**
	 * Lida com o processamento bem-sucedido de um pagamento.
	 */
	private function _handleSuccessfulPayment(Redis $redis, array $payload, int $processorId, string $timestamp): void
	{
		$processor = ($processorId === 1) ? 'default' : 'fallback';
		$member = $payload['amount'] . ':' . $payload['correlationId'];
		$key = "payments:{$processor}";

		$result = $redis->zAdd($key, $timestamp, (string) $member);

		if ($result != 1) {
			echo "[ERRO] Falha ao persistir no Redis para correlationId: {$payload['correlationId']}!\n";
			$this->totalFailed++;
		}
	}

	/**
	 * Lida com o processamento falho de um pagamento, incluindo lógica de retry.
	 */
	private function _handleFailedPayment(Redis $redis, array $payload): void
	{
		Coroutine::sleep(0.1);
		$redis->lpush('payment_queue', json_encode($payload));
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

$worker = new PaymentWorker();

if (extension_loaded('swoole') && method_exists(Swoole\Process::class, 'signal')) {
	Swoole\Process::signal(SIGTERM, function () use ($worker) {
		$worker->stop();
	});
	Swoole\Process::signal(SIGINT, function () use ($worker) {
		$worker->stop();
	});
}

try {
	$worker->start();
} catch (Exception $e) {
	echo "Erro Fatal na inicialização: " . $e->getMessage() . "\n";
	exit(1);
}
