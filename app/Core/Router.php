<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Middlewares\Middleware;

final class Router
{
    /**
     * @var array<string, array<string, array{
     *     handler: callable,
     *     middleware: list<Middleware>
     * }>>
     */
    private array $routes = [];

    /**
     * @var array<string, list<array{
     *     path: string,
     *     pattern: string,
     *     parameter_names: list<string>,
     *     handler: callable,
     *     middleware: list<Middleware>
     * }>>
     */
    private array $dynamicRoutes = [];

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
        $route = $this->resolveRoute($request->method(), $request->path());

        $handler = static function (Request $request) use ($route): Response {
            if ($route === null) {
                return Response::html(View::render('errors/404'), 404);
            }

            $parameters = $route['parameters'] ?? [];
            $response = $parameters === []
                ? ($route['handler'])($request)
                : ($route['handler'])($request, $parameters);

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

        $normalizedPath = $this->normalizePath($path);
        $route = [
            'handler' => $handler,
            'middleware' => array_values($middleware),
        ];

        if ($this->isDynamicPath($normalizedPath)) {
            $compiled = $this->compileDynamicPath($normalizedPath);
            $this->dynamicRoutes[$method][] = [
                'path' => $normalizedPath,
                'pattern' => $compiled['pattern'],
                'parameter_names' => $compiled['parameter_names'],
                ...$route,
            ];

            return;
        }

        $this->routes[$method][$normalizedPath] = $route;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * @return array{
     *     handler: callable,
     *     middleware: list<Middleware>,
     *     parameters: array<string, string>
     * }|null
     */
    private function resolveRoute(string $method, string $path): ?array
    {
        $exact = $this->routes[$method][$path] ?? null;

        if ($exact !== null) {
            return [
                ...$exact,
                'parameters' => [],
            ];
        }

        foreach ($this->dynamicRoutes[$method] ?? [] as $route) {
            $matches = [];

            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            $parameters = [];

            foreach ($route['parameter_names'] as $index => $name) {
                $value = (string) ($matches[$index + 1] ?? '');

                if ($value === '.' || $value === '..') {
                    continue 2;
                }

                $parameters[$name] = $value;
            }

            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'parameters' => $parameters,
            ];
        }

        return null;
    }

    private function isDynamicPath(string $path): bool
    {
        return str_contains($path, '{') || str_contains($path, '}');
    }

    /**
     * @return array{pattern: string, parameter_names: list<string>}
     */
    private function compileDynamicPath(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $patternSegments = [];
        $parameterNames = [];

        foreach ($segments as $segment) {
            if (
                str_starts_with($segment, '{')
                || str_ends_with($segment, '}')
            ) {
                if (
                    preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) !== 1
                ) {
                    throw new \InvalidArgumentException(
                        'Dynamic route parameters must occupy a full path segment.'
                    );
                }

                $name = $matches[1];

                if (in_array($name, $parameterNames, true)) {
                    throw new \InvalidArgumentException(
                        'Dynamic route parameter names must be unique per route.'
                    );
                }

                $parameterNames[] = $name;
                $patternSegments[] = '([^/]+)';
                continue;
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        return [
            'pattern' => '#^/' . implode('/', $patternSegments) . '$#',
            'parameter_names' => $parameterNames,
        ];
    }
}
