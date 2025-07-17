<?php
use Hyperf\Nano\Factory\AppFactory;
use Psr\Http\Message\ServerRequestInterface;

use Hyperf\Redis\RedisFactory;
use Hyperf\HttpMessage\Server\Response;

use function Hyperf\Support\env;

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
            'min_connections' => 1,
            'max_connections' => 20,
            'connect_timeout' => 5.0,
            'wait_timeout' => 3.0,
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

$app->post('/payments', function (ServerRequestInterface $request) use ($app) {
    $body = $request->getParsedBody();
    $correlationId = $body['correlationId'] ?? null;
    $amount = $body['amount'] ?? null;

    /** @var \Hyperf\Redis\RedisProxy $redis */
    $redis = $app->getContainer()->get(RedisFactory::class)->get('default');
    $paymentInProcess = $redis->get($correlationId);

    if (!$paymentInProcess) {
        // TODO: publicar na fila para processamento
        return (new Response())
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    return (new Response())
        ->withStatus(409)
        ->withHeader('Content-Type', 'application/json');
});

$app->run();
