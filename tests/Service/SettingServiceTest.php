<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogicException;
use LogiDock\Repository\SettingRepository;
use LogiDock\Repository\UserRepository;
use LogiDock\Service\SettingService;
use LogiDock\Tests\Support\IntegrationTestCase;

final class SettingServiceTest extends IntegrationTestCase
{
    private SettingService $service;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SettingService(new SettingRepository($this->database), $this->database);

        // updated_by è una chiave esterna verso users: serve un utente vero (annullato a fine test).
        $this->userId = (new UserRepository($this->database))->insert(
            'settings-test@example.com',
            'Utente di prova',
            password_hash('non-importa', PASSWORD_DEFAULT),
            \LogiDock\Enum\UserRole::Admin
        );
    }

    /**
     * @return array<string, string>
     */
    private function validInput(): array
    {
        return $this->service->values();
    }

    public function testDefaultsApplyUntilSomethingIsSaved(): void
    {
        self::assertSame(750, $this->service->getInt(SettingService::PALLET_MAX_HEIGHT_MM));
        self::assertSame(2, $this->service->getInt(SettingService::AGING_THRESHOLD_DAYS));
        self::assertSame(300, $this->service->getInt(SettingService::LOCATION_DEFAULT_CAPACITY));
        self::assertSame('', $this->service->get(SettingService::COMPANY_NAME));
    }

    public function testSavedValuesOverrideTheDefaults(): void
    {
        $this->service->update([
            ...$this->validInput(),
            'aging_threshold_days' => '5',
            'company_name' => '  Trasporti Rossi  ',
        ], $this->userId);

        self::assertSame(5, $this->service->getInt(SettingService::AGING_THRESHOLD_DAYS));
        self::assertSame('Trasporti Rossi', $this->service->get(SettingService::COMPANY_NAME));
        // Le altre restano al valore di partenza.
        self::assertSame(750, $this->service->getInt(SettingService::PALLET_MAX_HEIGHT_MM));
    }

    public function testSavingTwiceUpdatesInsteadOfDuplicating(): void
    {
        $this->service->update([...$this->validInput(), 'aging_threshold_days' => '5'], $this->userId);
        $this->service->update([...$this->validInput(), 'aging_threshold_days' => '9'], $this->userId);

        self::assertSame(9, $this->service->getInt(SettingService::AGING_THRESHOLD_DAYS));

        $count = $this->database->pdo()->query("SELECT COUNT(*) FROM settings WHERE name = 'aging_threshold_days'");
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testInvalidValueSavesNothing(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->update([
            ...$this->validInput(),
            'pallet_max_height_mm' => '9999',
            'aging_threshold_days' => '0',
            'company_name' => 'Questa non deve salvarsi',
        ], $this->userId));

        self::assertSame(['pallet_max_height_mm', 'aging_threshold_days'], array_keys($errors));
        self::assertSame('', $this->service->get(SettingService::COMPANY_NAME));
        self::assertSame(2, $this->service->getInt(SettingService::AGING_THRESHOLD_DAYS));
    }

    public function testTextTooLongIsRejected(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->update([
            ...$this->validInput(),
            'company_vat' => str_repeat('1', 31),
        ], $this->userId));

        self::assertSame(['company_vat'], array_keys($errors));
    }

    public function testUnknownSettingIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        $this->service->get('non_esiste');
    }
}
