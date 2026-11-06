<?php
/**
 * Dettaglio di un arrivo.
 *
 * @var \LogiDock\Model\SupplierDocument      $document
 * @var list<\LogiDock\Model\InboundPallet>   $pallets
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$mismatches = count(array_filter($pallets, static fn ($pallet): bool => $pallet->weightMismatch));
?>
<div class="page-header">
    <h1>Arrivo <?= e($document->number) ?></h1>
    <a class="button button--secondary" href="/inbound">Tutti gli arrivi</a>
</div>

<div class="card">
    <dl class="details">
        <div><dt>Fornitore</dt><dd><?= e($document->supplierName) ?></dd></div>
        <div><dt>Data del documento</dt><dd><?= e(date('d/m/Y', strtotime($document->documentDate))) ?></dd></div>
        <div><dt>Data di arrivo</dt><dd><?= e(date('d/m/Y', strtotime($document->receivedDate))) ?></dd></div>
        <div><dt>Bancali</dt><dd><?= e($document->palletCount) ?></dd></div>
        <div><dt>Valore</dt><dd><?= e($it($document->totalValue)) ?> €</dd></div>
        <div><dt>Peso segnato</dt><dd><?= e($it($document->totalRecordedWeightKg)) ?> kg</dd></div>
    </dl>
    <?php if ($document->notes !== null) : ?>
        <p class="muted"><?= e($document->notes) ?></p>
    <?php endif; ?>
</div>

<?php if ($mismatches > 0) : ?>
    <p class="alert alert--warning" role="status">
        <?= $mismatches === 1 ? '1 bancale ha' : e($mismatches) . ' bancali hanno' ?> un peso segnato diverso da quello
        calcolato (quantità × peso unitario) oltre la tolleranza delle <a href="/settings">impostazioni</a>.
    </p>
<?php endif; ?>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Codice</th>
                <th>Prodotto</th>
                <th>Tipo</th>
                <th class="table__number">Quantità</th>
                <th class="table__number">Prezzo (€)</th>
                <th class="table__number">Valore (€)</th>
                <th class="table__number">Peso segnato (kg)</th>
                <th class="table__number">Peso calcolato (kg)</th>
                <th>Lotto</th>
                <th>Scadenza</th>
                <th>Posizione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pallets as $pallet) : ?>
                <tr<?= $pallet->weightMismatch ? ' class="is-warning"' : '' ?>>
                    <td class="nowrap"><strong><?= e($pallet->code) ?></strong></td>
                    <td><?= e($pallet->productCode) ?> <span class="muted"><?= e($pallet->productDescription) ?></span></td>
                    <td class="nowrap"><?= e($pallet->palletTypeName ?? '') ?></td>
                    <td class="table__number nowrap"><?= e($it($pallet->quantity)) ?> <?= e($pallet->unitOfMeasure) ?></td>
                    <td class="table__number"><?= e($it($pallet->unitPrice ?? '')) ?></td>
                    <td class="table__number"><?= e($it($pallet->lineValue ?? '')) ?></td>
                    <td class="table__number"><?= e($it($pallet->recordedWeightKg ?? '')) ?></td>
                    <td class="table__number"><?= e($it($pallet->computedWeightKg)) ?></td>
                    <td><?= e($pallet->lotNumber ?? '') ?></td>
                    <td><?= $pallet->expiryDate === null ? '' : e(date('d/m/Y', strtotime($pallet->expiryDate))) ?></td>
                    <td><?= e($pallet->locationCode ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
