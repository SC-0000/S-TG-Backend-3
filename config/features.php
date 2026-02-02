<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Defaults
    |--------------------------------------------------------------------------
    |
    | Base defaults applied to every organization. These can be overridden
    | per-organization via settings.features.*, and further overridden by
    | the overrides array below (for super-admin enforced flags).
    |
    */
    'defaults' => [
        'teacher' => [
            'revenue_dashboard' => true,
            'content_edit' => true,
            'content_delete' => true,
        ],
        'parent' => [
            'ai' => [
                'chatbot' => true,
                'report_generation' => true,
                'post_submission_help' => true,
            ],
            'subscriptions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Forced Overrides
    |--------------------------------------------------------------------------
    |
    | Super-admin enforced flags. When set, these values trump both defaults
    | and per-organization settings. Leave empty to allow org-level control.
    |
    */
    'overrides' => [
        // 'parent' => [
        //     'ai' => [
        //         'chatbot' => false,
        //     ],
        // ],
    ],
];
