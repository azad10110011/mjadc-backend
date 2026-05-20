<?php

require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../utils/Response.php';

class Auth
{
    public static function getUser(): ?array
    {
        $token = self::getToken();
        if (!$token) {
            return null;
        }

        try {
            $decoded = JWTHandler::decode($token);
            $user = Database::fetch(
                "SELECT u.*, GROUP_CONCAT(ur.role) as roles 
                 FROM users u 
                 LEFT JOIN user_roles ur ON u.id = ur.user_id 
                 WHERE u.id = ? AND u.status = 'active' 
                 GROUP BY u.id",
                [$decoded->user_id]
            );

            if (!$user) {
                return null;
            }

            $user['roles'] = $user['roles'] ? explode(',', $user['roles']) : [];
            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function requireAuth(): array
    {
        $user = self::getUser();
        if (!$user) {
            Response::unauthorized('Authentication required');
        }
        return $user;
    }

    public static function requireRole(string $role): array
    {
        $user = self::requireAuth();
        if (!in_array($role, $user['roles'])) {
            Response::forbidden("Access denied. {$role} role required");
        }
        return $user;
    }

    public static function requireAnyRole(array $roles): array
    {
        $user = self::requireAuth();
        $userRoles = $user['roles'];
        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return $user;
            }
        }
        Response::forbidden('Access denied. Insufficient permissions');
        return $user;
    }

    public static function getToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
