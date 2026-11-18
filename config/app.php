<?php

return [
    'name' => $_ENV['APP_NAME'] ?? 'MJADC',
    'env' => $_ENV['APP_ENV'] ?? 'development',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? '',
    'jwt_expiry' => (int)($_ENV['JWT_EXPIRY'] ?? 86400),
    'upload_path' => $_ENV['UPLOAD_PATH'] ?? './uploads',
    'cors' => [
        'origin' => ['http://localhost:3000', 'https://mjadc.ac.bd', 'https://www.mjadc.ac.bd'],
        'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'headers' => 'Content-Type, Authorization, X-Requested-With',
    ],
];
