Aí muda bastante. Se a regra é **“vou usar IA o tempo todo, mas quero um projeto em que a IA não consiga simplesmente cuspir o sistema inteiro em 2 horas”**, o projeto precisa ter **complexidade arquitetural, problemas de concorrência, decisões de design e comportamento emergente**.

Eu iria para algo assim:

# 🧠 Projeto: Mini sistema distribuído de processamento de tarefas

Você vai construir uma espécie de **mini Celery / RabbitMQ + worker**, só que simplificado.

A ideia:

```text
             ┌──────────────┐
             │   API Laravel│
             └──────┬───────┘
                    │
                    ▼
             ┌──────────────┐
             │    Queue     │
             └──────┬───────┘
                    │
          ┌─────────┼─────────┐
          ▼         ▼         ▼
       Worker 1  Worker 2  Worker 3
          │         │         │
          └─────────┼─────────┘
                    ▼
                Database
```

O usuário manda:

```http
POST /jobs
```

```json
{
  "type": "send_email",
  "payload": {
    "to": "teste@email.com"
  }
}
```

A API coloca o trabalho na fila.

Os workers pegam os trabalhos e executam.

---

## O problema é que você vai fazer isso direito

Um CRUD com IA:

> "Claude, faça um CRUD de usuários."

2 horas.

Esse projeto:

> "Claude, faça um sistema distribuído com processamento concorrente, retry, idempotência, locking, dead-letter queue e workers escaláveis."

A IA pode gerar **um monte de código**.

Mas fazer aquilo funcionar corretamente é outra história.

---

# O que você teria que implementar

### 1. Queue

```text
PENDING
   ↓
PROCESSING
   ↓
COMPLETED
```

Ou:

```text
PENDING
   ↓
PROCESSING
   ↓
FAILED
   ↓
RETRY
```

---

### 2. Workers

Você pode executar:

```bash
php worker.php
```

E abrir:

```text
worker 1
worker 2
worker 3
worker 4
```

Todos pegando trabalhos da mesma fila.

---

# 3. Concorrência

Aqui começa a ficar interessante.

Imagine:

```text
Job #123
```

Worker 1 pega.

Milissegundos depois:

Worker 2 tenta pegar o mesmo job.

Você precisa garantir:

```text
Worker 1 → conseguiu
Worker 2 → não conseguiu
```

Isso vai te obrigar a estudar:

* race conditions
* database locks
* transactions
* atomicidade
* `SELECT ... FOR UPDATE`
* isolamento de transações

---

# 4. Retry

Se um job falhar:

```text
tentativa 1 ❌
tentativa 2 ❌
tentativa 3 ❌
tentativa 4 ❌
```

Depois:

```text
DEAD LETTER QUEUE
```

Você vai implementar coisas como:

```text
max_attempts = 5
retry_after = 30s
```

E pode implementar **exponential backoff**:

```text
30s
60s
120s
240s
```

---

# 5. Idempotência

Essa é uma das partes que eu mais colocaria no projeto.

Imagine que o worker processou:

```text
Enviar R$100
```

Mas morreu **depois de executar a operação e antes de marcar o job como concluído**.

Quando reiniciar:

```text
Job → PENDING
```

Ele pode executar novamente.

Você precisa projetar o sistema para que:

```text
processar(job)
```

duas vezes não cause um efeito duplicado.

Isso é um problema real de backend.

---

# 6. Dead Letter Queue

Jobs que não conseguem ser processados ficam separados:

```text
QUEUE

pending
processing
completed
failed
dead
```

E você cria uma API:

```http
GET /dead-jobs
```

```http
POST /dead-jobs/{id}/retry
```

---

# 7. Prioridade

A fila pode ter:

```text
CRITICAL
HIGH
NORMAL
LOW
```

Então:

```text
Job A → LOW
Job B → LOW
Job C → CRITICAL
Job D → HIGH
```

O worker precisa pegar:

```text
C
D
A
B
```

---

# 8. Agendamento

Depois você adiciona:

```json
{
  "type": "send_email",
  "execute_at": "2026-09-20 14:00:00"
}
```

O worker não pode executar antes.

Isso te coloca para trabalhar com:

* timestamps
* timezone
* polling
* scheduling
* jobs futuros

---

# 9. Dashboard

Aí você pode fazer um frontend simples mostrando:

```text
QUEUE
────────────────────────

Pending       43
Processing     8
Completed   1823
Failed        17
Dead           4

Workers
────────────────────────

Worker 1      ONLINE
Worker 2      ONLINE
Worker 3      OFFLINE
Worker 4      ONLINE
```

E:

```text
Jobs/sec: 24
Average time: 182ms
Failure rate: 1.3%
```

---

# 10. Derrubar worker de propósito

Essa parte é importante.

Você vai testar:

```text
worker processando job
        ↓
       💀
```

E o sistema precisa perceber:

> "Esse worker morreu. O job ficou preso."

Então depois de determinado tempo:

```text
PROCESSING
     ↓
TIMEOUT
     ↓
PENDING
```

Outro worker pega.

Isso é **bem mais difícil de resolver corretamente** do que simplesmente gerar código.

---

# Stack que eu usaria

Como você está estudando Laravel:

```text
Laravel
PHP
MySQL/PostgreSQL
Redis
Docker
```

Mas eu faria uma coisa interessante:

### V1

Não use Redis.

Faça a fila utilizando banco.

```text
Laravel API
     ↓
    MySQL
     ↓
 Workers PHP
```

### V2

Troque a implementação:

```text
Laravel API
     ↓
   Redis
     ↓
 Workers
```

E compare as duas abordagens.

---

# E a IA entra onde?

**Em praticamente tudo.**

Você pode falar:

> "Claude, implemente o worker."

Pode.

Depois:

> "Escreva os testes de concorrência."

Pode.

Depois:

> "Analise esse deadlock."

Pode.

Mas aí você vai descobrir a parte interessante:

**a IA consegue escrever código muito mais rápido do que consegue garantir que sua arquitetura está correta.**

Você vai precisar entender o suficiente para perguntar:

```text
Por que esse lock existe?
O que acontece se o worker morrer aqui?
Esse UPDATE é atômico?
Dois workers podem executar esse job?
O retry pode duplicar uma operação?
O que acontece se o banco cair?
Por que esse job ficou preso em PROCESSING?
```

E essas perguntas são justamente o que eu acho que você está procurando.

---

## E eu colocaria uma regra no projeto

**Você pode usar IA para escrever código.**

Mas toda decisão arquitetural precisa ser algo que você consegue explicar.

Se alguém perguntar:

> "Por que você utilizou transaction aqui?"

Você precisa saber responder.

> "Por que existe esse índice?"

Saber responder.

> "Como você impede dois workers de pegarem o mesmo job?"

Saber responder.

> "O que acontece se um worker morrer durante o processamento?"

Saber responder.

Aí o projeto deixa de ser **"olha o CRUD que o Claude fez"** e passa a ser **um projeto que você realmente precisou entender para conseguir terminar**.

Esse eu colocaria como um projeto de **nível júnior avançando para pleno**, sem depender de você implementar um sistema gigantesco.
