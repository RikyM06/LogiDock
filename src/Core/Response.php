<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Risposta HTTP. I controller la restituiscono, solo Application::run() la invia.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    /**
     * Un PDF da mostrare nel browser. nosniff impedisce al browser di indovinare un altro tipo di file;
     * no-store evita che resti nella cache (i documenti hanno prezzi e compensi).
     */
    public static function pdf(string $content, string $filename): self
    {
        // Il nome del file arriva da codici nostri (DDT-2026-000001), ma si ripulisce lo stesso: un
        // virgolette o un a-capo nell'intestazione sarebbero un problema.
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'documento.pdf';

        return new self($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeName . '"',
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
