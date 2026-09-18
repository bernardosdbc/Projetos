<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue driver (estudo Fase 1 vs Fase 2)
    |--------------------------------------------------------------------------
    |
    | mysql — claim com SELECT ... FOR UPDATE (Fase 1)
    | redis — claim com BRPOPLPUSH + delayed ZSET (Fase 2)
    |
    */

    'driver' => env('QUEUE_DRIVER', 'mysql'),

    /*
    | Base do backoff exponencial em segundos: base * 2^(attempt-1).
    | Default 30 → 30s, 60s, 120s, 240s. Use 1 em provas locais.
    */
    'backoff_base' => (int) env('JOB_BACKOFF_BASE', 30),

];
