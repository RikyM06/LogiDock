<?php
/**
 * @var list<\LogiDock\Model\WarehouseLocation> $items
 * @var string                                  $search
 * @var bool                                    $canManage
 * @var callable                                $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessuna posizione corrisponde alla ricerca.' : 'Non ci sono ancora posizioni di magazzino.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Zona</th>
                    <th>Descrizione</th>
                    <th class="table__number">Capacità (bancali)</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->code) ?></strong></td>
                        <td><?= e($item->zone ?? '') ?></td>
                        <td><?= e($item->description ?? '') ?></td>
                        <td class="table__number">
                            <?= $item->capacity === null ? '<span class="muted">Predefinita</span>' : e($item->capacity) ?>
                        </td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->code]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">«Predefinita» vuol dire che vale la capacità delle <a href="/settings">impostazioni</a>.</p>
<?php endif; ?>
