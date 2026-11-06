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
}
