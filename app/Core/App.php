<?php

declare(strict_types=1);

namespace App\Core;

final class App
{
    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly bool $debug
    ) {
    }

    public function run(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}
