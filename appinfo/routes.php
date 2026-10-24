<?php

return [
    'routes' => [
        // Admin UI page
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Feed CRUD
        ['name' => 'feed#index',   'url' => '/api/feeds',             'verb' => 'GET'],
        ['name' => 'feed#create',  'url' => '/api/feeds',             'verb' => 'POST'],
        ['name' => 'feed#show',    'url' => '/api/feeds/{id}',        'verb' => 'GET'],
        ['name' => 'feed#update',  'url' => '/api/feeds/{id}',        'verb' => 'PUT'],
        ['name' => 'feed#destroy', 'url' => '/api/feeds/{id}',        'verb' => 'DELETE'],

        // Feed actions
        ['name' => 'feed#preview',    'url' => '/api/feeds/preview',     'verb' => 'POST'],
        ['name' => 'feed#manualSync', 'url' => '/api/feeds/{id}/sync',   'verb' => 'POST'],
        ['name' => 'feed#quarantine', 'url' => '/api/feeds/{id}/quarantine', 'verb' => 'POST'],

        // Global settings
        ['name' => 'settings#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],

        // Groups list (proxy to OCS, avoids CORS)
        ['name' => 'settings#getGroups', 'url' => '/api/groups', 'verb' => 'GET'],

        // Service users list
        ['name' => 'settings#getServiceUsers', 'url' => '/api/users', 'verb' => 'GET'],
    ],
];
