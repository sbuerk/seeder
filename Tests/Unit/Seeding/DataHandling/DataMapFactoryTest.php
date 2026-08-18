<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\DataMapFactory;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedFileReference;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The data map is where every silent failure of the seeder is decided: a `pid`
 * that is not chained reverses the tree, a relation written as something other
 * than the comma separated placeholder list resolves to nothing, and a
 * suggested uid in the wrong place is dropped without a word.
 *
 * None of that is visible in the written rows without knowing what to look for,
 * which is why the map itself is pinned here rather than only its effect.
 */
final class DataMapFactoryTest extends UnitTestCase
{
    private DataMapFactory $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new DataMapFactory();
    }

    private function definition(SeedRecord ...$records): SeedDefinition
    {
        return new SeedDefinition(
            identifier: 'demo',
            title: 'Demo',
            records: array_values($records),
        );
    }

    #[Test]
    public function nestingBecomesThePidOfTheChild(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', ['title' => 'Home'], 1, [
                new SeedRecord('pages', 'sub', ['title' => 'Sub']),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        $this->assertSame('0', $map['pages']['NEWpages-home']['pid']);
        $this->assertSame('NEWpages-home', $map['pages']['NEWpages-sub']['pid']);
    }

    #[Test]
    public function siblingsAfterTheFirstArePlacedBehindTheirPredecessor(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'first', []),
            new SeedRecord('pages', 'second', []),
            new SeedRecord('pages', 'third', []),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // Without this a new record goes to the top of its parent, and the tree
        // would come out in reverse declaration order.
        $this->assertSame('0', $map['pages']['NEWpages-first']['pid']);
        $this->assertSame('-NEWpages-first', $map['pages']['NEWpages-second']['pid']);
        $this->assertSame('-NEWpages-second', $map['pages']['NEWpages-third']['pid']);
    }

    #[Test]
    public function thePredecessorIsTrackedPerTable(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'text-one', []),
                new SeedRecord('tt_content', 'text-two', []),
                new SeedRecord('pages', 'sub', []),
                new SeedRecord('sys_category', 'category', []),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // A negative pid names a record of the SAME table, so the first content
        // element, the first sub page and the first record of a third table all
        // address the page they belong to.
        $this->assertSame('NEWpages-home', $map['tt_content']['NEWttcontent-text-one']['pid']);
        $this->assertSame('-NEWttcontent-text-one', $map['tt_content']['NEWttcontent-text-two']['pid']);
        $this->assertSame('NEWpages-home', $map['pages']['NEWpages-sub']['pid']);
        $this->assertSame('NEWpages-home', $map['sys_category']['NEWsyscategory-category']['pid']);
    }

    #[Test]
    public function declaredUidsAreCollectedAsSuggestions(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], 1, [
                new SeedRecord('pages', 'sub', [], 4),
                new SeedRecord('pages', 'no-uid', []),
            ]),
        );

        $result = $this->subject->createFromDefinition($definition);

        // Keyed "<table>:<uid>", which is the key "DataHandler::insertDB()"
        // looks the suggestion up under. Keyed by the placeholder instead the
        // lookup never matches and the suggestion is dropped without a word.
        $this->assertSame(
            ['pages:1' => true, 'pages:4' => true],
            $result['suggestedUids'],
        );

        // And the uid has to be in the row as well, because that is where
        // DataHandler reads the suggestion from before it looks the key up. It
        // drops the column again before the insert, so this cannot write a uid
        // on its own.
        $this->assertSame(1, $result['dataMap']['pages']['NEWpages-home']['uid']);
        $this->assertArrayNotHasKey('uid', $result['dataMap']['pages']['NEWpages-no-uid']);
    }

    #[Test]
    public function recordsAreSeededVisible(): void
    {
        $definition = $this->definition(new SeedRecord('tt_content', 'text', ['header' => 'Text']));

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // DataHandler creates records hidden, which would leave a seeded tree
        // invisible in the frontend with nothing saying why.
        $this->assertSame(0, $map['tt_content']['NEWttcontent-text']['hidden']);
    }

    #[Test]
    public function aDefinitionCanAskForAHiddenRecord(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', ['hidden' => 1]));

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        $this->assertSame(1, $map['pages']['NEWpages-home']['hidden']);
    }

    #[Test]
    public function everyPageCarriesTheFieldsTheSiteConfigurationHookReads(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', ['title' => 'Home']));

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // "CreateSiteConfiguration" reads "l10n_parent", "pid" and "doktype"
        // out of the assembled field array, and neither "doktype" nor the two
        // language fields are guaranteed to be filled in from the TCA. Missing
        // here they become an "Undefined array key" warning while seeding.
        $this->assertSame(
            ['title' => 'Home', 'pid' => '0', 'hidden' => 0, 'doktype' => 1, 'l10n_parent' => 0, 'sys_language_uid' => 0],
            $map['pages']['NEWpages-home'],
        );
    }

    #[Test]
    public function aPageDeclaringThoseFieldsKeepsItsOwnValues(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'storage', [
                'title' => 'Storage',
                'doktype' => 254,
                'l10n_parent' => 3,
                'sys_language_uid' => 1,
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // They are defaults, not overrides: a translated page or a folder has
        // to be seedable, so only "pid" is structure and always wins.
        $this->assertSame(254, $map['pages']['NEWpages-storage']['doktype']);
        $this->assertSame(3, $map['pages']['NEWpages-storage']['l10n_parent']);
        $this->assertSame(1, $map['pages']['NEWpages-storage']['sys_language_uid']);
    }

    #[Test]
    public function thePageDefaultsAreNotAppliedToOtherTables(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'text', ['header' => 'Text']),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // "doktype" is a column of "pages" and of nothing else. Writing it onto
        // every table would be a field DataHandler discards on a good day and
        // an error on a bad one.
        $this->assertSame(
            ['header' => 'Text', 'pid' => 'NEWpages-home', 'hidden' => 0],
            $map['tt_content']['NEWttcontent-text'],
        );
    }

    #[Test]
    public function everyNonStructuralFieldReachesTheDataMapVerbatim(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [
                'title' => 'Home',
                'backend_layout' => 'pagets__content',
                'nav_hide' => 1,
                'abstract' => null,
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // Apart from "pid" and the defaults above, a field the factory has
        // never heard of needs no support to be seedable.
        $this->assertSame('pagets__content', $map['pages']['NEWpages-home']['backend_layout']);
        $this->assertSame(1, $map['pages']['NEWpages-home']['nav_hide']);
        $this->assertNull($map['pages']['NEWpages-home']['abstract']);
    }

    #[Test]
    public function theStructuralPidIsNeverTakenFromTheDefinition(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', ['pid' => 99]));

        $map = $this->subject->createFromDefinition($definition, 7)['dataMap'];

        $this->assertSame('7', $map['pages']['NEWpages-home']['pid']);
    }

    #[Test]
    public function recordsAreWrittenBelowTheGivenRootPage(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', []));

        $map = $this->subject->createFromDefinition($definition, 42)['dataMap'];

        $this->assertSame('42', $map['pages']['NEWpages-home']['pid']);
    }

    #[Test]
    public function aPlaceholderCarriesNoUnderscore(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'links', [], null, [], [], [
                    'tx_example_items' => [new SeedRecord('tx_example_item', 'links-docs', [])],
                ]),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // DataHandler::processRemapStack() reads a relation value containing an
        // underscore as the "<table>_<uid>" form and takes it apart there, so a
        // placeholder carrying one resolves to nothing and the relation is
        // written empty - with an empty error log.
        $placeholders = [
            'pages' => 'NEWpages-home',
            'tt_content' => 'NEWttcontent-links',
            'tx_example_item' => 'NEWtxexampleitem-links-docs',
        ];
        foreach ($placeholders as $table => $placeholder) {
            $this->assertArrayHasKey($placeholder, $map[$table]);
            $this->assertStringNotContainsString('_', $placeholder);
        }
    }

    #[Test]
    public function anInlineFieldIsWrittenAsTheCommaJoinedPlaceholdersInDeclarationOrder(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'links', ['CType' => 'example_linklist'], null, [], [], [
                    'tx_example_items' => [
                        new SeedRecord('tx_example_item', 'links-docs', ['label' => 'Docs']),
                        new SeedRecord('tx_example_item', 'links-media', ['label' => 'Media']),
                    ],
                ]),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // DataHandler numbers the relation by walking this list, so its order
        // is the order the children come out in - not the order of the data map
        // and not the "sorting" of the child records.
        $this->assertSame(
            'NEWtxexampleitem-links-docs,NEWtxexampleitem-links-media',
            $map['tt_content']['NEWttcontent-links']['tx_example_items'],
        );
    }

    #[Test]
    public function anInlineChildIsWrittenOntoThePageItsParentSitsOn(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'first', []),
                new SeedRecord('tt_content', 'links', [], null, [], [], [
                    'tx_example_items' => [
                        new SeedRecord('tx_example_item', 'links-docs', []),
                        new SeedRecord('tx_example_item', 'links-media', []),
                    ],
                ]),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // Not the parent's placeholder, which would put a content record on a
        // content record, and not the negative "insert after" hint the sibling
        // levels use: that is a sorting instruction against the same table, and
        // the order of inline children comes from the relation list instead.
        $this->assertSame('NEWpages-home', $map['tx_example_item']['NEWtxexampleitem-links-docs']['pid']);
        $this->assertSame('NEWpages-home', $map['tx_example_item']['NEWtxexampleitem-links-media']['pid']);
    }

    #[Test]
    public function anInlineChildTakesTheSuggestedUidAndTheVisibleDefault(): void
    {
        $definition = $this->definition(
            new SeedRecord('tt_content', 'links', [], null, [], [], [
                'tx_example_items' => [new SeedRecord('tx_example_item', 'links-docs', [], 42)],
            ]),
        );

        $result = $this->subject->createFromDefinition($definition);

        $this->assertTrue($result['suggestedUids']['tx_example_item:42']);
        $this->assertSame(42, $result['dataMap']['tx_example_item']['NEWtxexampleitem-links-docs']['uid']);
        $this->assertSame(0, $result['dataMap']['tx_example_item']['NEWtxexampleitem-links-docs']['hidden']);
    }

    #[Test]
    public function aRecordMayCarryMoreThanOneInlineField(): void
    {
        $definition = $this->definition(
            new SeedRecord('tt_content', 'element', [], null, [], [], [
                'tx_example_items' => [new SeedRecord('tx_example_item', 'first', [])],
                'tx_example_others' => [new SeedRecord('tx_example_other', 'second', [])],
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        $this->assertSame('NEWtxexampleitem-first', $map['tt_content']['NEWttcontent-element']['tx_example_items']);
        $this->assertSame('NEWtxexampleother-second', $map['tt_content']['NEWttcontent-element']['tx_example_others']);
        $this->assertArrayHasKey('NEWtxexampleitem-first', $map['tx_example_item']);
        $this->assertArrayHasKey('NEWtxexampleother-second', $map['tx_example_other']);
    }

    #[Test]
    public function inlineChildrenDoNotChainTheirSiblingsIntoTheDeclaredContentOrder(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'links', [], null, [], [], [
                    'tx_example_items' => [new SeedRecord('tx_example_item', 'links-docs', [])],
                ]),
                new SeedRecord('tt_content', 'social', [], null, [], [], [
                    'tx_example_items' => [new SeedRecord('tx_example_item', 'social-mastodon', [])],
                ]),
            ]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // The children of two different parents share one table. Chaining them
        // per table, as the page and content levels do, would make the second
        // parent's child point at the first parent's child.
        $this->assertSame('NEWpages-home', $map['tx_example_item']['NEWtxexampleitem-social-mastodon']['pid']);
        // The content elements themselves are still chained.
        $this->assertSame('-NEWttcontent-links', $map['tt_content']['NEWttcontent-social']['pid']);
    }

    #[Test]
    public function anEmptyInlineFieldIsNotWritten(): void
    {
        $definition = $this->definition(
            new SeedRecord('tt_content', 'links', [], null, [], [], ['tx_example_items' => []]),
        );

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // An empty relation value and an absent one are not the same thing:
        // written, it would ask DataHandler to resolve a list of nothing.
        $this->assertArrayNotHasKey('tx_example_items', $map['tt_content']['NEWttcontent-links']);
    }

    #[Test]
    public function aRecordWithoutAnyValueStillProducesItsStructuralRow(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', []));

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        // A record declaring nothing but its identifier is legal, and what it
        // produces is exactly the structure and the defaults.
        $this->assertSame(
            ['pid' => '0', 'hidden' => 0, 'doktype' => 1, 'l10n_parent' => 0, 'sys_language_uid' => 0],
            $map['pages']['NEWpages-home'],
        );
    }

    #[Test]
    public function aRecordWithoutChildrenProducesOneRow(): void
    {
        $definition = $this->definition(new SeedRecord('pages', 'home', ['title' => 'Home'], null, [], [], []));

        $map = $this->subject->createFromDefinition($definition)['dataMap'];

        $this->assertSame(['pages'], array_keys($map));
        $this->assertCount(1, $map['pages']);
    }

    #[Test]
    public function aDefinitionWithoutRecordsProducesAnEmptyDataMap(): void
    {
        $result = $this->subject->createFromDefinition(new SeedDefinition(identifier: 'empty', title: 'Empty'));

        // Empty, not a map with empty tables in it: the seeder refuses on this
        // rather than handing DataHandler something to do nothing with.
        $this->assertSame([], $result['dataMap']);
        $this->assertSame([], $result['suggestedUids']);
        $this->assertSame([], $result['references']);
    }

    /**
     * A file reference is collected, not written into the data map.
     *
     * `sys_file_reference.uid_foreign` is a plain integer column rather than a
     * relation DataHandler resolves, so a row written here would carry the
     * parent's `NEW…` placeholder as a string, be read as `0`, and belong to
     * record 0 - with an empty error log. What the factory produces is
     * therefore a description of what to write once the records exist.
     */
    #[Test]
    public function aFileReferenceIsCollectedRatherThanWrittenIntoTheDataMap(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', ['title' => 'Home'], null, [], [
                'media' => [new SeedFileReference('landscape', ['alternative' => 'A placeholder'])],
            ]),
        );

        $result = $this->subject->createFromDefinition($definition, 0, ['landscape' => 7]);

        $this->assertArrayNotHasKey('sys_file_reference', $result['dataMap']);
        // And no counter field either: the parent's field is written in the
        // second pass, from the placeholders of the rows created there.
        $this->assertArrayNotHasKey('media', $result['dataMap']['pages']['NEWpages-home']);
        $this->assertSame(
            [
                [
                    'parent' => 'NEWpages-home',
                    'table' => 'pages',
                    'field' => 'media',
                    'file' => 7,
                    'pid' => '0',
                    'values' => ['alternative' => 'A placeholder'],
                ],
            ],
            $result['references'],
        );
    }

    /**
     * The `pid` of a reference is the page of its level, never the record's own
     * `pid` - which for every sibling after the first is the negative "insert
     * after" hint, a sorting instruction and not a page.
     */
    #[Test]
    public function aReferenceIsPlacedOnThePageOfItsLevelRatherThanOnTheRecordsOwnPid(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [
                new SeedRecord('tt_content', 'first', []),
                new SeedRecord('tt_content', 'second', [], null, [], [
                    'image' => [new SeedFileReference('landscape')],
                ]),
            ]),
        );

        $result = $this->subject->createFromDefinition($definition, 0, ['landscape' => 7]);

        $this->assertCount(1, $result['references']);
        // The record itself is chained behind its predecessor...
        $this->assertSame('-NEWttcontent-first', $result['dataMap']['tt_content']['NEWttcontent-second']['pid']);
        // ...and its reference still goes onto the page.
        $this->assertSame('NEWpages-home', $result['references'][0]['pid']);
    }

    /**
     * The references of one field keep their declared order, because that is
     * the order the second pass numbers `sorting_foreign` in.
     */
    #[Test]
    public function theReferencesOfAFieldAreCollectedInDeclarationOrder(): void
    {
        $definition = $this->definition(
            new SeedRecord('tt_content', 'gallery', [], null, [], [
                'image' => [new SeedFileReference('landscape'), new SeedFileReference('portrait')],
            ]),
        );

        $references = $this->subject->createFromDefinition(
            $definition,
            0,
            ['landscape' => 7, 'portrait' => 8],
        )['references'];

        $this->assertSame([7, 8], array_column($references, 'file'));
    }

    /**
     * A record referencing a file the definition does not declare is refused
     * rather than written with a `uid_local` of nothing, which would be a
     * reference to no file at all.
     */
    #[Test]
    public function referencingAFileTheDefinitionDoesNotDeclareIsRefused(): void
    {
        $definition = $this->definition(
            new SeedRecord('pages', 'home', [], null, [], ['media' => [new SeedFileReference('nope')]]),
        );

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787076003);

        $this->subject->createFromDefinition($definition);
    }
}
