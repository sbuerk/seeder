<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * `setInDataMap()` is the single reason a scenario ends up in the order its
 * YAML declares it: DataHandler inserts new records at the top of a page, so
 * the factory rewrites every `pid` to `-<previous identifier>` to append
 * instead. Upstream ships that rule with no test at all, and no scenario
 * fixture in TYPO3 Core makes its two surprises visible:
 *
 * - The chain is complete only for `pages`. `resolveDataMapPageId()` resolves a
 *   `-<identifier>` back-reference by looking the identifier up in the data map
 *   of the table it is resolving for. `typo3/testing-framework` 9.6.1 hard
 *   codes `pages` there, which is the second divergence listed on
 *   {@see UpstreamConformanceTest} and the reason the chain of every other
 *   table used to collapse onto its first record.
 * - The same method reads `$normalizePageId[0]` unguarded, so a record with an
 *   empty `pid` makes it read an uninitialized string offset. That one is
 *   pinned as characterization: the assertion describes what the ported code
 *   does today, so that changing it is a decision someone takes with a red test
 *   in front of them rather than by accident.
 *
 * Data map keys are `uniqid('NEW', true)` and therefore differ on every run.
 * Nothing here asserts a literal key; assertions are made on values, on
 * positions within the ordered key list, and on counts.
 */
final class DataHandlerFactorySortingTest extends UnitTestCase
{
    /**
     * Modelled on the `entitySettings` block that all 20 scenario fixtures in
     * TYPO3 Core share, reduced to what sorting needs.
     */
    private const ENTITY_SETTINGS = [
        '*' => [
            'nodeColumnName' => 'pid',
            'columnNames' => ['id' => 'uid'],
            'defaultValues' => ['pid' => 0],
        ],
        'page' => [
            'isNode' => true,
            'tableName' => 'pages',
            'parentColumnName' => 'pid',
        ],
        'content' => [
            'tableName' => 'tt_content',
        ],
    ];

    /**
     * @param array<string, mixed> $entities
     * @param array<string, mixed>|null $entitySettings
     */
    private static function factory(array $entities, ?array $entitySettings = null): DataHandlerFactory
    {
        return new DataHandlerFactory([
            'entitySettings' => $entitySettings ?? self::ENTITY_SETTINGS,
            'entities' => $entities,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function identifiers(DataHandlerFactory $factory, string $tableName, int $workspaceId = 0): array
    {
        return array_keys($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? []);
    }

    /**
     * @return list<mixed>
     */
    private static function pids(DataHandlerFactory $factory, string $tableName, int $workspaceId = 0): array
    {
        return array_map(
            static fn(array $record): mixed => $record['pid'] ?? null,
            array_values($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? [])
        );
    }

    #[Test]
    public function pagesBelowTheSameParentAreChainedOneAfterTheOther(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'children' => [
                    ['self' => ['title' => 'First']],
                    ['self' => ['title' => 'Second']],
                    ['self' => ['title' => 'Third']],
                ],
            ]],
        ]);

        $identifiers = self::identifiers($factory, 'pages');

