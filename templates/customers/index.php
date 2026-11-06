<?php
/**
 * @var list<\LogiDock\Model\Customer> $items
 * @var string                         $search
 * @var bool                           $canManage
 * @var callable                       $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun cliente corrisponde alla ricerca.' : 'Non ci sono ancora clienti.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Ragione sociale</th>
                    <th>Giro</th>
                    <th>Indirizzo</th>
                    <th>Telefono</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->name) ?></strong></td>
                        <td><?= $item->roundName === null ? '<span class="muted">Non assegnato</span>' : e($item->roundName) ?></td>
                        <td><?= e($item->address ?? '') ?></td>
                        <td><?= e($item->phone ?? '') ?></td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->name]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
