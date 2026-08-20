<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerFactory as UpstreamDataHandlerFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Drift alarm for the port of the `typo3/testing-framework` scenario importer.
 *
 * `SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory` is a copy of
 * `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerFactory`
 * (9.6.1). A copy silently rots, so this test runs the same definition through
 * both classes and compares every observable of the parse run — the data map
 * per workspace, the command map per workspace, the table names and the
 * suggested ids. If a future edit to the port changes behaviour, this fails.
 *
 * `typo3/testing-framework` stays in `require-dev`, which is what makes the
 * comparison possible at all: the upstream class is on disk for the test suite
 * and absent from a production install.
 *
 * The comparison is a unit test because `DataHandlerFactory` has no TYPO3
 * dependency on either side — it reads an array and returns arrays.
 *
 * ## Why the structures have to be normalised first
 *
 * Every record is keyed by `str_replace('.', '', uniqid('NEW', true))`. Those
 * keys therefore differ between the two factory instances *and* between two
 * runs of the same instance, and they do not only appear as keys: they are
 * written into values as well — `pid: 'NEW…'` for a node pointer, `pid: '-NEW…'`
 * for the "insert after this record" form, `l10n_parent`, `l10n_source`,
 * `t3_origuid` and the `move` entries of the command map.
 *
 * {@see self::normalize()} therefore walks both structures depth first, in
 * array order, and rewrites every occurrence of such an identifier — as an
 * array key and anywhere inside a string value, the `-NEW…` form included — to
 * `NEW#0`, `NEW#1`, … in order of first appearance. Two runs that assign their
 * identifiers in the same order and use them in the same places therefore
 * normalise to the same structure, while any difference in *which* record a
 * pointer points at survives: it lands on a different ordinal, or on none.
 *
 * The traversal order is the array order of both structures, so the ordinals of
 * the two sides only line up while the two sides agree. That is deliberate: it
 * is the very difference the test is looking for, and it is why the ordinal map
 * is built per side rather than shared.
 *
 * ## How total the conformance assertion is
 *
 * One divergence is deliberate, and it is the `elseif ($currentIndex > 0)`
 * branch of `setInDataMap()`: upstream reads
 * `$identifiers[$identifiers[$currentIndex - 1]]`, indexing a list of
 * identifiers by an identifier instead of by an index. The port reads
 * `$identifiers[$currentIndex - 1]`.
 *
 * That branch **is** reachable from the public API, but only under a
 * construction no scenario in TYPO3 Core uses, and
 * {@see self::theOneDeliberateDivergenceIsTheDoubleIndexedIdentifierList()}
 * both spells it out and pins both behaviours. Everywhere else the assertion is
 * total: all four observables are compared in full, with `assertSame()`, for
 * every definition fed in.
 *
 * The core scenarios below are proof that the branch stays out of the way in
 * practice. Upstream raises an "Undefined array key" warning when it is hit,
 * and the suite fails on a warning, so a fixture that reached it could not pass
 * this test quietly.
 *
 * ## The fixtures
 *
 * `Fixtures/CoreScenarios/` holds verbatim copies of scenario files of the
 * TYPO3 Core `main` branch — the widest available exercise of this format,
 * since the format has no test fixtures of its own upstream. They are copied
 * into this repository rather than read from an installed core, so the test
 * does not depend on a core version shipping a particular test fixture.
 *
 * | Fixture                                | Origin (TYPO3 Core `main`)                                              |
 * |----------------------------------------|-------------------------------------------------------------------------|
 * | `CommonScenario.yaml`                  | EXT:backend `Tests/Functional/Fixtures/`                                 |
 * | `PagesWithBEPermissionsScenario.yaml`  | EXT:backend `Tests/Functional/Controller/Page/Fixtures/`                 |
 * | `DefaultViewScenario.yaml`             | EXT:backend `Tests/Functional/View/Fixtures/`                            |
 * | `LanguageComparisonScenario.yaml`      | EXT:backend `Tests/Functional/View/Fixtures/`                            |
 * | `AspectScenario.yaml`                  | EXT:core `Tests/Functional/Routing/Aspect/Fixtures/`                     |
 * | `SlugScenario.yaml`                    | EXT:frontend `Tests/Functional/SiteHandling/Fixtures/`                   |
 * | `MountPointScenario.yaml`              | EXT:frontend `Tests/Functional/SiteHandling/Fixtures/`                   |
 * | `LocalizedPageRenderingScenarioD.yaml` | EXT:frontend `Tests/Functional/SiteHandling/LocalizedPageRendering/…`    |
 * | `MetadataScenario.yaml`                | EXT:frontend `Tests/Functional/Aspect/Fixtures/`                         |
 * | `ContentScenario.yaml`                 | EXT:frontend `Tests/Functional/DataProcessing/Fixtures/`                 |
 * | `HrefLangScenario.yml`                 | EXT:seo `Tests/Functional/Fixtures/`                                     |
 *
 * Between them they cover page trees, `children`, nested `entities`,
 * `languageVariants` up to three levels, `versionVariants`, `actions`,
 * `valueInstructions`, wildcard entity settings, YAML anchors and the tables
 * `pages`, `tt_content`, `sys_template`, `sys_file*`, `be_groups`, `fe_users`,
 * `fe_groups` and `sys_workspace`. What they do *not* cover — `move/toTop`,
 * `move/afterRecord`, `discard`, an entity missing from `entitySettings` — is
 * covered by the synthetic definitions further down, which no core scenario
 * exercises.
 */
