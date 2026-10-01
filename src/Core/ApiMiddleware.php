<?php

declare(strict_types=1);

namespace BNT\Core;

class ApiMiddleware
{
    public function __construct(
        private ApiAuth $apiAuth,
        private ?RateLimiter $rateLimiter = null,
        private array $config = []
    ) {}
    
    /**
     * Middleware to require API authentication
     */
    public function requireAuth(): ?array
    {
        $token = $this->apiAuth->getTokenFromRequest();
        
        if (!$token) {
            ApiResponse::unauthorized('API token required');
            return null;
        }
        
        $ship = $this->apiAuth->validateToken($token);
        
        if (!$ship) {
            ApiResponse::unauthorized('Invalid or expired API token');
            return null;
        }
        
        // Token bucket per API token: 60/min for players, 30/min for NPCs by default.
        if ($this->rateLimiter !== null) {
            $limit = !empty($ship['is_npc'])
                ? (int)($this->config['api']['rate_limit_npc_per_min'] ?? 30)
                : (int)($this->config['api']['rate_limit_player_per_min'] ?? 60);
            $key = $this->apiAuth->currentTokenHash() ?? ('ship:' . $ship['ship_id']);
            $decision = $this->rateLimiter->consume($key, $limit);
            header('X-RateLimit-Limit: ' . $decision['limit']);
            if (!$decision['allowed']) {
                ApiResponse::tooManyRequests($decision['retry_after'], $decision['limit']);
                return null;
            }
            header('X-RateLimit-Remaining: ' . $decision['remaining']);
        }

        return $ship;
    }
    
    /**
     * Middleware to handle CORS
     */
    public function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        
        // In production, specify allowed origins
        // $allowedOrigins = ['https://yourdomain.com'];
        // if (in_array($origin, $allowedOrigins)) {
        //     header("Access-Control-Allow-Origin: $origin");
        // }
        
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}

