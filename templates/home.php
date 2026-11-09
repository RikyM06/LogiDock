<?php
/**
 * @var string                              $appName
 * @var \LogiDock\Service\AgingRule         $rule
 * @var int                                 $agingCount    bancali fermi oltre la soglia
 * @var list<\LogiDock\Model\StockPallet>   $agingPallets  i più vecchi, al massimo 5
 */
$days = static fn (int $count): string => $count === 1 ? '1 giorno' : $count . ' giorni';
$threshold = $days($rule->thresholdDays);
?>
<h1>Benvenuto in <?= e($appName) ?></h1>
<p class="lead">Le funzionalità arrivano una alla volta. Quelle già pronte sono qui sotto.</p>

<?php if ($agingCount > 0) : ?>
    <div class="alert alert--warning" role="status">
        <strong><?= $agingCount === 1 ? '1 bancale è fermo' : e($agingCount) . ' bancali sono fermi' ?> in magazzino
            da più di <?= e($threshold) ?>.</strong>
        <?= $agingCount > count($agingPallets) ? 'I più vecchi:' : '' ?>
        <ul class="aging-list">
            <?php foreach ($agingPallets as $pallet) : ?>
                <li>
                    <strong><?= e($pallet->code) ?></strong> · <?= e($pallet->productCode) ?> <?= e($pallet->productDescription) ?>
                    · <?= e($days($rule->daysInStock($pallet->receivedAt))) ?>
                    <?= $pallet->locationCode !== null ? '· posizione ' . e($pallet->locationCode) : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <a href="/stock?aging=1#bancali">Vedi tutti i bancali fermi</a>
    </div>
<?php else : ?>
    <p class="alert alert--success" role="status">Nessun bancale è fermo in magazzino da più di <?= e($threshold) ?>.</p>
<?php endif; ?>

<div class="card">
    <h2>Moduli</h2>
    <ul class="list">
        <li><a href="/inbound">Arrivi</a>: documento del fornitore e bancali, con prezzo, peso e posizione</li>
        <li><a href="/stock">Giacenze</a>: cosa c'è in magazzino, per prodotto e per bancale, con l'avviso dei bancali fermi</li>
        <li><a href="/products">Prodotti</a></li>
        <li><a href="/master-data">Anagrafiche</a>: fornitori, clienti, giri, veicoli, rimorchi, posizioni, tipi di bancale</li>
        <li><a href="/orders">Richieste</a>: cosa chiede ogni cliente, con quantità, prezzi e data di consegna</li>
        <li>Pianificazione viaggi <span class="muted">(in arrivo)</span></li>
        <li>Bolle e DDT <span class="muted">(in arrivo)</span></li>
    </ul>
</div>
