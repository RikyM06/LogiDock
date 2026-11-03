<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Rappresenta la richiesta HTTP in ingresso. Costruirla a mano (invece di leggere
 * i superglobali ovunque) rende router e controller facili da testare.
 */
final class Request
{
    private string $method;
    private string $path;

    /**
     * @param array<string, mixed> $query  parametri della query string ($_GET)
     * @param array<string, mixed> $post   corpo del form ($_POST)
     * @param array<string, mixed> $server $_SERVER
     */
    public function __construct(
        string $method,
        string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = []
    ) {
        $this->method = strtoupper($method);
        // Normalizza: sempre uno slash iniziale, mai uno finale ("/products/" diventa "/products").
        $this->path = '/' . trim($path, '/');
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        return new self((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function allInput(): array
    {
        return $this->post;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }
}
