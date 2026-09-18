# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — worker especializado por tipo (`--types=`)

`php artisan jobs:work --worker-id=worker-1 --types=smoke_check` restringe
o que aquele worker aceita. `JobQueueInterface::claimNextJob()` ganhou um
2º parâmetro `array $allowedTypes = []` (vazio = qualquer tipo, default
compatível com todos os call sites antigos).

**Decisão central**: recusar um job de tipo errado não pode contar como
tentativa (`attempts`) nem mudar `status` — não é culpa do job ter caído
num worker especializado. Por driver:

| Driver | Como "devolve" um job de tipo errado |
|---|---|
| `mysql` | nem chega a pegar — `WHERE type IN (...)` já filtra na query com lock |
| `redis` (LIST) | `RPOPLPUSH` já moveu pra `processing`; se o tipo não bate, `LREM` + `LPUSH` de volta na mesma lista de prioridade, sem tocar no job |
| `redis_streams` | não dá pra "devolver" uma entry de stream — `XACK` a original + `XADD` uma nova apontando pro mesmo `job_id` na mesma stream |

Nos dois drivers Redis isso reaproveita o loop de retry por prioridade que
já existia (o mesmo que resolveu o bug da sentinela `_init` — ver
checkpoint de prioridade): um worker restrito dreno as entradas erradas
até achar uma do seu tipo ou esgotar 8 tentativas naquele nível antes de
passar pra próxima prioridade.

**docker-compose.yml não foi alterado** — os 3 workers continuam sem
`--types` (aceitam qualquer tipo), porque as provas de concorrência
(`jobs:prove-redis`, `jobs:prove-negative-lock`) esperam que qualquer um
dos 3 pegue `send_email`. Pra especializar de verdade, edite o `command`
de um worker no compose ou rode um avulso via
`docker compose exec app php artisan jobs:work --types=smoke_check ...`.

Validado nos 3 drivers via `claimNextJob('id', ['smoke_check'])` direto e
via `jobs:work --types=` real: worker restrito só completa o tipo
permitido; os outros ficam `pending`/`attempts=0` intactos.

## Checkpoint anterior — handler `smoke_check`

Primeira fatia de uma ideia de usar este repo como laboratório de fila no
trabalho (health-check enfileirado, com retry/backoff/DLQ de graça em vez
de script solto). Implementado **só** o handler — sem integração com
mypm/`achados/`/Cursor skill, que dependem de infra fora deste repo e
ficaram de fora por decisão explícita, não por esquecimento.

`payload.targets` (lista de URLs) — se vazio, checa o próprio
`GET /api/health` deste app (`http://app:8000/api/health`, nome do serviço
Docker, alcançável pelos workers). Cada alvo é um GET com timeout
(`payload.timeout_seconds`, default 5s); qualquer falha lança e o job
segue o mesmo caminho de retry/backoff dos outros tipos — se esgotar as
tentativas, cai em `dead` e aparece em `GET /dead-jobs` como sinal de
"algo está fora do ar", sem alerta dedicado.

Testado: self-check (sem `targets`) completa; alvo inexistente falha com
`last_error` descritivo e agenda retry.

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
