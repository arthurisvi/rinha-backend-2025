#!/bin/bash

# Script avançado de monitoramento com alertas e histórico

LOG_FILE="monitor.log"
ALERT_CPU_THRESHOLD=80
ALERT_MEM_THRESHOLD=90

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Função para log
log_stats() {
    echo "$(date): $1" >> $LOG_FILE
}

# Função para obter cor baseada no uso
get_color() {
    local value=$1
    local threshold_high=$2
    local threshold_medium=$3

    if (( $(awk "BEGIN {print ($value > $threshold_high)}") )); then
        echo "${RED}"
    elif (( $(awk "BEGIN {print ($value > $threshold_medium)}") )); then
        echo "${YELLOW}"
    else
        echo "${GREEN}"
    fi
}

# Função para obter indicador visual
get_indicator() {
    local value=$1
    local threshold_high=$2
    local threshold_medium=$3

    if (( $(awk "BEGIN {print ($value > $threshold_high)}") )); then
        echo "🔴"
    elif (( $(awk "BEGIN {print ($value > $threshold_medium)}") )); then
        echo "🟡"
    else
        echo "🟢"
    fi
}

# Função para mostrar seção de serviços
show_service_section() {
    local title=$1
    local icon=$2
    local filter=$3

    echo -e "${CYAN}${icon} ${title}${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    local section_total_cpu=0
    local section_total_mem=0
    local section_total_limit=0

    # Processar cada container da seção
    while IFS=$'\t' read -r name cpu mem_usage mem_perc net_io; do
        if [[ $name =~ $filter ]]; then
            service_name=$(echo "$name" | sed 's/rinha-backend-2025-//g' | sed 's/-1$//')

            # Extrair valores numéricos
            cpu_num=$(echo $cpu | sed 's/%//' | sed 's/,/\./')
            mem_mb=$(echo $mem_usage | sed 's/MiB.*//' | sed 's/,/\./')
            mem_perc_num=$(echo $mem_perc | sed 's/%//' | sed 's/,/\./')

            # Converter para inteiros para evitar problemas de localização
            mem_mb_int=$(awk "BEGIN {printf \"%.0f\", $mem_mb}")
            mem_perc_int=$(awk "BEGIN {printf \"%.1f\", $mem_perc_num}")

            # Definir limite por serviço
            case $service_name in
                "nginx")
                    limit_mb=20
                    ;;
                "api_1"|"api_2")
                    limit_mb=70
                    ;;
                "worker_payments")
                    limit_mb=40
                    ;;
                "worker_health")
                    limit_mb=20
                    ;;
                "redis")
                    limit_mb=40
                    ;;
                *)
                    limit_mb=0
                    ;;
            esac

            # Somar para totais da seção
            section_total_cpu=$(awk "BEGIN {printf \"%.2f\", $section_total_cpu + $cpu_num}")
            section_total_mem=$(awk "BEGIN {printf \"%.0f\", $section_total_mem + $mem_mb}")
            section_total_limit=$(awk "BEGIN {printf \"%.0f\", $section_total_limit + $limit_mb}")

            # Obter cores e indicadores
            cpu_color=$(get_color $cpu_num 70 50)
            mem_color=$(get_color $mem_perc_num 80 60)
            cpu_indicator=$(get_indicator $cpu_num 70 50)
            mem_indicator=$(get_indicator $mem_perc_num 80 60)

            # Formatar entrada de rede
            net_in=$(echo $net_io | cut -d'/' -f1 | xargs)
            net_out=$(echo $net_io | cut -d'/' -f2 | xargs)

            # Mostrar informações do serviço
            printf "  %-15s ${cpu_color}%s %6s${NC} CPU    ${mem_color}%s %4sMB${NC} / %3dMB (%5s%%)    📡 %s↓ %s↑\n" \
                   "$service_name" "$cpu_indicator" "$cpu" "$mem_indicator" "$mem_mb_int" "$limit_mb" "$mem_perc_int" "$net_in" "$net_out"
        fi
    done < <(docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.NetIO}}" --no-stream)

    # Calcular porcentagem total da seção
    if (( $(awk "BEGIN {print ($section_total_limit > 0)}") )); then
        section_mem_percent=$(awk "BEGIN {printf \"%.1f\", $section_total_mem / $section_total_limit * 100}")
    else
        section_mem_percent="0.0"
    fi

    # Mostrar total da seção
    echo "  ────────────────────────────────────────────────────────────────────────────────────────────────────────────"
    printf "  %-15s 📊 %6.2f%% CPU    📊 %4.0fMB / %3.0fMB (%5.1f%%)\n" \
           "TOTAL" "$section_total_cpu" "$section_total_mem" "$section_total_limit" "$section_mem_percent"
    echo ""
}

