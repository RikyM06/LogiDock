<?php

declare(strict_types=1);

namespace LogiDock\Core;

use DateTimeImmutable;
use Throwable;

/**
 * Logger su file, una riga per evento. Se il file non è scrivibile ripiega su
 * error_log() di PHP: un errore di logging non deve mai far cadere la richiesta.
 */
final class Logger
{
    public function __construct(private readonly string $file)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf('[%s] %s: %s', (new DateTimeImmutable())->format(DATE_ATOM), strtoupper($level), $message);

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $line .= PHP_EOL;

        // Qui @ è voluto: l'errore viene gestito esplicitamente subito sotto (fallback su error_log)
        // e lanciare un'eccezione dentro il logger farebbe ricorsione nel gestore degli errori.
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function exception(Throwable $e, string $level = 'error'): void
    {
        $this->log(
            $level,
            sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()),
            ['trace' => $e->getTraceAsString()]
        );
    }
}
