<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Core\Database;
use LogiDock\Core\ValidationException;
use LogiDock\Model\Product;
use LogiDock\Repository\ProductRepository;
use LogiDock\Service\ProductService;
use LogiDock\Tests\Support\TestDatabase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test di integrazione: il service gira con il vero ProductRepository sul database di test
 * (ProductRepository è final e fa solo SQL, quindi un finto sarebbe meno utile).
 * Ogni test lavora dentro una transazione che si annulla alla fine: il database resta pulito.
 */
final class ProductServiceTest extends TestCase
{
    private Database $database;

    private ProductService $service;

    protected function setUp(): void
    {
        try {
            $this->database = TestDatabase::connect();
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL di test non raggiungibile: ' . $e->getMessage());
        }

        $this->database->pdo()->beginTransaction();
        $this->service = new ProductService(new ProductRepository($this->database));
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
    }

    // --- normalizeWeight (pura, senza database) ---

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function weightProvider(): array
    {
        return [
            'virgola' => ['12,5', '12.500'],
            'punto' => ['12.5', '12.500'],
            'intero' => ['7', '7.000'],
            'spazi intorno' => [' 0,25 ', '0.250'],
            'zeri iniziali' => ['007.1', '7.100'],
            'tre decimali' => ['1.234', '1.234'],
            'vuoto' => ['', null],
            'testo' => ['abc', null],
            'quattro decimali' => ['1.2345', null],
            'negativo' => ['-3', null],
            'due separatori' => ['1,2,3', null],
            'otto cifre intere' => ['12345678', null],
            'notazione scientifica' => ['1e3', null],
            'senza parte intera' => ['.5', null],
        ];
    }

    #[DataProvider('weightProvider')]
    public function testNormalizeWeight(string $raw, ?string $expected): void
    {
        self::assertSame($expected, ProductService::normalizeWeight($raw));
    }

    // --- create ---

    public function testCreateNormalizesAndStoresTheProduct(): void
    {
        $id = $this->service->create($this->validInput([
            'code' => ' zz-050 ',
            'description' => '  Acqua minerale  ',
            'unit_of_measure' => 'cf',
            'unit_weight_kg' => '9,5',
        ]));

        $product = $this->service->find($id);

        self::assertInstanceOf(Product::class, $product);
        self::assertSame('ZZ-050', $product->code);
        self::assertSame('Acqua minerale', $product->description);
        self::assertSame('cf', $product->unitOfMeasure);
        self::assertSame('9.500', $product->unitWeightKg);
        self::assertTrue($product->isActive);
    }

    public function testCreateReportsEveryInvalidField(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create([]));

