# Onboarding

Documento de entrada pro projeto: o que é, como funciona hoje, como chegou
até aqui e o que falta. Para o plano técnico detalhado da Fase 1 (schema,
locking, decisões de design linha a linha), ver [PLANO.md](PLANO.md). Para
o log de progresso mais granular (bugs achados, decisões de cada
checkpoint), ver [CONTINUACAO.md](CONTINUACAO.md). Este arquivo é o resumo
que amarra os dois.

## O que é o projeto

Um mini sistema distribuído de processamento de tarefas — uma versão
simplificada de Celery/RabbitMQ + workers, construído em Laravel/PHP. A
motivação (ver [README.md](README.md)) não é aprender Laravel fazendo um
CRUD: é um projeto desenhado para ter complexidade arquitetural real —
concorrência, race conditions, retry, idempotência, dead letter queue —
que resiste a ser gerado inteiro por IA em poucas horas. A regra do
projeto: IA pode escrever o código, mas toda decisão arquitetural (por que
esse lock existe, por que esse índice, como se evita dois workers pegando
o mesmo job) precisa ser algo que você consegue explicar sem consultar
nada.

Fluxo de ponta a ponta:

```
POST /jobs {"type": "send_email", "payload": {...}}
        ↓
   fila (MySQL, Redis LIST, ou Redis Streams — 3 drivers intercambiáveis)
        ↓
   worker-1 / worker-2 / worker-3 (containers Docker, mesma imagem)
        ↓
   handler do tipo do job executa; sucesso → completed, falha → retry/dead
```

## Como funciona

**Stack:** Laravel + PHP 8.2, MySQL 8, Redis 7, tudo via Docker Compose;
frontend React (Vite + TS) como console de acompanhamento.

**Os 3 drivers de fila** (mesma interface `JobQueueInterface`, trocável
via `QUEUE_DRIVER` no `.env` sem mudar código de negócio):

| Driver | Como reivindica um job (claim) | Onde vive a fila |
|---|---|---|
| `mysql` | `SELECT ... FOR UPDATE` numa transaction | tabela `jobs`, ordenada por `priority, available_at` |
| `redis` | `BRPOPLPUSH` | 4 listas `jobs:ready:{priority}` |
| `redis_streams` | `XREADGROUP` / `XAUTOCLAIM` | 4 streams `jobs:stream:{priority}`, consumer group `workers` |

**Máquina de estados de um job:**

```
enqueue → pending
pending --claimNextJob()--> processing
processing --sucesso--> completed
processing --falha, attempts < max_attempts--> pending (available_at = now + backoff)
processing --falha, attempts >= max_attempts--> dead
processing --reserved_at expirado (worker morreu)--> pending (attempts intacto)
dead --POST /dead-jobs/{id}/retry--> pending (attempts=0)
```

**Garantias que o sistema implementa e precisa saber explicar:**
- **Concorrência**: dois workers nunca pegam o mesmo job — lock atômico no
  claim (`FOR UPDATE` no MySQL; operações atômicas equivalentes nos
  drivers Redis). Provado com teste positivo (com lock, zero duplicatas)
  e negativo (sem lock, `dupes=200`).
- **Idempotência**: duas camadas — `idempotency_key` único no insert
  (dedupe de request duplicada) e uma tabela de "recibos"
  (`idempotency_receipts`) que o handler consulta antes de executar o
  efeito colateral (cobre o caso do worker morrer *depois* de executar e
  *antes* de marcar `completed`).
- **Retry com backoff exponencial**: `30s, 60s, 120s, 240s` entre
  tentativas, até `max_attempts` (default 5), depois cai em `dead`.
- **Dead letter queue**: `GET /dead-jobs` + `POST /dead-jobs/{id}/retry`
  (409 se o job não estiver `dead`).
- **Recuperação de worker morto**: job preso em `processing` além de um
  timeout volta pra `pending` sem perder `attempts`; outro worker
  completa.
- **Prioridade**: `CRITICAL > HIGH > NORMAL > LOW`, implementada de forma
  diferente em cada driver (coluna+`ORDER BY` no MySQL; listas/streams
  separados por prioridade nos dois Redis).
- **Agendamento** (`execute_at`): reaproveita a mesma coluna/mecanismo do
  backoff — um job futuro é só um `available_at` no futuro.

