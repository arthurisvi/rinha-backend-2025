<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;

// Habilitar corrotinas
Runtime::enableCoroutine();

echo "Health Check Worker iniciado...\n";

// Loop infinito para manter o worker rodando
while (true) {
    try {
        // Usar corrotinas para health checks paralelos
        Coroutine::create(function () {
            echo "Health check do default processor...\n";
            // Aqui ficará a lógica de health check
        });

        Coroutine::create(function () {
            echo "Health check do fallback processor...\n";
            // Aqui ficará a lógica de health check
        });

        // Health check a cada 5 segundos (usar sleep normal)
        sleep(5);

    } catch (Exception $e) {
        echo "Erro no health check worker: " . $e->getMessage() . "\n";
        sleep(1);
    }
}