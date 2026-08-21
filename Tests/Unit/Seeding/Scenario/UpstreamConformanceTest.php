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
 * Five divergences from upstream are deliberate in the class this test
 * compares - `DataHandlerWriter` has three more of its own, and they are covered
 * by `DataHandlerWriterSubstitutionTest` and by the functional tests. Every one
 * of the five is a defect of the upstream engine that this extension fixes,
 * every one has a test of its own below that runs both classes and states the
 * difference, and none of them loosens the comparison for anything else:
 *
 * | Divergence                                                        | How this test stays total                     |
 * |-------------------------------------------------------------------|-----------------------------------------------|
 * | `setInDataMap()` indexes the identifier list by an identifier     | unreachable by the definitions fed in below   |
 * | a `-NEW…` back reference is resolved through `pages` only         | modelled onto the upstream side, see below    |
 * | `discard` emits `clearWSID` as a command name                     | modelled onto the upstream side, see below    |
 * | the guard on a duplicate `id:` reads a registry nothing writes    | reached only by a refused definition          |
 * | a version variant takes its workspace from the processed values   | reached only by a remapped `workspace` column |
 *
 * Two of them change the maps that ordinary definitions produce, so leaving
 * them out of the assertion would take the core scenarios with them — eight of
 * the eleven differ in the sibling chain alone. Instead
 * {@see self::observablesOfUpstream()} applies both of them **to upstream's own
 * output** before the comparison, so all four observables stay under one
 * `assertSame()` for every definition fed in and any *other* drift still fails.
 *
 * That the two models are written against upstream's output rather than sharing
 * code with the fix is what keeps them honest: they cannot reproduce a mistake
 * in the fix, because they do not run it. Each is additionally pinned by a test
 * that compares port and upstream directly, on a definition small enough to
 * write the expected values down.
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
     * 1. two records of the table have to sit on the page already, which
     *    upstream reaches on `pages` only — its `resolveDataMapPageId()`
     *    follows a `-NEW…` back reference through
     *    `dataMapPerWorkspace[ws]['pages']` whatever table it is resolving,
     *    so on any other table the filtered list never grows past one entry.
     *    The port resolves through the record's own table, which is the
     *    second divergence below, so for it the table may be any;
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
    public function theIdentifierListIsIndexedByAnIndexRatherThanByAnIdentifier(): void
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
     * The second deliberate divergence, on the definition the model in
     * {@see self::withSequentialSiblingChains()} is derived from.
     *
     * `setInDataMap()` gives every record after the first on a page a `pid` of
     * `-<identifier of the record before it>`, which is how the declared order
     * of a scenario survives into `sorting`. Reaching the record before it
     * means resolving the back reference the *previous* record already carries,
     * and upstream resolves every back reference through
     * `dataMapPerWorkspace[ws]['pages']` whatever table it is looking at. On
     * `tt_content` that lookup misses, the second record drops out of the
     * filtered list, and the third is chained behind the first — so from the
     * third record onwards the declared order is reversed in the backend.
     *
     * The port resolves through the table the record belongs to. `pages` is
     * unaffected: there the two expressions are the same expression.
     */
    #[Test]
    public function aBackReferenceIsResolvedThroughTheTableItWasWrittenIn(): void
    {
        $definition = [
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages', 'defaultValues' => ['pid' => 0]],
                'content' => ['tableName' => 'tt_content', 'nodeColumnName' => 'pid'],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'entities' => [
                            'content' => [
                                ['self' => ['id' => 300, 'title' => 'First']],
                                ['self' => ['id' => 301, 'title' => 'Second']],
                                ['self' => ['id' => 302, 'title' => 'Third']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $upstream = (new UpstreamDataHandlerFactory($definition))->getDataMapPerWorkspace()[0]['tt_content'];
        $ported = (new DataHandlerFactory($definition))->getDataMapPerWorkspace()[0]['tt_content'];

        $upstreamIdentifiers = array_keys($upstream);
        $portedIdentifiers = array_keys($ported);
        $this->assertCount(3, $portedIdentifiers);

        // The first record of the page carries the page pointer on both sides,
        // and the second is chained behind it on both sides.
        $this->assertSame(
            '-' . $upstreamIdentifiers[0],
            $upstream[$upstreamIdentifiers[1]]['pid']
        );
        $this->assertSame(
            '-' . $portedIdentifiers[0],
            $ported[$portedIdentifiers[1]]['pid']
        );

        // The third is where they part.
        $this->assertSame(
            '-' . $upstreamIdentifiers[0],
            $upstream[$upstreamIdentifiers[2]]['pid'],
            'Upstream is expected to chain the third record behind the first.'
        );
        $this->assertSame(
            '-' . $portedIdentifiers[1],
            $ported[$portedIdentifiers[2]]['pid'],
            'The port is expected to chain the third record behind the second.'
        );
    }

    /**
     * The third deliberate divergence.
     *
     * `{action: 'discard'}` is meant to throw a workspace version away again.
     * Upstream writes `clearWSID` into the command map as the *command name*,
     * and `DataHandler::process_cmdmap()` has no case for it in v13 or in v14 —
     * it runs the hooks, matches nothing, and moves on, so the action does
     * nothing at all and says nothing about it. `clearWSID` is an *action* of
     * the `version` command, which is how the testing framework's own
     * `ActionService` discards a record.
     */
    #[Test]
    public function theDiscardActionEmitsACommandDataHandlerStillKnows(): void
    {
        $definition = [
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages', 'defaultValues' => ['pid' => 0]],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'versionVariants' => [
                            [
                                'version' => ['workspace' => 1, 'title' => 'In workspace 1'],
                                'actions' => [['action' => 'discard']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $upstream = (new UpstreamDataHandlerFactory($definition))->getCommandMapPerWorkspace()[1]['pages'];
        $ported = (new DataHandlerFactory($definition))->getCommandMapPerWorkspace()[1]['pages'];

        $this->assertSame([['clearWSID' => true]], array_values($upstream));
        $this->assertSame([['version' => ['action' => 'clearWSID']]], array_values($ported));
    }

    /**
     * The fourth deliberate divergence.
     *
     * `hasStaticId()` reads `$staticIdsPerEntity`, which upstream declares and
     * never writes, so the guard is always false and its exception 1533734370
     * cannot be raised. A duplicate `id:` is caught one step later by
     * `addSuggestedId()`, which reports the table and the uid it resolved to
     * rather than the entity and the id that was written down. Both refuse the
     * definition, so nothing is imported that should not be — this is about
     * which of the two messages a set author gets.
     */
    #[Test]
    public function aDuplicateStaticIdIsRefusedByTheGuardMeantToRefuseIt(): void
    {
        $definition = [
            'entitySettings' => [
                'page' => ['isNode' => true, 'tableName' => 'pages'],
            ],
            'entities' => [
                'page' => [
                    ['self' => ['id' => 4711, 'title' => 'First']],
                    ['self' => ['id' => 4711, 'title' => 'Second']],
                ],
            ],
        ];

        $upstreamFailure = $this->captureFailure(
            static fn(): object => new UpstreamDataHandlerFactory($definition)
        );
        $portedFailure = $this->captureFailure(
            static fn(): object => new DataHandlerFactory($definition)
        );

        $this->assertInstanceOf(\LogicException::class, $upstreamFailure);
        $this->assertSame(1568146788, $upstreamFailure->getCode());
        $this->assertSame('Cannot redeclare identifier "pages:4711" with "4711"', $upstreamFailure->getMessage());

        $this->assertInstanceOf(\LogicException::class, $portedFailure);
        $this->assertSame(1533734370, $portedFailure->getCode());
        $this->assertSame('Cannot assign ID "4711" multiple times', $portedFailure->getMessage());
    }

    /**
     * The fifth deliberate divergence.
     *
     * `processVersionVariantItem()` decides which workspace the variant belongs
     * to. Upstream reads that from the *processed* values, after `columnNames`
     * has been applied, so an entity that maps `workspace` onto another column
     * leaves no `workspace` key for it to read: the expression warns about an
     * undefined key, evaluates to workspace 0, and the version variant is
     * written into the live workspace — over the very record it was declared to
     * version. The port reads the declared value, as every other call site of
     * `setInDataMap()` does.
     *
     * Upstream is invoked through an error handler of this test's own because
     * the suite fails on a warning and the warning is part of what is pinned.
     * Nothing is suppressed: the warning is asserted, and the handler is
     * restored in a `finally`.
     */
    #[Test]
    public function aRemappedWorkspaceColumnStillRoutesTheVersionIntoItsWorkspace(): void
    {
        $definition = [
            'entitySettings' => [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'columnNames' => ['workspace' => 't3ver_wsid'],
                    'defaultValues' => ['pid' => 0],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['id' => 1000, 'title' => 'Root'],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'title' => 'In workspace 1']],
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
            $upstream = (new UpstreamDataHandlerFactory($definition))->getDataMapPerWorkspace();
        } finally {
            restore_error_handler();
        }
        $ported = (new DataHandlerFactory($definition))->getDataMapPerWorkspace();

        $this->assertSame(['Undefined array key "workspace"'], $warnings);
        // Upstream: no workspace 1 at all, and the live record replaced by the
        // values of the version that should have gone next to it.
        $this->assertSame([0], array_keys($upstream));
        $this->assertSame(
            ['In workspace 1'],
            array_column($upstream[0]['pages'], 'title')
        );

        // Ported: the live record is left alone and the version is in the
        // workspace it declares.
        $this->assertSame([0, 1], array_keys($ported));
        $this->assertSame(['Root'], array_column($ported[0]['pages'], 'title'));
        $this->assertSame(['In workspace 1'], array_column($ported[1]['pages'], 'title'));
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
     * Upstream's four observables, with the two deliberate divergences that
     * ordinary definitions reach applied to them. See the class docblock for
     * why they are modelled here instead of being excluded from the assertion.
     *
     * @return array{dataMapPerWorkspace: array<mixed>, commandMapPerWorkspace: array<mixed>, dataMapTableNames: array<mixed>, suggestedIds: array<mixed>}
     */
    private function observablesOfUpstream(UpstreamDataHandlerFactory $factory): array
    {
        return $this->normalizeObservables(
            $this->withSequentialSiblingChains($factory->getDataMapPerWorkspace()),
            $this->withDiscardEmittedAsAVersionCommand($factory->getCommandMapPerWorkspace()),
            $factory->getDataMapTableNames(),
            $factory->getSuggestedIds()
        );
    }

    /**
     * Models the first divergence: a `-NEW…` back reference is resolved through
     * the data map of the record's own table rather than through `pages`.
     *
     * The table the lookup is hard coded to is what bounds this: on `pages`
     * upstream and the port run the identical expression, so the two cannot
     * differ there and the model must not touch it. `pages` is also the only
     * table on which a `-NEW…` pointer is ever *handed to* a record rather than
     * computed for it — `processEntityItem()` passes `'-' . $newId` as the node
     * id of a language variant of a node — and those pointers are shared by
     * every variant of one record on both sides.
     *
     * On every other table upstream's filtered list never grows past its first
     * entry, so each record after the first points at the *first* record of the
     * page instead of at the one declared before it. Undoing that needs no
     * knowledge of how the fix works, only of what a chain looks like: the
     * records of one table of one workspace that point at the same record are
     * the ones upstream failed to chain, and in the order they were written
     * each of them points at the one before it.
     *
     * @param array<int, array<string, array<string, array<string, mixed>>>> $dataMapPerWorkspace
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    private function withSequentialSiblingChains(array $dataMapPerWorkspace): array
    {
        foreach ($dataMapPerWorkspace as $workspaceId => $tableDataMaps) {
            foreach ($tableDataMaps as $tableName => $tableDataMap) {
                if ($tableName === 'pages') {
                    continue;
                }
                $lastOfChain = [];
                foreach ($tableDataMap as $identifier => $values) {
                    $pageId = $values['pid'] ?? null;
                    if (!is_string($pageId) || !str_starts_with($pageId, '-')) {
                        continue;
                    }
                    $referenced = substr($pageId, 1);
                    // Not a record of this table: upstream's own defective
                    // branch writes a bare "-", and that one is pinned by a
                    // test of its own rather than modelled away here.
                    if (!isset($tableDataMap[$referenced])) {
                        continue;
                    }
                    $dataMapPerWorkspace[$workspaceId][$tableName][$identifier]['pid']
                        = '-' . ($lastOfChain[$referenced] ?? $referenced);
                    $lastOfChain[$referenced] = $identifier;
                }
            }
        }
        return $dataMapPerWorkspace;
    }

    /**
     * Models the second divergence: `{action: 'discard'}` emits the `version`
     * command with the `clearWSID` action rather than a command literally
     * called `clearWSID`, which `DataHandler::process_cmdmap()` has no case
     * for.
     *
     * The rename keeps the position of the entry, because a record may declare
     * `discard` and `delete` and the command map is processed in order.
     *
     * @param array<int, array<string, array<string, array<string, mixed>>>> $commandMapPerWorkspace
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    private function withDiscardEmittedAsAVersionCommand(array $commandMapPerWorkspace): array
    {
        foreach ($commandMapPerWorkspace as $workspaceId => $tableCommandMaps) {
            foreach ($tableCommandMaps as $tableName => $tableCommandMap) {
                foreach ($tableCommandMap as $identifier => $commands) {
                    if (!array_key_exists('clearWSID', $commands)) {
                        continue;
                    }
                    $renamed = [];
                    foreach ($commands as $command => $value) {
                        if ($command === 'clearWSID') {
                            $renamed['version'] = ['action' => 'clearWSID'];
                            continue;
                        }
                        $renamed[$command] = $value;
                    }
                    $commandMapPerWorkspace[$workspaceId][$tableName][$identifier] = $renamed;
                }
            }
        }
        return $commandMapPerWorkspace;
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