final class UpstreamConformanceTest extends UnitTestCase
{
    /**
     * @return \Generator<string, array{string}>
     */
    public static function coreScenarioFileNameProvider(): \Generator
    {
        $fileNames = [
            'CommonScenario.yaml',
            'PagesWithBEPermissionsScenario.yaml',
            'DefaultViewScenario.yaml',
            'LanguageComparisonScenario.yaml',
            'AspectScenario.yaml',
            'SlugScenario.yaml',
            'MountPointScenario.yaml',
            'LocalizedPageRenderingScenarioD.yaml',
            'MetadataScenario.yaml',
            'ContentScenario.yaml',
            'HrefLangScenario.yml',
        ];
        foreach ($fileNames as $fileName) {
            yield $fileName => [$fileName];
        }
    }

    #[DataProvider('coreScenarioFileNameProvider')]
    #[Test]
    public function coreScenarioProducesTheSameMapsAsUpstream(string $fileName): void
    {
        $file = __DIR__ . '/Fixtures/CoreScenarios/' . $fileName;
        $this->assertFileExists($file);

        $expected = $this->observablesOfUpstream(UpstreamDataHandlerFactory::fromYamlFile($file));
        $actual = $this->observablesOf(DataHandlerFactory::fromYamlFile($file));

        // A scenario that parsed into nothing would make the comparison below
        // pass without comparing anything.
        $this->assertNotSame([], $actual['dataMapPerWorkspace']);
        $this->assertNotSame([], $actual['suggestedIds']);
        $this->assertSame($expected, $actual);
    }

    /**
     * The definitions the core scenarios do not contain. Every one of them
     * reaches a branch that has no consumer in TYPO3 Core, so without them the
     * conformance assertion would stop at the parts of the format that happen
     * to be in use.
     *
     * @return \Generator<string, array{array<string, mixed>}>
     */
    public static function syntheticDefinitionProvider(): \Generator
    {
        $pageEntitySettings = [
            'page' => [
                'isNode' => true,
                'tableName' => 'pages',
                'parentColumnName' => 'pid',
                'languageColumnNames' => ['l10n_parent', 'l10n_source'],
                'defaultValues' => ['pid' => 0],
            ],
            'content' => [
                'tableName' => 'tt_content',
                'nodeColumnName' => 'pid',
                'languageColumnNames' => ['l18n_parent', 'l10n_source'],
                'columnNames' => ['title' => 'header'],
            ],
        ];

        yield 'empty definition' => [[]];

        yield 'entities without entity settings' => [[
            'entities' => [
                'pages' => [
                    ['self' => ['title' => 'Undeclared entity, table name from the entity name']],
                ],
            ],
        ]];

        yield 'wildcard entity settings merged into every entity' => [[
            'entitySettings' => [
                '*' => [
                    'defaultValues' => ['hidden' => 0, 'sys_language_uid' => 0],
                    'columnNames' => ['label' => 'title'],
                ],
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'columnNames' => ['name' => 'title'],
                    // "hidden" is declared in the wildcard as well. The settings
                    // are merged with array_merge_recursive, not with
                    // array_replace_recursive, so the default value of "hidden"
                    // becomes the array [0, 1] on both sides.
                    'defaultValues' => ['hidden' => 1],
                ],
            ],
            'entities' => [
                'page' => [
                    ['self' => ['id' => 1, 'label' => 'Root', 'name' => 'Root']],
                ],
            ],
        ]];

