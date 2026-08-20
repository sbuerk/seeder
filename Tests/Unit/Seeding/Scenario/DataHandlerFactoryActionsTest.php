<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * `actions:` is the least covered corner of the scenario format. Of the 20
 * scenario fixtures in TYPO3 Core exactly one declares actions at all, and it
 * uses `delete` and `move`/`toPage`; `move`/`toTop`, `move`/`afterRecord` and
 * `discard` have no consumer anywhere and no test anywhere. Whoever writes the
 * next `actions:` block has nothing to read but the source, so this file is the
 * documentation: every branch of `setInCommandMap()` gets a case, including the
 * ones that produce nothing.
 *
 * The silent branches matter as much as the loud ones. An unknown action, a
 * `move` without a target and a `discard` outside a workspace are dropped
 * without a word - there is no `else` - so a typo in a seed set is not an error
 * but a command that never happens. That is pinned deliberately: it is the
 * behaviour the format has today, and the assertions here are what a change to
 * it has to walk through.
 *
 * Command map keys are the `uniqid('NEW', true)` identifiers of the data map
 * and differ on every run, so identifiers are always looked up from the data
 * map rather than written out.
 */
final class DataHandlerFactoryActionsTest extends UnitTestCase
{
    /**
     * The placeholder used in the data provider for "the node the record hangs
     * below", whose identifier is only known once the factory has run.
     */
    private const NODE = '{node}';

    private const ENTITY_SETTINGS = [
        '*' => [
            'nodeColumnName' => 'pid',
            'columnNames' => ['id' => 'uid', 'language' => 'sys_language_uid'],
            'defaultValues' => ['pid' => 0],
        ],
        'page' => [
            'isNode' => true,
            'tableName' => 'pages',
            'parentColumnName' => 'pid',
            'languageColumnNames' => ['l10n_parent', 'l10n_source'],
        ],
        'content' => [
            'tableName' => 'tt_content',
            'languageColumnNames' => ['l18n_parent', 'l10n_source'],
        ],
    ];

    /**
     * @param array<string, mixed> $entities
     */
    private static function factory(array $entities): DataHandlerFactory
    {
        return new DataHandlerFactory([
            'entitySettings' => self::ENTITY_SETTINGS,
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
     * @return \Generator<string, array{actions: list<array<string, mixed>>, expected: array<string, mixed>}>
     */
    public static function actionItems(): \Generator
    {
        yield 'move to a page carries the target through unchanged' => [
            'actions' => [['action' => 'move', 'type' => 'toPage', 'target' => 5]],
            'expected' => ['move' => 5],
        ];
        yield 'move to the top of the node uses the node identifier' => [
            'actions' => [['action' => 'move', 'type' => 'toTop']],
            'expected' => ['move' => self::NODE],
        ];
        yield 'move after a record negates the target' => [
            'actions' => [['action' => 'move', 'type' => 'afterRecord', 'target' => 300]],
            'expected' => ['move' => '-300'],
        ];
        yield 'delete asks for a deletion' => [
            'actions' => [['action' => 'delete']],
            'expected' => ['delete' => true],
        ];
        yield 'several actions merge into one command entry' => [
            'actions' => [
                ['action' => 'delete'],
                ['action' => 'move', 'type' => 'toPage', 'target' => 5],
            ],
            'expected' => ['delete' => true, 'move' => 5],
        ];
        yield 'a later move overwrites an earlier one' => [
            'actions' => [
                ['action' => 'move', 'type' => 'toPage', 'target' => 5],
                ['action' => 'move', 'type' => 'afterRecord', 'target' => 7],
            ],
            'expected' => ['move' => '-7'],
        ];
        yield 'an empty action list produces no command map at all' => [
            'actions' => [],
            'expected' => [],
        ];
        yield 'move to a page without a target is dropped' => [
            'actions' => [['action' => 'move', 'type' => 'toPage']],
            'expected' => [],
        ];
        yield 'move to a page with a null target is dropped' => [
            'actions' => [['action' => 'move', 'type' => 'toPage', 'target' => null]],
            'expected' => [],
        ];
        yield 'move after a record without a target is dropped' => [
            'actions' => [['action' => 'move', 'type' => 'afterRecord']],
            'expected' => [],
        ];
        yield 'move without a type is dropped' => [
            'actions' => [['action' => 'move', 'target' => 5]],
            'expected' => [],
        ];
        yield 'move with an unknown type is dropped' => [
            'actions' => [['action' => 'move', 'type' => 'toBottom', 'target' => 5]],
            'expected' => [],
        ];
        yield 'an unknown action is dropped' => [
            'actions' => [['action' => 'publish']],
            'expected' => [],
        ];
        yield 'an action item without an action key is dropped' => [
            'actions' => [['type' => 'toPage', 'target' => 5]],
            'expected' => [],
        ];
        yield 'discard outside a workspace is dropped' => [
            'actions' => [['action' => 'discard']],
            'expected' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $expected
     */
    #[DataProvider('actionItems')]
    #[Test]
    public function actionsBecomeTheCommandOfTheRecordTheyAreDeclaredOn(array $actions, array $expected): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'entities' => [
                    'content' => [
                        ['self' => ['title' => 'Text'], 'actions' => $actions],
                    ],
                ],
            ]],
        ]);

        $commandMap = $factory->getCommandMapPerWorkspace();

        if ($expected === []) {
            // No else branch, no diagnostic: an action that matches nothing
            // leaves the command map untouched, and even the workspace key is
            // never created.
            $this->assertSame([], $commandMap);
            return;
        }

        $node = self::identifiers($factory, 'pages')[0];
        $identifier = self::identifiers($factory, 'tt_content')[0];

        $this->assertSame([0], array_keys($commandMap));
        $this->assertSame(['tt_content'], array_keys($commandMap[0]));
        $this->assertSame([$identifier], array_keys($commandMap[0]['tt_content']));
        $this->assertSame(
            array_map(
                static fn(mixed $value): mixed => $value === self::NODE ? $node : $value,
                $expected
            ),
            $commandMap[0]['tt_content'][$identifier]
        );
    }

