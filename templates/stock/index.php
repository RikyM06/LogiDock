<?php
/**
 * Giacenze: avviso dei bancali fermi, riepilogo per prodotto e lista dei bancali con i filtri.
 *
 * @var \LogiDock\Service\AgingRule                               $rule
 * @var list<\LogiDock\Model\StockProductSummary>                 $summary
 * @var list<\LogiDock\Model\StockPallet>                         $pallets
 * @var int                                                       $agingCount
 * @var int                                                       $listLimit
 * @var array<string, string>                                     $productOptions   id => nome
 * @var array<string, string>                                     $locationOptions  id => codice
 * @var array{product: int|null, location: int|null, aging: bool} $filters
 * @var \LogiDock\Model\User|null                                 $currentUser
 */
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$date = static fn (string $value): string => date('d/m/Y', strtotime($value));
$days = static fn (int $count): string => $count === 1 ? '1 giorno' : $count . ' giorni';
$threshold = $days($rule->thresholdDays);
$filtered = $filters['product'] !== null || $filters['location'] !== null || $filters['aging'];
$totalPallets = array_sum(array_map(static fn ($row): int => $row->palletCount, $summary));
?>
<?= $partial('breadcrumb', ['crumbs' => [['Giacenze']]]) ?>
<div class="page-header">
    <h1>Giacenze</h1>
</div>
<p class="lead">
    Cosa c'è adesso in magazzino. Un bancale è <strong>fermo</strong> quando è in magazzino da più di
    <?= e($threshold) ?>, contati dalla data di arrivo<?php if ($currentUser?->isAdmin()) : ?>
    (la soglia si cambia nelle <a href="/settings">impostazioni</a>)<?php endif; ?>.
</p>

<?php if ($agingCount > 0) : ?>
    <p class="alert alert--warning" role="status">
        <strong><?= $agingCount === 1 ? '1 bancale è fermo' : e($agingCount) . ' bancali sono fermi' ?></strong>
        da più di <?= e($threshold) ?>.
        <?php if (!$filters['aging']) : ?>
            <a href="/stock?aging=1#bancali">Mostra solo questi</a>
        <?php endif; ?>
    </p>
<?php endif; ?>

<h2 class="section-title">Per prodotto</h2>
<?php if ($summary === []) : ?>
    <p class="card">Il magazzino è vuoto: non ci sono bancali in giacenza.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Prodotto</th>
                    <th class="table__number">Bancali</th>
                    <th class="table__number">Quantità</th>
                    <th>Arrivo più vecchio</th>
                    <th class="table__number">Fermi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $row) : ?>
                    <tr>
                        <td>
                            <a href="/stock?product=<?= e($row->productId) ?>#bancali"><strong><?= e($row->productCode) ?></strong></a>
                            <span class="muted"><?= e($row->productDescription) ?></span>
                        </td>
                        <td class="table__number"><?= e($row->palletCount) ?></td>
                        <td class="table__number nowrap"><?= e($it($row->totalQuantity)) ?> <?= e($row->unitOfMeasure) ?></td>
                        <td class="nowrap"><?= e($date($row->oldestReceivedAt)) ?> <span class="muted">(<?= e($days($rule->daysInStock($row->oldestReceivedAt))) ?>)</span></td>
                        <td class="table__number">
                            <?php if ($row->agingCount > 0) : ?>
                                <a class="badge badge--aging" href="/stock?product=<?= e($row->productId) ?>&amp;aging=1#bancali"><?= e($row->agingCount) ?></a>
                            <?php else : ?>
                                <span class="muted">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Totale</th>
                    <th class="table__number"><?= e($totalPallets) ?></th>
                    <th colspan="2"></th>
                    <th class="table__number"><?= e($agingCount) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<h2 class="section-title" id="bancali">Bancali in magazzino</h2>
<form method="get" action="/stock#bancali" class="toolbar toolbar--wrap" role="search">
    <select name="product" aria-label="Prodotto">
        <option value="">Tutti i prodotti</option>
        <?php foreach ($productOptions as $value => $label) : ?>
            <option value="<?= e($value) ?>"<?= (string) $filters['product'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="location" aria-label="Posizione">
        <option value="">Tutte le posizioni</option>
        <?php foreach ($locationOptions as $value => $label) : ?>
            <option value="<?= e($value) ?>"<?= (string) $filters['location'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="toolbar__check">
        <input type="checkbox" name="aging" value="1"<?= $filters['aging'] ? ' checked' : '' ?>>
        Solo fermi da più di <?= e($threshold) ?>
    </label>
    <button type="submit" class="button button--secondary">Filtra</button>
    <?php if ($filtered) : ?>
        <a href="/stock#bancali">Azzera</a>
    <?php endif; ?>
</form>

<?php if ($pallets === []) : ?>
    <p class="card"><?= $filtered ? 'Nessun bancale corrisponde ai filtri.' : 'Non ci sono bancali in magazzino.' ?></p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Prodotto</th>
                    <th>Tipo</th>
                    <th class="table__number">Quantità</th>
                    <th>Lotto</th>
                    <th>Scadenza</th>
                    <th>Posizione</th>
                    <th>Arrivo</th>
                    <th>Arrivato il</th>
                    <th>In magazzino da</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pallets as $pallet) : ?>
                    <?php $isAging = $rule->isAging($pallet->receivedAt); ?>
                    <tr<?= $isAging ? ' class="is-warning"' : '' ?>>
                        <td class="nowrap">
                            <strong><?= e($pallet->code) ?></strong>
                            <?php if ($pallet->isReserved()) : ?>
                                <span class="badge badge--reserved">Prenotato</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($pallet->productCode) ?> <span class="muted"><?= e($pallet->productDescription) ?></span></td>
                        <td class="nowrap"><?= e($pallet->palletTypeName ?? '') ?></td>
                        <td class="table__number nowrap"><?= e($it($pallet->quantity)) ?> <?= e($pallet->unitOfMeasure) ?></td>
                        <td><?= e($pallet->lotNumber ?? '') ?></td>
                        <td class="nowrap"><?= $pallet->expiryDate === null ? '' : e($date($pallet->expiryDate)) ?></td>
                        <td class="nowrap"><?= e($pallet->locationCode ?? '') ?></td>
                        <td>
                            <?php if ($pallet->supplierDocumentId !== null) : ?>
                                <a href="/inbound/<?= e($pallet->supplierDocumentId) ?>"><?= e($pallet->documentNumber ?? '') ?></a><br>
                            <?php endif; ?>
                            <span class="muted"><?= e($pallet->supplierName ?? '') ?></span>
                        </td>
                        <td class="nowrap"><?= e($date($pallet->receivedAt)) ?></td>
                        <td class="nowrap">
                            <?= e($days($rule->daysInStock($pallet->receivedAt))) ?>
                            <?php if ($isAging) : ?>
                                <span class="badge badge--aging">Fermo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Dal bancale arrivato da più tempo; si vedono al massimo <?= e($listLimit) ?> bancali.</p>
<?php endif; ?>
