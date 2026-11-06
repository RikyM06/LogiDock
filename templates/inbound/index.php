<?php
/**
 * @var list<\LogiDock\Model\SupplierDocument> $items
 * @var string                                 $search
 * @var callable                               $partial
 */
?>
<?= $partial('list_header') ?>

<?php if ($items === []) : ?>
    <p class="card"><?= $search !== '' ? 'Nessun arrivo corrisponde alla ricerca.' : 'Non ci sono ancora arrivi registrati.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Arrivo</th>
                    <th>Documento</th>
                    <th>Fornitore</th>
                    <th>Data documento</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Valore (€)</th>
                    <th class="table__number">Peso segnato (kg)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td><?= e(date('d/m/Y', strtotime($item->receivedDate))) ?></td>
                        <td><a href="/inbound/<?= e($item->id) ?>"><strong><?= e($item->number) ?></strong></a></td>
                        <td><?= e($item->supplierName) ?></td>
                        <td><?= e(date('d/m/Y', strtotime($item->documentDate))) ?></td>
                        <td class="table__number"><?= e($item->palletCount) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->totalValue)) ?></td>
                        <td class="table__number"><?= e(str_replace('.', ',', $item->totalRecordedWeightKg)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Si vedono gli ultimi 200 arrivi; la ricerca cerca per numero del documento o fornitore.</p>
<?php endif; ?>
