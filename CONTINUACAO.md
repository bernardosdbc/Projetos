# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — §9.2 negativo fechado; stack de volta em Redis

`QUEUE_DRIVER=redis` · `QUEUE_CLAIM_LOCK=true`

### Prova negativa MySQL §9.2 — passou

Com `QUEUE_DRIVER=mysql` + `QUEUE_CLAIM_LOCK=false` (e `usleep` didático no claim):

```text
completed=200 dupes=200 workers=3
```

Sem `lockForUpdate`, **todos** os 200 jobs tiveram execução duplicada em `job_runs`. Isso calibra a prova positiva da Fase 1.

Comando:

```powershell
# temporário no .env: QUEUE_DRIVER=mysql e QUEUE_CLAIM_LOCK=false
docker compose up -d --force-recreate app worker-1 worker-2 worker-3
docker compose exec app php artisan jobs:prove-negative-lock 200
# restaurar: QUEUE_DRIVER=redis e QUEUE_CLAIM_LOCK=true + recreate
```

### Provas Redis (Fase 2) — ok

| Prova | Resultado |
|---|---|
| 200 jobs | `dupes=0`, `workers=3` |
| Retry / ZSET | completed attempt 3 |
| Recovery stuck | attempts intactos no recover |

### Próxima retomada (opcional)

1. Variante Streams `XREADGROUP` e comparar com `BRPOPLPUSH`
2. Prioridade / `execute_at` / heartbeats no dashboard
3. Commit desta fatia §9.2 (`jobs:prove-negative-lock` + usleep didático)

### Ambiente

- API http://localhost:8010 · Front http://localhost:5173
- `jobs:prove-redis --only-unit` · `jobs:prove-negative-lock`
