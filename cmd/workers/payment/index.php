<?php
require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Runtime;

// Habilitar corrotinas
Runtime::enableCoroutine();

echo "Payment Worker iniciado...\n";

// https://hyperf.fans/en/components/redis-subscriber.html

// Loop infinito para manter o worker rodando
while (true) {
    try {
        // Usar corrotina para processar pagamentos
        Coroutine::create(function () {
            echo "Processando pagamento...\n";
            // Aqui ficará a lógica de processamento

            // Deve consumir a fila do Redis - corrotina 1

            // Deve verificar o "bestHost" no Redis - corrotina 2
            // Se estiver próximo de expirar (< 3s), deve ser atualizado (req para /health-check)
            // Se não estiver próximo de expirar, deve ser usado o "bestHost" atual

            // Deve processar o pagamento - envia para o "bestHost"

        });

        // Evitar consumo excessivo de CPU (usar sleep normal)
        sleep(2);

    } catch (Exception $e) {
        echo "Erro no payment worker: " . $e->getMessage() . "\n";
        sleep(1);
    }
}