        yield 'value instructions expanding a single value' => [[
            'entitySettings' => [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'valueInstructions' => [
                        'root' => [
                            'true' => ['is_siteroot' => 1, 'backend_layout' => 'root'],
                        ],
                    ],
                ],
            ],
            'entities' => [
                'page' => [
                    ['self' => ['id' => 1, 'title' => 'Root', 'root' => 'true']],
                    ['self' => ['id' => 2, 'title' => 'Not a root', 'root' => 'false']],
                ],
            ],
        ]];

        yield 'value instructions on a column that has an alias' => [[
            'entitySettings' => [
                'content' => [
                    'tableName' => 'tt_content',
                    'columnNames' => ['title' => 'header'],
                    // The instruction is keyed by the declared name "title",
                    // which "columnNames" maps to "header". Upstream looks the
                    // instruction up by the declared name, before the mapping.
                    'valueInstructions' => [
                        'title' => [
                            'Special' => ['CType' => 'special'],
                        ],
                    ],
                ],
            ],
            'entities' => [
                'content' => [
                    ['self' => ['id' => 300, 'title' => 'Special']],
                    ['self' => ['id' => 301, 'title' => 'Ordinary']],
                ],
            ],
        ]];

        yield 'sequential siblings and nested children on the same page' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'children' => [
                            [
                                'self' => ['id' => 1100, 'title' => 'First'],
                                'children' => [
                                    ['self' => ['id' => 1110, 'title' => 'First of first']],
                                    ['self' => ['id' => 1120, 'title' => 'Second of first']],
                                ],
                            ],
                            ['self' => ['id' => 1200, 'title' => 'Second']],
                            ['self' => ['id' => 1300, 'title' => 'Third']],
                        ],
                        'entities' => [
                            'content' => [
                                ['self' => ['id' => 300, 'title' => 'Content one']],
                                ['self' => ['id' => 301, 'title' => 'Content two']],
                                ['self' => ['id' => 302, 'title' => 'Content three']],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'nested language variants of a node and of a content record' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'languageVariants' => [
                            [
                                'self' => ['id' => 1001, 'title' => 'Root FR', 'language' => 1],
                                'languageVariants' => [
                                    ['self' => ['id' => 1002, 'title' => 'Root FR-CA', 'language' => 2]],
                                ],
                            ],
                        ],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['id' => 300, 'title' => 'Content'],
                                    'languageVariants' => [
                                        ['self' => ['id' => 301, 'title' => 'Content FR', 'language' => 1]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'version variants of a record and of its language variant' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'title' => 'Root in workspace 1']],
                            ['version' => ['workspace' => 2, 'title' => 'Root in workspace 2']],
                        ],
                        'languageVariants' => [
                            [
                                'self' => ['id' => 1001, 'title' => 'Root FR', 'language' => 1],
                                'versionVariants' => [
                                    ['version' => ['workspace' => 1, 'title' => 'Root FR in workspace 1']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'all four action types including the three without a core consumer' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['id' => 300, 'title' => 'Moved to a page'],
                                    'actions' => [
                                        ['action' => 'move', 'type' => 'toPage', 'target' => 1000],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 301, 'title' => 'Moved to the top of its node'],
                                    'actions' => [
                                        ['action' => 'move', 'type' => 'toTop'],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 302, 'title' => 'Moved after a record'],
                                    'actions' => [
                                        ['action' => 'move', 'type' => 'afterRecord', 'target' => 300],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 303, 'title' => 'Deleted'],
                                    'actions' => [
                                        ['action' => 'delete'],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 304, 'title' => 'Discarded outside a workspace'],
                                    'actions' => [
                                        ['action' => 'discard'],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 305, 'title' => 'Never moved, no target'],
                                    'actions' => [
                                        ['action' => 'move', 'type' => 'toPage'],
                                        ['action' => 'unknownAction'],
                                    ],
                                ],
                                [
                                    'self' => ['id' => 306, 'title' => 'No actions at all'],
                                    'actions' => [],
                                ],
                                [
                                    'self' => ['id' => 307, 'title' => 'Moved twice, last one wins'],
                                    'actions' => [
                                        ['action' => 'move', 'type' => 'toPage', 'target' => 1000],
                                        ['action' => 'move', 'type' => 'afterRecord', 'target' => 300],
                                        ['action' => 'delete'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'discard and delete inside a workspace' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['id' => 300, 'title' => 'Content'],
                                    'versionVariants' => [
                                        [
                                            'version' => ['workspace' => 1, 'title' => 'Content in workspace 1'],
                                            'actions' => [
                                                ['action' => 'discard'],
                                                ['action' => 'delete'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'nested entities below a non node entity are ignored' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                [
                                    'self' => ['id' => 300, 'title' => 'Content'],
                                    'entities' => [
                                        'content' => [
                                            ['self' => ['id' => 301, 'title' => 'Never created']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'static and dynamic ids mixed, version items consuming two' => [[
            'entitySettings' => $pageEntitySettings,
            'entities' => [
                'page' => [
                    ['self' => ['title' => 'Dynamic id']],
                    ['self' => ['id' => 4711, 'title' => 'Static id']],
                    [
                        'self' => ['title' => 'Dynamic id with a version variant'],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'title' => 'In workspace 1']],
                        ],
                    ],
                    ['self' => ['title' => 'Dynamic id again']],
                    ['version' => ['workspace' => 1, 'title' => 'Declared in a workspace right away']],
                ],
            ],
        ]];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[DataProvider('syntheticDefinitionProvider')]
    #[Test]
    public function syntheticDefinitionProducesTheSameMapsAsUpstream(array $definition): void
    {
        $expected = $this->observablesOfUpstream(new UpstreamDataHandlerFactory($definition));
        $actual = $this->observablesOf(new DataHandlerFactory($definition));

        $this->assertSame($expected, $actual);
    }

    /**
     * A refusal is behaviour too: a definition upstream rejects has to be
     * rejected by the port with the same exception class, code and message,
     * or a scenario that fails to import upstream would import here.
     *
     * @return \Generator<string, array{array<string, mixed>}>
     */
    public static function rejectedDefinitionProvider(): \Generator
    {
        yield 'both self and version declared' => [[
            'entities' => [
                'pages' => [
                    ['self' => ['title' => 'Root'], 'version' => ['workspace' => 1]],
                ],
            ],
        ]];

        yield 'version without a workspace' => [[
            'entities' => [
                'pages' => [
                    ['version' => ['title' => 'Root']],
                ],
            ],
        ]];

        yield 'version with workspace zero' => [[
            'entities' => [
                'pages' => [
                    ['version' => ['workspace' => 0, 'title' => 'Root']],
                ],
            ],
        ]];

        yield 'neither self nor version declared' => [[
            'entities' => [
                'pages' => [
                    ['title' => 'Root'],
                ],
            ],
        ]];

        yield 'self declared as a scalar' => [[
            'entities' => [
                'pages' => [
                    ['self' => 'Root'],
                ],
            ],
        ]];

        yield 'self inside a version variant' => [[
            'entities' => [
                'pages' => [
                    [
                        'self' => ['title' => 'Root'],
                        'versionVariants' => [
                            ['self' => ['title' => 'Not allowed here']],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'id inside a version variant' => [[
            'entities' => [
                'pages' => [
                    [
                        'self' => ['title' => 'Root'],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'id' => 4711]],
                        ],
                    ],
                ],
            ],
        ]];

        yield 'the same id declared twice' => [[
            'entities' => [
                'pages' => [
                    ['self' => ['id' => 4711, 'title' => 'First']],
                    ['self' => ['id' => 4711, 'title' => 'Second']],
                ],
            ],
        ]];

        yield 'a value instruction that is not an array' => [[
            'entitySettings' => [
                'page' => [
                    'tableName' => 'pages',
                    'valueInstructions' => ['root' => 'true'],
                ],
            ],
            'entities' => [
                'page' => [
                    ['self' => ['title' => 'Root']],
                ],
            ],
        ]];

        // "entitySettings" are merged with array_merge_recursive, so a key
        // declared in the wildcard and in the entity ends up as an array. For
        // "columnNames" that array reaches resolveColumnName(), whose return
        // type rejects it — the same TypeError on both sides.
        yield 'a column name declared in the wildcard and in the entity' => [[
            'entitySettings' => [
                '*' => ['columnNames' => ['title' => 'title']],
                'page' => ['tableName' => 'pages', 'columnNames' => ['title' => 'header']],
            ],
            'entities' => [
                'page' => [
                    ['self' => ['title' => 'Root']],
                ],
            ],
        ]];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[DataProvider('rejectedDefinitionProvider')]
    #[Test]
    public function aRejectedDefinitionIsRejectedTheSameWayUpstreamRejectsIt(array $definition): void
    {
        $upstreamFailure = $this->captureFailure(
            static fn(): object => new UpstreamDataHandlerFactory($definition)
        );
        $portedFailure = $this->captureFailure(
            static fn(): object => new DataHandlerFactory($definition)
        );

        $this->assertNotNull($upstreamFailure, 'Upstream accepted the definition, so there is nothing to compare.');
        $this->assertNotNull($portedFailure, 'The ported factory accepted a definition upstream rejects.');
        $this->assertSame($upstreamFailure::class, $portedFailure::class);
        $this->assertSame($upstreamFailure->getCode(), $portedFailure->getCode());
        $this->assertSame(
            $this->withoutNamespace($upstreamFailure->getMessage()),
            $this->withoutNamespace($portedFailure->getMessage())
        );
    }

    /**
     * A `TypeError` names the class it was raised in, which is the one thing
     * about the two implementations that is supposed to differ.
     */
    private function withoutNamespace(string $message): string
    {
        return str_replace(
            [
                'TYPO3\\TestingFramework\\Core\\Functional\\Framework\\DataHandling\\Scenario\\',
                'SBUERK\\Seeder\\Seeding\\Scenario\\',
            ],
            '',
            $message
        );
    }

    /**
     * The one place where port and upstream disagree on purpose.
     *
     * `setInDataMap()` rewrites the `pid` of a record so records end up in
     * declaration order instead of all on top of their page. Its second branch,
     * `elseif ($currentIndex > 0)`, is taken when the identifier being written
     * is *already* in the filtered data map at a position other than the first.
     * Upstream then computes
     * `$previousIndex = $identifiers[$currentIndex - 1];` followed by
     * `$values['pid'] = '-' . $identifiers[$previousIndex];` — the second lookup
     * indexes the list of identifiers by an identifier string. In PHP 8 that is
     * an "Undefined array key" warning evaluating to `null`, so the record ends
     * up with a `pid` of `'-'`, which is not a record pointer at all. The port
     * drops the second lookup and points at the preceding identifier, which is
     * what the surrounding code is for.
     *
     * Reaching the branch takes all of the following at once, which is why no
     * scenario in TYPO3 Core does:
     *
     * 1. the table has to be `pages` — `resolveDataMapPageId()` follows a
     *    `-NEW…` back reference through `dataMapPerWorkspace[ws]['pages']` and
     *    nowhere else, so on any other table a record whose `pid` was already
     *    rewritten drops out of the filter and the filtered list never grows
     *    past one entry;
     * 2. the entity must not be a node, so that the version variant inherits the
     *    node id of its parent (here: none) instead of pointing at itself;
     * 3. the item and its version variant have to declare the same non-zero
     *    workspace, so that the version variant writes over an identifier that
     *    is already in that workspace's map;
     * 4. the version variant has to declare a `pid` that two earlier records of
     *    that workspace and table already sit on.
     *
     * Upstream is invoked here through an error handler of this test's own,
     * because the suite fails on a warning and the warning is the very thing
     * being pinned. Nothing is suppressed: the warning is asserted, and the
     * handler is restored in a `finally`.
     */
    #[Test]
    public function theOneDeliberateDivergenceIsTheDoubleIndexedIdentifierList(): void
    {
        $definition = [
            'entitySettings' => [
                'page' => [
                    'tableName' => 'pages',
                    'nodeColumnName' => 'pid',
                ],
            ],
            'entities' => [
                'page' => [
                    ['version' => ['workspace' => 1, 'pid' => 1, 'title' => 'First']],
                    ['version' => ['workspace' => 1, 'pid' => 1, 'title' => 'Second']],
                    [
                        'version' => ['workspace' => 1, 'pid' => 1, 'title' => 'Third'],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'pid' => 1, 'title' => 'Third, again']],
                        ],
                    ],
                ],
            ],
        ];

        $warnings = [];
        set_error_handler(
            static function (int $number, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            }
        );
        try {
            $upstreamDataMap = (new UpstreamDataHandlerFactory($definition))->getDataMapPerWorkspace();
        } finally {
            restore_error_handler();
        }
        $portedDataMap = (new DataHandlerFactory($definition))->getDataMapPerWorkspace();

        $upstreamIdentifiers = array_keys($upstreamDataMap[1]['pages']);
        $portedIdentifiers = array_keys($portedDataMap[1]['pages']);
        $this->assertCount(3, $portedIdentifiers);
        $this->assertSame(count($upstreamIdentifiers), count($portedIdentifiers));

        // Upstream: one warning, and a "pid" that points at no record.
        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Undefined array key "NEW', $warnings[0]);
        $this->assertSame('-', $upstreamDataMap[1]['pages'][$upstreamIdentifiers[2]]['pid']);

        // Ported: no warning, and a "pid" pointing at the preceding record.
        $this->assertSame(
            '-' . $portedIdentifiers[1],
            $portedDataMap[1]['pages'][$portedIdentifiers[2]]['pid']
        );
    }

    /**
     * The normaliser is the part of this test that could make everything pass
     * for the wrong reason, so it is tested itself: two structures that differ
     * only in *which* identifier a pointer names have to stay different after
     * normalisation, and two structures that differ only in the identifiers
     * themselves have to become equal.
     */
    #[Test]
    public function theNormalizerCollapsesIdentifiersWithoutCollapsingDifferences(): void
    {
        $first = 'NEW6a8750dd09ce4005849186';
        $second = 'NEW6a8750dd09d01513664114';

        $left = [$first => ['pid' => 0], $second => ['pid' => '-' . $first]];
        $sameShapeOtherIds = [
            'NEW1111111111111000000001' => ['pid' => 0],
            'NEW2222222222222000000002' => ['pid' => '-NEW1111111111111000000001'],
        ];
        $pointingAtItself = [$first => ['pid' => 0], $second => ['pid' => '-' . $second]];

        $this->assertSame(
            ['NEW#0' => ['pid' => 0], 'NEW#1' => ['pid' => '-NEW#0']],
            $this->normalizeStructure($left)
        );
        $this->assertSame($this->normalizeStructure($left), $this->normalizeStructure($sameShapeOtherIds));
        $this->assertNotSame($this->normalizeStructure($left), $this->normalizeStructure($pointingAtItself));
        // A string that merely starts with "NEW" is not an identifier.
        $this->assertSame(['header' => 'NEWSLETTER'], $this->normalizeStructure(['header' => 'NEWSLETTER']));
    }

    /**
     * @return array{dataMapPerWorkspace: array<mixed>, commandMapPerWorkspace: array<mixed>, dataMapTableNames: array<mixed>, suggestedIds: array<mixed>}
     */
    private function observablesOf(DataHandlerFactory $factory): array
    {
        return $this->normalizeObservables(
            $factory->getDataMapPerWorkspace(),
            $factory->getCommandMapPerWorkspace(),
            $factory->getDataMapTableNames(),
            $factory->getSuggestedIds()
        );
    }

    /**
     * @return array{dataMapPerWorkspace: array<mixed>, commandMapPerWorkspace: array<mixed>, dataMapTableNames: array<mixed>, suggestedIds: array<mixed>}
     */
    private function observablesOfUpstream(UpstreamDataHandlerFactory $factory): array
    {
        return $this->normalizeObservables(
            $factory->getDataMapPerWorkspace(),
            $factory->getCommandMapPerWorkspace(),
            $factory->getDataMapTableNames(),
            $factory->getSuggestedIds()
        );
    }

    /**
     * Normalises all four observables of one factory run with a single ordinal
     * counter, so a `NEW…` identifier keeps the same ordinal in the data map,
     * the command map and everywhere it is referenced.
     *
     * @param array<mixed> $dataMapPerWorkspace
     * @param array<mixed> $commandMapPerWorkspace
     * @param array<mixed> $dataMapTableNames
     * @param array<mixed> $suggestedIds
     * @return array{dataMapPerWorkspace: array<mixed>, commandMapPerWorkspace: array<mixed>, dataMapTableNames: array<mixed>, suggestedIds: array<mixed>}
     */
    private function normalizeObservables(
        array $dataMapPerWorkspace,
        array $commandMapPerWorkspace,
        array $dataMapTableNames,
        array $suggestedIds
    ): array {
        $ordinals = [];
        return [
            'dataMapPerWorkspace' => $this->normalize($dataMapPerWorkspace, $ordinals),
            'commandMapPerWorkspace' => $this->normalize($commandMapPerWorkspace, $ordinals),
            'dataMapTableNames' => $this->normalize($dataMapTableNames, $ordinals),
            'suggestedIds' => $this->normalize($suggestedIds, $ordinals),
        ];
    }

    /**
     * @param array<mixed> $structure
     * @return array<mixed>
     */
    private function normalizeStructure(array $structure): array
    {
        $ordinals = [];
        return $this->normalize($structure, $ordinals);
    }

    /**
     * Rewrites every generated identifier to `NEW#<ordinal>`, in order of first
     * appearance of a depth first walk in array order — as an array key, as a
     * whole string value and as a substring of one, which covers the `-NEW…`
     * form used for "insert this record after that one".
     *
     * Anything that is not an array and not a string is returned untouched, so
     * the integer `uid` values and the `true` of the suggested id map stay
     * comparable with `assertSame()`.
     *
     * @param array<mixed> $structure
     * @param array<string, string> $ordinals
     * @return array<mixed>
     */
    private function normalize(array $structure, array &$ordinals): array
    {
        $normalized = [];
        foreach ($structure as $key => $value) {
            $normalizedKey = is_string($key) ? $this->normalizeString($key, $ordinals) : $key;
            if (is_array($value)) {
                $normalized[$normalizedKey] = $this->normalize($value, $ordinals);
                continue;
            }
            $normalized[$normalizedKey] = is_string($value)
                ? $this->normalizeString($value, $ordinals)
                : $value;
        }
        return $normalized;
    }

    /**
     * The identifiers are `str_replace('.', '', uniqid('NEW', true))`, which is
     * `NEW`, thirteen hexadecimal characters of the current time and the digits
     * of the added entropy. The pattern is deliberately that specific: a record
     * value that merely happens to start with `NEW` must not be rewritten.
     *
     * @param array<string, string> $ordinals
     */
    private function normalizeString(string $value, array &$ordinals): string
    {
        $normalized = preg_replace_callback(
            '/NEW[0-9a-f]{13}\d+/',
            static function (array $matches) use (&$ordinals): string {
                $identifier = $matches[0];
                if (!isset($ordinals[$identifier])) {
                    $ordinals[$identifier] = 'NEW#' . count($ordinals);
                }
                return $ordinals[$identifier];
            },
            $value
        );
        return $normalized ?? $value;
    }

    /**
     * @param \Closure(): object $subject
     */
    private function captureFailure(\Closure $subject): ?\Throwable
    {
        try {
            $subject();
        } catch (\Throwable $throwable) {
            return $throwable;
        }
        return null;
    }
}
