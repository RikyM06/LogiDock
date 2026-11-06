<?php
/**
 * @var list<\LogiDock\Model\Vehicle> $items
 * @var string                        $search
 * @var bool                          $canManage
 * @var callable                      $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun veicolo corrisponde alla ricerca.' : 'Non ci sono ancora veicoli.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Targa</th>
                    <th>Descrizione</th>
                    <th>Rimorchio</th>
                    <th>Trasportatore</th>
                    <th class="table__number">Portata (kg)</th>
                    <th class="table__number">Bancali max</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->plate) ?></strong></td>
                        <td><?= e($item->description) ?></td>
                        <td><?= $item->trailerTypeName === null ? '<span class="muted">Non assegnato</span>' : e($item->trailerTypeName) ?></td>
                        <td><?= $item->carrierName === null ? '<span class="muted">Non assegnato</span>' : e($item->carrierName) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->maxWeightKg)) ?></td>
                        <td class="table__number"><?= e($item->maxPallets) ?></td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->plate]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
