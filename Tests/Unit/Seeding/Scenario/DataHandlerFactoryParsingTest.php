<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use SBUERK\DataFactory\Seeding\Scenario\EntityConfiguration;
use Symfony\Component\Yaml\Exception\ParseException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * {@see DataHandlerFactory} was ported from `typo3/testing-framework` 9.6.1,
 * where it never had a single test. Everything it does — including the parts
 * that are accidents of the implementation rather than decisions — is
 * therefore load bearing by default, because the twenty scenario fixtures in
 * TYPO3 Core were written against the behaviour and not against a
 * specification.
 *
 * This class pins the two things that happen before any record is looked at:
 * how a definition enters the factory (array or YAML file, and what surfaces
 * when the file is unusable), and how `entitySettings` becomes an
 * {@see EntityConfiguration}. Everything a configuration then *does* with a
 * value is pinned in {@see EntityConfigurationTest}.
 *
 * Two rules for reading the assertions below:
 *
 * - Data map keys come from `uniqid('NEW', true)` and are random per run, so
 *   they are normalised to `NEW1`, `NEW2`, … in order of first appearance
 *   before anything is compared. Suggested ids are `table:uid` and therefore
 *   asserted verbatim.
 * - Where a test pins a defect rather than an intention, it says so. The merge
 *   of the `'*'` defaults in particular is `array_merge_recursive()`, which
 *   makes a scalar declared on both levels an *array*, and that is documented
 *   here rather than fixed, because the port is byte-faithful and a fix
 *   belongs upstream first.
 */
final class DataHandlerFactoryParsingTest extends UnitTestCase
{
    private const FIXTURE_PATH = __DIR__ . '/Fixtures/';

