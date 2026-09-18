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

];
