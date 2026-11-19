<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\ValidationException;
use LogiDock\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function errorsAfter(array $input, callable $checks): array
    {
        $validator = new Validator($input);
        $checks($validator);

        try {
            $validator->assertValid();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    // --- testo ---

    public function testStringsAreTrimmed(): void
    {
        $v = new Validator(['name' => '  Rossi  ']);

        self::assertSame('Rossi', $v->requiredString('name', 'Nome', 10));
        $v->assertValid();
    }

    public function testRequiredStringRejectsEmptyAndMissing(): void
    {
        $errors = $this->errorsAfter(['a' => '   '], static function (Validator $v): void {
            $v->requiredString('a', 'Campo A', 10);
            $v->requiredString('b', 'Campo B', 10);
        });

        self::assertSame(['a', 'b'], array_keys($errors));
        self::assertStringContainsString('obbligatorio', $errors['a']);
    }

    public function testStringLongerThanMaxIsRejected(): void
    {
        $errors = $this->errorsAfter(['a' => str_repeat('è', 11)], static function (Validator $v): void {
            $v->requiredString('a', 'Campo A', 10);
        });

        // Si contano i caratteri, non i byte: "è" sono 11 caratteri anche se pesano 22 byte.
        self::assertSame(['a'], array_keys($errors));
    }

    public function testOptionalStringGivesNullWhenEmpty(): void
    {
        $v = new Validator(['a' => ' ']);

        self::assertNull($v->optionalString('a', 'Campo', 10));
        self::assertNull($v->optionalString('missing', 'Campo', 10));
        $v->assertValid();
    }

    public function testArrayInputIsTreatedAsEmpty(): void
    {
        // "name[]=x" nel form non deve far saltare nulla: vale come campo vuoto.
        $errors = $this->errorsAfter(['name' => ['x']], static function (Validator $v): void {
            $v->requiredString('name', 'Nome', 10);
        });

        self::assertSame(['name'], array_keys($errors));
    }

    public function testOnlyTheFirstErrorOfAFieldIsKept(): void
    {
        $validator = new Validator([]);
        $validator->fail('a', 'primo');
        $validator->fail('a', 'secondo');

        try {
            $validator->assertValid();
            self::fail('Attesa una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['a' => 'primo'], $e->errors());
        }
    }

    // --- e-mail ---

    #[DataProvider('emailProvider')]
    public function testOptionalEmail(string $input, bool $valid): void
    {
        $errors = $this->errorsAfter(['mail' => $input], static function (Validator $v): void {
            $v->optionalEmail('mail', 'E-mail');
        });

        self::assertSame($valid, $errors === []);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function emailProvider(): array
    {
        return [
            'valida' => ['mario@example.com', true],
            'vuota' => ['', true],
            'senza chiocciola' => ['mario.example.com', false],
            'senza dominio' => ['mario@', false],
            'con spazi dentro' => ['mario rossi@example.com', false],
        ];
    }

    public function testRequiredEmail(): void
    {
        $errors = $this->errorsAfter(['vuota' => '', 'sbagliata' => 'mario.example.com', 'giusta' => ' mario@example.com '], static function (Validator $v): void {
            $v->requiredEmail('vuota', 'E-mail');
            $v->requiredEmail('sbagliata', 'E-mail');
            self::assertSame('mario@example.com', $v->requiredEmail('giusta', 'E-mail'));
        });

        self::assertSame(['vuota', 'sbagliata'], array_keys($errors));
        self::assertStringContainsString('obbligatorio', $errors['vuota']);
        self::assertStringContainsString('non è un indirizzo e-mail valido', $errors['sbagliata']);
    }

    // --- numeri interi ---

    #[DataProvider('intProvider')]
    public function testRequiredInt(string $input, ?int $expected): void
    {
        $v = new Validator(['n' => $input]);
        $value = $v->requiredInt('n', 'Numero', 1, 100);

        if ($expected === null) {
            $this->expectException(ValidationException::class);
            $v->assertValid();

            return;
        }

        $v->assertValid();
        self::assertSame($expected, $value);
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function intProvider(): array
    {
        return [
            'dentro i limiti' => ['50', 50],
            'minimo' => ['1', 1],
            'massimo' => ['100', 100],
            'spazi intorno' => [' 7 ', 7],
            'sotto il minimo' => ['0', null],
            'sopra il massimo' => ['101', null],
            'negativo' => ['-5', null],
            'decimale' => ['1.5', null],
            'testo' => ['dieci', null],
            'vuoto' => ['', null],
            'troppo lungo' => ['99999999999', null],
        ];
    }

    public function testOptionalIntGivesNullWhenEmptyButChecksWhenFilled(): void
    {
        $v = new Validator(['a' => '', 'b' => '500']);

        self::assertNull($v->optionalInt('a', 'A', 1, 100));
        $v->optionalInt('b', 'B', 1, 100);

        $this->expectException(ValidationException::class);
        $v->assertValid();
    }

    // --- decimali ---

    #[DataProvider('decimalProvider')]
    public function testRequiredDecimal(string $input, ?string $expected): void
    {
        $v = new Validator(['d' => $input]);
        $value = $v->requiredDecimal('d', 'Importo', 8, 2);

        if ($expected === null) {
            $this->expectException(ValidationException::class);
            $v->assertValid();

            return;
        }

        $v->assertValid();
        self::assertSame($expected, $value);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function decimalProvider(): array
    {
        return [
            'virgola' => ['150,5', '150.50'],
            'punto' => ['150.5', '150.50'],
            'intero' => ['7', '7.00'],
            'due decimali' => ['0,25', '0.25'],
            'zeri iniziali' => ['007,10', '7.10'],
            'zero' => ['0', '0.00'],
            'tre decimali' => ['1,234', null],
            'negativo' => ['-5', null],
            'testo' => ['abc', null],
            'nove cifre intere' => ['123456789', null],
            'notazione scientifica' => ['1e3', null],
            'vuoto' => ['', null],
        ];
    }

    public function testZeroCanBeForbidden(): void
    {
        $errors = $this->errorsAfter(['a' => '0,00', 'b' => '0,01'], static function (Validator $v): void {
            $v->requiredDecimal('a', 'Portata', 8, 2, false);
            $v->requiredDecimal('b', 'Portata', 8, 2, false);
        });

        self::assertSame(['a'], array_keys($errors));
        self::assertStringContainsString('maggiore di zero', $errors['a']);
    }

    // --- codici ---

    public function testCodeIsUppercased(): void
    {
        $v = new Validator(['plate' => ' ab123cd ']);

        self::assertSame('AB123CD', $v->requiredCode('plate', 'Targa', 15));
        $v->assertValid();
    }

    #[DataProvider('badCodeProvider')]
    public function testCodeRejectsStrangeCharacters(string $input): void
    {
        $errors = $this->errorsAfter(['c' => $input], static function (Validator $v): void {
            $v->requiredCode('c', 'Codice', 30);
        });

        self::assertSame(['c'], array_keys($errors));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badCodeProvider(): array
    {
        return [
            'spazio dentro' => ['A 1'],
            'apice (SQL injection)' => ["A'; DROP TABLE x;--"],
            'tag HTML' => ['<b>'],
            'inizia con trattino' => ['-A1'],
            'accento' => ['È1'],
        ];
    }

    // --- scelta da elenco e riferimenti ---

    public function testRequiredChoiceAcceptsOnlyListedValues(): void
    {
        $errors = $this->errorsAfter(['ok' => 'kg', 'ko' => 'xx'], static function (Validator $v): void {
            $v->requiredChoice('ok', 'Unità', ['kg', 'pz']);
            $v->requiredChoice('ko', 'Unità', ['kg', 'pz']);
        });

        self::assertSame(['ko'], array_keys($errors));
    }

    #[DataProvider('referenceProvider')]
    public function testOptionalReference(string $input, ?int $expected, bool $valid): void
    {
        $v = new Validator(['r' => $input]);
        $value = $v->optionalReference('r', 'Giro', [3, 5]);

        if (!$valid) {
            $this->expectException(ValidationException::class);
            $v->assertValid();

            return;
        }

        $v->assertValid();
        self::assertSame($expected, $value);
    }

    /**
     * @return array<string, array{string, int|null, bool}>
     */
    public static function referenceProvider(): array
    {
        return [
            'ammesso' => ['5', 5, true],
            'vuoto = nessuno' => ['', null, true],
            'non ammesso' => ['4', null, false],
            'non numerico' => ['5 OR 1=1', null, false],
            'negativo' => ['-3', null, false],
        ];
    }

    // --- partita IVA / codice fiscale e telefono ---

    public function testTaxCodeIsNormalizedToUpperCaseWithoutSpaces(): void
    {
        $v = new Validator(['a' => ' it 0000 0000 101 ', 'b' => 'rssmra80a01h501u']);

        self::assertSame('IT00000000101', $v->requiredTaxCode('a', 'Partita IVA'));
        self::assertSame('RSSMRA80A01H501U', $v->optionalTaxCode('b', 'Partita IVA'));
        $v->assertValid();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTaxCodes(): array
    {
        return [
            'italiana con IT' => ['IT00000000101'],
            'italiana di 11 cifre' => ['12345678901'],
            'codice fiscale' => ['RSSMRA80A01H501U'],
            'estera' => ['DE123456789'],
            'lunghezza minima' => ['12345678'],
            'lunghezza massima' => ['ABCDEFGH12345678'],
        ];
    }

    #[DataProvider('validTaxCodes')]
    public function testValidTaxCodesAreAccepted(string $value): void
    {
        self::assertSame([], $this->errorsAfter(['a' => $value], static function (Validator $v): void {
            $v->requiredTaxCode('a', 'Partita IVA');
            $v->optionalTaxCode('a', 'Partita IVA');
        }));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidTaxCodes(): array
    {
        return [
            'una lettera' => ['x'],
            'troppo corta' => ['1234567'],
            'troppo lunga' => ['ABCDEFGH123456789'],
            'con trattino' => ['IT-0000000101'],
            'con punto' => ['IT.00000000101'],
            'con lettere accentate' => ['ÀÈÌÒÙ12345'],
            'con un tag' => ['<b>12345678</b>'],
            'con un apice' => ["IT0000000010'1"],
        ];
    }

    #[DataProvider('invalidTaxCodes')]
    public function testInvalidTaxCodesAreRejectedOnTheRightField(string $value): void
    {
        $errors = $this->errorsAfter(['a' => $value, 'b' => $value], static function (Validator $v): void {
            $v->requiredTaxCode('a', 'Partita IVA');
            $v->optionalTaxCode('b', 'Partita IVA');
        });

        self::assertSame(['a', 'b'], array_keys($errors));
        self::assertStringContainsString('lettere e numeri', $errors['a']);
    }

    public function testRequiredTaxCodeNeedsAValueButOptionalOneDoesNot(): void
    {
        $errors = $this->errorsAfter(['a' => '  ', 'b' => ''], static function (Validator $v): void {
            $v->requiredTaxCode('a', 'Partita IVA');
            self::assertNull($v->optionalTaxCode('b', 'Partita IVA'));
            self::assertNull($v->optionalTaxCode('missing', 'Partita IVA'));
        });

        self::assertSame(['a'], array_keys($errors));
        self::assertStringContainsString('obbligatorio', $errors['a']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validPhones(): array
    {
        return [
            'con spazio' => ['035 0000101'],
            'internazionale' => ['+39 035 0000101'],
            'con parentesi e trattini' => ['(035) 000-0101'],
            'con punto' => ['035.0000101'],
            'con barra' => ['035/0000101'],
            'solo cifre' => ['0350000000'],
            'minimo 6 cifre' => ['12 34 56'],
            'massimo 15 cifre' => ['+123456789012345'],
        ];
    }

    #[DataProvider('validPhones')]
    public function testValidPhonesAreAcceptedAndKeptAsWritten(string $value): void
    {
        $v = new Validator(['a' => '  ' . $value . '  ']);

        self::assertSame($value, $v->requiredPhone('a', 'Telefono'));
        self::assertSame($value, $v->optionalPhone('a', 'Telefono'));
        $v->assertValid();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPhones(): array
    {
        return [
            'troppe poche cifre' => ['12345'],
            'troppe cifre' => ['1234567890123456'],
            'solo lettere' => ['abc'],
            'lettere in mezzo' => ['035-abc-0000'],
            'doppio più' => ['++39 0350000'],
            'più in mezzo' => ['39+0350000'],
            'con un tag' => ['<b>0350000000</b>'],
            'solo simboli' => ['() - /'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function testInvalidPhonesAreRejectedOnTheRightField(string $value): void
    {
        $errors = $this->errorsAfter(['a' => $value, 'b' => $value], static function (Validator $v): void {
            $v->requiredPhone('a', 'Telefono');
            $v->optionalPhone('b', 'Telefono');
        });

        self::assertSame(['a', 'b'], array_keys($errors));
        self::assertStringContainsString('numeri', $errors['a']);
    }

    public function testRequiredPhoneNeedsAValueButOptionalOneDoesNot(): void
    {
        $errors = $this->errorsAfter(['a' => ''], static function (Validator $v): void {
            $v->requiredPhone('a', 'Telefono');
            self::assertNull($v->optionalPhone('a', 'Telefono'));
            self::assertNull($v->optionalPhone('missing', 'Telefono'));
        });

        self::assertSame(['a'], array_keys($errors));
    }
}
