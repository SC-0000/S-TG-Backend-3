<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LiveKit Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for LiveKit real-time audio/video communication
    |
    */

    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
    'url' => env('LIVEKIT_URL', 'wss://meet.digitize.global/livekit/'),

    /*
    |--------------------------------------------------------------------------
    | Force Opus Only
    |--------------------------------------------------------------------------
    |
    | Force all clients to use Opus codec only by disabling RED (redundancy)
    | encoding. This improves cross-browser compatibility, especially for
    | Safari and Firefox which have issues with RED encoding.
    |
    */

    'force_opus_only' => env('LIVEKIT_FORCE_OPUS_ONLY', true),

];
