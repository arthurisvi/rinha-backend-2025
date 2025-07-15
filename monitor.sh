#!/bin/bash

# Script de monitoramento para workers do Rinha Backend 2025

echo "=== MONITORAMENTO RINHA BACKEND 2025 ==="
echo "Pressione Ctrl+C para sair"
echo ""

# Função para mostrar estatísticas
show_stats() {
    clear
    echo "=== MONITORAMENTO RINHA BACKEND 2025 - $(date) ==="
    echo ""

    # Mostrar apenas containers do projeto
    docker stats --format "table {{.Container}}\t{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream | \
    grep -E "(CONTAINER|rinha-backend-2025)" | \
    sed 's/rinha-backend-2025-//g' | \
    column -t

    echo ""
    echo "=== LIMITES DE RECURSOS (docker-compose.yml) ==="
    echo "• nginx:           CPU: 0.1    | MEM: 20MB"
    echo "• api_1:           CPU: 0.35   | MEM: 80MB"
    echo "• api_2:           CPU: 0.35   | MEM: 80MB"
    echo "• worker_payments: CPU: 0.4    | MEM: 70MB"
    echo "• worker_health:   CPU: 0.1    | MEM: 30MB"
    echo "• redis:           CPU: 0.2    | MEM: 70MB"
    echo ""
    echo "TOTAL PERMITIDO:   CPU: 1.5    | MEM: 350MB"
    echo ""
    echo "Pressione Ctrl+C para sair"
}

# Monitoramento contínuo
if [ "$1" == "--watch" ]; then
    while true; do
        show_stats
        sleep 2
    done
else
    show_stats
fi