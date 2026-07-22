<?php
namespace App\Core;

/**
 * راوتر بسيط: يطابق (method + path) على معالِج.
 * المسارات تدعم مُعاملات مثل  /bawalis/{id}/edit
 */
class Router
{
    private array $routes = [];

    public function get(string $path, $handler): void  { $this->add('GET', $path, $handler); }
    public function post(string $path, $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, $handler): void
    {
        $this->routes[] = [$method, $path, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $base = App::baseUrl();
        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');
        if ($path === '/') { $path = '/'; }

        foreach ($this->routes as [$m, $route, $handler]) {
            if ($m !== $method) {
                continue;
            }
            $pattern = preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                $this->call($handler, $matches, $method);
                return;
            }
        }
        http_response_code(404);
        (new Controller())->render('errors/404', ['title' => 'غير موجود'], false);
    }

    private function call($handler, array $params, string $httpMethod): void
    {
        Access::enforce((string) $handler, $httpMethod);
        [$class, $action] = explode('@', $handler);
        $class = 'App\\Controllers\\' . $class;
        $controller = new $class();
        call_user_func_array([$controller, $action], $params);
    }
}
