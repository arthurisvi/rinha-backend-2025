#!/bin/bash

# Script avançado de monitoramento com alertas e histórico

LOG_FILE="monitor.log"
ALERT_CPU_THRESHOLD=80
ALERT_MEM_THRESHOLD=90

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Função para log
log_stats() {
    echo "$(date): $1" >> $LOG_FILE
}

# Função para alertas
check_alerts() {
    local container=$1
    local cpu=$2
    local mem=$3

    cpu_num=$(echo $cpu | sed 's/%//')
    mem_num=$(echo $mem | sed 's/%//')

    if (( $(echo "$cpu_num > $ALERT_CPU_THRESHOLD" | bc -l) )); then
        echo -e "${RED}⚠️  ALERTA: $container - CPU alto: $cpu${NC}"
        log_stats "ALERT: $container - CPU: $cpu"
    fi

    if (( $(echo "$mem_num > $ALERT_MEM_THRESHOLD" | bc -l) )); then
        echo -e "${RED}⚠️  ALERTA: $container - MEMÓRIA alta: $mem${NC}"
        log_stats "ALERT: $container - MEM: $mem"
    fi
}

# Função para mostrar estatísticas detalhadas
show_detailed_stats() {
    clear
    echo -e "${GREEN}=== MONITORAMENTO DETALHADO RINHA BACKEND 2025 ===${NC}"
    echo "$(date)"
    echo ""

    # Estatísticas dos workers
    echo -e "${YELLOW}=== WORKERS ===${NC}"
    docker stats --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}" --no-stream | \
    grep -E "(NAME|worker_payments|worker_health)" | \
    sed 's/rinha-backend-2025-//g'

    echo ""
    echo -e "${YELLOW}=== INFRAESTRUTURA ===${NC}"
    docker stats --format "table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}\t{{.BlockIO}}" --no-stream | \
    grep -E "(NAME|redis|nginx|api_)" | \
    sed 's/rinha-backend-2025-//g'

    echo ""
    echo -e "${YELLOW}=== VERIFICAÇÃO DE ALERTAS ===${NC}"

    # Verificar alertas para cada container
    while IFS= read -r line; do
        if [[ $line == *"rinha-backend-2025"* ]]; then
            container=$(echo $line | awk '{print $1}' | sed 's/rinha-backend-2025-//g')
            cpu=$(echo $line | awk '{print $2}')
            mem=$(echo $line | awk '{print $4}')
            check_alerts "$container" "$cpu" "$mem"
        fi
    done < <(docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream)

    echo ""
    echo -e "${YELLOW}=== RESUMO DE RECURSOS ===${NC}"

    # Calcular uso total
    total_cpu=0
    total_mem=0

    while IFS= read -r line; do
        if [[ $line == *"rinha-backend-2025"* ]]; then
            cpu=$(echo $line | awk '{print $2}' | sed 's/%//')
            mem_usage=$(echo $line | awk '{print $3}' | sed 's/MiB//')
            total_cpu=$(echo "$total_cpu + $cpu" | bc -l)
            total_mem=$(echo "$total_mem + $mem_usage" | bc -l)
        fi
    done < <(docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream)

    echo "CPU Total Usado: ${total_cpu}% (Limite: 150%)"
    echo "MEM Total Usada: ${total_mem}MB (Limite: 350MB)"

    if (( $(echo "$total_cpu > 120" | bc -l) )); then
        echo -e "${RED}⚠️  CPU próximo do limite!${NC}"
    fi

    if (( $(echo "$total_mem > 300" | bc -l) )); then
        echo -e "${RED}⚠️  Memória próxima do limite!${NC}"
    fi

    echo ""
    echo "Logs salvos em: $LOG_FILE"
    echo "Pressione Ctrl+C para sair"
}

# Verificar se bc está instalado
if ! command -v bc &> /dev/null; then
    echo "Instalando bc para cálculos..."
    sudo apt-get update && sudo apt-get install -y bc
fi

# Monitoramento contínuo
if [ "$1" == "--watch" ]; then
    while true; do
        show_detailed_stats
        sleep 3
    done
else
    show_detailed_stats
fi