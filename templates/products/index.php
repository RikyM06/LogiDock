<?php
/**
 * @var list<\LogiDock\Model\Product> $products
 * @var string                        $search
 * @var bool                          $canManage solo gli admin vedono i pulsanti di modifica
 * @var string                        $csrfToken
 */
?>
<?= $partial('breadcrumb', ['crumbs' => [['Prodotti']]]) ?>
<div class="page-header">
    <h1>Prodotti</h1>
    <?php if ($canManage) : ?>
        <a class="button" href="/products/create">Nuovo prodotto</a>
    <?php endif; ?>
</div>

<form method="get" action="/products" class="toolbar" role="search">
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Cerca per codice o descrizione"
           aria-label="Cerca prodotti" maxlength="100">
    <button type="submit" class="button button--secondary">Cerca</button>
    <?php if ($search !== '') : ?>
        <a href="/products">Azzera</a>
    <?php endif; ?>
</form>

<?php if ($products === []) : ?>
    <p class="card">
        <?= $search !== '' ? 'Nessun prodotto corrisponde alla ricerca.' : 'Non ci sono ancora prodotti.' ?>
    </p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Descrizione</th>
                    <th>UM</th>
                    <th class="table__number">Peso unitario (kg)</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?>
                        <th><span class="visually-hidden">Azioni</span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product) : ?>
                    <tr<?= $product->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($product->code) ?></strong></td>
                        <td><?= e($product->description) ?></td>
                        <td><?= e($product->unitOfMeasure) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $product->unitWeightKg)) ?></td>
                        <td>
                            <span class="badge <?= $product->isActive ? 'badge--ok' : 'badge--off' ?>">
                                <?= $product->isActive ? 'Attivo' : 'Disattivato' ?>
                            </span>
                        </td>
                        <?php if ($canManage) : ?>
                            <td class="table__actions">
                                <a class="button button--small button--secondary"
                                   href="/products/<?= e($product->id) ?>/edit">Modifica</a>

                                <?php if ($product->isActive) : ?>
                                    <form method="post" action="/products/<?= e($product->id) ?>/deactivate"
                                          data-confirm="Disattivare il prodotto <?= e($product->code) ?>?">
                                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                        <button type="submit" class="button button--small button--danger">Disattiva</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post" action="/products/<?= e($product->id) ?>/activate">
                                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                        <button type="submit" class="button button--small button--secondary">Riattiva</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
