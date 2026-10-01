<?php

declare(strict_types=1);

namespace BNT\Core;

class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = ['success' => true];
        
        if ($message !== null) {
            $response['message'] = $message;
        }
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    public static function error(string $message, ?string $code = null, int $statusCode = 400, array $details = []): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
            ]
        ];
        
        if ($code !== null) {
            $response['error']['code'] = $code;
        }
        
        if (!empty($details)) {
            $response['error']['details'] = $details;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    public static function unauthorized(string $message = 'Authentication required'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }
    
    public static function forbidden(string $message = 'Access denied'): void
    {
        self::error($message, 'FORBIDDEN', 403);
    }
    
    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 'NOT_FOUND', 404);
    }
    
    public static function tooManyRequests(int $retryAfter, int $limit): void
    {
        header('Retry-After: ' . $retryAfter);
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: 0');
        self::error('Rate limit exceeded. Retry in ' . $retryAfter . ' seconds.', 'RATE_LIMITED', 429, ['retry_after' => $retryAfter]);
    }

    public static function validationError(array $errors): void
    {
        self::error('Validation failed', 'VALIDATION_ERROR', 422, ['fields' => $errors]);
    }
}

