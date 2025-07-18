<?php
error_reporting(E_ALL & ~E_WARNING);

require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\Channel;

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
        $this->maxConcurrentPayments = 3;
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
                $data = $redis->brPop(['payment_queue'], 5);

                if ($data) {
                    $payload = $data[1]; // data[0] = nome da fila
                    echo "✅ Worker {$workerId} consumiu: {$payload}\n";
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
