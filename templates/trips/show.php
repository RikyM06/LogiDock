<?php
/**
 * Dettaglio di un viaggio confermato: il mezzo, il giro e i bancali, tappa per tappa.
 * Finché il viaggio è pianificato si possono fare scelte a mano: spostare o bloccare un bancale,
 * cambiare il mezzo.
 *
 * @var \LogiDock\Model\Trip                 $trip
 * @var list<\LogiDock\Model\TripItem>       $items        i bancali, cliente per cliente
 * @var list<\LogiDock\Model\Trip>           $moveTargets  altri viaggi dello stesso giro e giorno (vuota se non modificabile)
 * @var list<\LogiDock\Model\PlanVehicle>    $freeVehicles mezzi liberi quel giorno (vuota se non modificabile)
 * @var list<\LogiDock\Model\TransportDocument> $documents documenti emessi alla partenza (senza il compenso, se non sei admin)
 * @var string                               $csrfToken
 * @var \LogiDock\Model\User|null            $currentUser
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
// Gli orari nel database sono in UTC: si mostrano nel fuso del programma.
$local = static fn (string $utc, string $format): string => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
    ->format($format);
// Il compenso (importo e pagamento) lo vede solo l'admin.
$isAdmin = $currentUser?->isAdmin() ?? false;
$departed = in_array($trip->status, [\LogiDock\Model\Trip::STATUS_IN_TRANSIT, \LogiDock\Model\Trip::STATUS_COMPLETED], true);
$day = date('d/m/Y', strtotime($trip->plannedDate));
$editable = $trip->isEditable();
$columns = $editable ? 7 : 6;
// Si può togliere un bancale solo se ne resta almeno un altro sul viaggio.
$canMove = $editable && $trip->palletCount > 1 && ($moveTargets !== [] || $freeVehicles !== []);
// Per cambiare mezzo serve un mezzo libero diverso da quello attuale (quelli liberi non lo includono).
$canChangeVehicle = $editable && $freeVehicles !== [];

// I bancali raggruppati per tappa (cliente). Le tappe non sono numerate: in che ordine si fanno
// non lo decide ancora il programma, per ora sono in ordine alfabetico.
$stops = [];
foreach ($items as $item) {
    $stops[$item->customerId]['name'] = $item->customerName;
    $stops[$item->customerId]['items'][] = $item;
}
?>
<?= $partial('breadcrumb', ['crumbs' => [['Viaggi', '/trips'], [$trip->code]]]) ?>
<div class="page-header">
    <h1>Viaggio <?= e($trip->code) ?></h1>
    <div class="page-header__actions">
        <?php if ($trip->canDepart()) : ?>
            <form method="post" action="/trips/<?= e($trip->id) ?>/depart"
                  data-confirm="Far partire il viaggio <?= e($trip->code) ?>? I bancali escono dal magazzino e vengono emessi i documenti. Dopo la partenza il viaggio non si può più modificare né annullare.">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button">Fai partire il viaggio</button>
            </form>
        <?php endif; ?>
        <?php if ($trip->status === \LogiDock\Model\Trip::STATUS_IN_TRANSIT && !$trip->isConcluded()) : ?>
            <form method="post" action="/trips/<?= e($trip->id) ?>/conclude"
                  data-confirm="Segnare concluso il viaggio <?= e($trip->code) ?>? Vuol dire che la merce è stata consegnata.">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <button type="submit" class="button">Segna concluso</button>
            </form>
        <?php endif; ?>
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

<?php if ($trip->status === \LogiDock\Model\Trip::STATUS_IN_TRANSIT) : ?>
    <p class="alert alert--success" role="status">
        Questo viaggio è partito: i bancali sono usciti dal magazzino e non si possono più spostare. I documenti emessi sono qui sotto.
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
        <?php if ($isAdmin && $trip->carrierFee !== null && $departed) : ?>
            <div>
                <dt>Compenso</dt>
                <dd>
                    <?php if ($trip->isCompensationPaid()) : ?>
                        Pagato il <?= e($local((string) $trip->compensationPaidAt, 'd/m/Y')) ?>
                        <form method="post" class="inline-form" action="/trips/<?= e($trip->id) ?>/compensation/unpay"
                              data-confirm="Annullare il pagamento del compenso del viaggio <?= e($trip->code) ?>?">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <button type="submit" class="button button--secondary button--small">Annulla pagamento</button>
                        </form>
                    <?php else : ?>
                        Da pagare
                        <form method="post" class="inline-form" action="/trips/<?= e($trip->id) ?>/compensation/pay"
                              data-confirm="Segnare pagato il compenso di <?= e($it($trip->carrierFee)) ?> € del viaggio <?= e($trip->code) ?>?">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <button type="submit" class="button button--secondary button--small">Segna pagato</button>
                        </form>
                    <?php endif; ?>
                </dd>
            </div>
        <?php endif; ?>
        <div><dt>Stato</dt><dd><span class="badge badge--<?= e($trip->status) ?>"><?= e($trip->statusLabel()) ?></span></dd></div>
        <?php if ($trip->isConcluded()) : ?>
            <div><dt>Concluso il</dt><dd><?= e($local((string) $trip->concludedAt, 'd/m/Y H:i')) ?></dd></div>
        <?php endif; ?>
        <div><dt>Tappe</dt><dd><?= e(count($stops)) ?></dd></div>
        <div><dt>Bloccati a mano</dt><dd><?= e($trip->lockedCount) ?> su <?= e($trip->palletCount) ?></dd></div>
    </dl>
</div>

<h2 class="section-title" id="carico">Carico</h2>
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
                <?php if ($editable) : ?>
                    <th>Scelte a mano</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stops as $stop) : ?>
                <tr class="table__group">
                    <th colspan="<?= e($columns) ?>" scope="colgroup">
                        <?= e($stop['name']) ?> · <?= count($stop['items']) === 1 ? '1 bancale' : e(count($stop['items'])) . ' bancali' ?>
                    </th>
                </tr>
                <?php foreach ($stop['items'] as $item) : ?>
                    <tr>
                        <td class="nowrap">
                            <strong><?= e($item->palletCode) ?></strong>
                            <?php if ($item->isLocked) : ?>
                                <br><span class="badge badge--locked"
                                          title="Bloccato da <?= e($item->lockedByName ?? '') ?> il <?= e(date('d/m/Y H:i', strtotime((string) $item->lockedAt))) ?>">Bloccato a mano</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($item->productCode) ?> <span class="muted"><?= e($item->productDescription) ?></span></td>
                        <td class="nowrap"><?= e($item->palletTypeName ?? '') ?></td>
                        <td class="nowrap"><a href="/orders/<?= e($item->orderId) ?>"><?= e($item->orderCode) ?></a></td>
                        <td class="table__number nowrap"><?= e($it($item->quantity)) ?> <?= e($item->unitOfMeasure) ?></td>
                        <td class="table__number"><?= e($it($item->weightKg)) ?></td>
                        <?php if ($editable) : ?>
                            <td>
                                <div class="table__actions">
                                    <form method="post" class="inline-form"
                                          action="/trips/<?= e($trip->id) ?>/items/<?= e($item->palletId) ?>/lock">
                                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="locked" value="<?= $item->isLocked ? '0' : '1' ?>">
                                        <button type="submit" class="button button--secondary button--small">
                                            <?= $item->isLocked ? 'Sblocca' : 'Blocca' ?>
                                        </button>
                                    </form>
                                    <?php if ($canMove) : ?>
                                        <form method="post" class="inline-form"
                                              action="/trips/<?= e($trip->id) ?>/items/<?= e($item->palletId) ?>/move">
                                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                            <select name="target" required aria-label="Dove spostare il bancale <?= e($item->palletCode) ?>">
                                                <option value="">Sposta su…</option>
                                                <?php if ($moveTargets !== []) : ?>
                                                    <optgroup label="Altro viaggio dello stesso giro">
                                                        <?php foreach ($moveTargets as $target) : ?>
                                                            <option value="trip:<?= e($target->id) ?>"><?= e($target->code) ?> · <?= e($target->vehiclePlate) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                                <?php if ($freeVehicles !== []) : ?>
                                                    <optgroup label="Nuovo viaggio con un mezzo libero">
                                                        <?php foreach ($freeVehicles as $vehicle) : ?>
                                                            <option value="vehicle:<?= e($vehicle->id) ?>"><?= e($vehicle->plate) ?> · <?= e($vehicle->description) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                <?php endif; ?>
                                            </select>
                                            <button type="submit" class="button button--secondary button--small">Sposta</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4">Totale: <?= $trip->palletCount === 1 ? '1 bancale' : e($trip->palletCount) . ' bancali' ?></th>
                <th></th>
                <th class="table__number"><?= e($it($trip->totalWeightKg)) ?></th>
                <?php if ($editable) : ?>
                    <th></th>
                <?php endif; ?>
            </tr>
        </tfoot>
    </table>
</div>
<p class="muted">Quantità e peso sono quelli dei bancali nel momento della conferma.</p>

<?php if ($trip->status !== \LogiDock\Model\Trip::STATUS_CANCELLED) : ?>
    <h2 class="section-title" id="documenti">Documenti</h2>
    <?php if ($documents === []) : ?>
        <p class="muted">
            I documenti escono quando il viaggio parte: il documento di carico per il camion e un DDT con i prezzi per ogni cliente
            (e, per l'admin, il prospetto del compenso del trasportatore).
        </p>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Numero</th>
                        <th>Cliente</th>
                        <th>Emesso il</th>
                        <th>Da</th>
                        <th><span class="visually-hidden">PDF</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $document) : ?>
                        <tr>
                            <td><?= e($document->typeLabel()) ?></td>
                            <td class="nowrap"><strong><?= e($document->code()) ?></strong></td>
                            <td><?= $document->customerName === null ? '<span class="muted">Tutto il viaggio</span>' : e($document->customerName) ?></td>
                            <td class="nowrap"><?= e((new \DateTimeImmutable($document->issuedAt, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i')) ?></td>
                            <td><?= e($document->issuedByName) ?></td>
                            <td>
                                <div class="table__actions">
                                    <a class="button button--secondary button--small" href="/documents/<?= e($document->id) ?>"
                                       target="_blank" rel="noopener">Apri PDF<span class="visually-hidden"> <?= e($document->code()) ?></span></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">I documenti emessi non cambiano più: anche se in seguito cambiano prezzi o dati, il PDF resta quello consegnato.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($editable) : ?>
    <p class="muted">
        <strong>Spostare</strong> un bancale lo <strong>blocca</strong>: il ricalcolo del giorno non lo sposta più e
        riorganizza tutto il resto attorno a lui. Con <strong>Sblocca</strong> torna a disposizione del programma.
        Il programma controlla sempre che il carico entri nel mezzo.
        <?php if ($trip->palletCount < 2) : ?>
            Questo è l'unico bancale del viaggio, quindi non si può spostare (se non serve più, annulla il viaggio).
        <?php endif; ?>
    </p>

    <?php if ($canChangeVehicle) : ?>
        <h2 class="section-title">Cambia mezzo</h2>
        <form method="post" action="/trips/<?= e($trip->id) ?>/vehicle" class="card toolbar toolbar--wrap">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label for="vehicle-id">Mezzo libero il <?= e($day) ?></label>
            <select id="vehicle-id" name="vehicle_id" required>
                <option value="">Scegli il mezzo…</option>
                <?php foreach ($freeVehicles as $vehicle) : ?>
                    <option value="<?= e($vehicle->id) ?>">
                        <?= e($vehicle->plate) ?> · <?= e($vehicle->description) ?> · <?= e($vehicle->trailerTypeName) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button--secondary">Cambia mezzo</button>
            <span class="muted">Trasportatore e compenso diventano quelli del nuovo mezzo. Il carico deve starci.</span>
        </form>
    <?php endif; ?>
<?php endif; ?>
