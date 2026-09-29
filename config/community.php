<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Community features
    |--------------------------------------------------------------------------
    |
    | Recommendations, the inbox and sync-delivered notifications between users
    | of this one server. Each server is a closed community: nothing here ever
    | reaches another server. On a demo install (app.demo_mode) the features are
    | advertised in "demo" mode and every community endpoint is refused, so the
    | app can explain that they need a self-hosted server.
    |
    */

    'enabled' => (bool) env('COMMUNITY_ENABLED', true),

    /** Recommendation sends (one send may fan out to many people) allowed per user per hour. */
    'recommendation_sends_per_hour' => (int) env('COMMUNITY_RECOMMENDATION_SENDS_PER_HOUR', 30),

    /** Page size cap for inbox, sent and notification listings. */
    'max_page_size' => 100,

    /**
     * Default delivery per notification type when the user has not chosen one.
     * show = local notification + inbox, badge = inbox/badge only, off = not recorded.
     */
    'notification_defaults' => [
        'recommendation' => 'show',
        'recommendation_reaction' => 'show',
        'recommendation_reply' => 'show',
    ],
];
