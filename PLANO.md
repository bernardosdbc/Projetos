# Plano: Mini Sistema Distribuído de Processamento de Tarefas (Fase 1 — Núcleo)

## Contexto

O objetivo deste projeto é estudar Laravel/PHP de um jeito que resista ao uso pesado de IA: em vez de um CRUD que a IA gera em 2 horas, um sistema com concorrência real, condições de corrida, retry, idempotência e uma dead letter queue — problemas que exigem entendimento arquitetural, não só geração de código. A regra do projeto (definida no README) é que toda decisão de design precisa ser algo que você consegue explicar: por que existe uma transaction aqui, por que existe esse índice, como se evita que dois workers peguem o mesmo job, o que acontece se um worker morrer no meio do processamento.

O diretório do projeto está vazio (só existia o `README.md` com a descrição original em português) — é um projeto greenfield, sem código para reaproveitar.

Este plano cobre a **Fase 1**: o núcleo do sistema (fila em MySQL, workers PHP concorrentes, locking, retry com backoff exponencial, idempotência, dead letter queue e recuperação de jobs travados). Redis (V2), prioridade, agendamento (`execute_at`) e dashboard ficam como roadmap futuro — não fazem parte desta fase.

**Stack decidida:** Laravel + PHP 8.2, MySQL 8, tudo rodando via Docker Compose (Docker 29.7.2 e Docker Compose v5.3.1 já disponíveis; Composer e MySQL client não estão instalados localmente, então o Compose evita essa dependência).

## Arquitetura

```
             ┌──────────────┐
             │   API Laravel│  POST /jobs, GET /dead-jobs, POST /dead-jobs/{id}/retry
             └──────┬───────┘
                    ▼
             ┌──────────────┐
             │  MySQL (jobs)│  fila implementada como tabela, não Redis
             └──────┬───────┘
                    │
          ┌─────────┼─────────┐
          ▼         ▼         ▼
       worker-1  worker-2  worker-3   (containers Docker separados, mesma imagem)
```

## Estrutura do projeto

```
projeto/
├── docker-compose.yml
├── Dockerfile                      # imagem PHP-FPM/CLI compartilhada por api + workers
├── docker/php/php.ini
├── src/                            # raiz da app Laravel (montada nos containers)
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   └── WorkJobs.php               # `php artisan jobs:work --worker-id=worker-1`
│   │   ├── Http/Controllers/
│   │   │   ├── JobController.php          # POST /jobs, GET /jobs/{id}
│   │   │   └── DeadJobController.php      # GET /dead-jobs, POST /dead-jobs/{id}/retry
│   │   ├── Http/Requests/StoreJobRequest.php
│   │   ├── Models/Job.php
│   │   ├── Jobs/Handlers/
│   │   │   ├── JobHandlerInterface.php
│   │   │   ├── SendEmailHandler.php
│   │   │   └── HandlerRegistry.php        # mapeia `type` -> handler
│   │   └── Services/
│   │       ├── JobQueueService.php        # enqueue/claimNextJob/markCompleted/markFailedOrDead/recoverStuckJobs
│   │       └── BackoffCalculator.php
│   ├── database/migrations/
│   │   ├── xxxx_create_jobs_table.php
│   │   └── xxxx_create_idempotency_receipts_table.php
│   └── routes/api.php
└── README.md
```

**Decisão a documentar:** o worker é um Artisan Command (`jobs:work`), não um `worker.php` solto — assim ele ganha de graça o service container, Eloquent, config e logging do framework. `worker 1/2/3` do README vira o parâmetro `--worker-id`, um container por worker.

## 1. Tabela `jobs` (schema)

```php
Schema::create('jobs', function (Blueprint $table) {
    $table->id();
    $table->string('type');
    $table->json('payload');
    $table->string('idempotency_key')->unique();
    $table->string('status')->default('pending'); // pending|processing|completed|dead
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->unsignedTinyInteger('max_attempts')->default(5);
    $table->timestamp('available_at');   // quando fica elegível (enqueue ou retry com backoff)
    $table->timestamp('reserved_at')->nullable();
    $table->string('reserved_by')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'available_at'], 'idx_status_available_at');
});
```

