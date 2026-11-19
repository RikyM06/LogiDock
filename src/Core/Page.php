<?php

declare(strict_types=1);

namespace LogiDock\Core;

/**
 * Una pagina di una lista: le righe da mostrare e i dati della paginazione.
 *
 * @template T
 */
final class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly Pagination $pagination
    ) {
    }
}
