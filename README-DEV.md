# Rinha Backend 2025 - Ambientes

## 🚀 Desenvolvimento (Hot-reload)

Para desenvolvimento com **hot-reload** (mudanças aparecem imediatamente):

```bash
# Iniciar desenvolvimento
./dev.sh up

# Ou manualmente
docker-compose -f docker-compose.dev.yml up --build
```

**Volumes montados:**
- `./cmd/api/app/` → Container API (hot-reload)
- `./cmd/workers/payment/index.php` → Worker Payment
- `./cmd/workers/health-check/index.php` → Worker Health

## 🏭 Produção (Rinha Final)

Para teste final **sem volumes** (como será na Rinha):

```bash
# Iniciar produção
./dev.sh prod

# Ou manualmente
docker-compose up --build
```

**Características:**
- Código copiado para dentro da imagem
- Sem volumes = sem hot-reload
- Requer rebuild para mudanças

## 📋 Comandos Úteis

```bash
./dev.sh up                    # Desenvolvimento
./dev.sh logs worker_payments  # Ver logs de um serviço
./dev.sh restart              # Reiniciar serviços
./dev.sh prod                 # Produção
./dev.sh monitor              # Monitorar recursos
./dev.sh clean                # Limpar tudo
```

## 🔧 Workflow Recomendado

1. **Desenvolvimento**: Use `./dev.sh up` para iterar rapidamente
2. **Teste**: Use `./dev.sh prod` para validar antes de submeter
3. **Monitoramento**: Use `./dev.sh monitor` para verificar recursos

## 📊 Recursos (Limite: 1.5 CPU + 350MB)

| Serviço | CPU | Memória | Função |
|---------|-----|---------|---------|
| nginx | 0.1 | 20MB | Load Balancer |
| api_1 | 0.35 | 80MB | HTTP API |
| api_2 | 0.35 | 80MB | HTTP API |
| worker_payments | 0.4 | 70MB | Processamento |
| worker_health | 0.1 | 30MB | Health Check |
| redis | 0.2 | 70MB | Cache/Queue |

## 🔄 Diferenças Entre Ambientes

| Aspecto | Desenvolvimento | Produção |
|---------|----------------|----------|
| **Volumes** | ✅ Montados | ❌ Copiados |
| **Hot-reload** | ✅ Sim | ❌ Não |
| **APP_ENV** | development | production |
| **Rebuild** | ❌ Não precisa | ✅ Necessário |
| **Performance** | Ligeiramente menor | Otimizada |