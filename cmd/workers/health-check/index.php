<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Redis;

use function Hyperf\Support\env;

// Habilitar corrotinas para operações I/O (inclui Redis e HTTP)
Runtime::enableCoroutine();

function getHealthStatusProcessor(string $processorName, string $url): array|bool {
    try {
        echo "Iniciando health check do $processorName...\n";

        // Parse da URL para extrair host e porta
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'];
        $port = $parsedUrl['port'];
        $path = $parsedUrl['path'];

        // HTTP Client assíncrono do Swoole
        $client = new Swoole\Coroutine\Http\Client($host, $port);
        $client->set(['timeout' => 3]); // Timeout de 3 segundos

        $success = $client->get($path);

        if ($success && $client->statusCode === 200) {
            echo "✅ $processorName: Healthy (Response: {$client->statusCode})\n";
            return [
                '$processorName' => [
                    'failing' => false,
                    'minResponseTime' => 0
                ]
            ];
        }

        $client->close();

        echo "❌ $processorName: Unhealthy\n";

        return false;
    } catch (Exception $e) {
        echo "🚨 Erro no health check $processorName: " . $e->getMessage() . "\n";
        return false;
    }
}

function saveBestHostProcessor(string $bestHost): void {
    try {
        $redis = new Redis();
        $connected = $redis->connect('redis', 6379, 2); // timeout 2s

        if (!$connected) {
            throw new Exception("Falha ao conectar no Redis");
        }

        $key = "best-host-processor";

        $redis->setex($key, 5, $bestHost); // TTL de 5 segundos

        $redis->close();

        echo "📝 Melhor host salvo: $bestHost\n";
    } catch (Exception $e) {
        echo "❌ Erro ao salvar status no Redis: " . $e->getMessage() . "\n";
    }
}

function getBestHostProcessor(array $hosts): string {
    return 'default';
}

Coroutine::create(function () {
    while (true) {
        try {
            echo "\n🔄 Iniciando novo ciclo de health checks...\n";

            $endpointPath = '/payments/health';

            // Canal para sincronizar resultados
            $channel = new Channel(2);

            // Corrotina para health check do host default
            Coroutine::create(function () use ($channel, $endpointPath) {
                $url = env('PROCESSOR_DEFAULT_URL') ?: 'http://payment-processor-default:8080';
                $healthStatus = getHealthStatusProcessor('default', $url . $endpointPath);
                $channel->push($healthStatus);
            });

            // Corrotina para health check do host fallback
            Coroutine::create(function () use ($channel, $endpointPath) {
                $url = env('PROCESSOR_FALLBACK_URL') ?: 'http://payment-processor-fallback:8080';
                $healthStatus = getHealthStatusProcessor('fallback', $url . $endpointPath);
                $channel->push($healthStatus);
            });

            // Aguardar resultados de ambas as corrotinas
            $results = [];
            for ($i = 0; $i < 2; $i++) {
                $result = $channel->pop();
                if ($result === false) {
                    throw new \RuntimeException("Falha ao coletar resultado do health check");
                }
                $results[] = $result;
            }

            $channel->close();

            echo "Health checks concluídos, analisando resultados...\n";

            $bestHost = getBestHostProcessor($results);

            saveBestHostProcessor($bestHost);

            echo "Aguardando próximo ciclo...\n";

            Coroutine::sleep(5);
        } catch (Exception $e) {
            echo "💥 Erro no loop principal: " . $e->getMessage() . "\n";
            Coroutine::sleep(1);
        }
    }
});

// Manter o processo vivo
echo "🚀 Worker de Health Check rodando...\n";
Swoole\Event::wait();