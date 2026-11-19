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
        $expiry = ($payload['client'] ?? '') === 'app' ? self::APP_EXPIRY : self::getExpiry();
        $payload['exp'] = $issuedAt + $expiry;

        return JWT::encode($payload, self::getSecret(), 'HS256');
    }

    public static function decode(string $token): object
    {
        return JWT::decode($token, new Key(self::getSecret(), 'HS256'));
    }

    /** Session 116: the mobile apps stay signed in — 1-year tokens, renewed by /auth/refresh. */
    public const APP_EXPIRY = 365 * 86400;

    /**
     * Fingerprint of the user's current password hash. Stored in the token so that changing (or an
     * admin resetting) the password ends every other session — needed now that app tokens live a year.
     */
    public static function passwordStamp(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 12);
    }

    public static function generateToken(int $userId, string $email, array $roles, string $client = 'web', string $passwordHash = ''): string
    {
        $payload = [
            'user_id' => $userId,
            'email' => $email,
            'roles' => $roles,
            'client' => $client === 'app' ? 'app' : 'web',
        ];
        if ($passwordHash !== '') $payload['pws'] = self::passwordStamp($passwordHash);
        return self::encode($payload);
    }
}