        $this->assertCount(4, $identifiers);
        // The root keeps its declared pid, the first child points at the root
        // page, and every further child points at the child before it - which
        // is what makes DataHandler append rather than prepend.
        $this->assertSame(
            [
                0,
                $identifiers[0],
                '-' . $identifiers[1],
                '-' . $identifiers[2],
            ],
            self::pids($factory, 'pages')
        );
    }

    /**
     * @return \Generator<string, array{declaredPid: int|string}>
     */
    public static function declaredPids(): \Generator
    {
        yield 'the root page id' => ['declaredPid' => 0];
        yield 'a numeric page id' => ['declaredPid' => 5];
        yield 'a page id declared as a string' => ['declaredPid' => '5'];
    }

    #[DataProvider('declaredPids')]
    #[Test]
    public function theFirstRecordOnAPageKeepsItsDeclaredPidUnchanged(int|string $declaredPid): void
    {
        $factory = self::factory([
            'content' => [
                ['self' => ['title' => 'First', 'pid' => $declaredPid]],
            ],
        ]);

        // No predecessor on that page, so no rewrite happens - and the value is
        // handed on with the type it was declared with, not normalised to int.
        $this->assertSame([$declaredPid], self::pids($factory, 'tt_content'));
    }

    #[Test]
    public function recordsOfAnyOtherTableChainBehindTheirPredecessorAsPagesDo(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'entities' => [
                    'content' => [
                        ['self' => ['title' => 'First']],
                        ['self' => ['title' => 'Second']],
                        ['self' => ['title' => 'Third']],
                    ],
                ],
            ]],
        ]);

        $page = self::identifiers($factory, 'pages')[0];
        $content = self::identifiers($factory, 'tt_content');
        $pids = self::pids($factory, 'tt_content');

        $this->assertSame($page, $pids[0]);
        $this->assertSame('-' . $content[0], $pids[1]);
        // Reaching "Second" as the predecessor means resolving the `-<First>`
        // it already carries, and that lookup goes through the data map of the
        // record's own table. `typo3/testing-framework` 9.6.1 hard codes
        // `pages` there, where a tt_content identifier never is, so "Second"
        // resolved to null, dropped out of the page filter and "Third" was
        // chained behind "First" instead - reversing the declared order from
        // the third record onwards.
        $this->assertSame('-' . $content[1], $pids[2]);
    }

    #[Test]
    public function recordsOnDifferentPagesFormSeparateChains(): void
    {
        $factory = self::factory([
            'page' => [
                [
                    'self' => ['id' => 1000, 'title' => 'A'],
                    'children' => [
                        ['self' => ['title' => 'A1']],
                        ['self' => ['title' => 'A2']],
                    ],
                ],
                [
                    'self' => ['id' => 2000, 'title' => 'B'],
                    'children' => [
                        ['self' => ['title' => 'B1']],
                        ['self' => ['title' => 'B2']],
                    ],
                ],
            ],
        ]);

        $identifiers = self::identifiers($factory, 'pages');

        // A2 is chained behind A1 and B2 behind B1, while B - the second record
        // on the root page - is chained behind A and not behind A2. The filter
        // is per resolved page, so the two subtrees never see each other.
        $this->assertSame(
            [
                0,
                $identifiers[0],
                '-' . $identifiers[1],
                '-' . $identifiers[0],
                $identifiers[3],
                '-' . $identifiers[4],
            ],
            self::pids($factory, 'pages')
        );
    }

    #[Test]
    public function aRecordWithoutAPidIsStoredWithoutASortingRewrite(): void
    {
        $factory = self::factory(
            [
                'content' => [
                    ['self' => ['title' => 'First']],
                    ['self' => ['title' => 'Second']],
                ],
            ],
            ['content' => ['tableName' => 'tt_content']]
        );

        $records = array_values($factory->getDataMapPerWorkspace()[0]['tt_content']);

        // filterDataMapByPageId() returns [] for a null page id, so neither
        // record is given a pid it did not declare. A record with no page is
        // left where the caller put it rather than being invented onto page 0.
        $this->assertCount(2, $records);
        $this->assertArrayNotHasKey('pid', $records[0]);
        $this->assertArrayNotHasKey('pid', $records[1]);
        $this->assertSame('Second', $records[1]['title']);
    }

    #[Test]
    public function everyStoredRecordCarriesAUidSoTheEmptyValuesShortCircuitIsUnreachable(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'versionVariants' => [
                    ['version' => ['workspace' => 1]],
                ],
                'children' => [
                    ['self' => ['title' => 'First']],
                ],
                'entities' => [
                    'content' => [
                        ['self' => ['title' => 'Text']],
                    ],
                ],
            ]],
        ]);

        $records = [];
        foreach ($factory->getDataMapPerWorkspace() as $tables) {
            foreach ($tables as $tableRecords) {
                foreach ($tableRecords as $record) {
                    $records[] = $record;
                }
            }
        }

        // processEntityValues() always assigns `uid`, so `$values` is never
        // empty by the time setInDataMap() sees it. The `empty($values)`
        // short-circuit at the top of that method is unreachable through the
        // public API - it is pinned negatively, by proving the precondition.
        $this->assertCount(4, $records);
        foreach ($records as $record) {
            $this->assertArrayHasKey('uid', $record);
        }
    }

    #[Test]
    public function aRecordWrittenTwiceIsChainedBehindItsPredecessorAndNotBehindNothing(): void
    {
        // An entity may map onto `pages` without being a node. Its version
        // variants then inherit the *parent* node pointer instead of their own
        // identifier, which is what lets two variants of the same record land
        // in one workspace data map on one page - the only route by which
        // setInDataMap() ever finds the identifier it is about to write already
        // present, and the only route into its `$currentIndex > 0` branch.
        $factory = self::factory(
            [
                'page' => [[
                    'self' => ['id' => 1, 'title' => 'ACME Inc'],
                    'entities' => [
                        'sub' => [
                            [
                                'self' => ['id' => 11, 'title' => 'First'],
                                'versionVariants' => [
                                    ['version' => ['workspace' => 1]],
                                ],
                            ],
                            [
                                'self' => ['id' => 12, 'title' => 'Second'],
                                'versionVariants' => [
                                    ['version' => ['workspace' => 1]],
                                    ['version' => ['workspace' => 1]],
                                ],
                            ],
                        ],
                    ],
                ]],
            ],
            [
                '*' => [
                    'nodeColumnName' => 'pid',
                    'columnNames' => ['id' => 'uid'],
                    'defaultValues' => ['pid' => 0],
                ],
                'page' => ['isNode' => true, 'tableName' => 'pages'],
                'sub' => ['tableName' => 'pages'],
            ]
        );

        $identifiers = self::identifiers($factory, 'pages', 1);
        $pids = self::pids($factory, 'pages', 1);

        $this->assertCount(2, $identifiers);
        // Upstream reads `$identifiers[$identifiers[$currentIndex - 1]]` here,
        // indexing a list by one of its own values: an undefined array key
        // warning and a pid of a bare '-'. The port indexes by position, so the
        // second variant is appended after the first record on that page.
        $this->assertSame('-' . $identifiers[0], $pids[1]);
        $this->assertNotSame('-', $pids[1]);
    }

    #[Test]
    public function anEmptyPidReadsAnUninitializedStringOffsetAndActsAsItsOwnPage(): void
    {
        /** @var list<array{int, string}> $raised */
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = [$severity, $message];
            return true;
        });
        try {
            $factory = self::factory([
                'content' => [
                    ['self' => ['title' => 'First', 'pid' => '']],
                    ['self' => ['title' => 'Second', 'pid' => '']],
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        // resolveDataMapPageId() reads `$normalizePageId[0]` before it knows the
        // string has a first character. PHP answers with '' plus a warning, so
        // the empty pid compares equal to itself and the two records are treated
        // as sitting on one page. The handler above is what keeps this suite -
        // which fails on any warning - from failing on a warning that is the
        // subject of the test. Guarding the offset read is a fix that has to
        // come back through this assertion.
        $this->assertCount(1, $raised);
        $this->assertSame(E_WARNING, $raised[0][0]);
        $this->assertStringContainsString('Uninitialized string offset', $raised[0][1]);

        $identifiers = self::identifiers($factory, 'tt_content');
        $this->assertSame(['', '-' . $identifiers[0]], self::pids($factory, 'tt_content'));
    }

    /**
     * A scenario whose workspace 1 holds a table that workspace 0 does not, so
     * that the merged table list has a duplicate before its last entry.
     */
    private static function crossWorkspaceFactory(): DataHandlerFactory
    {
        return self::factory([
            'page' => [
                ['self' => ['id' => 1, 'title' => 'Live only']],
                ['version' => ['workspace' => 1, 'id' => 2, 'title' => 'Workspace page']],
            ],
            'content' => [
                ['version' => ['workspace' => 1, 'title' => 'Workspace content']],
            ],
        ]);
    }

    #[Test]
    public function tableNamesAreReportedOnceAcrossAllWorkspaces(): void
    {
        $factory = self::crossWorkspaceFactory();

        $this->assertSame(
            [['pages'], ['pages', 'tt_content']],
            array_values(array_map('array_keys', $factory->getDataMapPerWorkspace()))
        );
        $this->assertSame(['pages', 'tt_content'], array_values($factory->getDataMapTableNames()));
    }

    #[Test]
    public function tableNamesKeepTheKeysOfTheMergedListAndAreNotRenumbered(): void
    {
        $factory = self::crossWorkspaceFactory();

        // array_unique() keeps the key of the first occurrence, so the return
        // value of getDataMapTableNames() is a map with holes, not a list. A
        // caller may foreach over it; it may not index it by position.
        $this->assertSame([0, 2], array_keys($factory->getDataMapTableNames()));
    }

    #[Test]
    public function workspacesAreKeyedByIdInTheOrderTheyWereFirstUsed(): void
    {
        $factory = self::factory([
            'page' => [
                ['version' => ['workspace' => 3, 'id' => 2, 'title' => 'Workspace page']],
                ['self' => ['id' => 1, 'title' => 'Live page']],
            ],
        ]);

        // Insertion order, not numeric order: the live workspace is not
        // guaranteed to come first, and DataHandlerWriter has to switch the
        // backend user workspace for every key it iterates.
        $this->assertSame([3, 0], array_keys($factory->getDataMapPerWorkspace()));
    }

    #[Test]
    public function theDataMapIsNestedByWorkspaceThenTableThenIdentifier(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'entities' => [
                    'content' => [
                        ['self' => ['title' => 'Text']],
                    ],
                ],
            ]],
        ]);

        $dataMap = $factory->getDataMapPerWorkspace();

        $this->assertSame([0], array_keys($dataMap));
        $this->assertSame(['pages', 'tt_content'], array_keys($dataMap[0]));
        $this->assertCount(1, $dataMap[0]['tt_content']);
        $identifier = self::identifiers($factory, 'tt_content')[0];
        $this->assertStringStartsWith('NEW', $identifier);
        $this->assertStringNotContainsString('.', $identifier);
        $this->assertSame('Text', $dataMap[0]['tt_content'][$identifier]['title']);
    }
}
