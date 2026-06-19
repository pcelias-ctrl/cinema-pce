<?php

return [
    'app_name' => getenv('APP_NAME') ?: 'Cinema PCE',
    'app_env' => getenv('APP_ENV') ?: 'local',
    'app_url' => getenv('APP_URL') ?: 'http://localhost:8080',
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'cinema_pce',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];

