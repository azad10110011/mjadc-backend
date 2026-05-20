<?php

class Response
{
    public static function json($data, int $status = 200, string $message = 'OK'): void
    {
        while (ob_get_level()) ob_end_clean();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = null, string $message = 'Success'): void
    {
        self::json($data, 200, $message);
    }

    public static function created($data = null, string $message = 'Created successfully'): void
    {
        self::json($data, 201, $message);
    }

    public static function error(string $message, int $status = 400, $errors = null): void
    {
        while (ob_get_level()) ob_end_clean();
        $payload = ['status' => $status, 'message' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    public static function validationError(array $errors): void
    {
        self::error('Validation failed', 422, $errors);
    }
}
