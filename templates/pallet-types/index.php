<?php
/**
 * @var list<\LogiDock\Model\PalletType> $items
 * @var string                           $search
 * @var bool                             $canManage
 * @var callable                         $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun tipo di bancale corrisponde alla ricerca.' : 'Non ci sono ancora tipi di bancale.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Nome</th>
                    <th class="table__number">Lunghezza (mm)</th>
                    <th class="table__number">Larghezza (mm)</th>
                    <th class="table__number">Altezza massima (mm)</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->code) ?></strong></td>
                        <td><?= e($item->name) ?></td>
                        <td class="table__number"><?= e($item->lengthMm) ?></td>
                        <td class="table__number"><?= e($item->widthMm) ?></td>
                        <td class="table__number">
                            <?= $item->maxHeightMm === null ? '<span class="muted">Predefinita</span>' : e($item->maxHeightMm) ?>
                        </td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->code]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">«Predefinita» vuol dire che vale l'altezza massima delle <a href="/settings">impostazioni</a>.</p>
<?php endif; ?>
