<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny regex router. Routes are registered as (method, pattern, handler)
 * where pattern may contain {name} placeholders matching one path segment.
 * Handler is [ControllerClass, 'method'].
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, vars:array<int,string>, handler:array}> */
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $vars = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_]+)\}#', function ($m) use (&$vars) {
            $vars[] = $m[1];
            return '([^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'  => $method,
            'regex'   => $regex,
            'vars'    => $vars,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        // HEAD behaves like GET (PHP discards the body automatically).
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // Strip query string and normalise (no trailing slash except root).
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = rawurldecode($path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = [];
                foreach ($route['vars'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? null;
                }
                [$class, $action] = $route['handler'];
                $controller = new $class();
                $controller->$action($params);
                return;
            }
        }

        http_response_code(404);
        echo '404 — Page introuvable.';
    }
}
