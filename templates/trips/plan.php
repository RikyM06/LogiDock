<?php
/**
 * Il piano dei viaggi di un giorno, calcolato in questo momento (niente è salvato finché non si conferma).
 *
 * @var \LogiDock\Model\TripPlan   $plan
 * @var list<\LogiDock\Model\Trip> $confirmedTrips             viaggi già confermati per quel giorno
 * @var int                        $vehiclesWithoutTrailerType mezzi attivi che il programma non può usare
 * @var string                     $csrfToken
 * @var \LogiDock\Model\User|null  $currentUser
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$date = static fn (string $value): string => date('d/m/Y', strtotime($value));
// Da millimetri a metri con due decimali, solo con numeri interi (7200 -> "7,20").
$metres = static fn (int $mm): string => sprintf('%d,%02d', intdiv($mm, 1000), intdiv($mm % 1000, 10));
// "0.000" -> false: serve a non mostrare le quantità a zero.
$positive = static fn (string $decimal): bool => trim($decimal, '0.') !== '';
$pallets = static fn (int $count): string => $count === 1 ? '1 bancale' : $count . ' bancali';

$day = $date($plan->date);
$tripCount = count($plan->trips);

// C'è almeno un bancale senza tipo registrato? Serve per la nota sotto le tabelle.
$anyAssumed = false;
foreach ($plan->trips as $trip) {
    foreach ($trip->assignments as $assignment) {
        $anyAssumed = $anyAssumed || $assignment->pallet->typeAssumed;
    }
}
foreach ($plan->leftovers as $leftover) {
    $anyAssumed = $anyAssumed || $leftover->assignment->pallet->typeAssumed;
}
?>
<div class="page-header">
    <h1>Piano dei viaggi del <?= e($day) ?></h1>
    <div class="page-header__actions">
        <a class="button button--secondary" href="/trips">Tutti i viaggi</a>
    </div>
</div>

<form method="get" action="/trips/plan" class="toolbar toolbar--wrap">
    <label for="plan-date">Giorno di consegna</label>
    <input type="date" id="plan-date" name="date" value="<?= e($plan->date) ?>" required>
    <button type="submit" class="button button--secondary">Mostra il piano</button>
</form>

<?php if ($confirmedTrips !== []) : ?>
    <h2 class="section-title">Viaggi già confermati per questo giorno</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Giro</th>
                    <th>Mezzo</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Peso (kg)</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($confirmedTrips as $confirmed) : ?>
                    <tr>
                        <td class="nowrap"><a href="/trips/<?= e($confirmed->id) ?>"><strong><?= e($confirmed->code) ?></strong></a></td>
                        <td><?= e($confirmed->roundName) ?></td>
                        <td><strong><?= e($confirmed->vehiclePlate) ?></strong> <span class="muted"><?= e($confirmed->vehicleDescription) ?></span></td>
                        <td class="table__number"><?= e($confirmed->palletCount) ?></td>
                        <td class="table__number"><?= e($it($confirmed->totalWeightKg)) ?></td>
                        <td><span class="badge badge--<?= e($confirmed->status) ?>"><?= e($confirmed->statusLabel()) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($plan->coverage === []) : ?>
    <p class="card card--spaced">
        <?= $confirmedTrips !== []
            ? 'Per questo giorno è già tutto pianificato: non resta niente da caricare.'
            : 'Per questo giorno non ci sono richieste da pianificare.' ?>
    </p>
<?php else : ?>
    <h2 class="section-title">Viaggi proposti</h2>
    <p class="muted">
        Calcolato adesso con le richieste, i bancali e i mezzi di questo momento: i bancali arrivati prima
        escono per primi e ogni bancale parte intero.
    </p>

    <?php if ($vehiclesWithoutTrailerType > 0) : ?>
        <p class="alert alert--warning" role="status">
            <?= $vehiclesWithoutTrailerType === 1
                ? '1 mezzo attivo non ha un tipo di rimorchio'
                : e($vehiclesWithoutTrailerType) . ' mezzi attivi non hanno un tipo di rimorchio' ?>:
            il programma non li usa, perché senza le misure del pianale non può sapere cosa ci sta.
            <?php if ($currentUser?->isAdmin()) : ?>
                <a href="/vehicles">Completa i veicoli</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (!$plan->hasTrips()) : ?>
        <p class="alert alert--warning" role="status">
            Con i dati di adesso non c'è nessun viaggio da proporre. Qui sotto vedi, richiesta per richiesta, il motivo.
        </p>
    <?php endif; ?>

    <?php foreach ($plan->trips as $trip) : ?>
        <?php $usage = $trip->usage; ?>
        <section class="card trip-card">
            <h3 class="trip-card__title">
                <?= e($trip->roundName) ?> · <?= e($trip->vehicle->plate) ?>
                <span class="muted"><?= e($trip->vehicle->description) ?> · <?= e($trip->vehicle->trailerTypeName) ?></span>
            </h3>
            <p class="trip-card__stops">
                <strong>Tappe:</strong> <?= e(implode(', ', $trip->stops())) ?>
                <?php if ($trip->vehicle->carrierName !== null) : ?>
                    <span class="muted">· Trasportatore: <?= e($trip->vehicle->carrierName) ?></span>
                <?php endif; ?>
            </p>

            <div class="meters">
                <div class="meter">
                    <span class="meter__label">Bancali</span>
                    <progress aria-label="Bancali caricati" value="<?= e($usage->palletCount) ?>" max="<?= e($usage->maxPallets) ?>"></progress>
                    <span class="meter__value"><?= e($usage->palletCount) ?> su <?= e($usage->maxPallets) ?></span>
                </div>
                <div class="meter">
                    <span class="meter__label">Pianale occupato</span>
                    <progress aria-label="Pianale occupato" value="<?= e($usage->usedLengthMm) ?>" max="<?= e($usage->availableLengthMm) ?>"></progress>
                    <span class="meter__value"><?= e($metres($usage->usedLengthMm)) ?> m su <?= e($metres($usage->availableLengthMm)) ?> m</span>
                </div>
                <div class="meter">
                    <span class="meter__label">Peso</span>
                    <progress aria-label="Peso caricato" value="<?= e($usage->weightKg) ?>" max="<?= e($usage->maxWeightKg) ?>"></progress>
                    <span class="meter__value"><?= e($it($usage->weightKg)) ?> kg su <?= e($it($usage->maxWeightKg)) ?> kg</span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Richiesta</th>
                            <th>Bancale</th>
                            <th>Prodotto</th>
                            <th>Tipo</th>
                            <th class="table__number">Quantità</th>
                            <th class="table__number">Peso (kg)</th>
                            <th>Arrivato il</th>
                            <th>Posizione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trip->assignments as $assignment) : ?>
                            <?php $pallet = $assignment->pallet; ?>
                            <tr>
                                <td><?= e($assignment->demand->customerName) ?></td>
                                <td class="nowrap"><a href="/orders/<?= e($assignment->demand->orderId) ?>"><?= e($assignment->demand->orderCode) ?></a></td>
                                <td class="nowrap"><strong><?= e($pallet->code) ?></strong></td>
                                <td><?= e($pallet->productCode) ?> <span class="muted"><?= e($pallet->productDescription) ?></span></td>
                                <td class="nowrap"><?= e($pallet->palletTypeName) ?><?= $pallet->typeAssumed ? ' <span class="muted">(presunto)</span>' : '' ?></td>
                                <td class="table__number nowrap"><?= e($it($pallet->quantity)) ?> <?= e($pallet->unitOfMeasure) ?></td>
                                <td class="table__number"><?= e($it($pallet->weightKg)) ?></td>
                                <td class="nowrap"><?= e($date($pallet->receivedAt)) ?></td>
                                <td class="nowrap"><?= e($pallet->locationCode ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($plan->hasTrips()) : ?>
        <form method="post" action="/trips/plan" class="card trip-confirm"
              data-confirm="Confermare <?= $tripCount === 1 ? 'il viaggio' : 'i ' . e($tripCount) . ' viaggi' ?> del <?= e($day) ?>? I bancali verranno prenotati.">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="date" value="<?= e($plan->date) ?>">
            <input type="hidden" name="fingerprint" value="<?= e($plan->fingerprint()) ?>">
            <p>
                Se il piano va bene, confermalo: <?= $tripCount === 1 ? 'il viaggio diventa vero' : 'i viaggi diventano veri' ?>,
                i bancali vengono <strong>prenotati</strong> e le richieste passano a <strong>pianificate</strong>.
                Un viaggio confermato si può ancora annullare finché non è partito.
            </p>
            <button type="submit" class="button">
                <?= $tripCount === 1 ? 'Conferma il viaggio' : 'Conferma i ' . e($tripCount) . ' viaggi' ?>
            </button>
        </form>
    <?php endif; ?>

    <h2 class="section-title" id="richieste">Richieste del giorno</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Giro</th>
                    <th>Richiesta</th>
                    <th>Prodotto</th>
                    <th class="table__number">Richiesto</th>
                    <th class="table__number">Parte con questo piano</th>
                    <th>Esito</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plan->coverage as $row) : ?>
                    <?php $demand = $row->demand; ?>
                    <tr<?= $row->isCovered() ? '' : ' class="is-warning"' ?>>
                        <td><?= e($demand->customerName) ?></td>
                        <td><?= $demand->roundName === null ? '<span class="muted">Non assegnato</span>' : e($demand->roundName) ?></td>
                        <td class="nowrap"><a href="/orders/<?= e($demand->orderId) ?>"><?= e($demand->orderCode) ?></a></td>
                        <td><?= e($demand->productCode) ?> <span class="muted"><?= e($demand->productDescription) ?></span></td>
                        <td class="table__number nowrap">
                            <?= e($it($demand->quantity)) ?> <?= e($demand->unitOfMeasure) ?>
                            <?php if ($positive($demand->plannedQuantity)) : ?>
                                <br><span class="muted">già su altri viaggi: <?= e($it($demand->plannedQuantity)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="table__number nowrap">
                            <?php if ($row->loadedPallets > 0) : ?>
                                <?= e($it($row->loadedQuantity)) ?> <?= e($demand->unitOfMeasure) ?>
                                <br><span class="muted"><?= e($pallets($row->loadedPallets)) ?></span>
                            <?php else : ?>
                                <span class="muted">Niente</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$row->plannable) : ?>
                                <span class="badge badge--off">Cliente senza giro</span>
                            <?php elseif ($row->isCovered()) : ?>
                                <span class="badge badge--ok">Coperta</span>
                            <?php endif; ?>
                            <?php if ($row->hasMissing()) : ?>
                                <span class="badge badge--aging">Manca <?= e($it($row->missingQuantity)) ?> <?= e($demand->unitOfMeasure) ?></span>
                            <?php endif; ?>
                            <?php if ($row->hasNotLoaded()) : ?>
                                <span class="badge badge--aging">Resta in magazzino: <?= e($pallets($row->notLoadedPallets)) ?></span>
                            <?php endif; ?>
                            <?php if ($row->hasSurplus()) : ?>
                                <span class="badge badge--reserved">In più <?= e($it($row->surplusQuantity)) ?> <?= e($demand->unitOfMeasure) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">
        <strong>Manca</strong> = in magazzino non c'è abbastanza merce libera.
        <strong>Resta in magazzino</strong> = la merce c'è ma non entra nei mezzi liberi.
        <strong>In più</strong> = i bancali partono interi, quindi può partire un po' più del richiesto.
        <strong>Cliente senza giro</strong> = assegna un giro al cliente per poterlo pianificare.
    </p>

    <?php if ($plan->leftovers !== []) : ?>
        <h2 class="section-title">Bancali che restano in magazzino</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Bancale</th>
                        <th>Prodotto</th>
                        <th>Tipo</th>
                        <th class="table__number">Quantità</th>
                        <th class="table__number">Peso (kg)</th>
                        <th>Sarebbe per</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plan->leftovers as $leftover) : ?>
                        <?php $pallet = $leftover->assignment->pallet; ?>
                        <tr>
                            <td class="nowrap"><strong><?= e($pallet->code) ?></strong></td>
                            <td><?= e($pallet->productCode) ?> <span class="muted"><?= e($pallet->productDescription) ?></span></td>
                            <td class="nowrap"><?= e($pallet->palletTypeName) ?><?= $pallet->typeAssumed ? ' <span class="muted">(presunto)</span>' : '' ?></td>
                            <td class="table__number nowrap"><?= e($it($pallet->quantity)) ?> <?= e($pallet->unitOfMeasure) ?></td>
                            <td class="table__number"><?= e($it($pallet->weightKg)) ?></td>
                            <td>
                                <?= e($leftover->assignment->demand->customerName) ?>
                                <a href="/orders/<?= e($leftover->assignment->demand->orderId) ?>"><?= e($leftover->assignment->demand->orderCode) ?></a>
                            </td>
                            <td><?= e($leftover->reasonLabel()) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($anyAssumed) : ?>
        <p class="muted">
            <strong>(presunto)</strong> = sul bancale non è registrato il tipo: per prudenza il programma lo conta
            come il tipo di bancale più grande.
        </p>
    <?php endif; ?>
<?php endif; ?>
