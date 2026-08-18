<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\DataMapFactory;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\RecordSeeder;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seeds inline children into a real inline relation.
 *
 * Until this test the inline handling had unit coverage only: the data map was
 * asserted, and whether DataHandler resolves what it says was never proven,
 * because no table with an inline relation existed in this repository. That is
 * precisely the gap a seeder cannot afford, because every way of getting an
 * inline relation wrong is silent. A placeholder carrying an underscore, a
 * parent field that is not written, a child taking its parent's placeholder as
 * its `pid` - none of them is an error DataHandler logs. The relation is
 * written empty, the run reports success, and the only visible difference is
 * that a page which should show three items shows none.
 *
 * The relation comes from the `tests/inline-relations` fixture extension, whose
 * TCA is where the column names asserted here are declared:
 * `tt_content.tx_testsinlinerelations_items` names `parentid` as its
 * `foreign_field`, `parenttable` as its `foreign_table_field` and
 * `sorting_foreign` as its `foreign_sortby`. Nothing in the seeder knows any of
 * those - it writes the parent's field as the comma separated list of the
 * children's placeholders and leaves the rest to DataHandler - which is why
 * asserting the three columns is what proves the relation was understood rather
 * than that rows exist.
 *
 * Every row is read back through the `QueryBuilder`, never as hand written SQL:
 * PostgreSQL folds an unquoted identifier to lower case, so `SELECT CType`
 * asks for a column `ctype` that does not exist.
 */
final class InlineRelationSeedingTest extends AbstractFunctionalTestCase
{
    /**
     * The set is read from disk rather than built in the test, because the
     * file an inline child references is declared with a `source` relative to
     * the directory holding the set.
     */
    private const SEED = 'EXT:seeder/Tests/Functional/Fixtures/Seeds/InlineRelations.yaml';

    private const PARENT_TABLE = 'tx_testsinlinerelations_item';
    private const CHILD_TABLE = 'tx_testsinlinerelations_link';
    private const RELATION_FIELD = 'tx_testsinlinerelations_items';

    /**
     * The extension itself is repeated, because redeclaring the property
     * replaces the one of the parent class.
     */
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
        'tests/inline-relations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container: both
     * services are private and nothing references them yet, so Symfony removes
     * them while compiling the container. Their wiring is proven where they are
     * first injected rather than by publishing them for a test.
     */
    private function subject(): RecordSeeder
    {
        return new RecordSeeder(
            new DataMapFactory(),
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
        );
    }

    /**
     * A functional instance has no file storage, and the set declares a file
     * on one of its inline children. Created through the API rather than from
     * a CSV fixture, so the driver configuration stays the core's business -
     * see `FileSeedingTest::createDefaultStorage()`, which does the same for
     * the same reason.
     *
     * @return array<string, int> The written uids, keyed by seed identifier.
     */
    private function seedSet(): array
    {
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);

