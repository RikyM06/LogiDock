<?php
/**
 * @var list<\LogiDock\Model\Carrier> $items
 * @var string                        $search
 * @var bool                          $canManage
 * @var callable                      $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun trasportatore corrisponde alla ricerca.' : 'Non ci sono ancora trasportatori.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>P. IVA / C. F.</th>
                    <th>Telefono</th>
                    <th class="table__number">Compenso per viaggio (€)</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->name) ?></strong></td>
                        <td><?= e($item->taxCode ?? '') ?></td>
                        <td><?= e($item->phone ?? '') ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->tripFee)) ?></td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->name]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
