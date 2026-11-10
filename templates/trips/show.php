<?php
/**
 * Dettaglio di un viaggio confermato: il mezzo, il giro e i bancali, tappa per tappa.
 *
 * @var \LogiDock\Model\Trip           $trip
 * @var list<\LogiDock\Model\TripItem> $items  i bancali, cliente per cliente
 * @var string                         $csrfToken
 * @var \LogiDock\Model\User|null      $currentUser
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$day = date('d/m/Y', strtotime($trip->plannedDate));

// I bancali raggruppati per tappa (cliente). Le tappe non sono numerate: in che ordine si fanno
// non lo decide ancora il programma, per ora sono in ordine alfabetico.
$stops = [];
foreach ($items as $item) {
    $stops[$item->customerId]['name'] = $item->customerName;
    $stops[$item->customerId]['items'][] = $item;
}
?>
<div class="page-header">
    <h1>Viaggio <?= e($trip->code) ?></h1>
    <div class="page-header__actions">
        <?php if ($trip->isCancellable()) : ?>
            <form method="post" action="/trips/<?= e($trip->id) ?>/cancel"
                  data-confirm="Annullare il viaggio <?= e($trip->code) ?>? I bancali tornano liberi e le richieste tornano da pianificare.">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button button--danger">Annulla viaggio</button>
            </form>
        <?php endif; ?>
        <a class="button button--secondary" href="/trips/plan?date=<?= e($trip->plannedDate) ?>">Piano del <?= e($day) ?></a>
        <a class="button button--secondary" href="/trips">Tutti i viaggi</a>
    </div>
</div>

<?php if ($trip->status === \LogiDock\Model\Trip::STATUS_CANCELLED) : ?>
    <p class="alert alert--warning" role="status">
        Questo viaggio è stato annullato: i suoi bancali sono tornati liberi. Resta qui solo come storico.
    </p>
<?php endif; ?>

<div class="card">
    <dl class="details">
        <div><dt>Giorno</dt><dd><?= e($day) ?></dd></div>
        <div><dt>Giro</dt><dd><?= e($trip->roundName) ?></dd></div>
        <div><dt>Mezzo</dt><dd><?= e($trip->vehiclePlate) ?> <span class="muted"><?= e($trip->vehicleDescription) ?></span></dd></div>
        <div><dt>Rimorchio</dt><dd><?= $trip->trailerTypeName === null ? '<span class="muted">Non indicato</span>' : e($trip->trailerTypeName) ?></dd></div>
        <div><dt>Trasportatore</dt><dd><?= $trip->carrierName === null ? '<span class="muted">Non indicato</span>' : e($trip->carrierName) ?></dd></div>
        <?php if ($currentUser?->isAdmin() && $trip->carrierFee !== null) : ?>
            <div><dt>Compenso del viaggio</dt><dd><?= e($it($trip->carrierFee)) ?> €</dd></div>
        <?php endif; ?>
        <div><dt>Stato</dt><dd><span class="badge badge--<?= e($trip->status) ?>"><?= e($trip->statusLabel()) ?></span></dd></div>
        <div><dt>Tappe</dt><dd><?= e(count($stops)) ?></dd></div>
    </dl>
</div>

<h2 class="section-title">Carico</h2>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Bancale</th>
                <th>Prodotto</th>
                <th>Tipo</th>
                <th>Richiesta</th>
                <th class="table__number">Quantità</th>
                <th class="table__number">Peso (kg)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stops as $stop) : ?>
                <tr class="table__group">
                    <th colspan="6" scope="colgroup">
                        <?= e($stop['name']) ?> · <?= count($stop['items']) === 1 ? '1 bancale' : e(count($stop['items'])) . ' bancali' ?>
                    </th>
                </tr>
                <?php foreach ($stop['items'] as $item) : ?>
                    <tr>
                        <td class="nowrap"><strong><?= e($item->palletCode) ?></strong></td>
                        <td><?= e($item->productCode) ?> <span class="muted"><?= e($item->productDescription) ?></span></td>
                        <td class="nowrap"><?= e($item->palletTypeName ?? '') ?></td>
                        <td class="nowrap"><a href="/orders/<?= e($item->orderId) ?>"><?= e($item->orderCode) ?></a></td>
                        <td class="table__number nowrap"><?= e($it($item->quantity)) ?> <?= e($item->unitOfMeasure) ?></td>
                        <td class="table__number"><?= e($it($item->weightKg)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4">Totale: <?= $trip->palletCount === 1 ? '1 bancale' : e($trip->palletCount) . ' bancali' ?></th>
                <th></th>
                <th class="table__number"><?= e($it($trip->totalWeightKg)) ?></th>
            </tr>
        </tfoot>
    </table>
</div>
<p class="muted">Quantità e peso sono quelli dei bancali nel momento della conferma.</p>
