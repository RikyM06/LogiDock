<?php

declare(strict_types=1);

use LogiDock\Core\Application;
use LogiDock\Core\Database;
use LogiDock\Enum\UserRole;
use LogiDock\Repository\DuplicateEntryException;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\PasswordPolicy;

// Crea un utente da riga di comando. Serve soprattutto per il PRIMO admin in produzione,
// dove il seed è disabilitato.
//
// Uso:  php bin/create-user.php <email> "<Nome Cognome>" <admin|operatore>
//
// La password si digita al prompt (nascosta su Linux). In alternativa arriva da stdin,
// così non finisce nella cronologia dei comandi:
//       printf '%s' "$PASSWORD" | php bin/create-user.php admin@azienda.lan "Mario Rossi" admin

function readPassword(): string
{
    // Senza terminale (pipe): la prima riga di stdin è la password.
    if (!stream_isatty(STDIN)) {
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('Nessuna password ricevuta da stdin.');
        }

        return rtrim($line, "\r\n");
    }

    // Su Linux si nasconde ciò che si digita con stty; su Windows non è possibile e resta visibile.
    $canHide = DIRECTORY_SEPARATOR === '/';

    try {
        if ($canHide) {
            shell_exec('stty -echo');
        }
        fwrite(STDERR, 'Password (almeno 12 caratteri): ');
        $first = fgets(STDIN);
        fwrite(STDERR, "\nRipeti la password: ");
        $second = fgets(STDIN);
    } finally {
        if ($canHide) {
            shell_exec('stty echo');
        }
        fwrite(STDERR, "\n");
    }

    if ($first === false || $second === false || rtrim($first, "\r\n") !== rtrim($second, "\r\n")) {
        throw new RuntimeException('Le due password non coincidono.');
    }

    return rtrim($first, "\r\n");
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $email = $argv[1] ?? null;
    $name = $argv[2] ?? null;
    $roleValue = $argv[3] ?? null;

    if ($email === null || $name === null || $roleValue === null) {
        throw new InvalidArgumentException('Uso: php bin/create-user.php <email> "<Nome Cognome>" <admin|operatore>');
    }

    $role = UserRole::tryFrom($roleValue)
        ?? throw new InvalidArgumentException('Ruolo non valido: usa "admin" oppure "operatore".');

    // In minuscolo, come fa la gestione utenti dell'applicazione: l'e-mail è la chiave del login.
    $email = mb_strtolower(trim($email));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
        throw new InvalidArgumentException('Email non valida.');
    }

    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Il nome è obbligatorio (massimo 120 caratteri).');
    }

    // Stesse regole della gestione utenti dell'applicazione (src/Service/PasswordPolicy.php).
    $password = readPassword();
    $problem = PasswordPolicy::problem($password);
    if ($problem !== null) {
        throw new InvalidArgumentException($problem);
    }

    $config = Application::loadConfig($root);
    $users = new UserRepository(new Database($config['db']));

    $id = $users->insert($email, $name, password_hash($password, PASSWORD_DEFAULT), $role);
    echo "Utente creato: id $id, $email, ruolo {$role->value}.\n";
} catch (DuplicateEntryException) {
    fwrite(STDERR, "ERRORE: esiste già un utente con questa email.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
