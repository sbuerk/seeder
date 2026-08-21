<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedDefinitionNotFoundException;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

require_once __DIR__ . '/Fixtures/Composer/ShadowedIsReadable.php';

/**
 * {@see ScenarioComposer} turns the scenario files a set declares into the one
 * scenario the import is written from, and it is the only place where this
 * extension looks inside a file whose format belongs to
 * `typo3/testing-framework`.
 *
 * Three things are its own and are pinned here:
 *
 * - **Where a scenario may live.** `EXT:`, absolute, or relative to the
 *   directory holding the set - and an absolute path deliberately *not* run
 *   through `GeneralUtility::getFileAbsFileName()`, which would turn a set
 *   below `vendor/` into "the scenario does not exist".
 * - **That the file is a scenario at all.** The key set is closed, and every
 *   shape the factory would answer with a `TypeError` is refused with a message
 *   naming the file and the entity instead.
 * - **The merge**, which is defined per key because the two keys mean opposite
 *   things: `entitySettings` describe *how* a table is written and a later file
 *   overrides them, `entities` are the records and a later file adds to them.
 *
 * On top of that sits the `--root-page` transformation, which rewrites the
 * `pid` of top level items only.
 *
 * Assertions are on {@see ScenarioComposer::composeSettings()} where the
 * statement is about the composition, and on the built factory where it is
 * about the outcome - the data map keys of which are `uniqid('NEW', true)` and
 * therefore normalised to `NEW1`, `NEW2`, … before they are compared.
 */
final class ScenarioComposerTest extends UnitTestCase
{
    /**
     * The one scenario `Fixtures/Composer/ShadowedIsReadable.php` answers as
     * unreadable. It is a normal, readable fixture - see that file for why the
     * answer is shadowed rather than the permission bits changed.
     */
    public const UNREADABLE_SCENARIO = __DIR__ . '/Fixtures/Composer/Unreadable.yaml';

    private const FIXTURE_PATH = __DIR__ . '/Fixtures/Composer';

    protected bool $backupEnvironment = true;

