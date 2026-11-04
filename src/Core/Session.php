<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Sessione PHP con impostazioni sicure:
 * - cookie HttpOnly (non leggibile da JavaScript) e SameSite=Lax (non inviato da altri siti nei POST)
 * - Secure quando il sito gira su HTTPS
 * - use_strict_mode: PHP rifiuta ID di sessione che non ha generato lui (contro la session fixation)
 * - scadenza per inattività
 */
final class Session
{
    private const LAST_ACTIVITY_KEY = '_last_activity';
    private const FLASH_KEY = '_flash';

    public function __construct(
        private readonly string $name,
        private readonly int $lifetimeSeconds,
        private readonly bool $secure
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $this->lifetimeSeconds);

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0, // il cookie vale finché non si chiude il browser
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Sessione inattiva troppo a lungo: si svuota e si cambia ID.
        $now = time();
        $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? $now;
        if (is_int($lastActivity) && $now - $lastActivity > $this->lifetimeSeconds) {
            $this->invalidate();
        }
        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Nuovo ID di sessione mantenendo i dati. Da chiamare a ogni cambio di privilegi (login),
     * così un ID noto prima del login non vale dopo.
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Svuota la sessione e cambia ID (logout o sessione scaduta).
     */
    public function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Messaggio che sopravvive a un solo redirect (es. "Prodotto salvato").
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][$type][] = $message;
    }

    /**
     * Restituisce e cancella i messaggi flash, raggruppati per tipo.
     *
     * @return array<string, list<string>>
     */
    public function pullFlash(): array
    {
        $messages = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        return is_array($messages) ? $messages : [];
    }
}