**Por que cada peça existe** (você precisa saber responder isso):
- `available_at` unifica "pronto agora" (enqueue) e "retry agendado" (backoff) numa única coluna e numa única query de polling.
- `reserved_at`/`reserved_by`: base da detecção de job travado (`status='processing' AND reserved_at < now() - timeout`) e diagnóstico de qual worker tinha o job.
- Índice composto `(status, available_at)`: a query de claim filtra `status='pending' AND available_at <= NOW()`. Sem esse índice, o `SELECT ... FOR UPDATE` do claim (item 2) bloqueia mais linhas/gaps do que precisa, aumentando contenção entre workers — não é só performance, é escopo de lock.
- Sem status `failed` persistente: uma falha-com-retry é escrita direto como `pending` (attempts incrementado + `available_at` no futuro); `last_error` guarda o motivo. Só a falha terminal (attempts esgotados) vira status próprio (`dead`). Isso simplifica a máquina de estados em relação aos dois diagramas do README sem perder informação.

Tabela auxiliar `idempotency_receipts` (id, `idempotency_key` unique, `job_type`, `created_at`) — ver item 4.

## 2. Claim atômico (o núcleo da concorrência)

```php
public function claimNextJob(string $workerId): ?Job
{
    return DB::transaction(function () use ($workerId) {
        $job = Job::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->lockForUpdate()
            ->first();

        if (!$job) return null;

        $job->update([
            'status' => 'processing',
            'reserved_at' => now(),
            'reserved_by' => $workerId,
            'attempts' => $job->attempts + 1,
        ]);

        return $job;
    });
}
```

