#!/bin/bash

# Script helper para desenvolvimento do Rinha Backend 2025

set -e

DEV_COMPOSE="docker-compose -f docker-compose.dev.yml"
PROD_COMPOSE="docker-compose"

show_help() {
    echo "=== Rinha Backend 2025 - Helper de Desenvolvimento ==="
    echo ""
    echo "Uso: ./dev.sh [comando]"
    echo ""
    echo "Comandos disponíveis:"
    echo "  up          - Iniciar ambiente de desenvolvimento"
    echo "  down        - Parar ambiente de desenvolvimento"
    echo "  logs        - Ver logs de todos os serviços"
    echo "  logs [svc]  - Ver logs de um serviço específico"
    echo "  build       - Rebuild todas as imagens"
    echo "  restart     - Reiniciar todos os serviços"
    echo "  ps          - Status dos containers"
    echo "  prod        - Iniciar ambiente de produção"
    echo "  monitor     - Monitorar recursos"
    echo "  clean       - Limpar containers e volumes"
    echo ""
    echo "Exemplos:"
    echo "  ./dev.sh up                    # Desenvolvimento com hot-reload"
    echo "  ./dev.sh logs worker_payments  # Logs do worker de pagamentos"
    echo "  ./dev.sh prod                  # Teste final (produção)"
}

case "$1" in
    "up")
        echo "🚀 Iniciando ambiente de DESENVOLVIMENTO..."
        $DEV_COMPOSE up --build
        ;;
    "down")
        echo "🛑 Parando ambiente de desenvolvimento..."
        $DEV_COMPOSE down
        ;;
    "logs")
        if [ -z "$2" ]; then
            echo "📋 Logs de todos os serviços:"
            $DEV_COMPOSE logs -f
        else
            echo "📋 Logs do serviço: $2"
            $DEV_COMPOSE logs -f "$2"
        fi
        ;;
    "build")
        echo "🔨 Rebuilding todas as imagens..."
        $DEV_COMPOSE build --no-cache
        ;;
    "restart")
        echo "🔄 Reiniciando todos os serviços..."
        $DEV_COMPOSE restart
        ;;
    "ps")
        echo "📊 Status dos containers:"
        $DEV_COMPOSE ps
        ;;
    "prod")
        echo "🏭 Iniciando ambiente de PRODUÇÃO..."
        $PROD_COMPOSE up --build -d
        ;;
    "monitor")
        echo "📈 Monitorando recursos..."
        ./monitor.sh --watch
        ;;
    "clean")
        echo "🧹 Limpando containers e volumes..."
        $DEV_COMPOSE down -v --remove-orphans
        $PROD_COMPOSE down -v --remove-orphans
        docker system prune -f
        ;;
    *)
        show_help
        ;;
esac