    private ScenarioComposer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ScenarioComposer();
    }

    /**
     * @param list<string> $scenarios
     */
    private function definition(array $scenarios, string $basePath = self::FIXTURE_PATH): SeedDefinition
    {
        return new SeedDefinition(
            identifier: 'demo',
            title: 'Demo',
            basePath: $basePath,
            scenarios: $scenarios,
        );
    }

    #[Test]
    public function aRelativeScenarioResolvesAgainstTheDirectoryHoldingTheSet(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['Base.yaml']));

        $this->assertSame(self::baseEntities(), $settings['entities']);
    }

    #[Test]
    public function aRelativeScenarioMayPointIntoASubDirectoryOfTheSet(): void
    {
        $settings = $this->subject->composeSettings(
            $this->definition(['Composer/Base.yaml'], dirname(self::FIXTURE_PATH)),
        );

        $this->assertSame(self::baseEntities(), $settings['entities']);
    }

    #[Test]
    public function anAbsoluteScenarioIsReadWithoutABasePath(): void
    {
        $settings = $this->subject->composeSettings(
            $this->definition([self::FIXTURE_PATH . '/Base.yaml'], ''),
        );

        $this->assertSame(self::baseEntities(), $settings['entities']);
    }

    #[Test]
    public function anAbsoluteScenarioOutsideTheProjectPathIsStillRead(): void
    {
        // The case the whole exception in "resolvePath()" exists for: a set
        // shipped by a package sits below "vendor/", which a project with a
        // "public/" below it puts outside the public path - and an installation
        // is free to put the project path somewhere else entirely.
        $scenario = $this->writeScenarioOutsideTheProjectPath();

        $this->assertSame('', GeneralUtility::getFileAbsFileName($scenario));

        $settings = $this->subject->composeSettings($this->definition([$scenario], ''));

        $this->assertSame(
            ['page' => [['self' => ['id' => 1, 'title' => 'Outside']]]],
            $settings['entities'],
        );
    }

    #[Test]
    public function aRelativeScenarioWithoutABasePathIsNotFound(): void
    {
        // A definition parsed from an array carries no base path, so there is
        // nothing a relative path could resolve against. The message says what
        // was looked for rather than showing an empty path.
        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787256401);
        $this->expectExceptionMessage(
            'The scenario "Base.yaml" of the seed set "demo" does not exist. It was looked for at "Base.yaml".',
        );

        $this->subject->composeSettings($this->definition(['Base.yaml'], ''));
    }

    #[Test]
    public function aScenarioThatDoesNotExistIsRejectedNamingTheResolvedPath(): void
    {
        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787256401);
        $this->expectExceptionMessage(self::FIXTURE_PATH . '/DoesNotExist.yaml');

        $this->subject->composeSettings($this->definition(['DoesNotExist.yaml']));
    }

    #[Test]
    public function aDirectoryIsNotAScenario(): void
    {
        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787256401);

        $this->subject->composeSettings($this->definition([self::FIXTURE_PATH], ''));
    }

    #[Test]
    public function aScenarioThatCannotBeReadIsRejected(): void
    {
        // A separate code from "does not exist": one is a broken set, the other
        // is a permission problem on the machine running the import. The
        // fixture is readable; what is not is the answer the composer gets, see
        // "Fixtures/Composer/ShadowedIsReadable.php".
        $this->assertFileIsReadable(self::UNREADABLE_SCENARIO);

        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787256402);

        $this->subject->composeSettings($this->definition([self::UNREADABLE_SCENARIO], ''));
    }

    /**
     * @return \Generator<string, array{scenario: string, code: int}>
     */
    public static function unusableScenarioFiles(): \Generator
    {
        yield 'unparseable YAML' => ['scenario' => 'BrokenSyntax.yaml', 'code' => 1787256403];
        yield 'a scalar document' => ['scenario' => 'NotAMap.yaml', 'code' => 1787256404];
        // "Yaml::parseFile()" answers a file holding nothing but comments with
        // NULL, which is the same refusal as a scalar - and the state a
        // scenario file that was started and never written is in.
        yield 'an empty document' => ['scenario' => 'EmptyDocument.yaml', 'code' => 1787256404];
        yield 'an unknown top level key' => ['scenario' => 'UnknownKey.yaml', 'code' => 1787256405];
        yield 'entitySettings that are not a map' => [
            'scenario' => 'EntitySettingsNotAMap.yaml',
            'code' => 1787256406,
        ];
        yield 'entities that are not a map' => ['scenario' => 'EntitiesNotAMap.yaml', 'code' => 1787256407];
        yield 'an entity that is not a list of items' => ['scenario' => 'EntityNotAList.yaml', 'code' => 1787256408];
        yield 'an item that is not a map' => ['scenario' => 'EntityItemNotAMap.yaml', 'code' => 1787256409];
    }

    #[DataProvider('unusableScenarioFiles')]
    #[Test]
    public function anUnusableScenarioFileIsRejected(string $scenario, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->composeSettings($this->definition([$scenario]));
    }

    #[Test]
    public function anUnknownScenarioKeyNamesItselfAndTheKnownKeys(): void
    {
        // The message has to name the file: a set composes several of them, and
        // "unknown key" without one is a hunt through all of them.
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256405);
        $this->expectExceptionMessage(
            'The scenario "UnknownKey.yaml" of the seed set "demo" declares the unknown key "entitiy".'
            . ' Known keys are: entitySettings, entities, __variables.',
        );

        $this->subject->composeSettings($this->definition(['UnknownKey.yaml']));
    }

    #[Test]
    public function variablesAreAcceptedAndDropped(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['Variables.yaml']));

        // Accepted because every TYPO3 Core scenario carries one, dropped
        // because the anchors it holds are resolved by the YAML parser and
        // never cross a file - what is left of the key by now is inert.
        $this->assertSame(['entitySettings', 'entities'], array_keys($settings));
        $this->assertEquals(
            ['page' => [['self' => ['doktype' => 1, 'id' => 1, 'title' => 'Root']]]],
            $settings['entities'],
        );
    }

    #[Test]
    public function bothKeysArePresentEvenWhenNoFileDeclaresThem(): void
    {
        // "DataHandlerFactory" reads both with a null coalescing default, so
        // this is a promise to the caller rather than to the factory: what
        // comes out of the composer has the shape of a scenario.
        $settings = $this->subject->composeSettings($this->definition([]));

        $this->assertSame(['entitySettings' => [], 'entities' => []], $settings);
    }

    #[Test]
    public function entitySettingsAreDeepMergedWithTheLaterFileWinning(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['Base.yaml', 'Override.yaml']));

        $this->assertSame(
            [
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'defaultValues' => ['doktype' => 1],
                    // Added by the second file, which is what "deep" buys: the
                    // settings of the first file are not replaced wholesale.
                    'parentColumnName' => 'pid',
                ],
                'content' => [
                    // Declared by both, and the later file wins.
                    'tableName' => 'tt_content_override',
                ],
            ],
            $settings['entitySettings'],
        );
    }

    #[Test]
    public function theFileOrderDecidesWhichConflictingSettingWins(): void
    {
        // The same two files the other way round. If the merge were not
        // order dependent both assertions could not hold at once.
        $settings = $this->subject->composeSettings($this->definition(['Override.yaml', 'Base.yaml']));

        $this->assertSame(
            [
                'page' => [
                    'parentColumnName' => 'pid',
                    'isNode' => true,
                    'tableName' => 'pages',
                    'defaultValues' => ['doktype' => 1],
                ],
                'content' => [
                    'tableName' => 'tt_content',
                ],
            ],
            $settings['entitySettings'],
        );
    }

    #[Test]
    public function entitiesAreAppendedPerEntityNameInFileOrder(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['Base.yaml', 'Override.yaml']));

        $this->assertSame(
            [
                'page' => [
                    ['self' => ['id' => 1, 'title' => 'Root']],
                    // Appended rather than replacing the item of the first
                    // file: entities are records, not settings.
                    ['self' => ['id' => 2, 'title' => 'Second']],
                ],
                'content' => [
                    ['self' => ['id' => 10, 'title' => 'Text on the root page']],
                ],
                // An entity only the second file declares arrives whole.
                'category' => [
                    ['self' => ['id' => 20, 'title' => 'A category']],
                ],
            ],
            $settings['entities'],
        );
    }

    #[Test]
    public function theItemsOfAnEntityKeepTheOrderOfTheFilesDeclaringThem(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['Override.yaml', 'Base.yaml']));

        $this->assertSame(
            [
                'page' => [
                    ['self' => ['id' => 2, 'title' => 'Second']],
                    ['self' => ['id' => 1, 'title' => 'Root']],
                ],
                'category' => [
                    ['self' => ['id' => 20, 'title' => 'A category']],
                ],
                'content' => [
                    ['self' => ['id' => 10, 'title' => 'Text on the root page']],
                ],
            ],
            $settings['entities'],
        );
    }

    #[Test]
    public function aTopLevelItemIsPlacedBelowTheRootPageUnlessItNamesAPage(): void
    {
        $settings = $this->subject->composeSettings($this->definition(['RootPage.yaml']), 500);

        $this->assertSame(
            [
                'page' => [
                    [
                        // No "pid" declared, so the root page is written in.
                        'self' => ['id' => 1, 'title' => 'No pid at all', 'pid' => 500],
                        // Everything below stays where its ancestor puts it:
                        // moving it would take it off the tree it was declared
                        // in, and its "pid" comes from the node anyway.
                        'entities' => [
                            'content' => [
                                ['self' => ['id' => 10, 'title' => 'Nested entity']],
                            ],
                        ],
                        'children' => [
                            ['self' => ['id' => 2, 'title' => 'Nested child']],
                        ],
                        'languageVariants' => [
                            ['self' => ['id' => 3, 'title' => 'Translated']],
                        ],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'title' => 'Workspace overlay']],
                        ],
                    ],
                    // "pid: 0" is the page tree root, which is what the option
                    // replaces - it is not a page that was named.
                    ['self' => ['id' => 4, 'pid' => 500, 'title' => 'Explicit zero']],
                    // A scenario naming a specific page means that page.
                    ['self' => ['id' => 5, 'pid' => 99, 'title' => 'Somewhere specific']],
                    // A "version" item carries its values where a "self" item
                    // carries them, and is treated the same way.
                    [
                        'version' => [
                            'id' => 6,
                            'workspace' => 1,
                            'title' => 'A workspace version',
                            'pid' => 500,
                        ],
                    ],
                ],
            ],
            $settings['entities'],
        );
    }

    #[Test]
    public function nothingIsMovedWithoutARootPage(): void
    {
        $definition = $this->definition(['RootPage.yaml']);

        $withoutArgument = $this->subject->composeSettings($definition);
        $withZero = $this->subject->composeSettings($definition, 0);

        // Zero is "no root page given" rather than "the page tree root", so an
        // import without the option writes the scenario exactly as declared.
        $this->assertSame($withoutArgument, $withZero);
        $this->assertSame(
            [
                'page' => [
                    [
                        'self' => ['id' => 1, 'title' => 'No pid at all'],
                        'entities' => [
                            'content' => [
                                ['self' => ['id' => 10, 'title' => 'Nested entity']],
                            ],
                        ],
                        'children' => [
                            ['self' => ['id' => 2, 'title' => 'Nested child']],
                        ],
                        'languageVariants' => [
                            ['self' => ['id' => 3, 'title' => 'Translated']],
                        ],
                        'versionVariants' => [
                            ['version' => ['workspace' => 1, 'title' => 'Workspace overlay']],
                        ],
                    ],
                    ['self' => ['id' => 4, 'pid' => 0, 'title' => 'Explicit zero']],
                    ['self' => ['id' => 5, 'pid' => 99, 'title' => 'Somewhere specific']],
                    ['version' => ['id' => 6, 'workspace' => 1, 'title' => 'A workspace version']],
                ],
            ],
            $withZero['entities'],
        );
    }

    #[Test]
    public function theFactoryIsBuiltFromExactlyWhatComposeSettingsReturns(): void
    {
        $definition = $this->definition(['Base.yaml', 'Override.yaml']);

        $composed = $this->subject->compose($definition);
        $expected = new DataHandlerFactory($this->subject->composeSettings($definition));

        $this->assertSame(
            $this->withStableNewIds($expected->getDataMapPerWorkspace()),
            $this->withStableNewIds($composed->getDataMapPerWorkspace()),
        );
        $this->assertSame($expected->getSuggestedIds(), $composed->getSuggestedIds());
        // The value that agreement is about, so a change of behaviour is not
        // hidden behind "both sides changed". "id" is not a column of any of
        // these tables and is written through by the factory, which is
        // upstream's behaviour and pinned with it.
        $this->assertSame(
            [
                [
                    'pages' => [
                        'NEW1' => ['doktype' => 1, 'id' => 1, 'title' => 'Root', 'uid' => 1],
                        'NEW2' => ['doktype' => 1, 'id' => 2, 'title' => 'Second', 'uid' => 2],
                    ],
                    'tt_content_override' => [
                        'NEW3' => ['id' => 10, 'title' => 'Text on the root page', 'uid' => 10],
                    ],
                    'category' => [
                        'NEW4' => ['id' => 20, 'title' => 'A category', 'uid' => 20],
                    ],
                ],
            ],
            $this->withStableNewIds($composed->getDataMapPerWorkspace()),
        );
        $this->assertSame(
            ['pages:1' => true, 'pages:2' => true, 'tt_content_override:10' => true, 'category:20' => true],
            $composed->getSuggestedIds(),
        );
    }

    #[Test]
    public function theRootPageReachesTheDataMapOfTheBuiltFactory(): void
    {
        // The transformation happens on the settings rather than on the built
        // map, and this is what says it arrives: the factory exposes its maps
        // read-only, so there is no second chance to write a "pid".
        $factory = $this->subject->compose($this->definition(['Base.yaml']), 500);

        $this->assertSame(
            [
                [
                    'pages' => [
                        'NEW1' => ['doktype' => 1, 'id' => 1, 'title' => 'Root', 'pid' => 500, 'uid' => 1],
                    ],
                    'tt_content' => [
                        'NEW2' => ['id' => 10, 'title' => 'Text on the root page', 'pid' => 500, 'uid' => 10],
                    ],
                ],
            ],
            $this->withStableNewIds($factory->getDataMapPerWorkspace()),
        );
    }

    /**
     * The entities of `Fixtures/Composer/Base.yaml`, which several resolution
     * tests read through a different path spelling and have to agree on.
     *
     * @return array<string, mixed>
     */
    private static function baseEntities(): array
    {
        return [
            'page' => [
                ['self' => ['id' => 1, 'title' => 'Root']],
            ],
            'content' => [
                ['self' => ['id' => 10, 'title' => 'Text on the root page']],
            ],
        ];
    }

    /**
     * A scenario file that exists and is readable, and that
     * `GeneralUtility::getFileAbsFileName()` answers with an empty string
     * because it is outside the project path.
     */
    private function writeScenarioOutsideTheProjectPath(): string
    {
        $root = Environment::getVarPath() . '/tests/seeder-scenario-outside';
        GeneralUtility::rmdir($root, true);
        // Registered before anything is created, and removed with the varPath
        // that is restored by then - the environment this test replaces is put
        // back before the cleanup runs.
        $this->testFilesToDelete[] = $root;

        GeneralUtility::mkdir_deep($root . '/elsewhere');
        $scenario = $root . '/elsewhere/Scenario.yaml';
        GeneralUtility::writeFile(
            $scenario,
            "entities:\n  page:\n    - self:\n        id: 1\n        title: 'Outside'\n",
            true,
        );
        GeneralUtility::mkdir_deep($root . '/project/public');

        Environment::initialize(
            Environment::getContext(),
            Environment::isCli(),
            Environment::isComposerMode(),
            $root . '/project',
            $root . '/project/public',
            $root . '/project/var',
            $root . '/project/config',
            Environment::getCurrentScript(),
            'UNIX',
        );

        return $scenario;
    }

    /**
     * Data map keys come from `uniqid('NEW', true)` and are random per run, so
     * they are normalised to `NEW1`, `NEW2`, … in order of first appearance
     * before anything is compared. Suggested ids are `table:uid` and are
     * asserted verbatim.
     *
     * @param array<array-key, mixed> $dataMap
     * @return array<array-key, mixed>
     */
    private function withStableNewIds(array $dataMap): array
    {
        $replacements = [];

        return $this->replaceNewIds($dataMap, $replacements);
    }

    /**
     * @param array<array-key, mixed> $input
     * @param array<string, string> $replacements
     * @return array<array-key, mixed>
     */
    private function replaceNewIds(array $input, array &$replacements): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $key = $this->stableNewId($key, $replacements);
            }
            if (is_array($value)) {
                $value = $this->replaceNewIds($value, $replacements);
            } elseif (is_string($value)) {
                $value = $this->stableNewId($value, $replacements);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function stableNewId(string $subject, array &$replacements): string
    {
        $result = preg_replace_callback(
            '/NEW[0-9a-f]+/',
            static function (array $matches) use (&$replacements): string {
                $replacements[$matches[0]] ??= 'NEW' . (count($replacements) + 1);

                return $replacements[$matches[0]];
            },
            $subject,
        );

        return is_string($result) ? $result : $subject;
    }
}