    #[Test]
    public function moveToTheTopIsDroppedWhenTheRecordHasNoNode(): void
    {
        $factory = self::factory([
            'content' => [
                [
                    'self' => ['title' => 'Text'],
                    'actions' => [['action' => 'move', 'type' => 'toTop']],
                ],
            ],
        ]);

        // `toTop` is the one action whose target is not declared but taken from
        // the surrounding node. Declared on a record that hangs below no node -
        // a top level entity, or a child, which is passed its parent's node
        // pointer of null - it evaporates.
        $this->assertSame([], $factory->getCommandMapPerWorkspace());
        $this->assertCount(1, self::identifiers($factory, 'tt_content'));
    }

    #[Test]
    public function commandsOfAVersionVariantAreKeyedByTheAncestorInsideItsWorkspace(): void
    {
        // The one shape TYPO3 Core exercises: a versioned page that is deleted
        // in the workspace it was versioned into.
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1210, 'title' => 'EN: Frontend Editing'],
                'versionVariants' => [[
                    'version' => ['workspace' => 1],
                    'actions' => [['action' => 'delete']],
                ]],
            ]],
        ]);

        $identifier = self::identifiers($factory, 'pages')[0];
        $commandMap = $factory->getCommandMapPerWorkspace();

        $this->assertSame([1], array_keys($commandMap));
        // A version variant reuses the identifier of its ancestor as the data
        // map key, so the command addresses the same NEW placeholder in
        // workspace 1 that the versioned record was written under - not the
        // live record of workspace 0.
        $this->assertSame([$identifier], self::identifiers($factory, 'pages', 1));
        $this->assertSame(['delete' => true], $commandMap[1]['pages'][$identifier]);
    }

    #[Test]
    public function discardIsOnlyIssuedInsideAWorkspace(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1210, 'title' => 'EN: Frontend Editing'],
                'versionVariants' => [[
                    'version' => ['workspace' => 1],
                    'actions' => [['action' => 'discard']],
                ]],
            ]],
        ]);

        $identifier = self::identifiers($factory, 'pages')[0];

        // `discard` becomes DataHandler's `clearWSID`, which only means
        // anything inside a workspace - hence the `> 0` guard. The provider
        // above pins the other half: the same action in the live workspace is
        // dropped instead of reported.
        $this->assertSame(
            ['clearWSID' => true],
            $factory->getCommandMapPerWorkspace()[1]['pages'][$identifier]
        );
    }

    #[Test]
    public function aLanguageVariantInheritsTheNodePointerOfItsOriginalIncludingTheMinusPrefix(): void
    {
        $pageVariant = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'languageVariants' => [[
                    'self' => ['language' => 1, 'title' => 'DE: ACME Inc'],
                    'actions' => [['action' => 'move', 'type' => 'toTop']],
                ]],
            ]],
        ]);
        $contentVariant = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'entities' => [
                    'content' => [[
                        'self' => ['title' => 'Text'],
                        'languageVariants' => [[
                            'self' => ['language' => 1, 'title' => 'DE: Text'],
                            'actions' => [['action' => 'move', 'type' => 'toTop']],
                        ]],
                    ]],
                ],
            ]],
        ]);

        $pageIdentifiers = self::identifiers($pageVariant, 'pages');
        $contentPage = self::identifiers($contentVariant, 'pages')[0];
        $contentIdentifiers = self::identifiers($contentVariant, 'tt_content');

        // A language variant of a *node* is given the node pointer of its
        // original prefixed with '-', so `toTop` on it does not mean "to the
        // top of that page" as it does everywhere else - it produces a move
        // target of `-<node>`, which DataHandler reads as "behind that record".
        $this->assertSame(
            ['move' => '-' . $pageIdentifiers[0]],
            $pageVariant->getCommandMapPerWorkspace()[0]['pages'][$pageIdentifiers[1]]
        );
        // The language variant of a record below a node inherits that node
        // pointer unchanged, and `toTop` keeps its usual meaning.
        $this->assertSame(
            ['move' => $contentPage],
            $contentVariant->getCommandMapPerWorkspace()[0]['tt_content'][$contentIdentifiers[1]]
        );
    }

    #[Test]
    public function theCommandMapCarriesNoOrderingRelativeToTheDataMap(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'entities' => [
                    'content' => [
                        [
                            'self' => ['title' => 'First'],
                            'actions' => [['action' => 'delete']],
                        ],
                        ['self' => ['title' => 'Second']],
                        [
                            'self' => ['title' => 'Third'],
                            'actions' => [['action' => 'move', 'type' => 'toPage', 'target' => 5]],
                        ],
                    ],
                ],
            ]],
        ]);

        $identifiers = self::identifiers($factory, 'tt_content');
        $commands = $factory->getCommandMapPerWorkspace()[0]['tt_content'];

        // Both maps address the same records, and neither carries a position
        // relative to the other. Every record of every workspace is written
        // before the first command runs, which is what the `@todo immediate
        // actions` note in setInCommandMap() is about: a record that declares
        // `delete` is created first and deleted afterwards, and there is no way
        // to express an action that has to happen between two records.
        $this->assertCount(3, $identifiers);
        $this->assertSame([$identifiers[0], $identifiers[2]], array_keys($commands));
        $this->assertSame(['delete' => true], $commands[$identifiers[0]]);
        $this->assertSame(['move' => 5], $commands[$identifiers[2]]);
    }

    #[Test]
    public function onlyWorkspacesWithCommandsAppearInTheCommandMap(): void
    {
        $factory = self::factory([
            'page' => [[
                'self' => ['id' => 1000, 'title' => 'ACME Inc'],
                'actions' => [['action' => 'move', 'type' => 'toPage', 'target' => 5]],
                'versionVariants' => [
                    ['version' => ['workspace' => 1]],
                ],
            ]],
        ]);

        // The data map has a workspace 1, the command map does not: the maps
        // are built independently, and a workspace key only exists once
        // something was put into it.
        $this->assertSame([0, 1], array_keys($factory->getDataMapPerWorkspace()));
        $this->assertSame([0], array_keys($factory->getCommandMapPerWorkspace()));
    }
}
