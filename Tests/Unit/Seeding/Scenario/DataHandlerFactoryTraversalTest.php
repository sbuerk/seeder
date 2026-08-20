<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A scenario is a tree, and the factory flattens it into a data map. What that
 * flattening does with the two pointers it maintains — the current node and
 * the current parent — is where a scenario quietly seeds the wrong shape: a
 * nested `entities:` block below an entity that is not a node is dropped
 * without a word, and a nested block *does* inherit the node of the item it
 * sits in while keeping that item's own parent, so a page nested below a child
 * page is attached to its grandparent. Neither is stated anywhere, and both
 * produce a plausible looking import.
 *
 * The second half of this class pins the declaration rules of `self:` and
 * `version:`. They are guards, they fire in a fixed order, and the order is
 * load bearing: `version:` is checked for a workspace with `empty()`, so
 * `workspace: 0` — a legitimate looking way of saying "live" — is refused,
 * and an unusable `version:` never reaches the "missing declaration" guard at
 * all.
 *
 * The entity settings below deliberately use `node`/`parent` columns instead of
 * `pid` wherever the tree shape itself is under test: without a `pid` the
 * sorting pass in `setInDataMap()` cannot rewrite anything, so what is asserted
 * is the traversal and nothing else.
 */
final class DataHandlerFactoryTraversalTest extends UnitTestCase
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const TREE_SETTINGS = [
        'pages' => ['isNode' => true, 'nodeColumnName' => 'node', 'parentColumnName' => 'parent'],
        'content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'node', 'parentColumnName' => 'parent'],
    ];

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
     * @return list<string>
     */
    private static function identifiersOf(DataHandlerFactory $factory, string $tableName, int $workspaceId = 0): array
    {
        return array_keys($factory->getDataMapPerWorkspace()[$workspaceId][$tableName] ?? []);
    }

    #[Test]
    public function everyItemOfEveryEntityBecomesOneRecordInTheTableOfThatEntity(): void
    {
        $factory = self::factory(self::TREE_SETTINGS, [
            'content' => [
                ['self' => ['header' => 'first']],
                ['self' => ['header' => 'second']],
            ],
            'pages' => [
                ['self' => ['title' => 'a page']],
            ],
        ]);

        // The keys of `entities` are entity names, not table names, and the
        // tables appear in the order the entities are declared.
        $this->assertSame(['tt_content', 'pages'], $factory->getDataMapTableNames());
        $this->assertSame(
            [
                ['header' => 'first', 'uid' => 10000],
                ['header' => 'second', 'uid' => 10001],
            ],
            self::rowsOf($factory, 'tt_content')
        );
        $this->assertSame(
            [['title' => 'a page', 'uid' => 10000]],
            self::rowsOf($factory, 'pages')
        );
    }

    #[Test]
    public function childrenBecomeRecordsOfTheSameEntityPointingAtTheirParent(): void
    {
        $factory = self::factory(self::TREE_SETTINGS, [
            'pages' => [[
                'self' => ['title' => 'root'],
                'children' => [[
                    'self' => ['title' => 'child'],
                    'children' => [
                        ['self' => ['title' => 'grandchild']],
                    ],
                ]],
            ]],
        ]);

        $identifiers = self::identifiersOf($factory, 'pages');
        $rows = self::rowsOf($factory, 'pages');

        $this->assertCount(3, $rows);
        // A child is an item of the *same* entity, so it lands in the same
        // table and draws from the same id counter …
        $this->assertSame([10000, 10001, 10002], array_column($rows, 'uid'));
        // … and the only thing that makes it a child is the parent pointer.
        $this->assertArrayNotHasKey('parent', $rows[0]);
        $this->assertSame($identifiers[0], $rows[1]['parent']);
        $this->assertSame($identifiers[1], $rows[2]['parent']);
        // Children never become a node, not even for a node entity: the node
        // pointer is whatever the outer level handed down, here nothing.
        $this->assertArrayNotHasKey('node', $rows[1]);
        $this->assertArrayNotHasKey('node', $rows[2]);
    }

    /**
     * @return \Generator<string, array{isNode: mixed, expectedContentRecords: int}>
     */
    public static function nodeDeclarations(): \Generator
    {
        yield 'a node processes the nested entities' => [
            'isNode' => true,
            'expectedContentRecords' => 1,
        ];
        yield 'a non node drops them silently' => [
            'isNode' => false,
            'expectedContentRecords' => 0,
        ];
        yield 'the string "false" is a node, because isNode is cast to bool' => [
            'isNode' => 'false',
            'expectedContentRecords' => 1,
        ];
        yield 'an integer 1 is a node' => [
            'isNode' => 1,
            'expectedContentRecords' => 1,
        ];
        yield 'an integer 0 is not a node' => [
            'isNode' => 0,
            'expectedContentRecords' => 0,
        ];
        yield 'an unset isNode is not a node' => [
            'isNode' => null,
            'expectedContentRecords' => 0,
        ];
    }

    #[DataProvider('nodeDeclarations')]
    #[Test]
    public function nestedEntitiesAreProcessedOnlyBelowANodeEntity(mixed $isNode, int $expectedContentRecords): void
    {
        $pageSettings = ['nodeColumnName' => 'node', 'parentColumnName' => 'parent'];
        if ($isNode !== null) {
            $pageSettings['isNode'] = $isNode;
        }

        $factory = self::factory(
            ['pages' => $pageSettings] + self::TREE_SETTINGS,
            ['pages' => [[
                'self' => ['title' => 'root'],
                'entities' => ['content' => [['self' => ['header' => 'nested']]]],
            ]]]
        );

        // The page itself is always seeded; only the nested block depends on
        // the entity being a node — and when it is dropped, nothing says so.
        $this->assertCount(1, self::rowsOf($factory, 'pages'));
        $this->assertCount($expectedContentRecords, self::rowsOf($factory, 'tt_content'));
    }

    #[Test]
    public function anEmptyNestedEntitiesBlockSeedsNothing(): void
    {
        $factory = self::factory(self::TREE_SETTINGS, [
            'pages' => [[
                'self' => ['title' => 'root'],
                'entities' => [],
            ]],
        ]);

        $this->assertSame(['pages'], $factory->getDataMapTableNames());
    }

    #[Test]
    public function nestedEntitiesTakeTheNodeOfTheirItemButKeepItsParent(): void
    {
        $factory = self::factory(self::TREE_SETTINGS, [
            'pages' => [[
                'self' => ['title' => 'root'],
                'children' => [[
                    'self' => ['title' => 'child'],
                    'entities' => ['pages' => [['self' => ['title' => 'nested']]]],
                ]],
            ]],
        ]);

        $identifiers = self::identifiersOf($factory, 'pages');
        $rows = self::rowsOf($factory, 'pages');

        $this->assertSame(['root', 'child', 'nested'], array_column($rows, 'title'));
        // The nested item is below "child" as a node …
        $this->assertSame($identifiers[1], $rows[2]['node']);
        // … but its parent is the parent of "child", the root — the recursion
        // hands its own `$parentId` down unchanged. With `pid` serving as both
        // columns, as pages usually do, the parent wins and the nested page is
        // attached one level too high.
        $this->assertSame($identifiers[0], $rows[2]['parent']);
    }

    #[Test]
    public function theNodePointerIsWrittenOnlyWhenThereIsANodeAndAColumnForIt(): void
    {
        $factory = self::factory(
            [
                'pages' => ['isNode' => true, 'nodeColumnName' => 'node'],
                // No nodeColumnName: this entity cannot express where it sits.
                'content' => ['tableName' => 'tt_content'],
            ],
            ['pages' => [[
                'self' => ['title' => 'root'],
                'entities' => [
                    'pages' => [['self' => ['title' => 'nested']]],
                    'content' => [['self' => ['header' => 'nested']]],
                ],
            ]]]
        );

        $pages = self::rowsOf($factory, 'pages');

        // A top level item has no node to point at.
        $this->assertArrayNotHasKey('node', $pages[0]);
        $this->assertSame(self::identifiersOf($factory, 'pages')[0], $pages[1]['node']);
        // And an entity without a node column is seeded without a pointer
        // rather than with an invented one.
        $this->assertSame([['header' => 'nested', 'uid' => 10000]], self::rowsOf($factory, 'tt_content'));
    }

    #[Test]
    public function theParentPointerIsWrittenOnlyWhenThereIsAParentAndAColumnForIt(): void
    {
        $factory = self::factory(
            // No parentColumnName: children are still seeded, unattached.
            ['content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'node']],
            ['content' => [[
                'self' => ['header' => 'first'],
                'children' => [['self' => ['header' => 'second']]],
            ]]]
        );

        $this->assertSame(
            [
                ['header' => 'first', 'uid' => 10000],
                ['header' => 'second', 'uid' => 10001],
            ],
            self::rowsOf($factory, 'tt_content')
        );
    }

    #[Test]
    public function aDeepTreeIsFlattenedNodeByNode(): void
    {
        $factory = self::factory(self::TREE_SETTINGS, [
            'pages' => [[
                'self' => ['title' => 'root'],
                'children' => [[
                    'self' => ['title' => 'sub'],
                    'entities' => ['content' => [[
                        'self' => ['header' => 'sub content'],
                        'children' => [['self' => ['header' => 'sub content child']]],
                    ]]],
                ]],
                'entities' => ['content' => [['self' => ['header' => 'root content']]]],
            ]],
        ]);

        $pageIdentifiers = self::identifiersOf($factory, 'pages');
        $pages = self::rowsOf($factory, 'pages');
        $contents = self::rowsOf($factory, 'tt_content');

        // Depth first, and children before the nested entities of the same
        // item: the whole subtree of "sub" is walked before "root content".
        $this->assertSame(['root', 'sub'], array_column($pages, 'title'));
        $this->assertSame(
            ['sub content', 'sub content child', 'root content'],
            array_column($contents, 'header')
        );
        // "sub content" sits on "sub", and its own child inherits that node
        // while pointing at "sub content" as its parent.
        $this->assertSame($pageIdentifiers[1], $contents[0]['node']);
        $this->assertSame($pageIdentifiers[1], $contents[1]['node']);
        $this->assertSame(self::identifiersOf($factory, 'tt_content')[0], $contents[1]['parent']);
        // "root content" sits on the root page.
        $this->assertSame($pageIdentifiers[0], $contents[2]['node']);
    }

    #[Test]
    public function declaringSelfAndVersionTogetherIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1534872399);
        $this->expectExceptionMessage('Cannot declare both "self" and "version" for entity "pages"');

        self::factory(['pages' => []], ['pages' => [[
            'self' => ['title' => 'a'],
            'version' => ['workspace' => 1],
        ]]]);
    }

    #[Test]
    public function aNullVersionIsNotADeclarationAndLeavesSelfAlone(): void
    {
        // The guard above uses isset(), so an explicitly nulled `version:` is
        // not "declared" and the item is seeded from `self:` as usual.
        $factory = self::factory(['pages' => []], ['pages' => [[
            'self' => ['title' => 'a'],
            'version' => null,
        ]]]);

        $this->assertSame([['title' => 'a', 'uid' => 10000]], self::rowsOf($factory, 'pages'));
    }

    /**
     * @return \Generator<string, array{version: mixed}>
     */
    public static function unusableVersionDeclarations(): \Generator
    {
        yield 'no workspace at all' => ['version' => ['title' => 'a']];
        yield 'workspace 0, the live workspace' => ['version' => ['workspace' => 0, 'title' => 'a']];
        yield 'workspace "0" as a string' => ['version' => ['workspace' => '0', 'title' => 'a']];
        yield 'an empty workspace' => ['version' => ['workspace' => '', 'title' => 'a']];
        yield 'a null workspace' => ['version' => ['workspace' => null, 'title' => 'a']];
        yield 'a false workspace' => ['version' => ['workspace' => false, 'title' => 'a']];
        yield 'an empty version' => ['version' => []];
        yield 'a version that is not an array' => ['version' => 'a'];
    }

    #[DataProvider('unusableVersionDeclarations')]
    #[Test]
    public function aVersionWithoutAUsableWorkspaceIsRefused(mixed $version): void
    {
        $this->expectException(\LogicException::class);
        // The check is `empty()`, so `workspace: 0` is refused although it
        // names a real workspace. There is no way to declare a version in the
        // live workspace, and the message does not hint at that.
        $this->expectExceptionCode(1534872400);
        $this->expectExceptionMessage('Cannot declare "version" without "workspace" for entity "pages"');

        self::factory(['pages' => []], ['pages' => [['version' => $version]]]);
    }

    /**
     * @return \Generator<string, array{itemSettings: array<string, mixed>}>
     */
    public static function unusableSelfDeclarations(): \Generator
    {
        yield 'no self key at all' => ['itemSettings' => ['title' => 'a']];
        yield 'a null self' => ['itemSettings' => ['self' => null]];
        yield 'an empty self' => ['itemSettings' => ['self' => []]];
        yield 'an empty string' => ['itemSettings' => ['self' => '']];
        yield 'a self that is not an array' => ['itemSettings' => ['self' => 'a']];
        yield 'a zero self' => ['itemSettings' => ['self' => 0]];
        // `version: null` is not a declaration, so the item is judged by the
        // `self:` it does not have.
        yield 'a null version and no self' => ['itemSettings' => ['version' => null]];
    }

    /**
     * @param array<string, mixed> $itemSettings
     */
    #[DataProvider('unusableSelfDeclarations')]
    #[Test]
    public function aMissingOrUnusableSelfDeclarationIsRefused(array $itemSettings): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1533734369);
        // Always "self": an unusable `version:` is caught by the workspace
        // guard first, so this message never names "version" in practice.
        $this->expectExceptionMessage('Missing "self" declaration for entity "pages"');

        self::factory(['pages' => []], ['pages' => [$itemSettings]]);
    }

    #[Test]
    public function aVersionItemIsStoredInTheDataMapOfItsWorkspace(): void
    {
        $factory = self::factory(['pages' => []], ['pages' => [
            ['version' => ['workspace' => '2', 'title' => 'versioned']],
            ['self' => ['title' => 'live']],
        ]]);

        // The workspace is read from the raw item settings and cast to int, so
        // the map is keyed by 2 even though the scenario said "2".
        $this->assertSame([2, 0], array_keys($factory->getDataMapPerWorkspace()));
        // It also stays a plain value of the record, because `version:` is
        // processed like any other value set.
        $this->assertSame(
            [['workspace' => '2', 'title' => 'versioned', 'uid' => 10000]],
            self::rowsOf($factory, 'pages', 2)
        );
        $this->assertSame([['title' => 'live', 'uid' => 10002]], self::rowsOf($factory, 'pages'));
    }
}
