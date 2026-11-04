<?php
/**
 * Layout comune a tutte le pagine.
 *
 * @var string                     $appName
 * @var string                     $currentPath
 * @var string                     $content     HTML già renderizzato dal template della pagina (fidato, non va escapato)
 * @var string|null                $title
 * @var \LogiDock\Model\User|null  $currentUser utente collegato, null se non c'è
 * @var string                     $csrfToken
 * @var array<string, list<string>> $flash      messaggi da mostrare una volta sola, per tipo (success, error)
 */

// Voci di navigazione: percorso => etichetta. Si aggiungono man mano che nascono le funzionalità.
$navigation = [
    '/' => 'Home',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($title) ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="topbar">
        <div class="container topbar__inner">
            <a class="brand" href="/"><?= e($appName) ?></a>

            <?php if ($currentUser !== null) : ?>
                <nav class="nav" aria-label="Navigazione principale">
                    <?php foreach ($navigation as $path => $label) : ?>
                        <a class="nav__link<?= $path === $currentPath ? ' is-active' : '' ?>"
                           href="<?= e($path) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <div class="topbar__user">
                    <span><?= e($currentUser->name) ?> (<?= e($currentUser->role->value) ?>)</span>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                        <button type="submit" class="button button--ghost">Esci</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container main">
        <?php foreach ($flash as $type => $messages) : ?>
            <?php foreach ($messages as $message) : ?>
                <p class="alert alert--<?= e($type) ?>" role="status"><?= e($message) ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?= $content ?>
    </main>

    <footer class="footer">
        <div class="container"><?= e($appName) ?> · gestionale di magazzino e logistica</div>
    </footer>
</body>
</html>
