<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Calcolo puro della paginazione (niente database): dato quante righe ci sono, quante ne mostriamo per pagina
 * e quale pagina l'utente ha chiesto, dice quale pagina mostrare davvero e da quale riga partire.
 *
 * Un valore sporco ("abc", 0, -3, oltre l'ultima pagina) non e' mai un errore: si ripiega sulla pagina valida
 * piu' vicina.
 */
final class Pagination
{
    /** Nome del parametro nell'indirizzo (?pagina=2). */
    public const PARAM = 'pagina';

    public readonly int $perPage;
    public readonly int $total;
    public readonly int $pages;
    public readonly int $page;

    public function __construct(int $total, int $perPage, mixed $requestedPage)
    {
        $this->perPage = max(1, $perPage);
        $this->total = max(0, $total);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = min($this->pages, max(1, self::toInt($requestedPage)));
    }

    /** Numero di riga da cui partire (0 per la prima pagina). */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }

    /**
     * Indirizzo di una pagina, conservando gli altri parametri (ricerca, filtri). La pagina 1 non porta
     * il parametro: e' l'indirizzo "pulito" della lista.
     *
     * @param array<string, mixed> $query    parametri correnti (il parametro della pagina, se c'e', viene sostituito)
     * @param string               $fragment ancora della pagina senza "#" (es. "bancali"), vuota se non serve
     */
    public static function url(string $path, array $query, int $page, string $fragment = ''): string
    {
        unset($query[self::PARAM]);
        $query = array_filter($query, static fn (mixed $value): bool => $value !== '' && $value !== null);
        if ($page > 1) {
            $query[self::PARAM] = $page;
        }

        $url = $query === [] ? $path : $path . '?' . http_build_query($query);

        return $fragment === '' ? $url : $url . '#' . $fragment;
    }

    /** Un testo qualunque diventa un intero: solo cifre valgono, tutto il resto e' 1. */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[0-9]{1,9}\z/', $value) === 1) {
            return (int) $value;
        }

        return 1;
    }
}
