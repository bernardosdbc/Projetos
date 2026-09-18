# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — Fase 2 iniciada (Redis)

`QUEUE_DRIVER=redis`. MySQL continua como registro durável (API/stats); o claim da fila é Redis.

### Ambiente

- API `http://localhost:8010`
- Front `http://localhost:5173` (se `npm run dev`)
- MySQL healthy · Redis healthy
- Workers `worker-1/2/3` Up

### Prova Redis (primeira fatia) — passou

```text
jobs:seed-concurrency 50 --fresh
completed=50
pending=0
dupes=0
workers=3
```

Claim via `BRPOPLPUSH` (`jobs:ready` → `jobs:processing`); backoff em ZSET `jobs:delayed`.

### Arquitetura Fase 2

| Peça | Papel |
|---|---|
| `JobQueueInterface` | Contrato único |
| `MysqlJobQueueService` | Fase 1 (`FOR UPDATE`) |
| `RedisJobQueueService` | Fase 2 (`BRPOPLPUSH` + delayed) |
| `QUEUE_DRIVER=mysql\|redis` | Troca no `.env` |

Chaves Redis:

- `jobs:ready` — LIST
- `jobs:delayed` — ZSET (`score = available_at`)
- `jobs:processing` — LIST
- `jobs:meta:{id}` — HASH (`reserved_at`, `reserved_by`)

### Fase 1 (ainda válida)

Locking MySQL, retry, dead letter, worker morto, front React — ver histórico abaixo / git `c770a5a`.

Voltar à Fase 1: `QUEUE_DRIVER=mysql` e reiniciar app/workers.

## Próxima fatia Fase 2

1. Repetir prova §9.1 com 200 jobs no Redis
2. Provar retry/backoff só via delayed ZSET (wall-clock ou promote)
3. Provar recovery de worker morto no meta Redis
4. (Opcional) Streams `XREADGROUP` como variante e comparar
5. Commit da Fase 2 quando pedir

## Frontend React/Vite

```powershell
cd frontend
npm run dev
```

http://localhost:5173 — proxy `/api` → `:8010`.

## Comandos úteis

```powershell
docker compose up -d app mysql redis worker-1 worker-2 worker-3
docker compose exec app php artisan jobs:seed-concurrency 50 --fresh
# trocar driver:
# editar src/.env QUEUE_DRIVER=mysql|redis  e reiniciar app/workers
```

## Regras mantidas

- `attempts` só no claim
- Recovery de timeout não incrementa `attempts`
- Idempotência request ≠ efeito colateral
- Worker = Artisan `jobs:work`
