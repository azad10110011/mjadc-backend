<?php

require_once __DIR__ . '/../utils/JWTHandler.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Permissions.php';

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
            // Session 116: a token issued before the password last changed is no longer valid.
            // (Tokens from before this change carry no stamp and are accepted until they expire.)
            if (isset($decoded->pws) && !hash_equals(JWTHandler::passwordStamp((string)$user['password_hash']), (string)$decoded->pws)) {
                return null;
            }

            $user['roles'] = $user['roles'] ? explode(',', $user['roles']) : [];
            // Session 121: changes made in this request are recorded under this user in Update History
            if (function_exists('auditSetUser')) {
                try { auditSetUser($user); auditPeriodicSync(); } catch (\Throwable $e) { /* never block the request */ }
            }
            $user['_token_client'] = (string)($decoded->client ?? 'web');
            $user['_token_iat'] = (int)($decoded->iat ?? 0);
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

    // Session 80: both checks also consult per-user permissions (utils/Permissions.php):
    // a granted feature opens its endpoints to users without the role; a revoked feature closes them.
    public static function requireRole(string $role): array
    {
        $user = self::requireAuth();
        $roleOk = in_array($role, $user['roles']);
        if (!Permissions::allows($user, $roleOk)) {
            Response::forbidden($roleOk ? 'Access to this feature has been removed for your account' : "Access denied. {$role} role required");
        }
        return $user;
    }

    public static function requireAnyRole(array $roles): array
    {
        $user = self::requireAuth();
        $roleOk = (bool)array_intersect($roles, $user['roles']);
        if (!Permissions::allows($user, $roleOk)) {
            Response::forbidden($roleOk ? 'Access to this feature has been removed for your account' : 'Access denied. Insufficient permissions');
        }
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