# Função para mostrar estatísticas detalhadas
show_detailed_stats() {
    clear
    echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                                    MONITORAMENTO DETALHADO RINHA BACKEND 2025                                                   ║${NC}"
    echo -e "${GREEN}║                                                $(date)                                                ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    # Mostrar seções
    show_service_section "APIS" "🌐" "rinha-backend-2025.*api_"
    show_service_section "WORKERS" "⚙️" "rinha-backend-2025.*worker_"
    show_service_section "INFRAESTRUTURA" "🏗️" "rinha-backend-2025.*(redis|nginx)"

    # Verificação de alertas
    echo -e "${YELLOW}🚨 VERIFICAÇÃO DE ALERTAS${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    alerts_found=false

    # Verificar alertas para cada container
    while IFS=$'\t' read -r name cpu mem_usage mem_perc; do
        if [[ $name == *"rinha-backend-2025"* ]]; then
            service_name=$(echo "$name" | sed 's/rinha-backend-2025-//g' | sed 's/-1$//')

            cpu_num=$(echo $cpu | sed 's/%//' | sed 's/,/\./')
            mem_num=$(echo $mem_perc | sed 's/%//' | sed 's/,/\./')

            status="🟢 Normal"
            status_color="${GREEN}"

            if (( $(awk "BEGIN {print ($cpu_num > $ALERT_CPU_THRESHOLD)}") )); then
                status="🔴 CPU Alto ($cpu)"
                status_color="${RED}"
                alerts_found=true
                log_stats "ALERT: $service_name - CPU: $cpu"
            elif (( $(awk "BEGIN {print ($mem_num > $ALERT_MEM_THRESHOLD)}") )); then
                status="🔴 Memória Alta ($mem_perc)"
                status_color="${RED}"
                alerts_found=true
                log_stats "ALERT: $service_name - MEM: $mem_perc"
            fi

            printf "  %-15s ${status_color}%s${NC}\n" "$service_name" "$status"
        fi
    done < <(docker stats --format "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}" --no-stream)

    if [ "$alerts_found" = false ]; then
        echo -e "  ${GREEN}✅ Todos os serviços estão funcionando normalmente${NC}"
    fi
    echo ""

    # Resumo de recursos do sistema
    echo -e "${BLUE}📊 RESUMO GERAL DO SISTEMA${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    # Calcular uso total do sistema
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

    # Calcular porcentagens do sistema
    cpu_percent=$(awk "BEGIN {printf \"%.1f\", $total_cpu / 150 * 100}")
    mem_percent=$(awk "BEGIN {printf \"%.1f\", $total_mem / 350 * 100}")

    # Colorir baseado no uso
    cpu_status_color=""
    mem_status_color=""

    if [ "$(awk "BEGIN {print ($cpu_percent > 80)}")" = "1" ]; then
        cpu_status_color="${RED}"
    elif [ "$(awk "BEGIN {print ($cpu_percent > 60)}")" = "1" ]; then
        cpu_status_color="${YELLOW}"
    else
        cpu_status_color="${GREEN}"
    fi

    if [ "$(awk "BEGIN {print ($mem_percent > 80)}")" = "1" ]; then
        mem_status_color="${RED}"
    elif [ "$(awk "BEGIN {print ($mem_percent > 60)}")" = "1" ]; then
        mem_status_color="${YELLOW}"
    else
        mem_status_color="${GREEN}"
    fi

    # Mostrar resumo do sistema
    printf "  🖥️  CPU Total:     ${cpu_status_color}%6.2f%% / 150%% (1.5 cores)${NC}     📈 %6.1f%% do limite\n" "$total_cpu" "$cpu_percent"
    printf "  💾  Memória Total: ${mem_status_color}%6.0fMB / 350MB${NC}            📈 %6.1f%% do limite\n" "$total_mem" "$mem_percent"
    echo ""
    echo -e "${CYAN}📝 Logs salvos em: $LOG_FILE${NC}"
    echo -e "${CYAN}⌨️  Pressione Ctrl+C para sair${NC}"
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
