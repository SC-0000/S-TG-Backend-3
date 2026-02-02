<?php

return [
    'allowed_keys' => [
        'app_name',
        'timezone',
        'support_email',
        'maintenance_mode',
        'maintenance_message',
    ],

    'rules' => [
        'app_name' => 'string|max:255',
        'timezone' => 'string|max:255',
        'support_email' => 'email|max:255',
        'maintenance_mode' => 'boolean',
        'maintenance_message' => 'string|max:500',
    ],
];
