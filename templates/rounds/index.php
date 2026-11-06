<?php
/**
 * @var list<\LogiDock\Model\Round> $items
 * @var string                      $search
 * @var bool                        $canManage
 * @var callable                    $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun giro corrisponde alla ricerca.' : 'Non ci sono ancora giri.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Descrizione</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->name) ?></strong></td>
                        <td><?= e($item->description ?? '') ?></td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->name]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
