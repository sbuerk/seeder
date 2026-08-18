<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The placeholder is the one piece of {@see SeedRecord} that is not a plain
 * property, and getting it wrong fails silently at seeding time - see the
 * docblock of {@see SeedRecord::placeholder()}. It is therefore pinned here.
 */
final class SeedRecordTest extends UnitTestCase
{
    /**
     * @return \Generator<string, array{table: string, identifier: string, expected: string}>
     */
    public static function placeholders(): \Generator
    {
        yield 'a table without an underscore is used as it is' => [
            'table' => 'pages',
            'identifier' => 'home',
            'expected' => 'NEWpages-home',
        ];
        yield 'the underscores of the table are removed' => [
            'table' => 'tt_content',
            'identifier' => 'home-heading',
            'expected' => 'NEWttcontent-home-heading',
        ];
        yield 'a table with more than one underscore keeps none of them' => [
            'table' => 'tx_example_item',
            'identifier' => 'item1',
            'expected' => 'NEWtxexampleitem-item1',
        ];
    }

    #[DataProvider('placeholders')]
    #[Test]
    public function placeholderJoinsTheTableWithoutUnderscoresToTheIdentifier(string $table, string $identifier, string $expected): void
    {
        $record = new SeedRecord($table, $identifier, []);

        $this->assertSame($expected, $record->placeholder());
        // The load bearing property: whatever the table was called, the
        // placeholder DataHandler sees carries no underscore, so it is not
        // taken apart into a "<table>_<uid>" pair.
        $this->assertStringNotContainsString('_', $record->placeholder());
    }

    #[Test]
    public function aDeclaredUidDoesNotChangeThePlaceholder(): void
    {
        $withUid = new SeedRecord('pages', 'home', [], 4711);
        $withoutUid = new SeedRecord('pages', 'home', []);

        // The uid is only a *suggestion* to DataHandler; the data map is keyed
        // by the placeholder either way, so both records address the same row.
        $this->assertSame($withoutUid->placeholder(), $withUid->placeholder());
    }
}
