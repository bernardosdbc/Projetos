<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue driver (estudo Fase 1 vs Fase 2)
    |--------------------------------------------------------------------------
    |
    | mysql          — claim com SELECT ... FOR UPDATE (Fase 1)
    | redis          — BRPOPLPUSH + delayed ZSET (Fase 2)
    | redis_streams  — XREADGROUP + XACK + delayed ZSET (Fase 2 variante)
    |
    */

    'driver' => env('QUEUE_DRIVER', 'mysql'),

    /*
    | Base do backoff exponencial em segundos: base * 2^(attempt-1).
    | Default 30 → 30s, 60s, 120s, 240s. Use 1 em provas locais.
    */
    'backoff_base' => (int) env('JOB_BACKOFF_BASE', 30),

    /*
    | Segundos sem heartbeat até um worker ser considerado OFFLINE no
    | dashboard. Poucos ciclos do --sleep default (1s) já bastam; bem abaixo
    | do timeout de job travado (120s) — são perguntas diferentes (worker
    | vivo? vs. job abandonado?).
    */
    'worker_offline_after' => (int) env('WORKER_OFFLINE_AFTER', 8),

];
