<?php
namespace App\Routing;

 use App\Middleware\AdminMiddleware;

 
use App\Core\Container;
use App\Exceptions\AppException;
use App\Exceptions\NotFoundException;
 
use App\Middleware\AuthMiddleware ; 
class Router  
{
    private array $routes = [];
    private Container $container;

    private string $currentPrefix = '';
    private array $currentMiddleware = [];

    private array $namedRoutes = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    // Route Groups: عشان تحط prefix و middleware مشترك لمجموعة routes
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix ?? '';
        $previousMiddleware = $this->currentMiddleware ?? [];

        $this->currentPrefix = $previousPrefix . ($attributes['prefix'] ?? '');
        $this->currentMiddleware = array_merge($previousMiddleware, $attributes['middleware'] ?? []);

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;
    }


    private function addRoute(string $method, string $path, array $handler, array $middleware, ?string $name = null): void
    {
        $fullPath = ($this->currentPrefix ?? '') . $path;
        $fullMiddleware = array_merge($this->currentMiddleware ?? [], $middleware);

        $route = [
            'method' => $method,
            'path' => trim($fullPath, '/'),
            'handler' => $handler,
            'middleware' => $fullMiddleware,
            'pattern' => $this->convertToRegex($fullPath)
        ];

        $this->routes[] = $route;

        if ($name) {
            $this->namedRoutes[$name] = $fullPath;
        }
    }

    private function convertToRegex(string $path): string
    {
        $path = trim($path, '/');
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(string $method, string $uri)
    {
        $path = trim(parse_url($uri, PHP_URL_PATH), '/');
$path = preg_replace('#^education/index\.php/?#', '', $path);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                if ($route['method'] !== $method) {
                    $allowedMethods[] = $route['method'];
                    continue;
                }

                $params = array_filter(
                    $matches,
                    fn($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return $this->runRoute($route, $params);
            }
        }

        if (!empty($allowedMethods)) {
            throw new AppException(
                "Method not allowed. Allowed: " . implode(', ', $allowedMethods),
                405,
                'METHOD_NOT_ALLOWED'
            );
        }

        throw new NotFoundException("Route");
    }

    private function runRoute(array $route, array $params)
    {
        foreach ($route['middleware'] as $middlewareName) {
            $middleware = $this->
            resolveMiddleware($middlewareName);
            $middleware->handle();
        }

        [$controllerClass, $method] = $route['handler'];
        $controller = $this->container->make($controllerClass);

        $args = array_values($params);

        if (in_array($route['method'], ['POST', 'PUT', 'PATCH'])) {
            $args[] = $this->getRequestBody();
        }

        return call_user_func_array([$controller, $method], $args);
    } 

    private function getRequestBody(): array
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }


 private function resolveMiddleware(string $name): object
{
    // دعم middleware بشكل "role:admin,teacher"
    if (str_contains($name, ':')) {
        [$middlewareKey, $params] = explode(':', $name, 2);
        $args = explode(',', $params);

        if ($middlewareKey === 'role') {
            return new \App\Middleware\RoleMiddleware($args);
        }

        throw new AppException("Middleware '$middlewareKey' not registered");
    }

    $map = [
        'auth' => \App\Middleware\AuthMiddleware::class,
        'admin' => \App\Middleware\AdminMiddleware::class,
    ];

    if (!isset($map[$name])) {
        throw new AppException("Middleware '$name' not registered");
    }

    return $this->container->make($map[$name]);
} 
}
