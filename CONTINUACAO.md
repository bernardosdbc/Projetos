# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — Fase 2 (Redis) fatia de provas fechada

`QUEUE_DRIVER=redis`. Commit recente: front + driver Redis (`be3c359`+) e provas desta fatia.

### Ambiente

- API `http://localhost:8010` · Redis + MySQL healthy
- Workers Up
- Front: `cd frontend && npm run dev` → http://localhost:5173

### Provas Redis — passaram

| Prova | Resultado |
|---|---|
| Concorrência 200 jobs | `completed=200`, `dupes=0`, `workers=3` |
| Retry / delayed ZSET | fail_times=2 → delayed scores → completed attempt 3 (`JOB_BACKOFF_BASE=2` na prova) |
| Recovery stuck | `recovered=1`, attempts intactos no recover, +1 no re-claim, completed |

Comando:

```powershell
docker compose exec app php artisan jobs:seed-concurrency 200 --fresh
# com workers parados:
docker compose stop worker-1 worker-2 worker-3
docker compose exec app php artisan jobs:prove-redis --only-unit
docker compose up -d worker-1 worker-2 worker-3
```

### Arquitetura

| Peça | Papel |
|---|---|
| `JobQueueInterface` | Contrato |
| `MysqlJobQueueService` | Fase 1 |
| `RedisJobQueueService` | Fase 2 `BRPOPLPUSH` + ZSET |
| `jobs:prove-redis` | Bateria de provas |
| `JOB_BACKOFF_BASE` | Base do backoff (default 30) |

### Próxima retomada (opcional)

1. Teste negativo MySQL §9.2 (`QUEUE_CLAIM_LOCK=false`)
2. Variante Streams `XREADGROUP`
3. Push do commit para `origin`
4. Roadmap: prioridade, `execute_at` explícito, heartbeats no dashboard

### Voltar à Fase 1

`QUEUE_DRIVER=mysql` no `.env` e reiniciar app/workers.
