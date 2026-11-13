<?php
/**
 * Scheda Fornitori: merce ricevuta per fornitore e prodotto (righe piatte, raggruppate qui per fornitore).
 * La quantità è quella ricevuta, quindi non cala quando i bancali partono.
 *
 * @var list<\LogiDock\Model\History\SupplierHistoryRow> $suppliers
 */
?>
<?php if ($suppliers === []) : ?>
    <p class="card"><?= $filtered ? 'Nessun arrivo corrisponde ai filtri.' : 'Non ci sono ancora arrivi di merce.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Fornitore</th>
                    <th>Prodotto</th>
                    <th class="table__number">Documenti</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Quantità ricevuta</th>
                    <th class="table__number">Valore d'acquisto</th>
                </tr>
            </thead>
            <tbody>
                <?php $previousSupplier = null; ?>
                <?php foreach ($suppliers as $row) : ?>
                    <tr>
                        <td>
                            <?php if ($row->supplierId !== $previousSupplier) : ?>
                                <strong><?= e($row->supplierName) ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row->productCode) ?> <span class="muted"><?= e($row->productDescription) ?></span></td>
                        <td class="table__number"><?= e($row->documentCount) ?></td>
                        <td class="table__number"><?= e($row->palletCount) ?></td>
                        <td class="table__number nowrap"><?= e($it($row->quantity)) ?> <?= e($row->unitOfMeasure) ?></td>
                        <td class="table__number nowrap"><?= e($it($row->purchaseValue)) ?> €</td>
                    </tr>
                    <?php $previousSupplier = $row->supplierId; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($suppliers) >= $listLimit) : ?>
        <p class="muted">Mostrate le prime <?= e($listLimit) ?> righe: restringi il periodo o scegli un fornitore.</p>
    <?php endif; ?>
<?php endif; ?>
