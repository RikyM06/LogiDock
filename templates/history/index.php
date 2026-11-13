<?php
/**
 * Storico: quattro schede (link) con i loro filtri GET. Ogni scheda ha un template "_nome.php" qui accanto.
 *
 * @var string                             $tab         viaggi|fornitori|incassi|documenti
 * @var array<string, string>              $tabs        scheda => nome
 * @var array<string, mixed>               $filters     filtri normalizzati (from, to, round, status, ...)
 * @var string                             $currentUrl  URL della scheda con i suoi filtri (per i form "return")
 * @var int                                $listLimit
 * @var array<string, string>|null         $roundOptions
 * @var array<string, string>|null         $supplierOptions
 * @var array<string, string>|null         $customerOptions
 * @var string                             $csrfToken
 * @var \LogiDock\Model\User|null          $currentUser
 */
$isAdmin = $currentUser?->isAdmin() === true;
$it = static fn (string $decimal): string => str_replace('.', ',', $decimal);
$date = static fn (string $value): string => date('d/m/Y', strtotime($value));
// Gli orari nel database sono in UTC: si mostrano nel fuso del programma.
$local = static fn (string $utc, string $format): string => (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
    ->format($format);

// Che cosa indica il periodo "dal / al" in ogni scheda.
$periodLabels = [
    'viaggi' => 'Giorno del viaggio',
    'fornitori' => 'Data di ricevimento',
    'incassi' => 'Consegna della richiesta',
    'documenti' => 'Data di emissione',
];
$typeOptions = ['load_list' => 'Documento di carico', 'ddt' => 'DDT'];
if ($isAdmin) {
    $typeOptions['compensation'] = 'Compenso del trasportatore';
}

$filtered = false;
foreach ($filters as $key => $value) {
    $usedByTab = match ($tab) {
        'viaggi' => in_array($key, ['from', 'to', 'round', 'status'], true),
        'fornitori' => in_array($key, ['from', 'to', 'supplier'], true),
        'incassi' => in_array($key, ['from', 'to', 'customer', 'payment'], true),
        default => in_array($key, ['from', 'to', 'type', 'customer'], true),
    };
    if ($usedByTab && $value !== null) {
        $filtered = true;
    }
}

/** Un menu a tendina con "tutti" come prima voce. */
$select = static function (string $name, string $label, string $all, array $options, mixed $current): string {
    $html = '<select name="' . e($name) . '" aria-label="' . e($label) . '"><option value="">' . e($all) . '</option>';
    foreach ($options as $value => $text) {
        $selected = (string) $current === (string) $value ? ' selected' : '';
        $html .= '<option value="' . e((string) $value) . '"' . $selected . '>' . e($text) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="page-header">
    <h1>Storico</h1>
</div>
<p class="lead">
    Quello che è già successo: i viaggi, la merce ricevuta da ogni fornitore, gli incassi dei clienti e i documenti emessi.
</p>

<nav class="tabs" aria-label="Schede dello storico">
    <?php foreach ($tabs as $key => $name) : ?>
        <a class="tabs__link" href="/storico?scheda=<?= e($key) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($name) ?></a>
    <?php endforeach; ?>
</nav>

<form method="get" action="/storico" class="toolbar toolbar--wrap" role="search">
    <input type="hidden" name="scheda" value="<?= e($tab) ?>">
    <label class="toolbar__check">
        <span><?= e($periodLabels[$tab]) ?>: dal</span>
        <input type="date" name="dal" value="<?= e((string) ($filters['from'] ?? '')) ?>">
    </label>
    <label class="toolbar__check">
        <span>al</span>
        <input type="date" name="al" value="<?= e((string) ($filters['to'] ?? '')) ?>">
    </label>
    <?php if ($tab === 'viaggi') : ?>
        <?= $select('giro', 'Giro', 'Tutti i giri', $roundOptions ?? [], $filters['round']) ?>
        <?= $select('stato', 'Stato', 'Tutti gli stati', \LogiDock\Model\Trip::STATUS_LABELS, $filters['status']) ?>
    <?php elseif ($tab === 'fornitori') : ?>
        <?= $select('fornitore', 'Fornitore', 'Tutti i fornitori', $supplierOptions ?? [], $filters['supplier']) ?>
    <?php elseif ($tab === 'incassi') : ?>
        <?= $select('cliente', 'Cliente', 'Tutti i clienti', $customerOptions ?? [], $filters['customer']) ?>
        <?= $select('pagamento', 'Pagamento', 'Tutti i pagamenti', \LogiDock\Model\History\PaymentHistoryRow::STATE_LABELS, $filters['payment']) ?>
    <?php else : ?>
        <?= $select('tipo', 'Tipo di documento', 'Tutti i tipi', $typeOptions, $filters['type']) ?>
        <?= $select('cliente', 'Cliente', 'Tutti i clienti', $customerOptions ?? [], $filters['customer']) ?>
    <?php endif; ?>
    <button type="submit" class="button button--secondary">Filtra</button>
    <?php if ($filtered) : ?>
        <a href="/storico?scheda=<?= e($tab) ?>">Azzera filtri</a>
    <?php endif; ?>
</form>

<?php require __DIR__ . '/_' . $tab . '.php'; ?>