        self::assertSame(['code', 'description', 'unit_of_measure', 'unit_weight_kg'], array_keys($errors));
    }

    public function testCreateRejectsBadCodeFormat(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create($this->validInput(['code' => 'ZZ 01; DROP'])));

        self::assertSame(['code'], array_keys($errors));
    }

    public function testCreateRejectsTooLongDescription(): void
    {
        $errors = $this->errorsOf(
            fn () => $this->service->create($this->validInput(['description' => str_repeat('a', 256)]))
        );

        self::assertSame(['description'], array_keys($errors));
    }

    public function testCreateRejectsUnknownUnit(): void
    {
        $errors = $this->errorsOf(fn () => $this->service->create($this->validInput(['unit_of_measure' => 'xx'])));

        self::assertSame(['unit_of_measure'], array_keys($errors));
    }

    public function testCreateRejectsDuplicateCodeIgnoringCase(): void
    {
        $this->service->create($this->validInput(['code' => 'ZZ-DUP']));

        $errors = $this->errorsOf(fn () => $this->service->create($this->validInput(['code' => 'zz-dup'])));

        self::assertSame(['code'], array_keys($errors));
        self::assertStringContainsString('già un prodotto', $errors['code']);
    }

    // --- update ---

    public function testUpdateChangesTheProductAndKeepsItsOwnCode(): void
    {
        $id = $this->service->create($this->validInput(['code' => 'ZZ-UPD']));

        // Stesso codice del prodotto che si sta modificando: non è un duplicato.
        $this->service->update($id, $this->validInput([
            'code' => 'ZZ-UPD',
            'description' => 'Nuova descrizione',
            'unit_weight_kg' => '3',
        ]));

        $product = $this->service->find($id);
        self::assertNotNull($product);
        self::assertSame('Nuova descrizione', $product->description);
        self::assertSame('3.000', $product->unitWeightKg);
    }

    public function testUpdateRejectsTheCodeOfAnotherProduct(): void
    {
        $this->service->create($this->validInput(['code' => 'ZZ-A']));
        $secondId = $this->service->create($this->validInput(['code' => 'ZZ-B']));

        $errors = $this->errorsOf(fn () => $this->service->update($secondId, $this->validInput(['code' => 'ZZ-A'])));

        self::assertSame(['code'], array_keys($errors));
    }

    // --- activate / deactivate ---

    public function testDeactivateAndActivate(): void
    {
        $id = $this->service->create($this->validInput(['code' => 'ZZ-ACT']));

        $this->service->deactivate($id);
        self::assertFalse($this->service->find($id)?->isActive);

        $this->service->activate($id);
        self::assertTrue($this->service->find($id)?->isActive);
    }

    public function testFindUnknownIdReturnsNull(): void
    {
        self::assertNull($this->service->find(2_000_000_000));
    }

    // --- search ---

    public function testSearchMatchesCodeOrDescriptionIgnoringCase(): void
    {
        $this->service->create($this->validInput(['code' => 'ZZ-ALFA', 'description' => 'Prima cosa']));
        $this->service->create($this->validInput(['code' => 'ZZ-BETA', 'description' => 'Seconda cosa']));

        self::assertSame(['ZZ-ALFA'], $this->testCodes('zz-al'));
        self::assertSame(['ZZ-BETA'], $this->testCodes('SECONDA'));
        self::assertSame(['ZZ-ALFA', 'ZZ-BETA'], $this->testCodes('cosa'));
    }

    public function testSearchTreatsPercentAndUnderscoreAsPlainCharacters(): void
    {
        $this->service->create($this->validInput(['code' => 'ZZ-ALFA', 'description' => 'Prima cosa']));
        $this->service->create($this->validInput(['code' => 'ZZ-SCONTO', 'description' => 'Sconto 100% vero']));

        // Senza escape "%" sarebbe un jolly e troverebbe tutti i prodotti.
        self::assertSame(['ZZ-SCONTO'], $this->testCodes('%'));
        // Senza escape "_" varrebbe "un carattere qualsiasi" e "ZZ_ALFA" troverebbe ZZ-ALFA.
        self::assertSame([], $this->testCodes('ZZ_ALFA'));
    }

    public function testSearchPutsActiveProductsFirst(): void
    {
        $firstId = $this->service->create($this->validInput(['code' => 'ZZ-1']));
        $this->service->create($this->validInput(['code' => 'ZZ-2']));
        $this->service->deactivate($firstId);

        self::assertSame(['ZZ-2', 'ZZ-1'], $this->testCodes('ZZ-'));
    }

    /**
     * Codici trovati dalla ricerca, limitati a quelli creati dai test (iniziano con "ZZ-"),
     * così il risultato non dipende da eventuali altri dati nel database di test.
     *
     * @return list<string>
     */
    private function testCodes(string $term): array
    {
        $codes = array_map(static fn (Product $p): string => $p->code, $this->service->search($term));

        return array_values(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'ZZ-')));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'ZZ-001',
            'description' => 'Prodotto di prova',
            'unit_of_measure' => 'pz',
            'unit_weight_kg' => '1,5',
        ];
    }

    /**
     * Esegue $action, che deve fallire la validazione, e restituisce gli errori per campo.
     *
     * @return array<string, string>
     */
    private function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        self::fail('Attesa una ValidationException.');
    }
}