**Por que isso é seguro sob concorrência** (a explicação que você precisa internalizar):
- `SELECT ... FOR UPDATE` dentro de `DB::transaction()` pega um lock exclusivo de linha no InnoDB. Se o worker 2 tentar selecionar a mesma linha enquanto o worker 1 está na transaction, ele **bloqueia** — não lê dado desatualizado.
- Quando o worker 1 comita, o worker 2 (que estava bloqueado) reavalia o `WHERE status='pending'` — mas o worker 1 já mudou o status para `processing` antes de comitar, então essa linha some do resultado do worker 2.
- Isolamento padrão do MySQL é `REPEATABLE READ`; combinado com `FOR UPDATE`, o InnoDB usa next-key locking (record + gap lock). Com o índice do item 1, o lock fica restrito às linhas realmente candidatas, em vez de um range largo — outro motivo do índice não ser só performance.
- Alternativa válida e mais avançada: `UPDATE ... WHERE id = (SELECT id ... LIMIT 1) AND status='pending'` num único statement. Optamos por `SELECT FOR UPDATE` explícito porque é mais didático e generaliza melhor quando prioridade for adicionada depois.
- Otimização conhecida e deliberadamente adiada: `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8) evitaria bloqueio esperando e pularia para a próxima linha livre — vale citar que você está ciente dela, mas não é necessária com poucos workers.
- **O erro a evitar e saber apontar:** sem `FOR UPDATE`/transaction, dois workers podem ler `status=pending` antes de qualquer um escrever, e os dois processam o mesmo job — é exatamente a race condition da seção 3 do README.

## 3. Máquina de estados

```
enqueue → PENDING
PENDING --claimNextJob()--> PROCESSING
PROCESSING --sucesso--> COMPLETED
PROCESSING --falha, attempts < max_attempts--> PENDING (available_at = now()+backoff)
PROCESSING --falha, attempts >= max_attempts--> DEAD
PROCESSING --reserved_at expirado (worker morreu)--> PENDING (available_at = now(), attempts intacto)
DEAD --POST /dead-jobs/{id}/retry--> PENDING (attempts=0, available_at=now())
```

Only `claimNextJob()` escreve `processing`. `attempts` é incrementado **apenas** no claim — a recuperação por timeout não incrementa de novo (o "attempt" já foi contado quando o job foi pego).

## 4. Idempotência (duas camadas — este é o ponto mais importante do projeto)

**Camada 1 — POST /jobs duplicado** (cliente reenvia a mesma requisição): `idempotency_key` é `unique` no banco. No insert, capturar a violação de unique constraint (MySQL 1062) e devolver o job já existente em vez de criar outro.

**Camada 2 — a que o README realmente pede**: worker executa o efeito colateral e morre antes de marcar `COMPLETED`; o job volta a `PENDING` (por retry ou timeout) e pode ser reprocessado pela *mesma linha*. A unique key da camada 1 não ajuda aqui. Solução: um handler que insere um "recibo" antes de executar o efeito colateral:

```php
public function handle(Job $job): void
{
    DB::transaction(function () use ($job) {
        $inserted = DB::table('idempotency_receipts')->insertOrIgnore([
            'idempotency_key' => $job->idempotency_key,
            'job_type' => $job->type,
            'created_at' => now(),
        ]);

        if ($inserted === 0) return; // já foi feito (ou está sendo feito), pula

        Mail::to($job->payload['to'])->send(new GenericJobMail($job));
    });
}
```

**Limitação honesta a documentar:** o insert do recibo e o envio do e-mail não são atômicos entre si (o e-mail não é transacional). Isso dá "at-least-once execution + dedupe hook", não exactly-once perfeito. Exactly-once de verdade para um efeito colateral externo exige que o próprio sistema downstream seja idempotente (ex.: passar um `Message-ID` derivado de `idempotency_key` para o provedor de e-mail). Saber articular essa distinção é exatamente o tipo de pergunta que o projeto quer treinar.

## 5. Retry + backoff exponencial

```php
// BackoffCalculator::secondsFor(attempt): 30 * 2^(attempt-1) => 30s, 60s, 120s, 240s
```

```php
public function markFailedOrDead(Job $job, \Throwable $e): void
{
    DB::transaction(function () use ($job, $e) {
        $job->refresh();
        $job->last_error = substr($e->getMessage(), 0, 2000);

        if ($job->attempts >= $job->max_attempts) {
            $job->status = 'dead';
        } else {
            $job->status = 'pending';
            $job->available_at = now()->addSeconds(BackoffCalculator::secondsFor($job->attempts));
        }
        $job->save();
    });
}
```

`max_attempts=5` com backoff 30/60/120/240s: tentativa 1 é imediata, depois 4 esperas antes das tentativas 2–5 — deixar essa aritmética explícita evita bug de off-by-one.

## 6. Dead letter queue + API

```php
Route::post('/jobs', [JobController::class, 'store']);
Route::get('/jobs/{job}', [JobController::class, 'show']);
Route::get('/dead-jobs', [DeadJobController::class, 'index']);
Route::post('/dead-jobs/{job}/retry', [DeadJobController::class, 'retry']);
```

`retry` devolve `409 Conflict` se o job não estiver `dead` (é violação de máquina de estados, não erro de validação `422`). Ao reenviar, resetar `attempts=0` e `available_at=now()` — retry manual é uma decisão humana explícita de dar um novo orçamento de tentativas; manter `attempts` esgotado tornaria o job morto de novo na primeira falha seguinte.

## 7. Recuperação de job travado (worker morto)

```php
public function recoverStuckJobs(int $timeoutSeconds = 120): int
{
    return Job::query()
        ->where('status', 'processing')
        ->where('reserved_at', '<', now()->subSeconds($timeoutSeconds))
        ->update(['status' => 'pending', 'available_at' => now()]);
}
```

Roda dentro do próprio loop de cada worker (simplificação deliberada para a Fase 1; num sistema real seria um processo "reaper" dedicado, mas duplicar essa checagem barata em N workers não é incorreto). Não precisa de `lockForUpdate` aqui: é um único `UPDATE` atômico, sem leitura prévia que decida o quê escrever (diferente do claim do item 2).

**Conexão importante:** essa recuperação reabre o risco de double-processing (worker A só estava lento, não morto, e ainda termina depois que B pegou o job de novo) — é exatamente por isso que a idempotência do item 4 não é opcional. O claim do item 2 impede que dois workers *comecem* o mesmo job ao mesmo tempo; não impede que dois workers *eventualmente* executem o mesmo job ao longo do tempo.

## 8. docker-compose.yml

```yaml
services:
  app:
    build: ./
    volumes: ["./src:/var/www/html"]
    ports: ["8000:8000"]
    command: php artisan serve --host=0.0.0.0 --port=8000
    depends_on: [mysql]
    env_file: ./src/.env

  worker-1:
    build: ./
    volumes: ["./src:/var/www/html"]
    command: php artisan jobs:work --worker-id=worker-1
    depends_on: [mysql]
    env_file: ./src/.env
  worker-2: # igual ao worker-1, com --worker-id=worker-2
  worker-3: # igual ao worker-1, com --worker-id=worker-3

  mysql:
    image: mysql:8.0
    environment: { MYSQL_DATABASE: jobs_db, MYSQL_ROOT_PASSWORD: root }
    ports: ["3306:3306"]
    volumes: ["mysql_data:/var/lib/mysql"]

  adminer:
    image: adminer
    ports: ["8080:8080"]
    depends_on: [mysql]