    /**
     * The array equivalent of `Fixtures/Scenario.yaml`, kept next to the test
     * that compares the two so a change to one is visibly a change to both.
     *
     * @return array<string, mixed>
     */
    private static function scenarioAsArray(): array
    {
        return [
            'entitySettings' => [
                '*' => [
                    'defaultValues' => ['hidden' => 0],
                ],
                'page' => [
                    'isNode' => true,
                    'tableName' => 'pages',
                    'parentColumnName' => 'pid',
                    'defaultValues' => ['pid' => 0],
                ],
                'content' => [
                    'tableName' => 'tt_content',
                    'nodeColumnName' => 'pid',
                    'columnNames' => ['title' => 'header'],
                ],
            ],
            'entities' => [
                'page' => [
                    [
                        'self' => ['title' => 'Root'],
                        'entities' => [
                            'content' => [
                                ['self' => ['title' => 'Hello']],
                            ],
                        ],
                    ],
                    [
                        'self' => ['title' => 'Second'],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function aYamlFileProducesExactlyWhatTheEquivalentArrayProduces(): void
    {
        $fromFile = DataHandlerFactory::fromYamlFile(self::FIXTURE_PATH . 'Scenario.yaml');
        $fromArray = new DataHandlerFactory(self::scenarioAsArray());

        // The whole public surface, not just the data map: an entry point that
        // loses the entities would still agree on the table names.
        $this->assertSame(
            $this->withStableNewIds($fromArray->getDataMapPerWorkspace()),
            $this->withStableNewIds($fromFile->getDataMapPerWorkspace()),
        );
        $this->assertSame($fromArray->getCommandMapPerWorkspace(), $fromFile->getCommandMapPerWorkspace());
        $this->assertSame($fromArray->getDataMapTableNames(), $fromFile->getDataMapTableNames());
        $this->assertSame($fromArray->getSuggestedIds(), $fromFile->getSuggestedIds());

        // And the value that agreement is about, spelled out once, so that a
        // change of behaviour is not hidden behind "both sides changed".
        $this->assertSame(
            [
                [
                    'pages' => [
                        'NEW1' => ['hidden' => 0, 'pid' => 0, 'title' => 'Root', 'uid' => 10000],
                        'NEW2' => ['hidden' => 0, 'pid' => '-NEW1', 'title' => 'Second', 'uid' => 10001],
                    ],
                    'tt_content' => [
                        'NEW3' => ['hidden' => 0, 'header' => 'Hello', 'uid' => 10000, 'pid' => 'NEW1'],
                    ],
                ],
            ],
            $this->withStableNewIds($fromFile->getDataMapPerWorkspace()),
        );
        $this->assertSame(
            ['pages:10000' => true, 'tt_content:10000' => true, 'pages:10001' => true],
            $fromFile->getSuggestedIds(),
        );
        $this->assertSame(['pages', 'tt_content'], $fromFile->getDataMapTableNames());
    }

    /**
     * @return \Generator<string, array{path: string}>
     */
    public static function unusableFilePaths(): \Generator
    {
        yield 'a path that does not exist' => ['path' => self::FIXTURE_PATH . 'ThereIsNoSuchFile.yaml'];
        // is_file() is false for a directory as well, so a directory produces
        // the "does not exist" message rather than a read error.
        yield 'a directory instead of a file' => ['path' => self::FIXTURE_PATH];
    }

    #[DataProvider('unusableFilePaths')]
    #[Test]
    public function anUnusableFilePathSurfacesAsASymfonyParseException(string $path): void
    {
        // The factory adds no handling of its own, so the caller sees the
        // Symfony exception - and a ParseException carries no code, which is
        // why the message is asserted instead.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(sprintf('File "%s" does not exist.', $path));

        DataHandlerFactory::fromYamlFile($path);
    }

    #[Test]
    public function aSyntacticallyInvalidYamlFileSurfacesAsASymfonyParseException(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Malformed inline YAML string at line 4.');

        DataHandlerFactory::fromYamlFile(self::FIXTURE_PATH . 'InvalidSyntax.yaml');
    }

    #[Test]
    public function aYamlFileWithoutContentFailsWithATypeErrorRatherThanAnEmptyScenario(): void
    {
        // "Yaml::parseFile()" returns NULL for a file that contains nothing but
        // comments, and the constructor takes "array $settings" under
        // strict_types. A definition that got truncated therefore fails hard
        // instead of importing an empty scenario - which is the better of the
        // two outcomes, even though the message points at the factory rather
        // than at the file.
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches(
            '/__construct\(\): Argument #1 \(\$settings\) must be of type array, null given/',
        );

        DataHandlerFactory::fromYamlFile(self::FIXTURE_PATH . 'Empty.yaml');
    }

    #[Test]
    public function aScenarioWithoutEntitySettingsFallsBackToBareEntityConfigurations(): void
    {
        $subject = new DataHandlerFactory([
            'entities' => [
                'pages' => [
                    ['self' => ['title' => 'Root']],
                ],
            ],
        ]);

        // No table name, no defaults, no column mapping: the entity name is the
        // table name and every declared value is written as it stands.
        $this->assertSame(['pages'], $subject->getDataMapTableNames());
        $this->assertSame(
            [['pages' => ['NEW1' => ['title' => 'Root', 'uid' => 10000]]]],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    #[Test]
    public function aScenarioWithoutEntitiesProducesEmptyMaps(): void
    {
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                'page' => ['tableName' => 'pages'],
            ],
        ]);

        // An entity configuration on its own produces nothing - not an empty
        // workspace, not an empty table, nothing.
        $this->assertSame([], $subject->getDataMapPerWorkspace());
        $this->assertSame([], $subject->getCommandMapPerWorkspace());
        $this->assertSame([], $subject->getDataMapTableNames());
        $this->assertSame([], $subject->getSuggestedIds());
    }

    /**
     * @return \Generator<string, array{key: string, value: mixed}>
     */
    public static function unknownTopLevelKeys(): \Generator
    {
        // "__variables" is the one that matters: TYPO3 Core scenarios use it to
        // hold YAML anchors, and it is never read by the factory - the anchors
        // are already expanded by the time it sees the array.
        yield '__variables, as used by the core scenarios' => [
            'key' => '__variables',
            'value' => [['doktype' => 1]],
        ];
        yield 'a misspelled structural key' => ['key' => 'entitySetting', 'value' => ['page' => ['tableName' => 'x']]];
        yield 'an arbitrary scalar' => ['key' => 'whateverThisIs', 'value' => 'ignored'];
    }

    /**
     * Unlike the set descriptor of this extension, which refuses an unknown
     * key, the scenario format ignores it silently. That is upstream behaviour
     * and it is what the core fixtures rely on; it is pinned so the difference
     * between the two formats is a decision and not a surprise.
     */
    #[DataProvider('unknownTopLevelKeys')]
    #[Test]
    public function anUnknownTopLevelKeyIsIgnored(string $key, mixed $value): void
    {
        $settings = self::scenarioAsArray();
        $expected = new DataHandlerFactory($settings);

        $settings[$key] = $value;
        $subject = new DataHandlerFactory($settings);

        $this->assertSame(
            $this->withStableNewIds($expected->getDataMapPerWorkspace()),
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
        $this->assertSame($expected->getSuggestedIds(), $subject->getSuggestedIds());
    }

    #[Test]
    public function yamlAnchorsAreResolvedBeforeTheFactorySeesThem(): void
    {
        $subject = DataHandlerFactory::fromYamlFile(self::FIXTURE_PATH . 'Anchors.yaml');

        // The factory has no notion of a merge key. Both records carry the
        // anchored values, and the second one overrides "hidden" locally,
        // which only works if Symfony expanded the alias into a plain array.
        $this->assertSame(
            [
                [
                    'pages' => [
                        'NEW1' => ['doktype' => 1, 'hidden' => 0, 'title' => 'Root', 'uid' => 10000],
                        'NEW2' => ['doktype' => 1, 'hidden' => 1, 'title' => 'Second', 'uid' => 10001],
                    ],
                ],
            ],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    /**
     * Upstream declares `final public function __construct()` and builds with
     * `new static()`, which is the pattern used when a class is meant to be
     * extendable. The port is `final` and builds with `new self()`, so
     * `fromYamlFile()` can only ever hand back this class. Dropping `final`
     * would quietly reintroduce the upstream question, so it is asserted.
     */
    #[Test]
    public function theFileEntryPointCanOnlyReturnTheFactoryItself(): void
    {
        $subject = DataHandlerFactory::fromYamlFile(self::FIXTURE_PATH . 'Scenario.yaml');

        $this->assertSame(['pages', 'tt_content'], $subject->getDataMapTableNames());
        $this->assertTrue((new \ReflectionClass(DataHandlerFactory::class))->isFinal());
    }

    #[Test]
    public function wildcardSettingsAreMergedIntoEveryConfiguredEntity(): void
    {
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                '*' => [
                    'defaultValues' => ['hidden' => 0],
                    'columnNames' => ['title' => 'header'],
                ],
                'page' => ['tableName' => 'pages'],
                'content' => ['tableName' => 'tt_content'],
            ],
            'entities' => [
                'page' => [['self' => ['title' => 'Root']]],
                'content' => [['self' => ['title' => 'Hello']]],
            ],
        ]);

        // Both entities got the default value and the column mapping although
        // neither declares either of them.
        $this->assertSame(
            [
                [
                    'pages' => ['NEW1' => ['hidden' => 0, 'header' => 'Root', 'uid' => 10000]],
                    'tt_content' => ['NEW2' => ['hidden' => 0, 'header' => 'Hello', 'uid' => 10000]],
                ],
            ],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    #[Test]
    public function theWildcardKeyItselfNeverBecomesAnEntityConfiguration(): void
    {
        // An entity literally called "*" is the only way to observe this from
        // the outside: it is skipped while the configurations are built, so it
        // falls through to a bare configuration when it is used, and the
        // "tableName" declared under "*" does not apply to it.
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                '*' => ['tableName' => 'pages'],
            ],
            'entities' => [
                '*' => [['self' => ['title' => 'Root']]],
            ],
        ]);

        $this->assertSame(['*'], $subject->getDataMapTableNames());
        $this->assertSame(['*:10000' => true], $subject->getSuggestedIds());
    }

    #[Test]
    public function anEntityWithoutItsOwnEntitySettingsDoesNotGetTheWildcardDefaults(): void
    {
        // The wildcard is merged while iterating "entitySettings". An entity
        // that has no key there at all is never iterated, so it is built on
        // first use as a bare configuration and the defaults pass it by. A
        // scenario that adds an entity and forgets its (possibly empty)
        // "entitySettings" entry therefore seeds records without the defaults
        // every other entity gets, and nothing reports it.
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                '*' => ['defaultValues' => ['hidden' => 1]],
                'page' => ['tableName' => 'pages'],
            ],
            'entities' => [
                'page' => [['self' => ['title' => 'Root']]],
                'content' => [['self' => ['title' => 'Hello']]],
            ],
        ]);

        $this->assertSame(
            [
                [
                    'pages' => ['NEW1' => ['hidden' => 1, 'title' => 'Root', 'uid' => 10000]],
                    'content' => ['NEW2' => ['title' => 'Hello', 'uid' => 10000]],
                ],
            ],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    #[Test]
    public function anEmptyEntitySettingsEntryIsEnoughToReceiveTheWildcardDefaults(): void
    {
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                '*' => ['defaultValues' => ['hidden' => 1]],
                'content' => [],
            ],
            'entities' => [
                'content' => [['self' => ['title' => 'Hello']]],
            ],
        ]);

