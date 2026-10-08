<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $files = [],
        private ?string $rawBody = null
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            $method,
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $headers,
            $_FILES
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $path = '/' . ltrim($this->path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function rawBody(int $maxBytes = 1048576): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('The body limit must not be negative.');
        }

        $contentLength = $this->header('Content-Length');
        if (
            $contentLength !== null
            && ctype_digit($contentLength)
            && (int) $contentLength > $maxBytes
        ) {
            throw new \LengthException('Request body exceeds the configured limit.');
        }

        if ($this->rawBody === null) {
            $stream = fopen('php://input', 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read request body.');
            }

            $body = '';
            $readLimit = $maxBytes + 1;
            try {
                while (strlen($body) < $readLimit) {
                    $chunk = fread($stream, min(8192, $readLimit - strlen($body)));
                    if ($chunk === false) {
                        throw new \RuntimeException('Unable to read request body.');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }
            } finally {
                fclose($stream);
            }

            if (strlen($body) > $maxBytes) {
                throw new \LengthException('Request body exceeds the configured limit.');
            }
            $this->rawBody = $body;
        }

        if (strlen($this->rawBody) > $maxBytes) {
            throw new \LengthException('Request body exceeds the configured limit.');
        }

        return $this->rawBody;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }
}
