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

    # Cabeçalho da tabela
    printf "┌─────────────────┬─────────┬─────────────────┬─────────┬─────────┐\n"
    printf "│ %-15s │ %-7s │ %-15s │ %-7s │ %-7s │\n" "SERVIÇO" "CPU %" "MEMÓRIA" "MEM %" "LIMITE"
    printf "├─────────────────┼─────────┼─────────────────┼─────────┼─────────┤\n"

    # Variáveis para calcular totais
    total_cpu=0
    total_mem=0

    # Obter estatísticas dos containers
    docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream | \
    grep "rinha-backend-2025" | \
    while IFS=$'\t' read -r name cpu mem_usage mem_perc; do
        # Remover prefixo do nome
        service_name=$(echo "$name" | sed 's/rinha-backend-2025-//g' | sed 's/-1$//')

        # Extrair apenas o valor da memória em MB
        mem_mb=$(echo $mem_usage | sed 's/MiB.*//' | sed 's/,/\./')
        mem_formatted=$(awk "BEGIN {printf \"%.0fMB\", $mem_mb}")

        # Definir limites por serviço
        case $service_name in
            "nginx")
                limit="20MB"
                ;;
            "api_1"|"api_2")
                limit="70MB"
                ;;
            "worker_payments")
                limit="40MB"
                ;;
            "worker_health")
                limit="20MB"
                ;;
            "redis")
                limit="40MB"
                ;;
            *)
                limit="N/A"
                ;;
        esac

        # Formatear a linha da tabela
        printf "│ %-15s │ %-7s │ %-15s │ %-7s │ %-7s │\n" \
               "$service_name" "$cpu" "$mem_formatted" "$mem_perc" "$limit"
    done

    # Calcular totais após o loop
    total_cpu=0
    total_mem=0

    while IFS=$'\t' read -r name cpu mem_usage mem_perc; do
        if [[ $name == *"rinha-backend-2025"* ]]; then
            cpu_num=$(echo $cpu | sed 's/%//' | sed 's/,/\./')
            mem_mb=$(echo $mem_usage | sed 's/MiB.*//' | sed 's/,/\./')

            total_cpu=$(awk "BEGIN {printf \"%.2f\", $total_cpu + $cpu_num}")
            total_mem=$(awk "BEGIN {printf \"%.0f\", $total_mem + $mem_mb}")
        fi
    done < <(docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream)

    # Calcular porcentagem de memória total
    mem_percent_total=$(awk "BEGIN {printf \"%.1f\", $total_mem / 350 * 100}")

    # Linha separadora antes do total
    printf "├─────────────────┼─────────┼─────────────────┼─────────┼─────────┤\n"
    printf "│ %-15s │ %-7s │ %-15s │ %-7s │ %-7s │\n" \
           "TOTAL" "${total_cpu}%" "${total_mem}MB" "${mem_percent_total}%" "350MB"
    printf "└─────────────────┴─────────┴─────────────────┴─────────┴─────────┘\n"
    echo ""

    # Resumo de recursos
    printf "┌─────────────────────────────────────────────────────────────┐\n"
    printf "│                    RESUMO DE RECURSOS                       │\n"
    printf "├─────────────────────────────────────────────────────────────┤\n"
    printf "│ Total Permitido:  CPU: 1.5 cores  │  Memória: 350MB        │\n"
    printf "│ nginx:           CPU: 0.1          │  Memória: 20MB         │\n"
    printf "│ api_1:           CPU: 0.35         │  Memória: 80MB         │\n"
    printf "│ api_2:           CPU: 0.35         │  Memória: 80MB         │\n"
    printf "│ worker_payments: CPU: 0.4          │  Memória: 70MB         │\n"
    printf "│ worker_health:   CPU: 0.1          │  Memória: 30MB         │\n"
    printf "│ redis:           CPU: 0.2          │  Memória: 70MB         │\n"
    printf "└─────────────────────────────────────────────────────────────┘\n"
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
