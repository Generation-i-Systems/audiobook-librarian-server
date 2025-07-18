<?php

return [
    'project_id' => env('FIREBASE_PROJECT_ID', env('GCLOUD_PROJECT')),

    'credentials' => [
        'file' => env('FIREBASE_CREDENTIALS', env('GOOGLE_APPLICATION_CREDENTIALS')),
    ],
];
