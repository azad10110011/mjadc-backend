<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHandler
{
    private static function getSecret(): string
    {
        // Session 112: fail hard rather than silently signing tokens with a public constant.
        $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '';
        if (strlen($secret) < 32) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 500, 'message' => 'Server auth is misconfigured (JWT secret missing or too short)']);
            exit;
        }
        return $secret;
    }

    private static function getExpiry(): int
    {
        return (int)($_ENV['JWT_EXPIRY'] ?? 86400);
    }

    public static function encode(array $payload): string
    {
        $issuedAt = time();
        $payload['iat'] = $issuedAt;
        $payload['exp'] = $issuedAt + self::getExpiry();

        return JWT::encode($payload, self::getSecret(), 'HS256');
    }

    public static function decode(string $token): object
    {
        return JWT::decode($token, new Key(self::getSecret(), 'HS256'));
    }

    public static function generateToken(int $userId, string $email, array $roles): string
    {
        return self::encode([
            'user_id' => $userId,
            'email' => $email,
            'roles' => $roles,
        ]);
    }
}
