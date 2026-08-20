<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Every record a scenario produces carries a uid that is *suggested* to
 * DataHandler, and the rules that pick it are the least obvious part of the
 * ported factory: the counter is per entity *name* rather than per table, an
 * item declaring `version:` silently consumes two ids, and a declared `id:`
 * skips the counter but still moves it. Nothing of that is visible in a
 * scenario file, and a wrong uid surfaces only as a foreign key pointing at
 * the wrong row, so the arithmetic is pinned here rather than trusted.
 *
 * One behaviour pinned below is a defect that is deliberately kept:
 * {@see DataHandlerFactory} declares `$staticIdsPerEntity`, reads it in
 * `hasStaticId()` and never writes it, so that guard is always false and its
 * exception 1533734370 cannot be reached — in this port and in
 * `typo3/testing-framework` 9.6.1 alike. Duplicate ids are caught one step
 * later by `addSuggestedId()` as 1568146788 instead. These tests describe what
 * the code does today, not what the dead branch suggests it should do. Fixing
 * the guard is a production change and would rightly turn the two tests naming
 * it red; do not "repair" them to match the dead code.
 */
final class DataHandlerFactoryIdAssignmentTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $entitySettings
     * @param array<string, list<array<string, mixed>>> $entities
     */
    private static function factory(array $entitySettings, array $entities): DataHandlerFactory
    {
        return new DataHandlerFactory([
            'entitySettings' => $entitySettings,
            'entities' => $entities,
        ]);
    }

    /**
     * The data map is keyed by `uniqid('NEW', true)` and therefore random per
     * run, so the records of one table are addressed by position.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(DataHandlerFactory $factory, string $tableName, int $workspaceId = 0): array
    {
        return array_values($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? []);
    }

    /**
     * @return \Generator<string, array{entitySettings: array<string, mixed>, entities: array<string, list<array<string, mixed>>>, expected: list<string>}>
     */
    public static function idSequences(): \Generator
    {
        yield 'a dynamic id starts at 10000 and increments by one' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['self' => ['title' => 'a']],
                ['self' => ['title' => 'b']],
                ['self' => ['title' => 'c']],
            ]],
            'expected' => ['pages:10000', 'pages:10001', 'pages:10002'],
        ];
        yield 'every entity name has its own counter' => [
            'entitySettings' => ['pages' => [], 'tt_content' => []],
            'entities' => [
                'pages' => [['self' => ['title' => 'a']]],
                'tt_content' => [['self' => ['header' => 'x']], ['self' => ['header' => 'y']]],
            ],
            'expected' => ['pages:10000', 'tt_content:10000', 'tt_content:10001'],
        ];
        yield 'an entity used without entitySettings gets the same counter' => [
            'entitySettings' => [],
            'entities' => ['tx_example_item' => [
                ['self' => ['title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            'expected' => ['tx_example_item:10000', 'tx_example_item:10001'],
        ];
        yield 'a version item consumes two dynamic ids' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['version' => ['workspace' => 1, 'title' => 'a']],
                ['version' => ['workspace' => 1, 'title' => 'b']],
                ['self' => ['title' => 'c']],
            ]],
            // The skipped 10001 and 10003 are reserved for the live workspace
            // counterparts DataHandler creates for a versioned record.
            'expected' => ['pages:10000', 'pages:10002', 'pages:10004'],
        ];
        yield 'a static id does not consume a dynamic id' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['self' => ['id' => 5, 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            'expected' => ['pages:5', 'pages:10000'],
        ];
        yield 'a static id on a version item advances the counter by one' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['version' => ['workspace' => 1, 'id' => 7, 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            // incrementValue - 1: the static id is used, but the slot for the
            // live counterpart is still burned.
            'expected' => ['pages:7', 'pages:10001'],
        ];
        yield 'a static id declared as a string is cast to int' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['self' => ['id' => '42', 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            'expected' => ['pages:42', 'pages:10000'],
        ];
        yield 'id 0 is not a static id' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [['self' => ['id' => 0, 'title' => 'a']]]],
            'expected' => ['pages:10000'],
        ];
        yield 'a negative id is not a static id' => [
            'entitySettings' => ['pages' => []],
            'entities' => ['pages' => [
                ['self' => ['id' => -3, 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            'expected' => ['pages:10000', 'pages:10001'],
        ];
        yield 'children draw from the counter of their own entity' => [
            'entitySettings' => ['pages' => ['isNode' => true, 'parentColumnName' => 'parent']],
            'entities' => ['pages' => [[
                'self' => ['title' => 'root'],
                'children' => [
                    ['self' => ['title' => 'c1']],
                    ['self' => ['title' => 'c2']],
                ],
            ]]],
            'expected' => ['pages:10000', 'pages:10001', 'pages:10002'],
        ];
    }

    /**
     * @param array<string, mixed> $entitySettings
     * @param array<string, list<array<string, mixed>>> $entities
     * @param list<string> $expected
     */
    #[DataProvider('idSequences')]
    #[Test]
    public function idsAreAssignedInDeclarationOrderFollowingTheDynamicCounter(array $entitySettings, array $entities, array $expected): void
    {
        $factory = self::factory($entitySettings, $entities);

        // The suggested ids are collected in the order the items are processed,
        // which makes them the one place where the sequence is observable
        // across workspaces and tables at once.
        $this->assertSame($expected, array_keys($factory->getSuggestedIds()));
    }

    #[Test]
    public function theStaticIdRegistryIsNeverWrittenSoItsGuardIsUnreachable(): void
    {
        $factory = self::factory(
            ['pages' => []],
            ['pages' => [
                ['self' => ['id' => 5, 'title' => 'a']],
                ['self' => ['id' => 6, 'title' => 'b']],
            ]]
        );

        $property = new \ReflectionProperty(DataHandlerFactory::class, 'staticIdsPerEntity');

        // Two static ids were assigned and none of them was recorded: nothing
        // in the class ever writes this property. `hasStaticId()` therefore
        // always returns false and exception 1533734370 is dead code — see the
        // class docblock before changing this.
        $this->assertSame([], $property->getValue($factory));
    }

    /**
     * @return \Generator<string, array{entities: array<string, list<array<string, mixed>>>, identifier: string}>
     */
    public static function collidingIds(): \Generator
    {
        yield 'the same static id twice' => [
            'entities' => ['pages' => [
                ['self' => ['id' => 5, 'title' => 'a']],
                ['self' => ['id' => 5, 'title' => 'b']],
            ]],
            'identifier' => 'pages:5',
        ];
        yield 'a static id the dynamic counter starts on' => [
            'entities' => ['pages' => [
                ['self' => ['id' => 10000, 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            'identifier' => 'pages:10000',
        ];
        yield 'a static id claiming an already assigned dynamic id' => [
            'entities' => ['pages' => [
                ['self' => ['title' => 'a']],
                ['self' => ['id' => 10000, 'title' => 'b']],
            ]],
            'identifier' => 'pages:10000',
        ];
        yield 'a static id the counter only reaches later' => [
            // The first two items pass; the collision surfaces on the third,
            // when the counter walks into the statically claimed 10001.
            'entities' => ['pages' => [
                ['self' => ['id' => 10001, 'title' => 'a']],
                ['self' => ['title' => 'b']],
                ['self' => ['title' => 'c']],
            ]],
            'identifier' => 'pages:10001',
        ];
        yield 'a static id of a version item, one step further than a static self' => [
            'entities' => ['pages' => [
                ['version' => ['workspace' => 1, 'id' => 10001, 'title' => 'a']],
                ['self' => ['title' => 'b']],
            ]],
            // The static id burns the reserved live slot as well, so the
            // counter is already at 10001 when the next item asks for an id —
            // with `self:` instead of `version:` this pair would pass.
            'identifier' => 'pages:10001',
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $entities
     */
    #[DataProvider('collidingIds')]
    #[Test]
    public function aDuplicateIdIsRefusedByTheSuggestedIdGuardAndNotByTheStaticIdGuard(array $entities, string $identifier): void
    {
        $this->expectException(\LogicException::class);
        // Not 1533734370: that guard reads a registry nothing writes, so the
        // duplicate is only noticed once the identifier is registered.
        $this->expectExceptionCode(1568146788);
        $this->expectExceptionMessage(sprintf('Cannot redeclare identifier "%s"', $identifier));

        self::factory(['pages' => []], $entities);
    }

    #[Test]
    public function twoEntityNamesSharingOneTableCollideOnTheirSeparateCounters(): void
    {
        $this->expectException(\LogicException::class);
        // The counter is keyed by entity name, the guard by table name: both
        // entities start at 10000 and the second one is refused, even though
        // neither declares an id at all.
        $this->expectExceptionCode(1568146788);
        $this->expectExceptionMessage('Cannot redeclare identifier "pages:10000"');

        self::factory(
            ['pages' => [], 'shortcuts' => ['tableName' => 'pages']],
            [
                'pages' => [['self' => ['title' => 'a']]],
                'shortcuts' => [['self' => ['title' => 'b']]],
            ]
        );
    }

    #[Test]
    public function theSuggestedIdsAreKeyedByTableAndUidAndAlwaysTrue(): void
    {
        $factory = self::factory(
            ['pages' => [], 'content' => ['tableName' => 'tt_content']],
            [
                'pages' => [['self' => ['id' => 1, 'title' => 'a']]],
                'content' => [['self' => ['header' => 'x']]],
            ]
        );

        // This is the shape `DataHandler::$suggestedInsertUids` expects, and the
        // table name is the resolved one, not the entity name.
        $this->assertSame(
            ['pages:1' => true, 'tt_content:10000' => true],
            $factory->getSuggestedIds()
        );
    }

    #[Test]
    public function theAssignedUidOverridesADeclaredUidValue(): void
    {
        $factory = self::factory(
            ['pages' => []],
            ['pages' => [['self' => ['id' => 5, 'uid' => 99, 'title' => 'a']]]]
        );

        $row = self::rowsOf($factory, 'pages')[0];

        // `uid` is written after the declared values are processed, so a `uid`
        // in the scenario is silently discarded — `id` is the way to ask for one.
        $this->assertSame(5, $row['uid']);
    }

    #[Test]
    public function aStaticIdIsAlsoWrittenAsAnIdColumnValue(): void
    {
        $factory = self::factory(
            ['pages' => []],
            ['pages' => [['self' => ['id' => 5, 'title' => 'a']]]]
        );

        $row = self::rowsOf($factory, 'pages')[0];

        // `id` is not consumed by the factory: it stays in the value set and is
        // handed to DataHandler as a column of that name.
        $this->assertSame(['id' => 5, 'title' => 'a', 'uid' => 5], $row);
    }

    #[Test]
    public function dataMapIdentifiersAreUniqueNewPrefixedAndFreeOfDots(): void
    {
        $entitySettings = ['pages' => []];
        $entities = ['pages' => [
            ['self' => ['title' => 'a']],
            ['self' => ['title' => 'b']],
        ]];

        $identifiers = array_keys(self::factory($entitySettings, $entities)->getDataMapPerWorkspace()[0]['pages']);
        $identifiersOfSecondRun = array_keys(self::factory($entitySettings, $entities)->getDataMapPerWorkspace()[0]['pages']);

        $this->assertCount(2, $identifiers);
        foreach (array_merge($identifiers, $identifiersOfSecondRun) as $identifier) {
            $this->assertStringStartsWith('NEW', $identifier);
            // `uniqid()` with more entropy returns a value containing a dot,
            // which DataHandler would read as a "table.uid" separator.
            $this->assertStringNotContainsString('.', $identifier);
        }
        // Unique within one run and across runs: nothing is derived from the
        // declaration, so two identical scenarios never share an identifier.
        $this->assertSame($identifiers, array_unique($identifiers));
        $this->assertSame([], array_intersect($identifiers, $identifiersOfSecondRun));
    }
}
