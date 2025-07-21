<?php
error_reporting(E_ALL & ~E_WARNING);

require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
Runtime::enableCoroutine();

class PaymentWorker {
    private string $defaultHost;
    private int $defaultPort;
    private string $fallbackHost;
    private int $fallbackPort;
    private int $maxConcurrentPayments;
    private Channel $resultChannel;
    private Channel $workerPool;
    private bool $running = true;
    private int $activeWorkers = 0;
    private int $totalProcessed = 0;
    private int $totalFailed = 0;
    private float $totalDuration = 0;
    private int $totalRequests = 0;

    public function __construct() {
        $this->defaultHost = getenv('PROCESSOR_DEFAULT_HOST') ?: 'payment-processor-default';
        $this->defaultPort = getenv('PROCESSOR_DEFAULT_PORT') ?: 8080;
        $this->fallbackHost = getenv('PROCESSOR_FALLBACK_HOST') ?: 'payment-processor-fallback';
        $this->fallbackPort = getenv('PROCESSOR_FALLBACK_PORT') ?: 8080;
        $this->maxConcurrentPayments = 20;
        $this->resultChannel = new Channel($this->maxConcurrentPayments);
        $this->workerPool = new Channel($this->maxConcurrentPayments);
    }

    public function start(): void {
        echo "🚀 Payment Worker iniciado com {$this->maxConcurrentPayments} workers concorrentes\n";

        Coroutine::create(function () {
            $this->runWorker();
        });

        Swoole\Event::wait();
    }

    private function runWorker(): void {
        // ✅ INICIAR COMPONENTES
        $this->startPaymentWorkers();
    }

    private function startPaymentWorkers(): void {
        // ✅ POOL DE WORKERS
        for ($i = 0; $i < $this->maxConcurrentPayments; $i++) {
            Coroutine::create(function () use ($i) {
                $this->paymentWorker($i);
            });
        }

        echo "👥 {$this->maxConcurrentPayments} workers de pagamento iniciados\n";
    }

    private function paymentWorker(int $workerId): void {
        $redis = $this->connectRedis();

        echo "🧵 Worker {$workerId} iniciado\n";

        while ($this->running) {
            try {
                $data = $redis->brPop(['payment_queue'], 2);

                if ($data) {
                    echo "✅ Worker {$workerId} consumiu: {$data[1]}\n";
                    $payload = json_decode($data[1], true);
                    $bestHost = $redis->get('best-host-processor') ?? 1;
                    echo "✅ Worker {$workerId} obteve melhor host: {$bestHost}\n";

                    $host = '';
                    $port = '';
                    $uri = '/payments';
                    if ($bestHost == 1) {
                        $host = $this->defaultHost;
                        $port = $this->defaultPort;
                    } else {
                        $host = $this->fallbackHost;
                        $port = $this->fallbackPort;
                    }

                    $currentTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    $currentTimeTimestamp = $currentTime->getTimestamp();

                    $data = [
                        'correlationId' => $payload['correlationId'],
                        'amount' => (float) $payload['amount'],
                        'requestedAt' => $currentTime->format('c')
                    ];

                    echo "🔎 Worker {$workerId} -Enviando requisição para {$host}:{$port}{$uri}\n";
                    echo "Payload: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";

                    $httpClient = new Client($host, $port);
                    $httpClient->set(['timeout' => 1.5]);

                    $httpClient->setHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ]);

                    $httpClient->setMethod('POST');
                    $httpClient->setData(json_encode($data));

                    $httpClient->execute($uri);

                    if ($httpClient->statusCode == 200) {
                        $processor = $bestHost == 1 ? 'default' : 'fallback';
                        $member = $payload['amount'] . ':' . $payload['correlationId'];

                        echo "🔎 Worker {$workerId} - Persistindo no Redis ({$processor}) - currentTime: {$currentTimeTimestamp} - member: {$member}\n";
                        $result = $redis->zAdd(
                            "payments:{$processor}",
                            $currentTimeTimestamp,
                            (string) $member
                        );

                        if ($result === 1) {
                            echo "[DEBUG] Persistido com sucesso no Redis (novo elemento).\n";
                            // ✅ LIBERAR LOCK APÓS SUCESSO
                            if (isset($payload['lockValue'])) {
                                $lockKey = "payment_lock:{$payload['correlationId']}";
                                if ($redis->get($lockKey) === $payload['lockValue']) {
                                    $redis->del($lockKey);
                                    echo "�� Worker {$workerId} - Lock liberado\n";
                                }
                            }
                            echo "✅ Worker {$workerId} - Pagamento processado com sucesso!\n";
                        } elseif ($result === 0) {
                            echo "[DEBUG] Elemento já existia no Redis (score/member iguais).\n";
                        } else {
                            echo "[ERRO] Falha ao persistir no Redis!\n";
                        }
                    } elseif ($httpClient->statusCode != 422) {
                        // ❌ FALHA - Reinfileirar com controle de tentativas
                        $retryCount = $payload['retryCount'] ?? 0;
                        $maxRetries = 2;

                        if ($httpClient->statusCode <= 0) {
                            echo "❌ Worker {$workerId} - Erro de conexão:\n";
                            echo "ErrCode: {$httpClient->errCode} - " . swoole_strerror($httpClient->errCode) . PHP_EOL;
                        } else {
                            echo "❌ Worker {$workerId} - Status não-sucesso: {$httpClient->statusCode}\n";
                        }

                        if ($retryCount < $maxRetries) {
                            // Incrementar contador de tentativas
                            $payload['retryCount'] = $retryCount + 1;

                            // Reinfileirar para nova tentativa
                            $requeue = $redis->lpush('payment_queue', json_encode($payload));
                            echo "🔄 Worker {$workerId} - Pagamento reinfileirado (tentativa {$payload['retryCount']}/{$maxRetries}) - Queue result: {$requeue}\n";
                        } else {
                            // Máximo de tentativas atingido - apenas log
                            echo "💀 Worker {$workerId} - Pagamento descartado após {$maxRetries} tentativas\n";
                            $this->totalFailed++;
                        }
                    }

                    $httpClient->close();
                }
            } catch (Exception $e) {
                echo "💥 Erro no worker {$workerId}: " . $e->getMessage() . "\n";
                $this->activeWorkers--;
                $this->totalFailed++;
                Coroutine::sleep(0.1);
            }
        }
    }

    // ✅ CONEXÃO REDIS INDIVIDUAL
    private function connectRedis(): Redis {
        $redis = new Swoole\Coroutine\Redis();
        $redisHost = getenv('REDIS_HOST') ?: 'redis';
        $redisPort = getenv('REDIS_PORT') ?: 6379;

        $connected = $redis->connect($redisHost, $redisPort, 2);
        if (!$connected) {
            throw new Exception("Falha ao conectar no Redis");
        }

        return $redis;
    }
}

// Iniciar worker
$worker = new PaymentWorker();
$worker->start();
