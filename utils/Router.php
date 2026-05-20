<?php

require_once __DIR__ . '/../utils/Response.php';

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, callable $handler, array $middleware): self
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $this->pathToPattern($path),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    private function pathToPattern(string $path): string
    {
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove base path if behind a subdirectory
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && $basePath !== '\\') {
            $uri = substr($uri, strlen($basePath));
        }
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                // Run middleware
                foreach ($route['middleware'] as $mw) {
                    $mw();
                }

                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                call_user_func($route['handler'], $params);
                return;
            }
        }

        Response::notFound('Route not found');
    }
}