        $this->assertSame(
            [['content' => ['NEW1' => ['hidden' => 1, 'title' => 'Hello', 'uid' => 10000]]]],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    /**
     * The sharp edge of the wildcard merge: it is `array_merge_recursive()`,
     * not `array_replace_recursive()`. A setting the entity declares does not
     * replace the wildcard one, it is appended to it, and a scalar declared on
     * both levels ends up as a two element list. Here that list reaches the
     * data map as the value of a column, which is silently wrong rather than
     * loud - see the two tests below for the variants that do fail loudly.
     */
    #[Test]
    public function aDefaultValueDeclaredOnBothLevelsIsAppendedRatherThanReplaced(): void
    {
        $subject = new DataHandlerFactory([
            'entitySettings' => [
                '*' => ['defaultValues' => ['hidden' => 0]],
                'page' => ['tableName' => 'pages', 'defaultValues' => ['hidden' => 1]],
            ],
            'entities' => [
                'page' => [['self' => ['title' => 'Root']]],
            ],
        ]);

        $this->assertSame(
            [['pages' => ['NEW1' => ['hidden' => [0, 1], 'title' => 'Root', 'uid' => 10000]]]],
            $this->withStableNewIds($subject->getDataMapPerWorkspace()),
        );
    }

    /**
     * @return \Generator<string, array{setting: string}>
     */
    public static function scalarEntitySettings(): \Generator
    {
        yield 'tableName' => ['setting' => 'tableName'];
        yield 'parentColumnName' => ['setting' => 'parentColumnName'];
        yield 'nodeColumnName' => ['setting' => 'nodeColumnName'];
    }

    /**
     * The same merge against a setting that is typed `?string` on
     * {@see EntityConfiguration}: the two element list produced by
     * `array_merge_recursive()` cannot be assigned, and the import dies with a
     * TypeError naming the property rather than the scenario. Declaring a
     * table or a column name under `'*'` *and* on the entity is therefore not
     * an override, it is a crash.
     */
    #[DataProvider('scalarEntitySettings')]
    #[Test]
    public function aScalarSettingDeclaredOnBothLevelsFailsAgainstTheTypedProperty(string $setting): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            sprintf('Cannot assign array to property %s::$%s of type ?string', EntityConfiguration::class, $setting),
        );

