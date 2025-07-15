<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;

// Habilitar corrotinas
Runtime::enableCoroutine();

echo "Payment Worker iniciado...\n";

// Loop infinito para manter o worker rodando
while (true) {
    try {
        // Usar corrotina para processar pagamentos
        Coroutine::create(function () {
            echo "Processando pagamento...\n";
            // Aqui ficará a lógica de processamento
        });

        // Evitar consumo excessivo de CPU (usar sleep normal)
        sleep(2);

    } catch (Exception $e) {
        echo "Erro no payment worker: " . $e->getMessage() . "\n";
        sleep(1);
    }
}
