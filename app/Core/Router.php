<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Middlewares\Middleware;

final class Router
{
    /**
     * @var array<string, array<string, array{
     *     handler: callable(Request): Response,
     *     middleware: list<Middleware>
     * }>>
     */
    private array $routes = [];

    /**
     * @var list<Middleware>
     */
    private array $middleware = [];

    public function middleware(Middleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $route = $this->routes[$request->method()][$request->path()] ?? null;

        $handler = static function (Request $request) use ($route): Response {
            if ($route === null) {
                return Response::html(View::render('errors/404'), 404);
            }

            $response = ($route['handler'])($request);

            if (!$response instanceof Response) {
                throw new \UnexpectedValueException('Route handlers must return a Response.');
            }

            return $response;
        };

        $routeMiddleware = $route['middleware'] ?? [];
        $pipeline = array_merge($this->middleware, $routeMiddleware);

        foreach (array_reverse($pipeline) as $middleware) {
            $next = $handler;
            $handler = static fn (Request $request): Response => $middleware->process($request, $next);
        }

        return $handler($request);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware> $middleware
     */
    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        foreach ($middleware as $item) {
            if (!$item instanceof Middleware) {
                throw new \InvalidArgumentException('Route middleware must implement Middleware.');
            }
        }

        $this->routes[$method][$this->normalizePath($path)] = [
            'handler' => $handler,
            'middleware' => array_values($middleware),
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
