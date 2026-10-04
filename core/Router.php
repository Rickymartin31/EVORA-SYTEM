<?php

/**
 * Minimal route table dispatcher. Routes map a ?route= query param to a
 * module PHP file. Role gating happens inside each target file via
 * Auth::requireRole(), keeping the router itself dumb and centralized.
 */
class Router
{
    private array $routes = [];

    public function get(string $name, string $file): void
    {
        $this->routes[$name] = $file;
    }

    public function dispatch(string $route): void
    {
        if (!isset($this->routes[$route])) {
            http_response_code(404);
            include EVORA_ROOT . '/modules/shared/404.php';
            return;
        }

        include $this->routes[$route];
    }
}
