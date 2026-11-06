<?php
/**
 * @var list<\LogiDock\Model\TrailerType> $items
 * @var string                            $search
 * @var bool                              $canManage
 * @var callable                          $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun tipo di rimorchio corrisponde alla ricerca.' : 'Non ci sono ancora tipi di rimorchio.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th class="table__number">Lunghezza (mm)</th>
                    <th class="table__number">Larghezza (mm)</th>
                    <th class="table__number">Altezza interna (mm)</th>
                    <th class="table__number">Portata (kg)</th>
                    <th>Stato</th>
                    <?php if ($canManage) : ?><th><span class="visually-hidden">Azioni</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->isActive ? '' : ' class="is-inactive"' ?>>
                        <td><strong><?= e($item->name) ?></strong></td>
                        <td class="table__number"><?= e($item->lengthMm) ?></td>
                        <td class="table__number"><?= e($item->widthMm) ?></td>
                        <td class="table__number"><?= e($item->innerHeightMm) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->maxWeightKg)) ?></td>
                        <?= $partial('row_actions', ['item' => $item, 'itemName' => $item->name]) ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
