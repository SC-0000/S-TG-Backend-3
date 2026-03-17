<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Token Exchange Rate
    |--------------------------------------------------------------------------
    |
    | How many platform tokens equal £1. This controls the purchase pricing.
    | Example: 100 means £1 buys 100 platform tokens.
    |
    */
    'exchange_rate' => env('AGENT_TOKEN_EXCHANGE_RATE', 100),

];
