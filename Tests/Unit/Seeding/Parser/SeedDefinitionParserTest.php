<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Definition\SeedFile;
use SBUERK\Seeder\Seeding\Definition\SeedFileReference;
use SBUERK\Seeder\Seeding\Definition\SeedSiteConfiguration;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

require_once __DIR__ . '/Fixtures/ShadowedIsReadable.php';

/**
 * The `config.yml` of a seed set is what an integrator writes against, so it is
 * parsed strictly: the key set is closed at the top level and on a site, and an
 * unknown key fails the import naming the key rather than being skipped.
 *
 * Since the records moved into scenario files, the descriptor describes the
 * **set** only - which scenarios it is written from, which files it brings,
 * which record fields those files hang on, and which sites it sets up. That is
 * what this class covers, in three layers:
 *
 * - {@see SeedDefinitionParser::parse()} on an array, which is the whole
 *   validation surface and needs no file system;
 * - {@see SeedDefinitionParser::parseFile()}, which adds `imports` through
 *   `YamlFileLoader` and the two deviations from how the core calls it -
 *   placeholders switched off, a failing import raised rather than logged;
 * - the silent fallbacks of a `files` entry, which are deliberately *not*
 *   errors and would otherwise only be noticed when a file lands in the wrong
 *   storage.
 *
 * Where a path is confined - a set below `vendor/`, a set outside the project
 * path - is a question about `GeneralUtility::getFileAbsFileName()` rather than
 * about parsing, and lives in {@see SeedDefinitionParserPathResolutionTest}.
 */
final class SeedDefinitionParserTest extends UnitTestCase
{
    use SeedYamlFileLoaderTestTrait;

    /**
     * The one entry file `Fixtures/ShadowedIsReadable.php` answers as
     * unreadable. It is a normal, readable fixture - see that file for why the
     * answer is shadowed rather than the permission bits changed.
     */
    public const UNREADABLE_ENTRY_FILE = __DIR__ . '/Fixtures/Unreadable/config.yml';

