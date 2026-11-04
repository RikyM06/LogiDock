<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Csrf;
use LogiDock\Core\Logger;
use LogiDock\Core\Session;
use LogiDock\Enum\UserRole;
use LogiDock\Model\User;
use LogiDock\Repository\UserRepository;

/**
 * Login, logout e "chi è l'utente corrente". Nella sessione si salva solo l'id dell'utente:
 * i dati (ruolo, stato attivo) si rileggono dal database a ogni richiesta, così disattivare un
 * utente o cambiargli ruolo ha effetto subito.
 */
final class AuthService
{
    private const SESSION_USER_KEY = 'user_id';

    /**
     * Hash di una password casuale mai usata. Serve a fare comunque un password_verify quando l'email
     * non esiste: il tempo di risposta resta lo stesso e non si scopre quali email sono registrate.
     */
    private const DUMMY_HASH = '$2y$10$UfHaOAcojNFKjrkMg33yNeOVwCdbWYpOEsnJAl2KTG7/bgkKmyUTK';

    private ?User $currentUser = null;
    private bool $currentUserResolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Logger $logger
    ) {
    }

    /**
     * Verifica le credenziali e, se corrette, apre la sessione.
     * Il motivo del fallimento (email sconosciuta, password errata, utente disattivato) non viene
     * mai restituito al chiamante: all'utente si mostra sempre lo stesso messaggio.
     */
    public function attempt(string $email, string $password, string $ip): ?User
    {
        $user = $this->users->findByEmail($email);

        $passwordIsCorrect = password_verify($password, $user?->passwordHash ?? self::DUMMY_HASH);

        if ($user === null || !$passwordIsCorrect || !$user->isActive) {
            // Nel log sì: serve a notare tentativi sospetti. Mai la password.
            $this->logger->warning('Login fallito', ['email' => $email, 'ip' => $ip]);

            return null;
        }

        // Se l'algoritmo predefinito di PHP è cambiato, aggiorna l'hash ora che abbiamo la password in chiaro.
        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
        }

        // Nuovo ID di sessione e nuovo token CSRF: nulla di ciò che esisteva prima del login vale dopo.
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER_KEY, $user->id);

        $this->users->touchLastLogin($user->id);
        $this->logger->info('Login riuscito', ['user_id' => $user->id, 'ip' => $ip]);

        $this->currentUser = $user;
        $this->currentUserResolved = true;

        return $user;
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->csrf->rotate();

        $this->currentUser = null;
        $this->currentUserResolved = true;
    }

    public function user(): ?User
    {
        if ($this->currentUserResolved) {
            return $this->currentUser;
        }
        $this->currentUserResolved = true;

        $id = $this->session->get(self::SESSION_USER_KEY);
        if (!is_int($id)) {
            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null || !$user->isActive) {
            // Utente cancellato o disattivato mentre era collegato: la sessione non vale più.
            $this->session->invalidate();

            return null;
        }

        return $this->currentUser = $user;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->user()?->role === $role;
    }
}
