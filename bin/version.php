<?php

declare(strict_types=1);

use LogiDock\Core\Version;

// Gestione della versione (file VERSION, formato MAGGIORE.MINORE.FASE.COMMIT).
//
// Uso:  php bin/version.php            mostra la versione attuale
//       php bin/version.php bump       +1 all'ultimo numero: da lanciare prima di ogni commit
//       php bin/version.php phase 11   inizia una nuova fase: 0.0.11.0
//
// Oltre al file VERSION aggiorna il numero mostrato nel sito vetrina (docs/index.html, <span data-version>).
// L'applicazione invece legge VERSION da sola, a ogni richiesta.

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$versionFile = $root . '/VERSION';
$siteFile = $root . '/docs/index.html';

try {
    $current = Version::fromFile($versionFile);
    $command = $argv[1] ?? '';

    $next = match ($command) {
        '' => null,
        'bump' => $current->nextCommit(),
        'phase' => $current->startPhase(
            ctype_digit($argv[2] ?? '') ? (int) $argv[2] : throw new InvalidArgumentException('Uso: php bin/version.php phase <numero>')
        ),
        default => throw new InvalidArgumentException('Comando sconosciuto: ' . $command . ' (usa bump oppure phase <numero>).'),
    };

    if ($next === null) {
        echo $current->toString(), "\n";
        exit(0);
    }

    file_put_contents($versionFile, $next->toString() . "\n");

    // Il sito è HTML statico: il numero va scritto dentro la pagina.
    $site = is_readable($siteFile) ? (string) file_get_contents($siteFile) : '';
    $updated = preg_replace('/(<span data-version>)[^<]*(<\/span>)/', '${1}' . $next->toString() . '${2}', $site, -1, $count);
    if ($count > 0 && $updated !== null) {
        file_put_contents($siteFile, $updated);
    }

    echo $current->toString(), ' -> ', $next->toString(), $count > 0 ? " (aggiornato anche il sito)\n" : "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
