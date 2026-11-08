<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Enum\UserRole;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\UserChangeNotAllowedException;
use LogiDock\Service\UserService;
use LogiDock\Tests\Support\IntegrationTestCase;

/**
 * Gestione degli utenti, con le protezioni per non restare senza admin.
 * Test di integrazione sul database di test. Ogni test parte disattivando gli admin già presenti
 * nel database di test (dentro la transazione del test, che poi si annulla), così il numero degli
 * admin attivi dipende solo da quelli creati qui.
 */
final class UserServiceTest extends IntegrationTestCase
{
    private const PASSWORD = 'Password-di-prova-1';

    private UserService $users;
    private UserRepository $repository;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database->pdo()->exec("UPDATE users SET is_active = 0 WHERE role = 'admin'");
        $this->repository = new UserRepository($this->database);
        $this->users = new UserService($this->database, $this->repository);
        $this->adminId = $this->users->create($this->input(['email' => 'zz-admin@example.com', 'role' => 'admin']));
    }

    // --- creazione ---

    public function testCreateStoresTheUserWithAHashedPassword(): void
    {
        $id = $this->users->create($this->input(['email' => '  ZZ-Mario@Example.COM ', 'name' => ' Mario Rossi ']));

        $user = $this->users->find($id);
        self::assertNotNull($user);
        self::assertSame('zz-mario@example.com', $user->email, 'L\'e-mail si salva pulita e in minuscolo.');
        self::assertSame('Mario Rossi', $user->name);
        self::assertSame(UserRole::Operatore, $user->role);
        self::assertTrue($user->isActive);
        self::assertNotSame(self::PASSWORD, $user->passwordHash, 'La password non si salva mai in chiaro.');
        self::assertTrue(password_verify(self::PASSWORD, $user->passwordHash));
        self::assertNull($user->lastLoginAt);
    }

    public function testCreateValidatesEveryField(): void
    {
        $errors = $this->errorsOf(fn () => $this->users->create([]));

        self::assertSame(['email', 'name', 'role', 'password'], array_keys($errors));
    }

    public function testShortPasswordAndWrongConfirmationAreRejected(): void
    {
        $short = $this->errorsOf(fn () => $this->users->create($this->input(['password' => 'corta', 'password_confirmation' => 'corta'])));
        self::assertSame(['password'], array_keys($short));

        $different = $this->errorsOf(fn () => $this->users->create($this->input(['password_confirmation' => 'Un-altra-password-1'])));
        self::assertSame(['password_confirmation'], array_keys($different));
    }

    public function testEmailMustBeUniqueIgnoringCase(): void
    {
        $this->users->create($this->input(['email' => 'zz-doppia@example.com']));

        $errors = $this->errorsOf(fn () => $this->users->create($this->input(['email' => 'ZZ-Doppia@Example.com'])));

        self::assertSame(['email'], array_keys($errors));
    }

    public function testUnknownRoleIsRejected(): void
    {
        $errors = $this->errorsOf(fn () => $this->users->create($this->input(['role' => 'superuser'])));

        self::assertSame(['role'], array_keys($errors));
    }

    // --- modifica ---

    public function testUpdateChangesNameAndRole(): void
    {
        $id = $this->users->create($this->input());

        $this->users->update($id, ['name' => 'Nuovo Nome', 'role' => 'admin'], $this->adminId);

        $user = $this->users->find($id);
        self::assertSame('Nuovo Nome', $user?->name);
        self::assertSame(UserRole::Admin, $user?->role);
    }

    public function testAdminCannotDemoteThemselves(): void
    {
        // Anche se ci sono altri admin: togliersi il ruolo da soli non si può.
        $this->users->create($this->input(['email' => 'zz-admin2@example.com', 'role' => 'admin']));

        $errors = $this->errorsOf(fn () => $this->users->update($this->adminId, ['name' => 'Io', 'role' => 'operatore'], $this->adminId));

        self::assertSame(['role'], array_keys($errors));
        self::assertSame(UserRole::Admin, $this->users->find($this->adminId)?->role);
    }

    public function testLastActiveAdminCannotBeDemotedByAnotherUser(): void
    {
        // Un operatore promosso e poi... l'unico admin attivo resta $adminId: non si può retrocedere.
        $errors = $this->errorsOf(fn () => $this->users->update($this->adminId, ['name' => 'Admin', 'role' => 'operatore'], 999999));

        self::assertSame(['role'], array_keys($errors));
        self::assertStringContainsString('almeno un admin attivo', $errors['role']);
    }

    public function testAdminCanBeDemotedWhenAnotherActiveAdminExists(): void
    {
        $second = $this->users->create($this->input(['email' => 'zz-admin2@example.com', 'role' => 'admin']));

        $this->users->update($second, ['name' => 'Ex admin', 'role' => 'operatore'], $this->adminId);

        self::assertSame(UserRole::Operatore, $this->users->find($second)?->role);
    }

    // --- disattivazione ---

    public function testDeactivateAndActivate(): void
    {
        $id = $this->users->create($this->input());

        $this->users->deactivate($id, $this->adminId);
        self::assertFalse($this->users->find($id)?->isActive);

        $this->users->activate($id);
        self::assertTrue($this->users->find($id)?->isActive);
    }

    public function testAdminCannotDeactivateThemselves(): void
    {
        // C'è un altro admin attivo: a fermare l'operazione deve essere proprio il divieto di
        // disattivare sé stessi, non il controllo sull'ultimo admin.
        $this->users->create($this->input(['email' => 'zz-admin2@example.com', 'role' => 'admin']));

        $this->expectException(UserChangeNotAllowedException::class);
        $this->expectExceptionMessage('tuo stesso utente');

        $this->users->deactivate($this->adminId, $this->adminId);
    }

    public function testLastActiveAdminCannotBeDeactivated(): void
    {
        $this->expectException(UserChangeNotAllowedException::class);
        $this->expectExceptionMessage('ultimo admin attivo');

        $this->users->deactivate($this->adminId, 999999);
    }

    public function testAnAdminCanBeDeactivatedIfAnotherOneRemains(): void
    {
        $second = $this->users->create($this->input(['email' => 'zz-admin2@example.com', 'role' => 'admin']));

        $this->users->deactivate($second, $this->adminId);

        self::assertFalse($this->users->find($second)?->isActive);
    }

    // --- password ---

    public function testAdminResetsAnotherUsersPassword(): void
    {
        $id = $this->users->create($this->input());

        $this->users->resetPassword($id, ['password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123'], $this->adminId);

        $hash = (string) $this->users->find($id)?->passwordHash;
        self::assertTrue(password_verify('Nuova-password-123', $hash));
        self::assertFalse(password_verify(self::PASSWORD, $hash));
    }

    public function testAdminCannotResetTheirOwnPasswordFromTheUserList(): void
    {
        $this->expectException(UserChangeNotAllowedException::class);

        $this->users->resetPassword($this->adminId, ['password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123'], $this->adminId);
    }

    public function testChangeOwnPasswordNeedsTheCurrentOne(): void
    {
        $id = $this->users->create($this->input());

        $wrong = $this->errorsOf(fn () => $this->users->changeOwnPassword($id, [
            'current_password' => 'non-e-questa-1', 'password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123',
        ]));
        self::assertSame(['current_password'], array_keys($wrong));

        $this->users->changeOwnPassword($id, [
            'current_password' => self::PASSWORD, 'password' => 'Nuova-password-123', 'password_confirmation' => 'Nuova-password-123',
        ]);
        self::assertTrue(password_verify('Nuova-password-123', (string) $this->users->find($id)?->passwordHash));
    }

    public function testNewPasswordMustDifferFromTheCurrentOne(): void
    {
        $id = $this->users->create($this->input());

        $errors = $this->errorsOf(fn () => $this->users->changeOwnPassword($id, [
            'current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]));

        self::assertSame(['password'], array_keys($errors));
    }

    public function testPasswordsAreNotTrimmed(): void
    {
        // Uno spazio finale non va tolto in silenzio: la regola lo segnala.
        $errors = $this->errorsOf(fn () => $this->users->create($this->input([
            'password' => self::PASSWORD . ' ', 'password_confirmation' => self::PASSWORD . ' ',
        ])));

        self::assertSame(['password'], array_keys($errors));
    }

    // --- ricerca ---

    public function testSearchByNameOrEmailWithActiveUsersFirst(): void
    {
        $active = $this->users->create($this->input(['email' => 'zz-attivo@example.com', 'name' => 'Qq Beta']));
        $inactive = $this->users->create($this->input(['email' => 'zz-spento@example.com', 'name' => 'Qq Alfa']));
        $this->users->deactivate($inactive, $this->adminId);

        $ids = array_map(static fn ($u): int => $u->id, $this->users->search('qq'));

        self::assertSame([$active, $inactive], $ids, 'Prima gli attivi, anche se il nome viene dopo in ordine alfabetico.');
        self::assertSame([$active], array_map(static fn ($u): int => $u->id, $this->users->search('zz-attivo@')));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'email' => 'zz-utente@example.com',
            'name' => 'Utente di prova',
            'role' => 'operatore',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }
}
