<?php

declare(strict_types=1);

namespace BNT\Core;

class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function dispatch(string $method, string $uri): mixed
    {
        // Remove query string
        $uri = parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method) {
                $pattern = $this->convertPathToRegex($route['path']);
                if (preg_match($pattern, $uri, $matches)) {
                    array_shift($matches); // Remove full match

                    // Execute middlewares
                    foreach ($this->middlewares as $middleware) {
                        $middleware();
                    }

                    return call_user_func_array($route['handler'], $matches);
                }
            }
        }

        http_response_code(404);
        return ['error' => 'Route not found'];
    }

    private function convertPathToRegex(string $path): string
    {
        // Convert /user/:id to /user/([^/]+)
        $pattern = preg_replace('/:[a-zA-Z0-9_]+/', '([^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
}
