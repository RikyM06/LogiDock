<?php

declare(strict_types=1);

namespace LogiDock\Tests\Service;

use LogiDock\Service\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    #[DataProvider('passwordProvider')]
    public function testPasswordRules(string $password, ?string $expectedProblem): void
    {
        $problem = PasswordPolicy::problem($password);

        if ($expectedProblem === null) {
            self::assertNull($problem);
        } else {
            self::assertNotNull($problem);
            self::assertStringContainsString($expectedProblem, $problem);
        }
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function passwordProvider(): array
    {
        return [
            'esattamente 12 caratteri' => ['abcdefghijkl', null],
            'frase lunga con spazi in mezzo' => ['cavallo batteria graffetta', null],
            '11 caratteri' => ['abcdefghijk', 'almeno 12'],
            'vuota' => ['', 'almeno 12'],
            '72 byte' => [str_repeat('a', 72), null],
            '73 byte' => [str_repeat('a', 73), 'al massimo 72'],
            // "è" pesa 2 byte: 40 caratteri accentati sono 80 byte, oltre il limite di bcrypt.
            '40 lettere accentate' => [str_repeat('è', 40), 'al massimo 72'],
            'spazio iniziale' => [' abcdefghijkl', 'spazio'],
            'spazio finale' => ['abcdefghijkl ', 'spazio'],
        ];
    }
}
