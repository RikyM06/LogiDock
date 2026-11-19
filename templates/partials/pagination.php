<?php
/**
 * Totale dei risultati e, se le pagine sono più di una, i link Precedente / Successivo.
 * Gli altri parametri dell'indirizzo (ricerca, filtri) restano quelli passati in $query.
 *
 * @var \LogiDock\Core\Pagination $pagination
 * @var string                    $path  indirizzo della lista, senza parametri
 * @var array<string, mixed>      $query parametri correnti (ricerca, filtri): il numero di pagina lo aggiunge la classe
 * @var string|null               $fragment (facoltativo) ancora a cui tornare, es. "bancali"
 */
use LogiDock\Core\Pagination;

$total = $pagination->total;
$fragment ??= '';
?>
<nav class="pagination" aria-label="Paginazione">
    <p class="pagination__summary">
        <?= e((string) $total) ?> <?= $total === 1 ? 'risultato' : 'risultati' ?>
        <?php if ($pagination->pages > 1) : ?>
            - pagina <?= e((string) $pagination->page) ?> di <?= e((string) $pagination->pages) ?>
        <?php endif; ?>
    </p>
    <?php if ($pagination->pages > 1) : ?>
        <p class="pagination__links">
            <?php if ($pagination->hasPrevious()) : ?>
                <a class="button button--secondary" rel="prev" href="<?= e(Pagination::url($path, $query, $pagination->page - 1, $fragment)) ?>">&larr; Precedente</a>
            <?php else : ?>
                <span class="button button--secondary is-disabled" aria-disabled="true">&larr; Precedente</span>
            <?php endif; ?>
            <?php if ($pagination->hasNext()) : ?>
                <a class="button button--secondary" rel="next" href="<?= e(Pagination::url($path, $query, $pagination->page + 1, $fragment)) ?>">Successivo &rarr;</a>
            <?php else : ?>
                <span class="button button--secondary is-disabled" aria-disabled="true">Successivo &rarr;</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</nav>
