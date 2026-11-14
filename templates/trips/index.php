<?php
/**
 * Viaggi: i giorni che hanno ancora qualcosa da pianificare e gli ultimi viaggi confermati.
 *
 * @var list<\LogiDock\Model\PlanningDay> $days   giorni di consegna con richieste da pianificare
 * @var list<\LogiDock\Model\Trip>        $items  ultimi viaggi confermati
 * @var string                            $today  la data di oggi, AAAA-MM-GG
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$date = static fn (string $value): string => date('d/m/Y', strtotime($value));
?>
<?= $partial('breadcrumb', ['crumbs' => [['Viaggi']]]) ?>
<div class="page-header">
    <h1>Viaggi</h1>
</div>
<p class="lead">
    Scegli un giorno di consegna: il programma propone come dividere i bancali sui mezzi, giro per giro.
    Finché non confermi il piano non viene salvato niente.
</p>

<h2 class="section-title">Giorni da pianificare</h2>
<?php if ($days === []) : ?>
    <p class="card">Non ci sono richieste da pianificare.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Giorno di consegna</th>
                    <th class="table__number">Richieste</th>
                    <th class="table__number">Prodotti da pianificare</th>
                    <th><span class="visually-hidden">Azioni</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($days as $day) : ?>
                    <tr>
                        <td class="nowrap">
                            <strong><?= e($date($day->date)) ?></strong>
                            <?php if ($day->date < $today) : ?>
                                <span class="badge badge--aging">Giorno già passato</span>
                            <?php elseif ($day->date === $today) : ?>
                                <span class="badge badge--ok">Oggi</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__number"><?= e($day->orderCount) ?></td>
                        <td class="table__number"><?= e($day->lineCount) ?></td>
                        <td>
                            <div class="table__actions">
                                <a class="button button--small" href="/trips/plan?date=<?= e($day->date) ?>">Pianifica</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="get" action="/trips/plan" class="toolbar toolbar--wrap toolbar--below">
    <label for="plan-date">Oppure apri il piano di un altro giorno:</label>
    <input type="date" id="plan-date" name="date" value="<?= e($today) ?>" required>
    <button type="submit" class="button button--secondary">Apri il piano</button>
</form>

<h2 class="section-title">Viaggi confermati</h2>
<?php if ($items === []) : ?>
    <p class="card">Non ci sono ancora viaggi confermati.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Giorno</th>
                    <th>Giro</th>
                    <th>Mezzo</th>
                    <th>Trasportatore</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Peso (kg)</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr<?= $item->status === \LogiDock\Model\Trip::STATUS_CANCELLED ? ' class="is-inactive"' : '' ?>>
                        <td class="nowrap"><a href="/trips/<?= e($item->id) ?>"><strong><?= e($item->code) ?></strong></a></td>
                        <td class="nowrap"><?= e($date($item->plannedDate)) ?></td>
                        <td><?= e($item->roundName) ?></td>
                        <td><strong><?= e($item->vehiclePlate) ?></strong> <span class="muted"><?= e($item->vehicleDescription) ?></span></td>
                        <td><?= $item->carrierName === null ? '<span class="muted">Non indicato</span>' : e($item->carrierName) ?></td>
                        <td class="table__number"><?= e($item->palletCount) ?></td>
                        <td class="table__number"><?= e($it($item->totalWeightKg)) ?></td>
                        <td><span class="badge badge--<?= e($item->status) ?>"><?= e($item->statusLabel()) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Si vedono gli ultimi 200 viaggi, dal giorno più lontano al più vicino.</p>
<?php endif; ?>
