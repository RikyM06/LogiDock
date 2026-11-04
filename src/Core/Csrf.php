<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Protezione CSRF: un sito malevolo non può far inviare un form al browser dell'utente
 * perché non conosce il token, che sta nella sessione e in un campo nascosto dei nostri form.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        // hash_equals confronta in tempo costante, senza rivelare quanti caratteri sono giusti.
        return is_string($token) && $token !== '' && $submitted !== null && hash_equals($token, $submitted);
    }

    public function rotate(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }
}
