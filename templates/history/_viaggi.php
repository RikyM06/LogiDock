<?php
/**
 * Scheda Viaggi. Il compenso (importo e pagamento) esiste solo per l'admin: l'operatore non ha le colonne.
 *
 * @var list<\LogiDock\Model\History\TripHistoryRow> $trips
 */
?>
<?php if ($trips === []) : ?>
    <p class="card"><?= $filtered ? 'Nessun viaggio corrisponde ai filtri.' : 'Non ci sono ancora viaggi.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Viaggio</th>
                    <th>Giorno</th>
                    <th>Giro</th>
                    <th>Mezzo</th>
                    <th>Trasportatore</th>
                    <th>Stato</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Clienti</th>
                    <th class="table__number">Fatturato</th>
                    <?php if ($isAdmin) : ?>
                        <th class="table__number">Compenso</th>
                        <th>Compenso pagato</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trips as $trip) : ?>
                    <tr>
                        <td class="nowrap"><a href="/trips/<?= e($trip->id) ?>"><strong><?= e($trip->code) ?></strong></a></td>
                        <td class="nowrap"><?= e($date($trip->plannedDate)) ?></td>
                        <td><?= e($trip->roundName) ?></td>
                        <td class="nowrap"><?= e($trip->vehiclePlate) ?></td>
                        <td><?= $trip->carrierName === null ? '<span class="muted">-</span>' : e($trip->carrierName) ?></td>
                        <td><span class="badge badge--<?= e($trip->status) ?>"><?= e($trip->statusLabel()) ?></span></td>
                        <td class="table__number"><?= e($trip->palletCount) ?></td>
                        <td class="table__number"><?= e($trip->customerCount) ?></td>
                        <td class="table__number nowrap"><?= $trip->billedTotal === null ? '<span class="muted">-</span>' : e($it($trip->billedTotal)) . ' €' ?></td>
                        <?php if ($isAdmin) : ?>
                            <td class="table__number nowrap"><?= $trip->fee === null ? '<span class="muted">-</span>' : e($it($trip->fee)) . ' €' ?></td>
                            <td class="nowrap">
                                <?php if ($trip->fee === null) : ?>
                                    <span class="muted">-</span>
                                <?php elseif ($trip->compensationPaidAt !== null) : ?>
                                    <?= e($local($trip->compensationPaidAt, 'd/m/Y')) ?>
                                <?php else : ?>
                                    <span class="muted">Non ancora</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($trips) >= $listLimit) : ?>
        <p class="muted">Mostrati i primi <?= e($listLimit) ?> viaggi: restringi il periodo per vedere gli altri.</p>
    <?php endif; ?>
<?php endif; ?>