volumes:
  mysql_data:
```

`app` e `worker-N` usam a mesma imagem, só muda o `command` — mapeia diretamente o "worker 1, worker 2, worker 3" do README para containers. Composer não está no PATH local, então todo `composer`/`php artisan` roda via `docker compose exec app <comando>` (ou `run --rm app <comando>` antes do primeiro boot). `.env` usa `DB_HOST=mysql` (nome do serviço).

## 9. Como provar que a concorrência está correta

1. **Prova empírica (principal):** seed de ~200 jobs com um handler que loga `job_id + reserved_by + timestamp` numa tabela `job_runs`. Sobe os 3 workers via `docker compose up worker-1 worker-2 worker-3`. Depois: `SELECT job_id, COUNT(*) FROM job_runs GROUP BY job_id HAVING COUNT(*) > 1` deve retornar zero linhas; `COUNT(status='completed') = N`.
2. **Teste negativo de calibração:** remover temporariamente o `lockForUpdate()` e rodar o mesmo teste — deve aparecer duplicação. Ver o teste falhar sem o lock e passar com o lock é a confirmação de que o lock realmente faz o trabalho alegado, não é só decoração.
3. **Teste de worker morto (capstone):** handler com `sleep(30)` artificial, `docker kill -s SIGKILL <container-worker>` no meio do processamento, confirmar que o job fica em `processing` até o timeout, depois é recuperado e outro worker completa.
4. (Opcional/stretch) teste Pest com `pcntl_fork` + duas conexões PDO reais disputando `claimNextJob()` — mais complexo, menor retorno que a prova empírica; só vale a pena se quiser treinar teste de concorrência isolado.

## 10. Ordem de construção

1. Scaffolding: Compose sobe (`app`, `mysql`, `adminer`), Laravel criado em `src/`, `.env` apontando pro `mysql`, `migrate` funcionando via Docker.
2. Tabela `jobs` + `POST /jobs` com dedupe de `idempotency_key` (camada 1 apenas).
3. Worker único, caminho feliz: `WorkJobs` + `claimNextJob`/`markCompleted` com handler trivial (log).
4. Workers concorrentes + prova de locking (item 9.1 e 9.2).
5. Retry + backoff exponencial (handler que falha sob controle via payload, ex. `{"fail_times": 3}`).
6. Idempotência camada 2 (`idempotency_receipts` + contrato do handler).
7. Dead letter queue + endpoints `GET /dead-jobs` e `POST /dead-jobs/{id}/retry`.
8. Recuperação de job travado + teste de worker morto (item 9.3) — fecha o ciclo unindo máquina de estados, idempotência e recovery.

## Roadmap futuro (fora do escopo desta fase)

- **V2 com Redis**: reimplementar a fila com Redis (`BRPOPLPUSH`/`XREADGROUP`) e comparar com a versão em MySQL.
- **Prioridade** (`CRITICAL/HIGH/NORMAL/LOW`): exige revisar índice e query de claim para ordenar por prioridade antes de `available_at`.
- **Agendamento** (`execute_at`): grande parte já existe implicitamente via `available_at`.
- **Dashboard**: métricas de fila e status de workers — depende de heartbeats/contadores ainda não instrumentados.

## Verificação

- `docker compose up -d && docker compose exec app php artisan migrate` sobe o stack sem erro.
- `curl -X POST localhost:8000/api/jobs -d '{"type":"send_email","payload":{"to":"teste@email.com"}}'` cria um job e ele é processado por algum worker (checar via Adminer ou `GET /jobs/{id}`).
- Reenviar o mesmo `idempotency_key` não cria um segundo job.
- Rodar o teste de concorrência do item 9 (positivo e negativo).
- Forçar falhas via payload e confirmar `attempts`/`available_at`/status `dead` batendo com o backoff esperado.
- `POST /dead-jobs/{id}/retry` tira o job de `dead` e ele é reprocessado.
- Matar um worker no meio de um job (`docker kill -s SIGKILL`) e confirmar recuperação automática após o timeout.

### Arquivos críticos
- `src/app/Services/JobQueueService.php`
- `src/database/migrations/xxxx_create_jobs_table.php`
- `src/app/Console/Commands/WorkJobs.php`
- `src/app/Http/Controllers/JobController.php`
- `docker-compose.yml`
