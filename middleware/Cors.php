<?php

class Cors
{
    public static function handle(): void
    {
        // Session 112: exact allow-list, and NO wildcard fallback. The production site runs on
        // www.mjadc.ac.bd, so both hosts are listed; an unknown origin gets no CORS header at all.
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'https://mjadc.ac.bd',
            'https://www.mjadc.ac.bd',
        ];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
