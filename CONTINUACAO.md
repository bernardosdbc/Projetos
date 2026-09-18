# Continuação do projeto

Este documento registra o ponto de retomada. Plano: [PLANO.md](PLANO.md).

## Checkpoint atual — variante Redis Streams

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