**Endpoints principais:**

```
GET  /api/health
POST /api/jobs                    # cria job (type, payload, priority?, execute_at?)
GET  /api/jobs                    # lista
GET  /api/jobs/stats
GET  /api/jobs/{id}
GET  /api/dead-jobs
POST /api/dead-jobs/{id}/retry
```

**Tipos de job (handlers) implementados:** `send_email`,
`generate_report`, `smoke_check` (health-check enfileirado, com
retry/backoff/DLQ de graça).

**Como rodar:** `docker compose up -d`, depois
`docker compose exec app php artisan migrate`. API em `localhost:8010`,
Adminer em `localhost:8080`. Testes: `docker compose exec app composer test`
(50 testes, ~24s, contra MySQL/Redis reais — não sqlite, porque
`lockForUpdate` não tem semântica de lock real lá).

## Etapas de desenvolvimento (como chegou até aqui)

1. **Fase 1 — núcleo em MySQL**: tabela `jobs`, claim atômico com
   `SELECT ... FOR UPDATE`, retry/backoff, idempotência (2 camadas), dead
   letter queue, recuperação de job travado. Provado com teste positivo e
   negativo de locking.
2. **V2 — variante Redis**: fila reimplementada com `BRPOPLPUSH`, console
   React pra acompanhar. Objetivo explícito: comparar as duas abordagens.
3. **Variante Redis Streams**: terceiro driver (`XREADGROUP`/`XACK`/
   `XAUTOCLAIM`), provado com os mesmos cenários de concorrência, retry e
   recovery que os outros dois.
4. **Prioridade** (`CRITICAL/HIGH/NORMAL/LOW`) implementada nos 3 drivers.
5. **Agendamento** (`execute_at`) nos 3 drivers.
6. **Handler `smoke_check`**: primeira fatia de usar o repo como
   laboratório de fila fora do escopo didático original.
7. **Filtro por tipo por worker** (`jobs:work --types=`): worker
   especializado que recusa (sem penalizar `attempts`) jobs de outro tipo.
8. **Suíte de testes automatizados**: suíte de contrato rodada 3× (uma por
   driver) contra MySQL/Redis reais + testes HTTP de verdade
   (`JobController`, `DeadJobController`) — 50 testes no total.
9. **CI no GitHub Actions**: suíte de testes do backend + build/lint do
   frontend rodando a cada push/PR.

Cada etapa acima corresponde a um checkpoint documentado em
[CONTINUACAO.md](CONTINUACAO.md), com os bugs reais encontrados e as
decisões de design de cada uma — vale ler antes de mexer numa área que já
foi provada, pra não reintroduzir um bug já corrigido (ex.: a sentinela
`_init` do Redis Streams, ou o `requeue()` ausente no revive de dead job).

## Metas a cumprir (roadmap)

Do próprio `CONTINUACAO.md`, ainda não iniciado:

1. **Comparativo escrito LIST vs Streams** — latência, tamanho do PEL
   (pending entries list), overhead operacional. Fecha o objetivo original
   de "comparar as duas abordagens" que motivou a variante Streams.
2. **Métricas no dashboard**: prioridade, `execute_at` e heartbeats de
   worker ainda não aparecem no console React — hoje ele mostra estado da
   fila, mas não esses sinais.
3. **Decidir o driver padrão do `.env`** — hoje fixo para desenvolvimento;
   avaliar se volta a ser `redis` no dia a dia ou fica `mysql`.

Metas estruturais que continuam valendo como critério de qualidade do
projeto (não é código a escrever, é entendimento a manter):

- Toda decisão arquitetural nova precisa continuar sendo algo que dá pra
  explicar sem consultar nada — é a regra fundadora do README.
- Concorrência real entre processos (não apenas testes unitários)
  permanece provada pelos comandos `jobs:prove-redis` e
  `jobs:prove-negative-lock`, deliberadamente fora do PHPUnit.
- Qualquer novo driver ou feature que toque o claim precisa manter a
  garantia central: nenhum job é processado por dois workers ao mesmo
  tempo, e o retry/idempotência cobre o caso de ser processado duas vezes
  *ao longo do tempo* (worker "morto" que na verdade só estava lento).