        new DataHandlerFactory([
            'entitySettings' => [
                '*' => [$setting => 'fromWildcard'],
                'page' => [$setting => 'fromEntity'],
            ],
            'entities' => [
                'page' => [['self' => ['title' => 'Root']]],
            ],
        ]);
    }

    /**
     * And the variant most likely to be written by accident, because `pid` is
     * exactly the default a scenario declares globally and then again per
     * entity: the merged `[0, 5]` survives {@see EntityConfiguration} (it is a
     * plain array of default values) and only blows up when the data map
     * assembly tries to use it as a page id.
     */
    #[Test]
    public function aPidDefaultDeclaredOnBothLevelsBreaksTheDataMapAssembly(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches(
            '/filterDataMapByPageId\(\): Argument #3 \(\$pageId\) must be of type string\|int\|null, array given/',
        );

        new DataHandlerFactory([
            'entitySettings' => [
                '*' => ['defaultValues' => ['pid' => 0]],
                'page' => ['tableName' => 'pages', 'defaultValues' => ['pid' => 5]],
            ],
            'entities' => [
                'page' => [['self' => ['title' => 'Root']]],
            ],
        ]);
    }

    /**
     * Replaces every `uniqid('NEW', true)` identifier - in keys and in string
     * values alike - with `NEW1`, `NEW2`, … in order of first appearance, so
     * that a data map can be compared at all.
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
