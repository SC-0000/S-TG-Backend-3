<?php

return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'key'       => env('OPENAI_API_KEY'),
            'model'     => 'gpt-5-nano',
            'timeout'   => 60,
            'retry'     => false,
            'rate_limit' => [
                'enabled'   => false,
                'max_tokens'=> 250000,
                'tokens_per_minute' => 150000
            ],
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. Increased from 30 to 90 seconds for GPT-5 with larger
    | max_completion_tokens (4000) which takes longer to generate.
    */

    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 90),
];
