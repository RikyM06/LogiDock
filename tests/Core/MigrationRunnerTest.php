<?php

declare(strict_types=1);

namespace LogiDock\Tests\Core;

use LogiDock\Core\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Solo lo spezzatore di istruzioni SQL: il resto del runner parla con MySQL
 * ed è già coperto dall'esecuzione reale di bin/migrate.php.
 */
final class MigrationRunnerTest extends TestCase
{
    public function testSplitsStatementsAtTheEndOfLineSemicolon(): void
    {
        $sql = "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n";

        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            MigrationRunner::splitStatements($sql)
        );
    }

    public function testIgnoresCommentLines(): void
    {
        $sql = "-- tabella a\nCREATE TABLE a (id INT);\n   -- commento indentato\n";

        self::assertSame(['CREATE TABLE a (id INT)'], MigrationRunner::splitStatements($sql));
    }

    public function testKeepsMultiLineStatementsTogether(): void
    {
        $sql = "CREATE TABLE a (\n    id INT,\n    name VARCHAR(10)\n);\n";

        self::assertSame(
            ["CREATE TABLE a (\n    id INT,\n    name VARCHAR(10)\n)"],
            MigrationRunner::splitStatements($sql)
        );
    }

    public function testKeepsTheLastStatementWithoutFinalSemicolon(): void
    {
        self::assertSame(['SELECT 1'], MigrationRunner::splitStatements("SELECT 1\n"));
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $sql = "CREATE TABLE a (id INT);\r\nCREATE TABLE b (id INT);\r\n";

        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            MigrationRunner::splitStatements($sql)
        );
    }

    public function testEmptyOrCommentOnlyFileGivesNoStatements(): void
    {
        self::assertSame([], MigrationRunner::splitStatements(''));
        self::assertSame([], MigrationRunner::splitStatements("-- solo un commento\n\n"));
    }
}
