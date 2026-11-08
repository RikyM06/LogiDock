<?php
/**
 * Layout comune a tutte le pagine.
 *
 * @var string                     $appName
 * @var string                     $appVersion  versione dal file VERSION (es. 0.0.10.0)
 * @var string                     $currentPath
 * @var string                     $content     HTML già renderizzato dal template della pagina (fidato, non va escapato)
 * @var string|null                $title
 * @var \LogiDock\Model\User|null  $currentUser utente collegato, null se non c'è
 * @var string                     $csrfToken
 * @var array<string, list<string>> $flash      messaggi da mostrare una volta sola, per tipo (success, error)
 * @var bool|null                  $wide       (facoltativo) pagina più larga, per le tabelle con molte colonne
 */

// Voci di navigazione: percorso => etichetta. Si aggiungono man mano che nascono le funzionalità.
$navigation = [
    '/' => 'Home',
    '/inbound' => 'Arrivi',
    '/orders' => 'Richieste',
    '/products' => 'Prodotti',
    '/master-data' => 'Anagrafiche',
];
if ($currentUser !== null && $currentUser->role === \LogiDock\Enum\UserRole::Admin) {
    $navigation['/users'] = 'Utenti';
    $navigation['/settings'] = 'Impostazioni';
}

// Le pagine delle anagrafiche stanno sotto percorsi diversi, ma evidenziano tutte la voce "Anagrafiche".
$masterDataPaths = [
    '/master-data', '/suppliers', '/customers', '/rounds', '/carriers',
    '/vehicles', '/trailer-types', '/locations', '/pallet-types',
];

// Home è attiva solo sulla home; le altre voci anche nelle loro sottopagine (/products/3/edit).
$isActive = static function (string $path) use ($currentPath, $masterDataPaths): bool {
    if ($path === '/') {
        return $currentPath === '/';
    }

    if ($path === '/master-data') {
        foreach ($masterDataPaths as $masterDataPath) {
            if (str_starts_with($currentPath, $masterDataPath)) {
                return true;
            }
        }

        return false;
    }

    return str_starts_with($currentPath, $path);
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <header class="topbar">
        <div class="container topbar__inner<?= !empty($wide) ? ' container--wide' : '' ?>">
            <a class="brand" href="/">
                <img class="brand__logo" src="/assets/img/logo.svg" alt="" width="30" height="30">
                <?= e($appName) ?>
            </a>

            <?php if ($currentUser !== null) : ?>
                <nav class="nav" aria-label="Navigazione principale">
                    <?php foreach ($navigation as $path => $label) : ?>
                        <a class="nav__link<?= $isActive($path) ? ' is-active' : '' ?>"
                           href="<?= e($path) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <div class="topbar__user">
                    <span><?= e($currentUser->name) ?> (<?= e($currentUser->role->value) ?>)</span>
                    <a class="topbar__link" href="/account/password">La mia password</a>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                        <button type="submit" class="button button--ghost">Esci</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container main<?= !empty($wide) ? ' container--wide' : '' ?>">
        <?php foreach ($flash as $type => $messages) : ?>
            <?php foreach ($messages as $message) : ?>
                <p class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

    <footer class="footer">
        <div class="container<?= !empty($wide) ? ' container--wide' : '' ?>"><?= e($appName) ?> · gestionale di magazzino e logistica · versione <?= e($appVersion ?? '') ?></div>
    </footer>
</body>
</html>
