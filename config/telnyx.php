<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telnyx API Key
    |--------------------------------------------------------------------------
    |
    | Default Telnyx API key. Organizations can override this via their
    | settings (api_keys.telnyx). Used as fallback when an org has no
    | key configured.
    |
    */
    'api_key' => env('TELNYX_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default Phone Number
    |--------------------------------------------------------------------------
    |
    | Default Telnyx phone number for sending SMS/WhatsApp. Organizations
    | can configure their own via telnyx_phone_numbers table.
    |
    */
    'default_phone_number' => env('TELNYX_DEFAULT_PHONE_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Messaging Profile ID
    |--------------------------------------------------------------------------
    |
    | Default Telnyx messaging profile ID. Required for WhatsApp Business.
    |
    */
    'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Used to verify inbound webhook signatures from Telnyx.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Call Control Connection ID
    |--------------------------------------------------------------------------
    |
    | Telnyx Call Control connection ID for voice calls.
    |
    */
    'connection_id' => env('TELNYX_CONNECTION_ID'),

    'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'),
];
