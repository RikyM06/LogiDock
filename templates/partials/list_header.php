<?php
/**
 * Intestazione di una lista di anagrafica: titolo, pulsante "Nuovo" (solo admin) e ricerca.
 *
 * @var string                                                          $title
 * @var string                                                          $path
 * @var array{singular: string, plural: string, ending: string}        $labels
 * @var bool                                                            $canManage
 * @var string                                                          $search
 */
?>
<div class="page-header">
    <h1><?= e($title) ?></h1>
    <?php if ($canManage) : ?>
        <a class="button" href="<?= e($path) ?>/create">Nuov<?= e($labels['ending']) ?> <?= e(lcfirst($labels['singular'])) ?></a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e($path) ?>" class="toolbar" role="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca" aria-label="Cerca" maxlength="100">
    <button type="submit" class="button button--secondary">Cerca</button>
    <?php if ($search !== '') : ?>
        <a href="<?= e($path) ?>">Azzera</a>
    <?php endif; ?>
</form>
