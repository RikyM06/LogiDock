<?php
/**
 * Briciole di pane: il percorso della pagina, sopra il titolo.
 *
 * "Home" è sempre la prima voce e non si scrive nelle pagine. Ogni voce è [etichetta] oppure
 * [etichetta, url]: le voci con un URL sono link, l'ultima è sempre la pagina corrente (senza link).
 * Il separatore lo disegna il CSS, così non finisce nel testo letto dagli screen reader.
 *
 * @var list<array{0: string, 1?: string}> $crumbs
 */
$crumbs ??= [];
$lastIndex = count($crumbs) - 1;
?>
<?php if ($crumbs !== []) : ?>
<nav class="breadcrumb" aria-label="Briciole di pane">
    <ol>
        <li><a href="/">Home</a></li>
        <?php foreach ($crumbs as $index => $crumb) : ?>
            <?php if ($index === $lastIndex) : ?>
                <li><span aria-current="page"><?= e($crumb[0]) ?></span></li>
            <?php elseif (isset($crumb[1])) : ?>
                <li><a href="<?= e($crumb[1]) ?>"><?= e($crumb[0]) ?></a></li>
            <?php else : ?>
                <li><?= e($crumb[0]) ?></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
