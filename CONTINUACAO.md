# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — agendamento (`execute_at`)

Sem coluna nova: `execute_at` no `POST /jobs` é só o seed de `available_at`
(o mesmo campo que o retry/backoff já usa) — `JobQueueInterface::enqueue()`
ganhou um 5º parâmetro `?DateTimeInterface $executeAt`, default `null` =
`now()`. Nos drivers Redis, `requeue()` já decide sozinho ZADD (delayed) vs
push imediato comparando o timestamp com `time()`; nenhuma lógica nova de
agendamento foi necessária, só passar o valor adiante — exatamente o que o
roadmap do PLANO.md previa ("grande parte já existe implicitamente via
available_at").

Validado nos drivers `redis_streams` e `redis` (LIST): job fica `pending`
até `available_at` e é reservado ~1s depois (granularidade do polling);
`execute_at` no passado vira imediato; `execute_at` inválido dá 422.
`mysql` não testado de novo nesta rodada — a query de claim já é a mesma
usada (e provada) pelo backoff de retry, `execute_at` só alimenta a mesma
coluna.

## Checkpoint anterior — prioridade (CRITICAL/HIGH/NORMAL/LOW)

Coluna `jobs.priority` (MySQL ENUM — sorteia por ordem de declaração, então
`ORDER BY priority` já claim critical primeiro sem FIELD()/CASE). Ordem
canônica vive em `App\Enums\JobPriority` e tem que bater com a migration e
com o array `PRIORITIES` dos dois drivers Redis.

| Driver | Estrutura por prioridade | Claim |
|---|---|---|
| `mysql` | uma tabela, coluna `priority` | `ORDER BY priority, available_at` |
| `redis` | 4 listas `jobs:ready:{priority}` | sweep `RPOPLPUSH` não-bloqueante em ordem; fallback bloqueante em `low` |
| `redis_streams` | 4 streams `jobs:stream:{priority}`, mesmo grupo `workers` | sweep `XREADGROUP` não-bloqueante em ordem; fallback bloqueante em `low` |

Provado (`docker compose exec app php artisan jobs:prove-redis --only-unit`
e teste manual com lote low/low/normal/high/critical + `sleep_seconds`):
ordem de claim `critical > high > normal > low` nos três drivers; empate
resolvido por ordem de criação.

**Bug achado e corrigido durante a prova**: `ensureGroup()` semeia cada
stream novo com uma entrada sentinela `_init` pra poder criar o consumer
group num stream vazio. Uma varredura de uma leitura só por prioridade lia
a sentinela, dava ACK e pulava pra próxima prioridade sem voltar — perdendo
o job real logo atrás dela. Fix: `readOne()` re-tenta (até 8x) na mesma
stream antes de desistir dela, restaurando o comportamento do loop
original de tentativa única (agora por stream, dentro do sweep).

**Bug pré-existente, não relacionado à prioridade, corrigido de brinde**:
`DeadJobController::retry` só fazia `$job->update()` no MySQL — nos drivers
Redis/Streams isso nunca sinalizava um worker, então um dead job "revivido"
ficava `pending` pra sempre. Agora chama `$queue->requeue($job)` depois do
update; `requeue()` é no-op no driver MySQL (que faz polling direto na
tabela) e reempurra pra ready-list/stream certos nos outros dois.

## Checkpoint anterior — variante Redis Streams

`QUEUE_DRIVER=redis_streams` (XREADGROUP + XACK + delayed ZSET).

### Drivers

| `QUEUE_DRIVER` | Claim |
|---|---|
| `mysql` | `SELECT ... FOR UPDATE` |
| `redis` | `BRPOPLPUSH` |
| `redis_streams` | `XREADGROUP` / `XAUTOCLAIM` |

### Provas Streams — passaram

| Prova | Resultado |
|---|---|
| Concorrência 30 jobs | `completed=30`, `dupes=0`, `workers=3` |
| Retry / delayed ZSET | completed attempt 3 |
| Recovery (`XAUTOCLAIM`) | attempts intactos no recover |

Nota: Predis + Laravel Facade quebra `XADD` com array — streams usam `executeRaw` com prefixo explícito.

### §9.2 MySQL (já no remoto)

`jobs:prove-negative-lock` → `dupes=200` sem lock.

### Próxima retomada (opcional)

1. Comparativo escrito LIST vs Streams (latência / PEL / ops)
2. Prioridade, `execute_at`, heartbeats no dashboard
3. Voltar default `.env` para `redis` se preferir a variante LIST no dia a dia

```powershell
# trocar driver no src/.env e recreate app/workers
QUEUE_DRIVER=redis|redis_streams|mysql
```
