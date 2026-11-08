<?php

declare(strict_types=1);

namespace LogiDock\Service;

use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;
use LogiDock\Enum\UserRole;
use LogiDock\Model\User;
use LogiDock\Repository\DuplicateEntryException;
use LogiDock\Repository\UserRepository;

/**
 * Gestione degli utenti dall'applicazione (le rotte sono riservate agli admin, tranne "la mia password").
 *
 * Regole per non restare chiusi fuori:
 * - un admin non può disattivare sé stesso né togliersi il ruolo di admin;
 * - deve restare sempre almeno un admin attivo (controllo fatto bloccando le righe degli admin);
 * - un admin non reimposta la propria password da qui: usa "La mia password", che chiede quella attuale.
 *
 * Gli utenti non si cancellano mai: sono collegati ad arrivi, richieste e movimenti. Si disattivano.
 */
final class UserService
{
    public function __construct(
        private readonly Database $database,
        private readonly UserRepository $users
    ) {
    }

    /**
     * @return list<User>
     */
    public function search(string $term = ''): array
    {
        return $this->users->search(trim($term));
    }

    public function find(int $id): ?User
    {
        return $this->users->findById($id);
    }

    /**
     * @param array<string, mixed> $input email, name, role, password, password_confirmation
     * @throws ValidationException
     */
    public function create(array $input): int
    {
        $v = new Validator($input);
        // L'e-mail si salva in minuscolo: è la chiave del login e deve essere una sola per persona.
        $email = mb_strtolower($v->requiredEmail('email', 'E-mail'));
        $name = $v->requiredString('name', 'Nome e cognome', 120);
        $role = $v->requiredChoice('role', 'Ruolo', array_map(static fn (UserRole $r): string => $r->value, UserRole::cases()));
        $password = $this->checkNewPassword($input, $v);

        $v->assertValid();

        try {
            return $this->users->insert($email, $name, password_hash($password, PASSWORD_DEFAULT), UserRole::from($role));
        } catch (DuplicateEntryException) {
            throw new ValidationException(['email' => 'Esiste già un utente con questa e-mail.']);
        }
    }

    /**
     * Modifica nome e ruolo. L'e-mail non si cambia da qui: è la chiave con cui la persona entra.
     *
     * @param array<string, mixed> $input name, role
     * @throws ValidationException
     */
    public function update(int $id, array $input, int $actingUserId): void
    {
        $target = $this->users->findById($id) ?? throw new ValidationException(['name' => 'Utente non trovato.']);

        $v = new Validator($input);
        $name = $v->requiredString('name', 'Nome e cognome', 120);
        $role = $v->requiredChoice('role', 'Ruolo', array_map(static fn (UserRole $r): string => $r->value, UserRole::cases()));
        $v->assertValid();

        $newRole = UserRole::from($role);
        $losesAdmin = $target->isAdmin() && $newRole !== UserRole::Admin;

        if ($losesAdmin && $target->id === $actingUserId) {
            throw new ValidationException(['role' => 'Non puoi toglierti da solo il ruolo di admin: chiedilo a un altro admin.']);
        }

        $this->database->transaction(function () use ($target, $name, $newRole, $losesAdmin): void {
            if ($losesAdmin && $target->isActive) {
                $this->assertAnotherActiveAdminExists($target->id, 'role');
            }
            $this->users->updateProfile($target->id, $name, $newRole);
        });
    }

    /**
     * @throws UserChangeNotAllowedException
     */
    public function deactivate(int $id, int $actingUserId): void
    {
        if ($id === $actingUserId) {
            throw new UserChangeNotAllowedException('Non puoi disattivare il tuo stesso utente.');
        }

        $this->database->transaction(function () use ($id): void {
            $target = $this->users->findById($id);
            if ($target === null || !$target->isActive) {
                return;
            }
            if ($target->isAdmin()) {
                try {
                    $this->assertAnotherActiveAdminExists($target->id, 'role');
                } catch (ValidationException) {
                    throw new UserChangeNotAllowedException('Non puoi disattivare l\'ultimo admin attivo.');
                }
            }
            $this->users->setActive($target->id, false);
        });
    }

    public function activate(int $id): void
    {
        $this->users->setActive($id, true);
    }

    /**
     * Un admin imposta una nuova password a un ALTRO utente (per esempio se l'ha dimenticata o se
     * potrebbe essere stata rubata): le sessioni aperte di quell'utente si chiudono.
     *
     * @param array<string, mixed> $input password, password_confirmation
     * @throws ValidationException
     * @throws UserChangeNotAllowedException se l'admin prova a farlo su sé stesso
     */
    public function resetPassword(int $id, array $input, int $actingUserId): void
    {
        if ($id === $actingUserId) {
            throw new UserChangeNotAllowedException('Per la tua password usa «La mia password»: ti chiede anche quella attuale.');
        }

        $v = new Validator($input);
        $password = $this->checkNewPassword($input, $v);
        $v->assertValid();

        $this->users->updatePasswordHash($id, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * L'utente collegato cambia la propria password: serve quella attuale.
     *
     * @param array<string, mixed> $input current_password, password, password_confirmation
     * @throws ValidationException
     */
    public function changeOwnPassword(int $userId, array $input): void
    {
        $user = $this->users->findById($userId) ?? throw new ValidationException(['current_password' => 'Utente non trovato.']);

        $v = new Validator($input);
        $current = $this->rawString($input, 'current_password');
        if ($current === '' || !password_verify($current, $user->passwordHash)) {
            $v->fail('current_password', 'La password attuale non è corretta.');
        }

        $password = $this->checkNewPassword($input, $v);
        if ($current !== '' && $password !== '' && hash_equals($current, $password)) {
            $v->fail('password', 'La nuova password deve essere diversa da quella attuale.');
        }

        $v->assertValid();

        $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
    }

    /**
     * Controlla nuova password e conferma. Le password si leggono così come sono, SENZA togliere
     * gli spazi (il Validator li toglierebbe): lo spazio iniziale o finale lo segnala PasswordPolicy.
     *
     * @param array<string, mixed> $input
     */
    private function checkNewPassword(array $input, Validator $v): string
    {
        $password = $this->rawString($input, 'password');
        $confirmation = $this->rawString($input, 'password_confirmation');

        if ($password === '') {
            $v->fail('password', 'Il campo «Password» è obbligatorio.');
        } elseif (($problem = PasswordPolicy::problem($password)) !== null) {
            $v->fail('password', $problem);
        } elseif (!hash_equals($password, $confirmation)) {
            $v->fail('password_confirmation', 'Le due password non coincidono.');
        }

        return $password;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rawString(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Va chiamata dentro una transazione: blocca le righe degli admin attivi e controlla che, tolto
     * $userId, ne resti almeno uno.
     *
     * @throws ValidationException sul campo $field
     */
    private function assertAnotherActiveAdminExists(int $userId, string $field): void
    {
        $others = array_filter($this->users->lockActiveAdminIds(), static fn (int $id): bool => $id !== $userId);

        if ($others === []) {
            throw new ValidationException([$field => 'Deve restare almeno un admin attivo: prima nomina un altro admin.']);
        }
    }
}
