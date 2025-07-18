<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

Runtime::enableCoroutine();

class PaymentWorker {
    private string $defaultUrl;
    private string $fallbackUrl;
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
        $this->defaultUrl = getenv('PROCESSOR_DEFAULT_URL') ?: 'http://payment-processor-default:8080';
        $this->fallbackUrl = getenv('PROCESSOR_FALLBACK_URL') ?: 'http://payment-processor-fallback:8080';
        $this->maxConcurrentPayments = 50;
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
        $this->startResultProcessor();
        //$this->startMetricsReporter();
        $this->startHealthCheckMonitor();
    }

    private function startPaymentWorkers(): void {
        // ✅ POOL DE WORKERS OTIMIZADO
        for ($i = 0; $i < $this->maxConcurrentPayments; $i++) {
            Coroutine::create(function () use ($i) {
                $this->paymentWorker($i);
            });
        }

        echo "👥 {$this->maxConcurrentPayments} workers de pagamento iniciados\n";
    }

    private function paymentWorker(int $workerId): void {
        // ✅ CONEXÃO REDIS INDIVIDUAL POR WORKER
        $redis = $this->connectRedis();

        echo " Worker {$workerId} iniciado\n";

        while ($this->running) {
            try {
                // ✅ CONTROLE DE BACKPRESSURE
                $this->workerPool->push($workerId);

                // ✅ CONSUMO ASSÍNCRONO DA FILA
                $paymentData = $redis->brpop('payment_queue', 5);

                echo " Worker {$workerId} recebeu: {$paymentData}\n";

                if ($paymentData) {
                    $payment = json_decode($paymentData[1], true);
                    $this->activeWorkers++;

                    echo " Worker {$workerId} processando: {$payment['correlationId']} (Ativos: {$this->activeWorkers})\n";

                    // ✅ VALIDAÇÃO ASSÍNCRONA
                    if ($this->validateLock($payment, $redis)) {
                        $result = $this->processPayment($payment, $workerId, $redis);
                        $this->resultChannel->push($result);
                    } else {
                        echo "⚠️ Worker {$workerId}: Lock inválido para {$payment['correlationId']}\n";
                        $this->totalFailed++;
                    }

                    $this->activeWorkers--;
                } else {
                    echo " Worker {$workerId} ocioso (fila vazia)\n";
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

    private function startResultProcessor(): void {
        Coroutine::create(function () {
            // ✅ CONEXÃO REDIS INDIVIDUAL
            $redis = $this->connectRedis();

            while ($this->running) {
                try {
                    $result = $this->resultChannel->pop();
                    if (!$result) continue;

                    // ✅ PROCESSAMENTO ASSÍNCRONO DE RESULTADOS
                    $this->handlePaymentResult($result, $redis);

                } catch (Exception $e) {
                    echo "💥 Erro no processador de resultados: " . $e->getMessage() . "\n";
                }
            }
        });

        echo "📤 Processador de resultados iniciado\n";
    }

    private function startMetricsReporter(): void {
        Coroutine::create(function () {
            // ✅ CONEXÃO REDIS INDIVIDUAL
            $redis = $this->connectRedis();

            while ($this->running) {
                try {
                    // ✅ MÉTRICAS ASSÍNCRONAS
                    $queueSize = $redis->llen('payment_queue');
                    $availableWorkers = $this->workerPool->length();
                    $utilization = (($this->maxConcurrentPayments - $availableWorkers) / $this->maxConcurrentPayments) * 100;
                    $avgDuration = $this->totalRequests > 0 ? $this->totalDuration / $this->totalRequests : 0;

                    // ✅ MONITORAMENTO DE BACKPRESSURE
                    if ($queueSize > 100 && $availableWorkers < 10) {
                        echo "⚠️ BACKPRESSURE: Fila={$queueSize}, Workers Livres={$availableWorkers}\n";
                    }

                    echo "📈 Métricas: Fila={$queueSize}, Workers Livres={$availableWorkers}, Utilização={$utilization}%, Processados={$this->totalProcessed}, Falhas={$this->totalFailed}, Duração Média={$avgDuration}ms\n";

                    Coroutine::sleep(10);

                } catch (Exception $e) {
                    echo "💥 Erro no reporter: " . $e->getMessage() . "\n";
                }
            }
        });

        echo "📊 Reporter de métricas iniciado\n";
    }

    private function startHealthCheckMonitor(): void {
        Coroutine::create(function () {
            // ✅ CONEXÃO REDIS INDIVIDUAL
            $redis = $this->connectRedis();

            while ($this->running) {
                try {
                    // ✅ MONITORAMENTO ASSÍNCRONO DE HEALTH CHECK
                    $bestHost = $redis->get('best-host-processor');
                    $ttl = $redis->ttl('best-host-processor');

                    if ($ttl < 3) {
                        echo "⚠️ Health check expirando (TTL: {$ttl}s)\n";
                    }

                    Coroutine::sleep(5);

                } catch (Exception $e) {
                    echo "💥 Erro no monitor de health: " . $e->getMessage() . "\n";
                }
            }
        });

        echo "🏥 Monitor de health check iniciado\n";
    }

    private function validateLock(array $payment, Redis $redis): bool {
        $lockKey = "payment_lock:{$payment['correlationId']}";
        $lockValue = $payment['lockValue'] ?? null;

        if (!$lockValue) return false;

        // ✅ VALIDAÇÃO ASSÍNCRONA
        $currentLock = $redis->get($lockKey);
        return $currentLock === $lockValue;
    }

    private function processPayment(array $payment, int $workerId, Redis $redis): array {
        $startTime = microtime(true);
        $correlationId = $payment['correlationId'];
        $amount = $payment['amount'];

        try {
            // ✅ OBTER PROCESSADOR ASSÍNCRONO
            $bestProcessor = $this->getBestProcessor($redis);

            if (!$bestProcessor) {
                throw new Exception("Nenhum processador disponível");
            }

            // ✅ PROCESSAMENTO ASSÍNCRONO
            $result = $this->processWithRetry($payment, $bestProcessor);

            $duration = (microtime(true) - $startTime) * 1000;
            $this->totalDuration += $duration;
            $this->totalRequests++;

            return [
                'success' => true,
                'correlationId' => $correlationId,
                'amount' => $amount,
                'processor' => $bestProcessor,
                'duration' => $duration,
                'workerId' => $workerId,
                'result' => $result
            ];

        } catch (Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            return [
                'success' => false,
                'correlationId' => $correlationId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'duration' => $duration,
                'workerId' => $workerId
            ];
        }
    }

    private function getBestProcessor(Redis $redis): ?string {
        // ✅ CONSULTA ASSÍNCRONA AO REDIS
        $bestHost = $redis->get('best-host-processor');
        $ttl = $redis->ttl('best-host-processor');

        if ($ttl < 3) {
            echo "⚠️ Cache de processador expirando (TTL: {$ttl}s)\n";
            return null;
        }

        if ($bestHost == 1) return 'default';
        if ($bestHost == 2) return 'fallback';

        return null;
    }

    private function processWithRetry(array $payment, string $processor): array {
        $maxRetries = 3;
        $baseDelay = 0.1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $url = $processor === 'default' ? $this->defaultUrl : $this->fallbackUrl;

                // ✅ HTTP CLIENT ASSÍNCRONO
                $result = $this->callPaymentProcessor($url, $payment);

                echo "✅ Pagamento processado com sucesso: {$payment['correlationId']} via {$processor}\n";
                return $result;

            } catch (Exception $e) {
                echo "❌ Tentativa {$attempt} falhou para {$payment['correlationId']}: " . $e->getMessage() . "\n";

                if ($attempt < $maxRetries) {
                    // ✅ SLEEP ASSÍNCRONO
                    $delay = $baseDelay * pow(2, $attempt - 1);
                    Coroutine::sleep($delay);

                    if ($processor === 'default') {
                        echo "🔄 Tentando fallback para {$payment['correlationId']}\n";
                        return $this->processWithRetry($payment, 'fallback');
                    }
                }
            }
        }

        throw new Exception("Todas as tentativas falharam para {$payment['correlationId']}");
    }

    private function callPaymentProcessor(string $url, array $payment): array {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'localhost';
        $port = $parsedUrl['port'] ?? 80;

        // ✅ HTTP CLIENT ASSÍNCRONO OTIMIZADO
        $client = new Swoole\Coroutine\Http\Client($host, $port);
        $client->set([
            'timeout' => 5,
            'keep_alive' => true, // ✅ CONNECTION POOLING
            'http_proxy_host' => '',
            'http_proxy_port' => 0,
        ]);

        $requestData = [
            'correlationId' => $payment['correlationId'],
            'amount' => $payment['amount'],
            'requestedAt' => $payment['requestedAt']
        ];

        // ✅ REQUEST ASSÍNCRONO
        $success = $client->post('/payments', json_encode($requestData));

        if (!$success || $client->statusCode !== 200) {
            $client->close();
            throw new Exception("HTTP {$client->statusCode}: " . $client->getBody());
        }

        $response = json_decode($client->getBody(), true);
        $client->close();

        return $response;
    }

    private function handlePaymentResult(array $result, Redis $redis): void {
        $correlationId = $result['correlationId'];

        if ($result['success']) {
            // ✅ OPERAÇÕES REDIS ASSÍNCRONAS EM PARALELO
            Coroutine::create(function () use ($result, $redis, $correlationId) {
                // Marcar como processado
                $processedKey = "payment_processed:{$correlationId}";
                $redis->setex($processedKey, 3600, json_encode($result));

                // Liberar lock
                $lockKey = "payment_lock:{$correlationId}";
                $redis->del($lockKey);

                // Persistir para auditoria
                $this->persistForAudit($result, $redis);
            });

            $this->totalProcessed++;
            echo "✅ Pagamento finalizado: {$correlationId} em {$result['duration']}ms\n";

        } else {
            // ✅ LIBERAR LOCK ASSÍNCRONO
            Coroutine::create(function () use ($redis, $correlationId) {
                $lockKey = "payment_lock:{$correlationId}";
                $redis->del($lockKey);
            });

            $this->totalFailed++;
            echo "❌ Pagamento falhou: {$correlationId} - {$result['error']}\n";
        }
    }

    private function persistForAudit(array $result, Redis $redis): void {
        // ✅ PERSISTÊNCIA ASSÍNCRONA
        $auditKey = "audit:{$result['correlationId']}";
        $auditData = [
            'correlationId' => $result['correlationId'],
            'amount' => $result['amount'],
            'processor' => $result['processor'],
            'timestamp' => date('c'),
            'duration' => $result['duration']
        ];

        $redis->setex($auditKey, 86400, json_encode($auditData));
    }
}

// Iniciar worker
$worker = new PaymentWorker();
$worker->start();
