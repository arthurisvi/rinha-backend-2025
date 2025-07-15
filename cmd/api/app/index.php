<?php
use Hyperf\Nano\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create('0.0.0.0', 8080);

$app->get('/health', function () {
    return [
        'status' => 'healthy',
        'timestamp' => time(),
        'service' => 'api'
    ];
});

$app->run();