    private SeedDefinitionParser $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SeedDefinitionParser($this->seedYamlFileLoader());
    }

    #[Test]
    public function setMetadataIsParsed(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo page tree',
            'description' => 'A page tree to look at.',
            'scenarios' => ['Pages.yaml'],
        ]);

        $this->assertSame('demo', $definition->identifier);
        $this->assertSame('Demo page tree', $definition->title);
        $this->assertSame('A page tree to look at.', $definition->description);
    }

    #[Test]
    public function aDefinitionWithoutFilesOrSitesIsAccepted(): void
    {
        // A set that writes records and nothing else is the common case, and
        // neither key is required for it.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
        ]);

        $this->assertSame(['Pages.yaml'], $definition->scenarios);
        $this->assertSame([], $definition->files);
        $this->assertSame([], $definition->sites);
        $this->assertSame('', $definition->description);
        $this->assertSame('', $definition->basePath);
    }

    #[Test]
    public function anEmptyDescriptionKeyIsTheSameAsNone(): void
    {
        // "description:" with nothing behind it decodes to null, which the null
        // coalescing operator cannot tell from an absent key - and must not,
        // because both mean "this set has no long text".
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'description' => null,
            'scenarios' => ['Pages.yaml'],
        ]);

        $this->assertSame('', $definition->description);
    }

    /**
     * @return \Generator<string, array{basePath: string, expected: string}>
     */
    public static function basePaths(): \Generator
    {
        yield 'a directory without a trailing slash is kept' => [
            'basePath' => '/var/www/set',
            'expected' => '/var/www/set',
        ];
        yield 'a trailing slash is removed' => [
            'basePath' => '/var/www/set/',
            'expected' => '/var/www/set',
        ];
        yield 'several trailing slashes are removed' => [
            'basePath' => '/var/www/set///',
            'expected' => '/var/www/set',
        ];
        // The consumers concatenate "basePath . '/' . $relative", so the root
        // directory has to arrive as an empty string rather than as "/" for
        // that to produce one separator instead of two.
        yield 'the root directory becomes an empty string' => [
            'basePath' => '/',
            'expected' => '',
        ];
        yield 'no base path stays empty' => [
            'basePath' => '',
            'expected' => '',
        ];
    }

    #[DataProvider('basePaths')]
    #[Test]
    public function theBasePathIsRightTrimmed(string $basePath, string $expected): void
    {
        $definition = $this->subject->parse(
            ['identifier' => 'demo', 'title' => 'Demo', 'scenarios' => ['Pages.yaml']],
            'seed definition',
            $basePath,
        );

        $this->assertSame($expected, $definition->basePath);
    }

    #[Test]
    public function anUnknownTopLevelKeyNamesItselfAndTheKnownKeys(): void
    {
        // The typo this guards against is "scenario:" for "scenarios:", which
        // would otherwise be an import that reports success and writes nothing.
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072814);
        $this->expectExceptionMessage(
            'The seed definition "config.yml" declares the unknown key "scenario". Known keys are:'
            . ' identifier, title, description, imports, scenarios, files, references, sites.',
        );

        $this->subject->parse(
            ['identifier' => 'demo', 'title' => 'Demo', 'scenario' => ['Pages.yaml']],
            'config.yml',
        );
    }

    #[Test]
    public function anImportsKeyIsAcceptedAlthoughTheLoaderConsumesIt(): void
    {
        // "YamlFileLoader" merges and removes "imports", so the parser never
        // sees one in practice. It stays a known key so that "parse()" can be
        // called with a raw array carrying one.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'imports' => [['resource' => 'Scenarios.yaml']],
            'scenarios' => ['Pages.yaml'],
        ]);

        $this->assertSame('demo', $definition->identifier);
    }

    /**
     * @return \Generator<string, array{definition: mixed, code: int}>
     */
    public static function invalidDefinitions(): \Generator
    {
        yield 'not a map' => [
            'definition' => 'nope',
            'code' => 1787072810,
        ];
        yield 'null rather than a map' => [
            'definition' => null,
            'code' => 1787072810,
        ];
        yield 'unknown key at the set level' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => []],
            'code' => 1787072814,
        ];
        // A stray list entry produces an integer key, which the message casts
        // to a string rather than failing on.
        yield 'a numeric key' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 0 => 'stray'],
            'code' => 1787072814,
        ];
        yield 'no identifier' => [
            'definition' => ['title' => 'Demo', 'scenarios' => ['Pages.yaml']],
            'code' => 1787072811,
        ];
        yield 'empty identifier' => [
            'definition' => ['identifier' => '', 'title' => 'Demo', 'scenarios' => ['Pages.yaml']],
            'code' => 1787072811,
        ];
        yield 'identifier not a string' => [
            'definition' => ['identifier' => 17, 'title' => 'Demo', 'scenarios' => ['Pages.yaml']],
            'code' => 1787072811,
        ];
        yield 'no title' => [
            'definition' => ['identifier' => 'demo', 'scenarios' => ['Pages.yaml']],
            'code' => 1787072812,
        ];
        yield 'empty title' => [
            'definition' => ['identifier' => 'demo', 'title' => '', 'scenarios' => ['Pages.yaml']],
            'code' => 1787072812,
        ];
        yield 'title not a string' => [
            'definition' => ['identifier' => 'demo', 'title' => 17, 'scenarios' => ['Pages.yaml']],
            'code' => 1787072812,
        ];
        yield 'description not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'description' => ['nope'],
                'scenarios' => ['Pages.yaml'],
            ],
            'code' => 1787072813,
        ];
    }

    #[DataProvider('invalidDefinitions')]
    #[Test]
    public function anInvalidDefinitionIsRejected(mixed $definition, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->parse($definition);
    }

    #[Test]
    public function scenariosAreKeptInDeclarationOrderAndVerbatim(): void
    {
        // The order is the order the files are composed in, so a later file
        // overrides an earlier one - sorting them would silently change which
        // settings win. Nothing is normalised either: a path is resolved when
        // it is read, not here.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => [
                'Zulu.yaml',
                'Alpha.yaml',
                'EXT:demo/Configuration/Seeder/demo/Pages.yaml',
                '/absolute/Content.yaml',
                'Nested/../Content.yaml',
            ],
        ]);

        $this->assertSame(
            [
                'Zulu.yaml',
                'Alpha.yaml',
                'EXT:demo/Configuration/Seeder/demo/Pages.yaml',
                '/absolute/Content.yaml',
                'Nested/../Content.yaml',
            ],
            $definition->scenarios,
        );
    }

    /**
     * @return \Generator<string, array{scenarios: mixed}>
     */
    public static function unusableScenarioDeclarations(): \Generator
    {
        yield 'absent' => ['scenarios' => null];
        yield 'a single path rather than a list' => ['scenarios' => 'Pages.yaml'];
        yield 'a map rather than a list' => ['scenarios' => ['first' => 'Pages.yaml']];
        yield 'an empty list' => ['scenarios' => []];
        yield 'boolean false' => ['scenarios' => false];
    }

    #[DataProvider('unusableScenarioDeclarations')]
    #[Test]
    public function aSetWithoutScenariosIsRejected(mixed $scenarios): void
    {
        // Required and non-empty: a descriptor that names no scenario writes no
        // record, and saying so by omission is indistinguishable from having
        // misspelled the key.
        $definition = ['identifier' => 'demo', 'title' => 'Demo'];
        if ($scenarios !== null) {
            $definition['scenarios'] = $scenarios;
        }

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256301);

        $this->subject->parse($definition);
    }

    /**
     * @return \Generator<string, array{scenario: mixed}>
     */
    public static function unusableScenarioEntries(): \Generator
    {
        yield 'an empty string' => ['scenario' => ''];
        yield 'whitespace only' => ['scenario' => "  \t "];
        yield 'a number' => ['scenario' => 17];
        yield 'null' => ['scenario' => null];
        yield 'a nested list' => ['scenario' => ['Pages.yaml']];
        yield 'a map' => ['scenario' => ['resource' => 'Pages.yaml']];
    }

    #[DataProvider('unusableScenarioEntries')]
    #[Test]
    public function aScenarioEntryThatIsNoPathIsRejected(mixed $scenario): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256302);

        $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml', $scenario],
        ]);
    }

    #[Test]
    public function aFileIsParsedWithEverythingItDeclares(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [
                [
                    'identifier' => 'placeholder',
                    'source' => 'Files/placeholder.svg',
                    'folder' => 'demo/images',
                    'name' => 'renamed.svg',
                    'storage' => 2,
                ],
            ],
        ]);

        $this->assertCount(1, $definition->files);
        $this->assertEquals(
            new SeedFile('placeholder', 'Files/placeholder.svg', 'demo/images', 'renamed.svg', 2),
            $definition->files[0],
        );
    }

    #[Test]
    public function aFileFallsBackToTheStorageRootAndTheSourceName(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [
                ['identifier' => 'placeholder', 'source' => 'Files/placeholder.svg'],
            ],
        ]);

        $this->assertSame('/', $definition->files[0]->folder);
        $this->assertNull($definition->files[0]->name);
        $this->assertNull($definition->files[0]->storage);
    }

    /**
     * @return \Generator<string, array{file: array<string, mixed>, expected: SeedFile}>
     */
    public static function filesWithUnusableOptionalValues(): \Generator
    {
        yield 'a folder that is not a string falls back to the storage root' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'folder' => 17],
            'expected' => new SeedFile('f', 's.svg', '/'),
        ];
        yield 'a folder that is a list falls back to the storage root' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'folder' => ['demo']],
            'expected' => new SeedFile('f', 's.svg', '/'),
        ];
        yield 'a name that is not a string falls back to the source name' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'name' => 17],
            'expected' => new SeedFile('f', 's.svg', '/', null),
        ];
        // "storage: '2'" is what a quoted YAML scalar produces, and it is
        // dropped rather than cast - the file lands in the default storage.
        yield 'a storage that is a numeric string falls back to the default storage' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'storage' => '2'],
            'expected' => new SeedFile('f', 's.svg', '/', null, null),
        ];
        yield 'a storage that is a boolean falls back to the default storage' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'storage' => true],
            'expected' => new SeedFile('f', 's.svg', '/', null, null),
        ];
        // Storage 0 is a real storage uid in TYPO3 - the one holding files
        // outside any storage - so it is kept rather than treated as "none".
        yield 'storage zero is kept' => [
            'file' => ['identifier' => 'f', 'source' => 's.svg', 'storage' => 0],
            'expected' => new SeedFile('f', 's.svg', '/', null, 0),
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    #[DataProvider('filesWithUnusableOptionalValues')]
    #[Test]
    public function anUnusableOptionalFileValueFallsBackSilently(array $file, SeedFile $expected): void
    {
        // Pinned rather than turned into an error: the optional values of a
        // file are conveniences, and a wrong type in one of them is not worth
        // refusing a set over.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [$file],
        ]);

        $this->assertEquals($expected, $definition->files[0]);
    }

    /**
     * A file declaration is configuration, not a record: nothing in it is
     * written verbatim to anything, so an unknown key can only be a mistake.
     * Accepting `foldr:` silently would put the file in the storage root and
     * report success.
     */
    #[Test]
    public function anUnknownFileKeyNamesItselfAndTheKnownKeys(): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256303);
        $this->expectExceptionMessage(
            'A file of the seed definition "seed definition" declares the unknown key "foldr".'
            . ' Known keys are: identifier, source, folder, name, storage.'
        );

        $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [
                ['identifier' => 'f', 'source' => 's.svg', 'foldr' => 'demo'],
            ],
        ]);
    }

    /**
     * @return \Generator<string, array{files: mixed, code: int}>
     */
    public static function invalidFileDeclarations(): \Generator
    {
        yield 'files not a list' => ['files' => 'nope', 'code' => 1787072820];
        yield 'files a map rather than a list' => [
            'files' => ['placeholder' => ['source' => 's.svg']],
            'code' => 1787072820,
        ];
        yield 'a file that is not a map' => ['files' => ['nope'], 'code' => 1787072821];
        yield 'a file without an identifier' => ['files' => [['source' => 's.svg']], 'code' => 1787072822];
        yield 'a file with an empty identifier' => [
            'files' => [['identifier' => '', 'source' => 's.svg']],
            'code' => 1787072822,
        ];
        yield 'a file with an identifier that is not a string' => [
            'files' => [['identifier' => 17, 'source' => 's.svg']],
            'code' => 1787072822,
        ];
        yield 'the same file identifier twice' => [
            'files' => [
                ['identifier' => 'placeholder', 'source' => 'a.svg'],
                ['identifier' => 'placeholder', 'source' => 'b.svg'],
            ],
            'code' => 1787072823,
        ];
        yield 'a file without a source' => ['files' => [['identifier' => 'f']], 'code' => 1787072824];
        yield 'a file with an empty source' => [
            'files' => [['identifier' => 'f', 'source' => '']],
            'code' => 1787072824,
        ];
        yield 'a file with a source that is not a string' => [
            'files' => [['identifier' => 'f', 'source' => 17]],
            'code' => 1787072824,
        ];
    }

    #[DataProvider('invalidFileDeclarations')]
    #[Test]
    public function anInvalidFileDeclarationIsRejected(mixed $files, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => $files,
        ]);
    }

    #[Test]
    public function aFileReferenceIsParsedWithEverythingItDeclares(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [
                ['identifier' => 'teaser', 'source' => 'Files/teaser.svg'],
            ],
            'references' => [
                [
                    'file' => 'teaser',
                    'table' => 'tt_content',
                    'uid' => 2001,
                    'field' => 'image',
                    'values' => ['title' => 'Teaser', 'alternative' => 'A teaser image'],
                ],
            ],
        ]);

        $this->assertCount(1, $definition->references);
        $this->assertEquals(
            new SeedFileReference(
                'teaser',
                'tt_content',
                2001,
                'image',
                ['title' => 'Teaser', 'alternative' => 'A teaser image'],
            ),
            $definition->references[0],
        );
    }

    #[Test]
    public function fileReferencesAreKeptInDeclarationOrder(): void
    {
        // The order is the order the references are written in, and a field
        // taking several files takes them in exactly that order - sorting them
        // would silently reorder a gallery.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [
                ['identifier' => 'zulu', 'source' => 'Files/zulu.svg'],
                ['identifier' => 'alpha', 'source' => 'Files/alpha.svg'],
            ],
            'references' => [
                ['file' => 'zulu', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image'],
                ['file' => 'alpha', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image'],
                ['file' => 'zulu', 'table' => 'pages', 'uid' => 1000, 'field' => 'media'],
            ],
        ]);

        $this->assertEquals(
            [
                new SeedFileReference('zulu', 'tt_content', 2001, 'image'),
                new SeedFileReference('alpha', 'tt_content', 2001, 'image'),
                new SeedFileReference('zulu', 'pages', 1000, 'media'),
            ],
            $definition->references,
        );
    }

    #[Test]
    public function aFileReferenceWithoutValuesWritesNoFieldOfItsOwn(): void
    {
        // Attaching a file without titling it is the common case, and an absent
        // "values" is an empty map rather than an error.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [['identifier' => 'teaser', 'source' => 'Files/teaser.svg']],
            'references' => [
                ['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image'],
            ],
        ]);

        $this->assertSame([], $definition->references[0]->values);
    }

    #[Test]
    public function aDefinitionWithoutReferencesIsAccepted(): void
    {
        // A set may bring files without hanging any of them on a record - they
        // are then simply indexed into the storage.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [['identifier' => 'teaser', 'source' => 'Files/teaser.svg']],
        ]);

        $this->assertSame([], $definition->references);
    }

    /**
     * @return \Generator<string, array{value: scalar|null}>
     */
    public static function referenceValuesWrittenVerbatim(): \Generator
    {
        yield 'a string' => ['value' => 'A teaser image'];
        yield 'an empty string' => ['value' => ''];
        yield 'an integer' => ['value' => 17];
        yield 'a float' => ['value' => 1.5];
        yield 'boolean true' => ['value' => true];
        yield 'boolean false' => ['value' => false];
        // "alternative:" with nothing behind it decodes to null, and null is a
        // value a "sys_file_reference" column can carry - so it is passed on
        // rather than dropped or turned into an empty string.
        yield 'null' => ['value' => null];
    }

    #[DataProvider('referenceValuesWrittenVerbatim')]
    #[Test]
    public function aFileReferenceValueSurvivesVerbatim(mixed $value): void
    {
        // A record value is content: it is written as it was declared, so the
        // parser neither casts nor normalises it.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [['identifier' => 'teaser', 'source' => 'Files/teaser.svg']],
            'references' => [
                [
                    'file' => 'teaser',
                    'table' => 'tt_content',
                    'uid' => 2001,
                    'field' => 'image',
                    'values' => ['alternative' => $value],
                ],
            ],
        ]);

        $this->assertSame(['alternative' => $value], $definition->references[0]->values);
    }

    /**
     * The key set of a reference is closed for the same reason a file's is:
     * nothing outside `values` is written verbatim, so an unknown key can only
     * be a mistake - and `fields:` accepted silently would attach the file
     * without any of the metadata the set declares.
     */
    #[Test]
    public function anUnknownFileReferenceKeyNamesItselfAndTheKnownKeys(): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256312);
        $this->expectExceptionMessage(
            'A file reference of the seed definition "config.yml" declares the unknown key "fields". Known'
            . ' keys are: file, table, uid, field, values.',
        );

        $this->subject->parse(
            [
                'identifier' => 'demo',
                'title' => 'Demo',
                'scenarios' => ['Pages.yaml'],
                'files' => [['identifier' => 'teaser', 'source' => 'Files/teaser.svg']],
                'references' => [
                    [
                        'file' => 'teaser',
                        'table' => 'tt_content',
                        'uid' => 2001,
                        'field' => 'image',
                        'fields' => ['title' => 'Teaser'],
                    ],
                ],
            ],
            'config.yml',
        );
    }

    #[Test]
    public function aReferenceToAnUndeclaredFileNamesTheFilesTheSetDeclares(): void
    {
        // The "files" list comes out of the same descriptor, so this is a
        // mistake the parser can name before anything is written - rather than
        // one that surfaces halfway through a run that has already created a
        // page tree.
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256314);
        $this->expectExceptionMessage(
            'A file reference of the seed definition "config.yml" names the file "teasre", which the set does'
            . ' not declare under "files" - it declares: teaser, logo.',
        );

        $this->subject->parse(
            [
                'identifier' => 'demo',
                'title' => 'Demo',
                'scenarios' => ['Pages.yaml'],
                'files' => [
                    ['identifier' => 'teaser', 'source' => 'Files/teaser.svg'],
                    ['identifier' => 'logo', 'source' => 'Files/logo.svg'],
                ],
                'references' => [
                    ['file' => 'teasre', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image'],
                ],
            ],
            'config.yml',
        );
    }

    #[Test]
    public function aReferenceInASetDeclaringNoFilesSaysThatItDeclaresNone(): void
    {
        // Worth a wording of its own: listing the declared files is unhelpful
        // when there are none, and the likely mistake is a missing "files" key
        // rather than a misspelled identifier.
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787256314);
        $this->expectExceptionMessage(
            'A file reference of the seed definition "config.yml" names the file "teaser", which the set does'
            . ' not declare under "files" - it declares no files at all.',
        );

        $this->subject->parse(
            [
                'identifier' => 'demo',
                'title' => 'Demo',
                'scenarios' => ['Pages.yaml'],
                'references' => [
                    ['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image'],
                ],
            ],
            'config.yml',
        );
    }

    /**
     * @return \Generator<string, array{references: mixed, code: int}>
     */
    public static function invalidReferenceDeclarations(): \Generator
    {
        yield 'references not a list' => ['references' => 'nope', 'code' => 1787256310];
        yield 'references a map rather than a list' => [
            'references' => ['teaser' => ['table' => 'tt_content', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256310,
        ];
        yield 'a reference that is not a map' => ['references' => ['nope'], 'code' => 1787256311];
        yield 'a reference that is a number' => ['references' => [17], 'code' => 1787256311];
        yield 'a reference with an unknown key' => [
            'references' => [
                ['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image', 'pid' => 1],
            ],
            'code' => 1787256312,
        ];
        yield 'a reference without a file' => [
            'references' => [['table' => 'tt_content', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256313,
        ];
        yield 'a reference with an empty file' => [
            'references' => [['file' => '', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256313,
        ];
        yield 'a reference with a file that is not a string' => [
            'references' => [['file' => 17, 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256313,
        ];
        yield 'a reference to a file the set does not declare' => [
            'references' => [['file' => 'missing', 'table' => 'tt_content', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256314,
        ];
        yield 'a reference without a table' => [
            'references' => [['file' => 'teaser', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256315,
        ];
        yield 'a reference with an empty table' => [
            'references' => [['file' => 'teaser', 'table' => '', 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256315,
        ];
        yield 'a reference with a table that is not a string' => [
            'references' => [['file' => 'teaser', 'table' => 17, 'uid' => 2001, 'field' => 'image']],
            'code' => 1787256315,
        ];
        // "uid" is the uid a scenario entity declares as its "id", so a string
        // is not "close enough": casting it would look up record 0.
        yield 'a reference without a uid' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference whose uid is a numeric string' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => '2001', 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference whose uid is zero' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => 0, 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference whose uid is negative' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => -1, 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference whose uid is a float' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001.0, 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference whose uid is null' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => null, 'field' => 'image']],
            'code' => 1787256316,
        ];
        yield 'a reference without a field' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001]],
            'code' => 1787256317,
        ];
        yield 'a reference with an empty field' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001, 'field' => '']],
            'code' => 1787256317,
        ];
        yield 'a reference with a field that is not a string' => [
            'references' => [['file' => 'teaser', 'table' => 'tt_content', 'uid' => 2001, 'field' => 17]],
            'code' => 1787256317,
        ];
        yield 'a reference whose values are a string' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => 'Teaser',
            ]],
            'code' => 1787256318,
        ];
        yield 'a reference whose values are a number' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => 17,
            ]],
            'code' => 1787256318,
        ];
        // A list where a map belongs: the fields arrive with integer keys, and
        // an unnamed field cannot be written to any column.
        yield 'a reference whose values are a list' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => ['Teaser'],
            ]],
            'code' => 1787256319,
        ];
        yield 'a reference with a values field whose name is empty' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => ['' => 'Teaser'],
            ]],
            'code' => 1787256319,
        ];
        // A nested map would reach "DataHandler" as the string "Array".
        yield 'a reference with a values field that is a map' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => ['crop' => ['default' => ['x' => 0]]],
            ]],
            'code' => 1787256320,
        ];
        yield 'a reference with a values field that is a list' => [
            'references' => [[
                'file' => 'teaser',
                'table' => 'tt_content',
                'uid' => 2001,
                'field' => 'image',
                'values' => ['title' => ['Teaser']],
            ]],
            'code' => 1787256320,
        ];
    }

    #[DataProvider('invalidReferenceDeclarations')]
    #[Test]
    public function anInvalidFileReferenceDeclarationIsRejected(mixed $references, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'files' => [['identifier' => 'teaser', 'source' => 'Files/teaser.svg']],
            'references' => $references,
        ]);
    }

    #[Test]
    public function aSiteIsParsedWithEverythingItDeclares(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'sites' => [
                [
                    'identifier' => 'main',
                    'rootPage' => 1000,
                    'template' => 'Sites/other',
                    'base' => 'https://example.com/',
                ],
            ],
        ]);

        $this->assertCount(1, $definition->sites);
        $this->assertEquals(
            new SeedSiteConfiguration('main', 1000, 'Sites/other', 'https://example.com/'),
            $definition->sites[0],
        );
    }

    #[Test]
    public function aSiteTemplateDefaultsToTheDirectoryNamedAfterTheIdentifier(): void
    {
        // Filled in here rather than in the seeder, so no consumer has to know
        // the convention - and so an explicit template always wins.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'sites' => [
                ['identifier' => 'main', 'rootPage' => 1000],
                ['identifier' => 'second', 'rootPage' => 2000, 'template' => null],
            ],
        ]);

        $this->assertSame('Sites/main', $definition->sites[0]->template);
        $this->assertSame('Sites/second', $definition->sites[1]->template);
        $this->assertNull($definition->sites[0]->base);
    }

    #[Test]
    public function anEmptySiteBaseIsNotTheSameAsNone(): void
    {
        // An empty string is a base - the one a site rendered under the domain
        // it is called with needs - and only null leaves the template's alone.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'sites' => [['identifier' => 'main', 'rootPage' => 1000, 'base' => '']],
        ]);

        $this->assertSame('', $definition->sites[0]->base);
    }

    #[Test]
    public function anUnknownSiteKeyNamesItselfAndTheKnownKeys(): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072878);
        $this->expectExceptionMessage(
            'A site of the seed definition "config.yml" declares the unknown key "rootPageId". Known keys are:'
            . ' identifier, rootPage, template, base.',
        );

        $this->subject->parse(
            [
                'identifier' => 'demo',
                'title' => 'Demo',
                'scenarios' => ['Pages.yaml'],
                'sites' => [['identifier' => 'main', 'rootPageId' => 1000]],
            ],
            'config.yml',
        );
    }

    /**
     * @return \Generator<string, array{sites: mixed, code: int}>
     */
    public static function invalidSiteDeclarations(): \Generator
    {
        yield 'sites not a list' => ['sites' => 'nope', 'code' => 1787072816];
        yield 'sites a map rather than a list' => [
            'sites' => ['main' => ['rootPage' => 1000]],
            'code' => 1787072816,
        ];
        yield 'a site that is not a map' => ['sites' => ['nope'], 'code' => 1787072870];
        yield 'a site without an identifier' => ['sites' => [['rootPage' => 1000]], 'code' => 1787072871];
        yield 'a site with an empty identifier' => [
            'sites' => [['identifier' => '', 'rootPage' => 1000]],
            'code' => 1787072871,
        ];
        yield 'a site with an identifier that is not a string' => [
            'sites' => [['identifier' => 17, 'rootPage' => 1000]],
            'code' => 1787072871,
        ];
        // Everything below becomes a directory name under "config/sites/".
        yield 'a site identifier holding a separator' => [
            'sites' => [['identifier' => 'main/sub', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'a site identifier that is the parent directory' => [
            'sites' => [['identifier' => '..', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'a site identifier holding a dot' => [
            'sites' => [['identifier' => 'main.site', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'a site identifier holding a space' => [
            'sites' => [['identifier' => 'main site', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'a site identifier starting with an underscore' => [
            'sites' => [['identifier' => '_main', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'a site identifier starting with a dash' => [
            'sites' => [['identifier' => '-main', 'rootPage' => 1000]],
            'code' => 1787072872,
        ];
        yield 'the same site identifier twice' => [
            'sites' => [
                ['identifier' => 'main', 'rootPage' => 1000],
                ['identifier' => 'main', 'rootPage' => 2000],
            ],
            'code' => 1787072873,
        ];
        // "rootPage" is a page uid now, so a string is not "close enough":
        // casting it would accept "eleven" as page 0.
        yield 'a site without a rootPage' => ['sites' => [['identifier' => 'main']], 'code' => 1787072874];
        yield 'a site whose rootPage is a numeric string' => [
            'sites' => [['identifier' => 'main', 'rootPage' => '1000']],
            'code' => 1787072874,
        ];
        yield 'a site whose rootPage is a seed identifier' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 'home']],
            'code' => 1787072874,
        ];
        yield 'a site whose rootPage is zero' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 0]],
            'code' => 1787072874,
        ];
        yield 'a site whose rootPage is negative' => [
            'sites' => [['identifier' => 'main', 'rootPage' => -1]],
            'code' => 1787072874,
        ];
        yield 'a site whose rootPage is a float' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 1000.0]],
            'code' => 1787072874,
        ];
        yield 'a site whose rootPage is null' => [
            'sites' => [['identifier' => 'main', 'rootPage' => null]],
            'code' => 1787072874,
        ];
        yield 'a site whose template is empty' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 1000, 'template' => '']],
            'code' => 1787072877,
        ];
        yield 'a site whose template is not a string' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 1000, 'template' => 17]],
            'code' => 1787072877,
        ];
        yield 'a site whose base is not a string' => [
            'sites' => [['identifier' => 'main', 'rootPage' => 1000, 'base' => 17]],
            'code' => 1787072879,
        ];
    }

    #[DataProvider('invalidSiteDeclarations')]
    #[Test]
    public function anInvalidSiteDeclarationIsRejected(mixed $sites, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'sites' => $sites,
        ]);
    }

    #[Test]
    public function aSiteIdentifierMayHoldLettersDigitsDashesAndUnderscores(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'scenarios' => ['Pages.yaml'],
            'sites' => [
                ['identifier' => 'main', 'rootPage' => 1],
                ['identifier' => 'Main-2_site', 'rootPage' => 2],
                ['identifier' => '2nd', 'rootPage' => 3],
            ],
        ]);

        $this->assertSame(
            ['main', 'Main-2_site', '2nd'],
            array_map(static fn(SeedSiteConfiguration $site): string => $site->identifier, $definition->sites),
        );
    }

    #[Test]
    public function anEntryFileIsReadWithTheDirectoryHoldingItAsBasePath(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Imports/config.yml');

        $this->assertSame('imports-demo', $definition->identifier);
        $this->assertSame('A set split over two files', $definition->title);
        // Relative resource paths of the set resolve against this, not against
        // the public path and not against the current working directory.
        $this->assertSame(__DIR__ . '/Fixtures/Imports', $definition->basePath);
    }

    #[Test]
    public function anImportedFileContributesItsScenariosInDeclarationOrder(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Imports/config.yml');

        // The imported list is merged in front of the importing one rather than
        // replacing it, which is what makes a descriptor splittable without the
        // composition order changing.
        $this->assertSame(['Pages.yaml', 'Content.yaml'], $definition->scenarios);
    }

    #[Test]
    public function aFailingImportIsRejectedRatherThanSilentlyDropped(): void
    {
        // "YamlFileLoader" logs a failing import and carries on. For a seed
        // definition that is data loss, so "ThrowOnErrorLogger" turns the
        // report back into an exception.
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072804);

        $this->subject->parseFile(__DIR__ . '/Fixtures/MissingImport/config.yml');
    }

    #[Test]
    public function anEntryFileThatIsNotYamlIsRejected(): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072803);

        $this->subject->parseFile(__DIR__ . '/Fixtures/BrokenYaml/config.yml');
    }

    #[Test]
    public function anEntryFileThatDoesNotExistIsRejected(): void
    {
        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787072801);

        $this->subject->parseFile(__DIR__ . '/Fixtures/DoesNotExist/config.yml');
    }

    #[Test]
    public function aDirectoryIsNotAnEntryFile(): void
    {
        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787072801);

        $this->subject->parseFile(__DIR__ . '/Fixtures/Imports');
    }

    #[Test]
    public function anEntryFileThatCannotBeReadIsRejected(): void
    {
        // A separate code from "does not exist", because the two need different
        // answers: one is a broken descriptor, the other is a permission
        // problem on the machine running the import.
        //
        // The fixture is readable; what is not is the answer the parser gets,
        // see the shadowed "is_readable()" at the bottom of this file.
        $this->assertFileIsReadable(self::UNREADABLE_ENTRY_FILE);

        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787072802);

        $this->subject->parseFile(self::UNREADABLE_ENTRY_FILE);
    }

    #[Test]
    public function percentSignsInValuesSurviveVerbatim(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Placeholders/config.yml');

        // Placeholder substitution is switched off deliberately: a title is
        // content, "50%" is a percentage rather than the beginning of a
        // placeholder, and "%identifier%" would otherwise be replaced with the
        // identifier of the very definition it stands in.
        $this->assertSame('Save 50% today, 100% sure', $definition->title);
        $this->assertSame('%identifier% is not substituted', $definition->description);
        $this->assertSame(['%identifier%.yaml'], $definition->scenarios);
    }

}