        return $this->subject()->seed(
            (new SeedDefinitionParser())->parseFile(self::SEED),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what happens to be visible.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function queryTable(string $table, array $columns, string $orderBy): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy($orderBy)
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * The rows of the inline child table, keyed by uid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function items(): array
    {
        $items = [];
        foreach ($this->queryTable(
            self::PARENT_TABLE,
            ['uid', 'pid', 'parentid', 'parenttable', 'sorting_foreign', 'title', 'image', 'links', 'hidden'],
            'uid',
        ) as $row) {
            $items[(int)$row['uid']] = $row;
        }

        return $items;
    }

    /**
     * An inline child is nested by a relation, not by a `pid`: it goes onto the
     * page its parent sits on.
     *
     * The two mistakes this rules out both look plausible in a data map. Taking
     * the parent's placeholder as the `pid` - which is what the `children` and
     * `content` levels do - puts a record onto a content element rather than
     * onto a page. Taking the page the seed was written below puts every child
     * of the whole set onto the page tree root, which passes as long as the set
     * has one level and stops passing here, because one of the relations sits
     * on a sub page.
     */
    #[Test]
    public function inlineChildrenSitOnThePageTheirParentSitsOn(): void
    {
        $uids = $this->seedSet();

        $items = $this->items();

        foreach (['items-first', 'items-second', 'items-third'] as $identifier) {
            $this->assertSame(
                $uids['home'],
                (int)$items[$uids[$identifier]]['pid'],
                sprintf('The inline child "%s" does not sit on the page its parent sits on.', $identifier),
            );
            // Not on the record that carries the relation, which is the
            // failure a "pid" taken from the parent placeholder produces.
            $this->assertNotSame($uids['items'], (int)$items[$uids[$identifier]]['pid']);
        }

        // One page deeper, and this is the assertion that tells "the page the
        // parent sits on" apart from "the page the seed was written below".
        $this->assertSame($uids['sub'], (int)$items[$uids['sub-items-only']]['pid']);
        $this->assertNotSame($uids['home'], (int)$items[$uids['sub-items-only']]['pid']);
    }

    /**
     * The relation columns DataHandler writes on the child name the record
     * carrying the relation and the table it belongs to.
     *
     * Which columns those are is declared by the TCA of the parent field, not
     * by the seeder: `foreign_field` is `parentid` and `foreign_table_field` is
     * `parenttable`. A relation that was not resolved leaves both at their
     * default - `0` and the empty string - and logs nothing.
     */
    #[Test]
    public function theRelationColumnsOfEveryChildNameItsParent(): void
    {
        $uids = $this->seedSet();

        $items = $this->items();

        foreach (['items-first', 'items-second', 'items-third'] as $identifier) {
            $this->assertSame($uids['items'], (int)$items[$uids[$identifier]]['parentid']);
            $this->assertSame('tt_content', $items[$uids[$identifier]]['parenttable']);
        }

        $this->assertSame($uids['sub-items'], (int)$items[$uids['sub-items-only']]['parentid']);
        $this->assertSame('tt_content', $items[$uids['sub-items-only']]['parenttable']);
    }

    /**
     * `sorting_foreign` follows the order the children are declared in, which
     * is the whole reason the parent's field is written as the comma separated
     * placeholder list at all.
     *
     * `RelationHandler::writeForeignField()` numbers the relation by walking
     * that list, so the list is the only thing the order comes from - not the
     * order of the data map, and not the `sorting` of the child records, which
     * an inline child does not get. Three children rather than two, because a
     * relation numbered in reverse is indistinguishable from one numbered in
     * declaration order when there are two.
     */
    #[Test]
    public function inlineChildrenAreNumberedInDeclarationOrder(): void
    {
        $uids = $this->seedSet();

        $items = $this->items();

        $this->assertSame(1, (int)$items[$uids['items-first']]['sorting_foreign']);
        $this->assertSame(2, (int)$items[$uids['items-second']]['sorting_foreign']);
        $this->assertSame(3, (int)$items[$uids['items-third']]['sorting_foreign']);
        // The relation of the sub page is numbered on its own rather than
        // continuing the count of the first one.
        $this->assertSame(1, (int)$items[$uids['sub-items-only']]['sorting_foreign']);
    }

    /**
     * The counter field of the parent holds the number of children, which is
     * what a resolved relation leaves behind on the parent side.
     *
     * Nothing renders it, so it is only ever right on purpose: the value the
     * seeder writes into it is the placeholder list, and DataHandler replaces
     * that with the count once it has resolved the relation. A parent still
     * carrying `0` is a relation that was never resolved.
     */
    #[Test]
    public function theCounterFieldOfTheParentCountsItsInlineChildren(): void
    {
        $uids = $this->seedSet();

        $content = array_column(
            $this->queryTable('tt_content', ['uid', self::RELATION_FIELD], 'uid'),
            self::RELATION_FIELD,
            'uid',
        );

        $this->assertSame(3, (int)$content[$uids['items']]);
        $this->assertSame(1, (int)$content[$uids['sub-items']]);
        // The element declaring no relation keeps a counter of zero, which is
        // what makes the two above mean something.
        $this->assertSame(0, (int)$content[$uids['intro']]);
    }

    /**
     * An inline child may declare a `uid`, and it is suggested to DataHandler
     * exactly as a page's or a content element's is - 4711 rather than a small
     * number, because a suggestion that is ignored produces the uid the record
     * would have got anyway.
     *
     * It comes out visible as well: a record created through DataHandler is
     * hidden unless the seed says otherwise, and an inline child is no
     * exception.
     */
    #[Test]
    public function anInlineChildKeepsItsSuggestedUidAndIsSeededVisible(): void
    {
        $uids = $this->seedSet();

        $this->assertSame(4711, $uids['items-first']);

        $items = $this->items();

        $this->assertArrayHasKey(4711, $items);
        $this->assertSame('First item', $items[4711]['title']);
        $this->assertSame(0, (int)$items[4711]['hidden']);
    }

    /**
     * An inline child may carry file references of its own, and they are
     * written against the child's table rather than against the table of the
     * record that carries the relation.
     *
     * The `pid` of the reference is the page of the level the child sits on,
     * which for an inline child is the page its parent sits on - never the
     * child's own record, and never the negative "insert after" hint the
     * sibling levels use.
     */
    #[Test]
    public function anInlineChildMayCarryFileReferencesOfItsOwn(): void
    {
        $uids = $this->seedSet();

        $references = $this->queryTable(
            'sys_file_reference',
            ['uid', 'pid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign', 'alternative'],
            'uid',
        );

        $this->assertCount(1, $references);
        $this->assertSame($uids['items-first'], (int)$references[0]['uid_foreign']);
        $this->assertSame(self::PARENT_TABLE, $references[0]['tablenames']);
        $this->assertSame('image', $references[0]['fieldname']);
        $this->assertSame($uids['home'], (int)$references[0]['pid']);
        $this->assertSame(1, (int)$references[0]['sorting_foreign']);
        $this->assertSame('A placeholder graphic', $references[0]['alternative']);
        // The file the reference points at exists rather than the reference
        // pointing at "0", which is what an unresolved placeholder would leave
        // behind - with an empty error log.
        $this->assertSame(
            [(int)$references[0]['uid_local']],
            array_map('intval', array_column($this->queryTable('sys_file', ['uid'], 'uid'), 'uid')),
        );
        // And the counter field of the child itself, which is the parent side
        // of that relation.
        $this->assertSame(1, (int)$this->items()[$uids['items-first']]['image']);
    }

    /**
     * An inline child may carry inline children of its own, and the level below
     * is written exactly like the one above it: onto the page the outermost
     * parent sits on, tied to its own parent by the relation columns of *its*
     * parent field, and numbered in declaration order.
     *
     * `parenttable` is what tells the two levels apart. It carries the child
     * table here rather than `tt_content`, so a nested relation that was
     * silently attached to the content element instead cannot pass.
     */
    #[Test]
    public function anInlineChildMayCarryInlineChildrenOfItsOwn(): void
    {
        $uids = $this->seedSet();

        $links = [];
        foreach ($this->queryTable(
            self::CHILD_TABLE,
            ['uid', 'pid', 'parentid', 'parenttable', 'sorting_foreign', 'title'],
            'uid',
        ) as $row) {
            $links[(int)$row['uid']] = $row;
        }

        $this->assertCount(2, $links);

        foreach (['items-first-alpha' => 1, 'items-first-beta' => 2] as $identifier => $sorting) {
            $link = $links[$uids[$identifier]];
            $this->assertSame($uids['items-first'], (int)$link['parentid']);
            $this->assertSame(self::PARENT_TABLE, $link['parenttable']);
            $this->assertSame($sorting, (int)$link['sorting_foreign']);
            // The page the outermost parent sits on, at every depth.
            $this->assertSame($uids['home'], (int)$link['pid']);
        }

        $this->assertSame('Alpha', $links[$uids['items-first-alpha']]['title']);
        $this->assertSame('Beta', $links[$uids['items-first-beta']]['title']);
        // And the counter field of the level above.
        $this->assertSame(2, (int)$this->items()[$uids['items-first']]['links']);
    }
}
