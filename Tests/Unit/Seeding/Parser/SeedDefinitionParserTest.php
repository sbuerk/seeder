<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SeedDefinitionParserTest extends UnitTestCase
{
    private SeedDefinitionParser $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SeedDefinitionParser();
    }

    #[Test]
    public function setMetadataIsParsed(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo page tree',
            'description' => 'A page tree to look at.',
        ]);

        $this->assertSame('demo', $definition->identifier);
        $this->assertSame('Demo page tree', $definition->title);
        $this->assertSame('A page tree to look at.', $definition->description);
    }

    #[Test]
    public function aDefinitionWithoutRecordsIsAccepted(): void
    {
        // A set may ship files or site configurations alone, and an empty set
        // is a valid intermediate state while one is being written.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
        ]);

        $this->assertSame([], $definition->records);
        $this->assertSame([], $definition->files);
        $this->assertSame([], $definition->sites);
        $this->assertSame('', $definition->description);
        $this->assertSame('', $definition->basePath);
    }

    #[Test]
    public function anEmptyDescriptionKeyIsTheSameAsNone(): void
    {
        // "description:" with nothing behind it decodes to null.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'description' => null,
        ]);

        $this->assertSame('', $definition->description);
    }

    #[Test]
    public function structuralKeysAreNotWrittenAsFields(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [
                ['identifier' => 'home', 'uid' => 1, 'title' => 'Home', 'children' => [], 'content' => []],
            ],
        ]);

        $record = $definition->records[0];

        $this->assertSame(['title' => 'Home'], $record->values);
        $this->assertSame(1, $record->uid);
        $this->assertSame('home', $record->identifier);
        $this->assertSame('pages', $record->table);
    }

    #[Test]
    public function everyKeyThatIsNotStructureIsCopiedVerbatim(): void
    {
        // The load bearing property of the format: a field needs no support in
        // the seeder to be seedable. Nothing here is known to this extension.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'tx_vendor_unknown_field' => 'kept',
                'nav_hide' => 1,
                'abstract' => null,
                'is_siteroot' => true,
                'tx_vendor_float' => 1.5,
            ]],
        ]);

        $this->assertSame(
            [
                'tx_vendor_unknown_field' => 'kept',
                'nav_hide' => 1,
                'abstract' => null,
                'is_siteroot' => true,
                'tx_vendor_float' => 1.5,
            ],
            $definition->records[0]->values,
        );
    }

    #[Test]
    public function contentIsNestedAsTtContentAndChildrenAsPages(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [
                [
                    'identifier' => 'home',
                    'content' => [['identifier' => 'text', 'CType' => 'text']],
                    'children' => [['identifier' => 'sub', 'title' => 'Sub']],
                ],
            ],
        ]);

        $children = $definition->records[0]->children;

        $this->assertCount(2, $children);
        // Content first, so it lands above the sub pages of the same parent.
        $this->assertSame('tt_content', $children[0]->table);
        $this->assertSame('pages', $children[1]->table);
    }

    #[Test]
    public function uidIsOptional(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [['identifier' => 'home', 'title' => 'Home']],
        ]);

        $this->assertNull($definition->records[0]->uid);
    }

    #[Test]
    public function aFileReferenceIsEitherAnIdentifierOrAMapCarryingItsFields(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [
                [
                    'identifier' => 'home',
                    'files' => [
                        'media' => [
                            'plain',
                            ['identifier' => 'annotated', 'alternative' => 'Alt text', 'description' => 'Caption'],
                        ],
                    ],
                ],
            ],
        ]);

        $references = $definition->records[0]->files['media'];

        $this->assertSame('plain', $references[0]->identifier);
        // The short form declares no fields rather than empty ones, so nothing
        // it does not mention is written to the reference at all.
        $this->assertSame([], $references[0]->values);

        $this->assertSame('annotated', $references[1]->identifier);
        $this->assertSame(['alternative' => 'Alt text', 'description' => 'Caption'], $references[1]->values);
    }

    #[Test]
    public function aFileFieldWithAnEmptyListDeclaresNoReference(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [['identifier' => 'home', 'files' => ['media' => []]]],
        ]);

        // Not an empty list under "media": seeding writes, and an empty
        // declaration is not an instruction to clear the relation, so the
        // field is not addressed at all.
        $this->assertSame([], $definition->records[0]->files);
    }

    #[Test]
    public function anEmptyInlineMapDeclaresNoChildren(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [['identifier' => 'home', 'inline' => []]],
        ]);

        $this->assertSame([], $definition->records[0]->inline);
        // ... and "inline" is still structure, so it is not written as a field.
        $this->assertSame([], $definition->records[0]->values);
    }

    #[Test]
    public function inlineChildrenAreKeyedByTheParentFieldAndCarryTheirOwnTable(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [[
                    'identifier' => 'links',
                    'CType' => 'example_linklist',
                    'inline' => [
                        'tx_example_items' => [
                            ['identifier' => 'links-docs', 'table' => 'tx_example_item', 'link_label' => 'Docs'],
                            ['identifier' => 'links-media', 'table' => 'tx_example_item', 'link_label' => 'Media'],
                        ],
                    ],
                ]],
            ]],
        ]);

        $parent = $definition->records[0]->children[0];
        $children = $parent->inline['tx_example_items'];

        $this->assertSame(['tx_example_items'], array_keys($parent->inline));
        $this->assertCount(2, $children);
        $this->assertSame('tx_example_item', $children[0]->table);
        $this->assertSame('links-docs', $children[0]->identifier);
        $this->assertSame('links-media', $children[1]->identifier);
        // "table" is structure on an inline child, so it is not written as a
        // field of the record.
        $this->assertSame(['link_label' => 'Docs'], $children[0]->values);
        // ... and "inline" is not written as a field of the parent either.
        $this->assertSame(['CType' => 'example_linklist'], $parent->values);
    }

    #[Test]
    public function aRecordMayCarryMoreThanOneInlineField(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [[
                    'identifier' => 'element',
                    'inline' => [
                        'tx_example_items' => [
                            ['identifier' => 'first', 'table' => 'tx_example_item'],
                        ],
                        'tx_example_others' => [
                            ['identifier' => 'second', 'table' => 'tx_example_other'],
                        ],
                    ],
                ]],
            ]],
        ]);

        $inline = $definition->records[0]->children[0]->inline;

        $this->assertSame(['tx_example_items', 'tx_example_others'], array_keys($inline));
        $this->assertSame('tx_example_item', $inline['tx_example_items'][0]->table);
        $this->assertSame('tx_example_other', $inline['tx_example_others'][0]->table);
    }

    #[Test]
    public function anInlineChildTakesTheSameUidAndFilesAsAnyOtherRecord(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [[
                    'identifier' => 'grid',
                    'inline' => [
                        'tx_example_items' => [[
                            'identifier' => 'grid-tile',
                            'table' => 'tx_example_item',
                            'uid' => 42,
                            'files' => ['image' => ['placeholder']],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $child = $definition->records[0]->children[0]->inline['tx_example_items'][0];

        $this->assertSame(42, $child->uid);
        $this->assertSame('placeholder', $child->files['image'][0]->identifier);
    }

    #[Test]
    public function recordsCarryTheirOwnTableAndAreNestedOntoThePageDeclaringThem(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'storage',
                'doktype' => 254,
                'records' => [
                    ['identifier' => 'category-news', 'table' => 'sys_category', 'title' => 'News'],
                    ['identifier' => 'user-doe', 'table' => 'fe_users', 'username' => 'doe'],
                ],
            ]],
        ]);

        $page = $definition->records[0];
        $records = $page->children;

        $this->assertCount(2, $records);
        $this->assertSame('sys_category', $records[0]->table);
        $this->assertSame('fe_users', $records[1]->table);
        // "table" is structure under "records", exactly as it is on an inline
        // child, so it is not written as a field of the record.
        $this->assertSame(['title' => 'News'], $records[0]->values);
        // ... and "records" is not written as a field of the page either.
        $this->assertSame(['doktype' => 254], $page->values);
    }

    #[Test]
    public function recordsJoinContentAndChildrenRatherThanReplacingThem(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [['identifier' => 'home-heading', 'CType' => 'header']],
                'records' => [['identifier' => 'home-category', 'table' => 'sys_category']],
                'children' => [['identifier' => 'about']],
            ]],
        ]);

        $tables = array_map(
            static fn(SeedRecord $record): string => $record->table,
            $definition->records[0]->children,
        );

        $this->assertSame(['tt_content', 'sys_category', 'pages'], $tables);
    }

    #[Test]
    public function aRecordTakesTheSameUidFilesAndInlineAsAnyOtherRecord(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'storage',
                'records' => [[
                    'identifier' => 'profile-doe',
                    'table' => 'tx_example_profile',
                    'uid' => 42,
                    'files' => ['image' => ['placeholder']],
                    'inline' => [
                        'contracts' => [[
                            'identifier' => 'contract-doe',
                            'table' => 'tx_example_contract',
                            'position' => 'Professor',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $record = $definition->records[0]->children[0];

        $this->assertSame(42, $record->uid);
        $this->assertSame('placeholder', $record->files['image'][0]->identifier);
        $this->assertSame('tx_example_contract', $record->inline['contracts'][0]->table);
        $this->assertSame(['position' => 'Professor'], $record->inline['contracts'][0]->values);
    }

    #[Test]
    public function recordsIsAFieldEverywhereButOnAPage(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [['identifier' => 'insert', 'CType' => 'shortcut', 'records' => 'tt_content_601']],
            ]],
        ]);

        $record = $definition->records[0]->children[0];

        // "tt_content" has a column of that name - the one the "Insert records"
        // element writes into - so on a content element it is an ordinary field
        // and has to survive as one.
        $this->assertSame(['CType' => 'shortcut', 'records' => 'tt_content_601'], $record->values);
        $this->assertSame([], $record->children);
    }

    #[Test]
    public function recordsIsStructureOnAPageWhereverThatPageWasDeclared(): void
    {
        // The resolved table decides, not the level: a page declared below
        // "records" is still a page, and "records" on it is still structure.
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'records' => [[
                    'identifier' => 'sub',
                    'table' => 'pages',
                    'title' => 'Sub',
                    'records' => [['identifier' => 'sub-category', 'table' => 'sys_category']],
                ]],
            ]],
        ]);

        $sub = $definition->records[0]->children[0];

        $this->assertSame('pages', $sub->table);
        $this->assertSame(['title' => 'Sub'], $sub->values);
        $this->assertCount(1, $sub->children);
        $this->assertSame('sys_category', $sub->children[0]->table);
    }

    #[Test]
    public function tableIsAFieldOnAPageButStructureOnAnInlineChild(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                // "pages" has real fields whose name starts with "table", and
                // the table of a page comes from the nesting, so a "table" key
                // here is an ordinary field and has to survive as one.
                'table' => 'nope',
                'tablespace' => 'also a field',
                'inline' => [
                    'tx_example_items' => [
                        ['identifier' => 'item', 'table' => 'tx_example_item', 'label' => 'Docs'],
                    ],
                ],
            ]],
        ]);

        $page = $definition->records[0];

        $this->assertSame('pages', $page->table);
        $this->assertSame(['table' => 'nope', 'tablespace' => 'also a field'], $page->values);
        $this->assertSame('tx_example_item', $page->inline['tx_example_items'][0]->table);
        $this->assertSame(['label' => 'Docs'], $page->inline['tx_example_items'][0]->values);
    }

    #[Test]
    public function tableIsAFieldOnAContentElementAsWell(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'content' => [['identifier' => 'a-table', 'CType' => 'table', 'table_caption' => 'Prices', 'table' => 'nope']],
            ]],
        ]);

        $record = $definition->records[0]->children[0];

        $this->assertSame('tt_content', $record->table);
        $this->assertSame(['CType' => 'table', 'table_caption' => 'Prices', 'table' => 'nope'], $record->values);
    }

    #[Test]
    public function filesOfTheSetCarryTheirDefaults(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'files' => [
                ['identifier' => 'plain', 'source' => 'Files/placeholder.svg'],
                [
                    'identifier' => 'full',
                    'source' => 'EXT:seeder/Resources/Public/Icons/Extension.svg',
                    'folder' => 'seeder',
                    'name' => 'renamed.svg',
                    'storage' => 2,
                ],
            ],
        ]);

        $this->assertSame('plain', $definition->files[0]->identifier);
        $this->assertSame('Files/placeholder.svg', $definition->files[0]->source);
        $this->assertSame('/', $definition->files[0]->folder);
        $this->assertNull($definition->files[0]->name);
        $this->assertNull($definition->files[0]->storage);

        $this->assertSame('seeder', $definition->files[1]->folder);
        $this->assertSame('renamed.svg', $definition->files[1]->name);
        $this->assertSame(2, $definition->files[1]->storage);
    }

    #[Test]
    public function sitesArePartOfTheDefinition(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [
                ['identifier' => 'home', 'is_siteroot' => 1],
                ['identifier' => 'shop', 'is_siteroot' => 1],
            ],
            'sites' => [
                ['identifier' => 'main', 'rootPage' => 'home'],
                ['identifier' => 'shop_site', 'rootPage' => 'shop', 'template' => 'Sites/other', 'base' => 'https://example.com/'],
            ],
        ]);

        $this->assertCount(2, $definition->sites);
        $this->assertSame('main', $definition->sites[0]->identifier);
        $this->assertSame('home', $definition->sites[0]->rootPage);
        // The template defaults to "Sites/<identifier>", resolved here so no
        // consumer has to know the default.
        $this->assertSame('Sites/main', $definition->sites[0]->template);
        $this->assertNull($definition->sites[0]->base);

        $this->assertSame('Sites/other', $definition->sites[1]->template);
        $this->assertSame('https://example.com/', $definition->sites[1]->base);
    }

    #[Test]
    public function aSiteMayPointAtAPageDeclaredAnywhereInTheDefinition(): void
    {
        $definition = $this->subject->parse([
            'identifier' => 'demo',
            'title' => 'Demo',
            'pages' => [[
                'identifier' => 'home',
                'children' => [['identifier' => 'campaign', 'is_siteroot' => 1]],
            ]],
            'sites' => [['identifier' => 'campaign', 'rootPage' => 'campaign']],
        ]);

        $this->assertSame('campaign', $definition->sites[0]->rootPage);
    }

    #[Test]
    public function theEntryFileIsReadWithItsDirectoryAsTheBasePath(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Imports/config.yml');

        $this->assertSame('imports-demo', $definition->identifier);
        $this->assertSame('A set split over two files', $definition->title);
        // Relative resource paths of the set resolve against this, not against
        // the public path and not against "EXT:" alone.
        $this->assertSame(__DIR__ . '/Fixtures/Imports', $definition->basePath);
    }

    #[Test]
    public function anImportedFileContributesItsRecordsInDeclarationOrder(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Imports/config.yml');

        $identifiers = array_map(
            static fn(SeedRecord $record): string => $record->identifier,
            $definition->records,
        );

        // The imported list is merged in front of the importing one, which is
        // what makes a set splittable without its page tree changing order.
        $this->assertSame(['home', 'contact'], $identifiers);
        $this->assertSame(['title' => 'Home', 'slug' => '/'], $definition->records[0]->values);
    }

    #[Test]
    public function aFailingImportIsRejectedRatherThanSilentlyDropped(): void
    {
        // "YamlFileLoader" logs a failing import and carries on. For a seed
        // definition that is data loss, so the parser turns the report back
        // into an exception.
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
    public function percentSignsInValuesSurviveVerbatim(): void
    {
        $definition = $this->subject->parseFile(__DIR__ . '/Fixtures/Placeholders/config.yml');

        // Placeholder substitution is switched off deliberately: a seed
        // definition is content, and "50%" is a percentage rather than the
        // beginning of a placeholder.
        $this->assertSame('Save 50% today, 100% sure', $definition->title);
        $this->assertSame(
            [
                'title' => '%identifier% is not substituted',
                'subtitle' => 'width: 50%; height: 100%',
            ],
            $definition->records[0]->values,
        );
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
        yield 'unknown key at the set level' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'page' => []],
            'code' => 1787072814,
        ];
        yield 'no identifier' => [
            'definition' => ['title' => 'Demo', 'pages' => []],
            'code' => 1787072811,
        ];
        yield 'empty identifier' => [
            'definition' => ['identifier' => '', 'title' => 'Demo'],
            'code' => 1787072811,
        ];
        yield 'no title' => [
            'definition' => ['identifier' => 'demo', 'pages' => []],
            'code' => 1787072812,
        ];
        yield 'title not a string' => [
            'definition' => ['identifier' => 'demo', 'title' => 17],
            'code' => 1787072812,
        ];
        yield 'description not a string' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'description' => ['nope']],
            'code' => 1787072813,
        ];
        yield 'pages not a list' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => 'nope'],
            'code' => 1787072815,
        ];
        yield 'pages a map rather than a list' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => ['home' => ['title' => 'Home']]],
            'code' => 1787072815,
        ];
        yield 'record not a map' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => ['nope']],
            'code' => 1787072830,
        ];
        yield 'record without identifier' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['title' => 'Home']]],
            'code' => 1787072831,
        ];
        yield 'identifier carrying an underscore' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => 'home_page']]],
            'code' => 1787072832,
        ];
        yield 'identifier starting with a dash' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => '-home']]],
            'code' => 1787072832,
        ];
        yield 'identifier carrying a dot' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => 'home.page']]],
            'code' => 1787072832,
        ];
        yield 'identifier carrying a space' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => 'home page']]],
            'code' => 1787072832,
        ];
        yield 'duplicate identifier' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home'], ['identifier' => 'home']],
            ],
            'code' => 1787072833,
        ];
        yield 'duplicate identifier across nesting levels' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [
                    ['identifier' => 'home', 'children' => [['identifier' => 'home']]],
                ],
            ],
            'code' => 1787072833,
        ];
        yield 'duplicate identifier between an inline child and a page' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'inline' => [
                        'tx_example_items' => [['identifier' => 'home', 'table' => 'tx_example_item']],
                    ],
                ]],
            ],
            'code' => 1787072833,
        ];
        yield 'duplicate identifier between a record and a page' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'records' => [['identifier' => 'home', 'table' => 'sys_category']],
                ]],
            ],
            'code' => 1787072833,
        ];
        yield 'uid not a positive integer' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => 'home', 'uid' => 0]]],
            'code' => 1787072834,
        ];
        yield 'uid a numeric string' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'pages' => [['identifier' => 'home', 'uid' => '1']]],
            'code' => 1787072834,
        ];
        yield 'inline child without table' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'inline' => ['tx_example_items' => [['identifier' => 'child']]],
                ]],
            ],
            'code' => 1787072835,
        ];
        yield 'record without table' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'records' => [['identifier' => 'orphan']]]],
            ],
            'code' => 1787072835,
        ];
        yield 'content not a list' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'content' => 'nope']],
            ],
            'code' => 1787072836,
        ];
        yield 'records not a list' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'records' => 'nope']],
            ],
            'code' => 1787072837,
        ];
        yield 'children not a list' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'children' => 'nope']],
            ],
            'code' => 1787072838,
        ];
        yield 'field name not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 7 => 'nope']],
            ],
            'code' => 1787072839,
        ];
        yield 'field value not scalar' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'title' => ['nope']]],
            ],
            'code' => 1787072840,
        ];
        yield 'files of the set not a list' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'files' => 'nope'],
            'code' => 1787072820,
        ];
        yield 'file not a map' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'files' => ['nope']],
            'code' => 1787072821,
        ];
        yield 'file without identifier' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'files' => [['source' => 'Files/a.svg']]],
            'code' => 1787072822,
        ];
        yield 'duplicate file identifier' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'files' => [
                    ['identifier' => 'a', 'source' => 'Files/a.svg'],
                    ['identifier' => 'a', 'source' => 'Files/b.svg'],
                ],
            ],
            'code' => 1787072823,
        ];
        yield 'file without source' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'files' => [['identifier' => 'a']]],
            'code' => 1787072824,
        ];
        yield 'file references of a record not a map' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'files' => 'nope']],
            ],
            'code' => 1787072850,
        ];
        yield 'file field name not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'files' => [7 => ['a']]]],
            ],
            'code' => 1787072851,
        ];
        yield 'file field not a list' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'files' => ['media' => 'nope']]],
            ],
            'code' => 1787072852,
        ];
        yield 'file reference neither identifier nor map' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'files' => ['media' => [17]]]],
            ],
            'code' => 1787072853,
        ];
        yield 'file reference map without identifier' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'files' => ['media' => [['alternative' => 'Alt']]]]],
            ],
            'code' => 1787072854,
        ];
        yield 'file reference field name not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'files' => ['media' => [['identifier' => 'hero', 7 => 'nope']]],
                ]],
            ],
            'code' => 1787072855,
        ];
        yield 'file reference field value not scalar' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'files' => ['media' => [['identifier' => 'hero', 'alternative' => ['nope']]]],
                ]],
            ],
            'code' => 1787072856,
        ];
        yield 'inline not a map' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'inline' => 'nope']],
            ],
            'code' => 1787072860,
        ];
        yield 'inline field name not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'inline' => [7 => []]]],
            ],
            'code' => 1787072861,
        ];
        yield 'inline field not a list of records' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home', 'inline' => ['tx_example_items' => 'nope']]],
            ],
            'code' => 1787072862,
        ];
        yield 'sites not a list' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'sites' => 'nope'],
            'code' => 1787072816,
        ];
        yield 'site not a map' => [
            'definition' => ['identifier' => 'demo', 'title' => 'Demo', 'sites' => ['nope']],
            'code' => 1787072870,
        ];
        yield 'site without identifier' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['rootPage' => 'home']],
            ],
            'code' => 1787072871,
        ];
        yield 'site identifier carrying a path separator' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => '../escape', 'rootPage' => 'home']],
            ],
            'code' => 1787072872,
        ];
        yield 'duplicate site identifier' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home'], ['identifier' => 'shop']],
                'sites' => [
                    ['identifier' => 'main', 'rootPage' => 'home'],
                    ['identifier' => 'main', 'rootPage' => 'shop'],
                ],
            ],
            'code' => 1787072873,
        ];
        yield 'site without rootPage' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => 'main']],
            ],
            'code' => 1787072874,
        ];
        yield 'site rootPage naming nothing the definition declares' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => 'main', 'rootPage' => 'typo']],
            ],
            'code' => 1787072875,
        ];
        yield 'site rootPage naming a record that is not a page' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [[
                    'identifier' => 'home',
                    'content' => [['identifier' => 'heading', 'CType' => 'header']],
                ]],
                'sites' => [['identifier' => 'main', 'rootPage' => 'heading']],
            ],
            'code' => 1787072876,
        ];
        yield 'site template not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => 'main', 'rootPage' => 'home', 'template' => 17]],
            ],
            'code' => 1787072877,
        ];
        yield 'site unknown key' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => 'main', 'rootPage' => 'home', 'rootPageId' => 1]],
            ],
            'code' => 1787072878,
        ];
        yield 'site base not a string' => [
            'definition' => [
                'identifier' => 'demo',
                'title' => 'Demo',
                'pages' => [['identifier' => 'home']],
                'sites' => [['identifier' => 'main', 'rootPage' => 'home', 'base' => ['nope']]],
            ],
            'code' => 1787072879,
        ];
    }

    #[DataProvider('invalidDefinitions')]
    #[Test]
    public function invalidDefinitionIsRejected(mixed $definition, int $code): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode($code);

        $this->subject->parse($definition);
    }
}
