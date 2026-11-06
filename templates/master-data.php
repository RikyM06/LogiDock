<?php
/**
 * Indice delle anagrafiche.
 *
 * @var \LogiDock\Model\User|null $currentUser
 */
$isAdmin = $currentUser !== null && $currentUser->role === \LogiDock\Enum\UserRole::Admin;

// percorso => [titolo, descrizione, solo admin]
$sections = [
    ['/suppliers', 'Fornitori', 'Chi ci manda la merce.', false],
    ['/customers', 'Clienti', 'I compratori che fanno le richieste, ognuno con il suo giro.', false],
    ['/rounds', 'Giri', 'I giri di consegna fissi a cui appartengono i clienti.', false],
    ['/vehicles', 'Veicoli', 'I camion, con il tipo di rimorchio e il proprietario.', false],
    ['/trailer-types', 'Tipi di rimorchio', 'Misure e portata dei rimorchi.', false],
    ['/carriers', 'Trasportatori', 'I camionisti proprietari dei camion e il loro compenso.', true],
    ['/locations', 'Posizioni di magazzino', 'Dove si mettono i bancali.', false],
    ['/pallet-types', 'Tipi di bancale', 'Le misure dei bancali (EPAL 1 e EPAL 3).', false],
];
?>
<div class="page-header">
    <h1>Anagrafiche</h1>
</div>
<p class="lead">I dati di base su cui lavorano arrivi, richieste e viaggi.</p>

<div class="grid">
    <?php foreach ($sections as [$path, $name, $description, $adminOnly]) : ?>
        <?php if ($adminOnly && !$isAdmin) : ?>
            <?php continue; ?>
        <?php endif; ?>
        <a class="card card--link" href="<?= e($path) ?>">
            <h2><?= e($name) ?></h2>
            <p class="muted"><?= e($description) ?></p>
        </a>
    <?php endforeach; ?>
</div>
