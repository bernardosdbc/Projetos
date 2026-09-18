# Continuação do projeto

Este documento registra o ponto de retomada da implementação descrita em [PLANO.md](PLANO.md).

## Checkpoint atual — Fase 1 fechada

Núcleo do sistema validado de ponta a ponta.

### Ambiente

- `app` em `http://localhost:8010`
- MySQL healthy
- Workers `worker-1/2/3` **stopped** (default `--timeout=120`)

### Provas fechadas

| Prova | Resultado |
|---|---|
| Locking §9.1 (200 jobs, 3 workers) | `completed=200`, `runs=200`, `dupes=0` |
| Retry / backoff | fail_times=2 → 30s → 60s → completed |
| Dead letter API | `dead` → retry com `attempts=0` |
| Worker morto §9.3 | ver abaixo |
| Idempotência camada 2 | 2 runs do handler, **1** recibo |

### Worker morto §9.3 — passou

```text
READY_TO_KILL reserved_by=worker-1 attempts=1
after_kill status=processing attempts=1   # recovery NÃO incrementa attempts
DONE attempts=2 runs=2                     # 2º claim incrementa; 2 invocações do handler
receipts=1                                 # efeito colateral deduplicado
```

Procedimento usado:

1. Só `worker-1` pegou o job (`sleep_seconds=45`)
2. `docker kill` no container no meio do sleep
3. Subiu `worker-2` com recovery (`--timeout` curto na janela do teste)
4. Job voltou a `pending` sem bump de `attempts`, depois completed pelo worker-2

### Entregue no código

- Fila MySQL + claim `lockForUpdate`
- `jobs:work --worker-id --sleep --timeout` (default 120)
- `job_runs` + `jobs:seed-concurrency`
- Handler com `fail_times`, `sleep_seconds`, `idempotency_receipts`
- `DeadJobController` + rotas
- `QUEUE_CLAIM_LOCK` para calibração negativa §9.2 (ainda não executada)

## Próxima retomada (opcional)

1. Teste negativo §9.2: `QUEUE_CLAIM_LOCK=false`, reseedar, esperar `dupes > 0`
2. Roadmap fora da Fase 1: Redis, prioridade, `execute_at`, dashboard
3. Commit / branch — só se pedido

## Comandos úteis

```powershell
docker compose up -d app
docker compose up -d worker-1 worker-2 worker-3
docker compose stop worker-1 worker-2 worker-3
docker compose exec app php artisan jobs:seed-concurrency 200 --fresh
docker compose exec app php artisan route:list --path=api
```

## Regras arquiteturais mantidas

- Worker = Artisan `jobs:work`
- Fila Fase 1 = MySQL
- `attempts` só no claim
- Claim com `lockForUpdate()` (salvo `QUEUE_CLAIM_LOCK=false`)
- Falha recuperável → `pending` + backoff
- Falha terminal → `dead`
- Recovery de timeout não incrementa `attempts`
- Idempotência de request ≠ idempotência de efeito colateral
