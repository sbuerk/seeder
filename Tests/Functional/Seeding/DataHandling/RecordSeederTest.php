<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\DataMapFactory;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\RecordSeeder;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes seed definitions into a real instance.
 *
 * The subject is the writing, and the reason it goes through `DataHandler`
 * rather than through SQL: generated slugs, computed `sorting` and a resolved
 * page tree are precisely what a seeder writing rows itself would have to
 * reimplement.
 *
 * Every assertion here reads the database back through the `QueryBuilder`.
 * Hand written SQL would pass on SQLite and MySQL and fail on PostgreSQL,
 * which folds an unquoted identifier to lower case - `SELECT CType` asks for a
 * column `ctype` that does not exist.
 */
final class RecordSeederTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container.
     *
     * `RecordSeeder` is a private service and nothing references it yet, so
     * Symfony removes it while compiling the container and `$this->get()`
     * cannot reach it. Its wiring is proven where it is first injected - the
     * import command - rather than by publishing it for a test.
     *
     * The `FileSeeder` it is given never has anything to do here: none of these
     * definitions declares a file, and a definition without files touches no
     * storage - which is why this test case needs none.
     */
    private function subject(): RecordSeeder
    {
        return new RecordSeeder(
            new DataMapFactory(),
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
        );
    }

    /**
     * A page tree covering everything the writing has to get right at once:
     * three records of three tables on one page, sub pages below it, a second
     * level below one of those, a page declaring its slug and one leaving it to
     * DataHandler, and fields the seeder knows nothing about.
     *
     * @return array<string, mixed>
     */
    private static function tree(): array
    {
        return [
            'identifier' => 'tree',
            'title' => 'A page tree',
            'pages' => [
                [
                    'identifier' => 'home',
                    'uid' => 1,
                    'title' => 'Home',
                    'slug' => '/',
                    'is_siteroot' => 1,
                    'content' => [
                        ['identifier' => 'home-header', 'CType' => 'header', 'header' => 'Welcome'],
                        ['identifier' => 'home-text', 'CType' => 'text', 'header' => 'Text', 'bodytext' => '<p>Body</p>'],
                    ],
                    'records' => [
                        ['identifier' => 'category-first', 'table' => 'sys_category', 'title' => 'First category'],
                        ['identifier' => 'category-second', 'table' => 'sys_category', 'title' => 'Second category'],
                    ],
                    'children' => [
                        ['identifier' => 'first', 'title' => 'First', 'slug' => '/first', 'nav_hide' => 1],
                        ['identifier' => 'second', 'title' => 'Second'],
                        [
                            'identifier' => 'third',
                            'title' => 'Third',
                            'children' => [
                                ['identifier' => 'third-sub', 'title' => 'Third sub'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, int> The written uids, keyed by seed identifier.
     */
    private function seed(array $definition, int $backendUserUid = 1, int $rootPageId = 0): array
    {
        return $this->subject()->seed(
            (new SeedDefinitionParser())->parse($definition),
            $this->setUpBackendUser($backendUserUid),
            $rootPageId,
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
     * A page declaring nothing but its identifier and its title is seeded
     * complete: on the page tree root, visible, in the default language and as
     * a standard page.
     *
     * `CreateSiteConfiguration` still runs after every new page and still reads
     * `l10n_parent`, `pid` and `doktype` out of the assembled field array - the
     * `l10n_parent` read unguarded on TYPO3 v13.4, `doktype` unguarded on both
     * versions. `$dataHandler->isImporting` is the **last** condition of that
     * hook's early return, so suppression stops the site configuration being
     * written and stops nothing else: a field the factory does not write still
     * raises a warning there, and the suite still turns that into a failure.
     *
     * What suppression does take away is the positive evidence this test used
     * to carry. Until #10 it asserted the written `autogenerated-*` directory,
     * which proved the hook was registered *and* walked. That assertion is now
     * the opposite one - no automatic site configuration - and the reads are
     * covered by the absence of a warning alone.
     *
     * `hidden` is the one of the four written fields this test is sensitive to
     * on its own: dropped from the factory, a seeded page comes out `hidden=1`
     * and this goes red. The other three are pinned by the unit test, because
     * the TCA of 13.4 and 14.3 supplies the same values today - which is the
     * dependency writing them removes.
     */
    #[Test]
    public function aPageDeclaringNothingButATitleIsSeededComplete(): void
    {
        $uids = $this->seed([
            'identifier' => 'minimal',
            'title' => 'A minimal page',
            'pages' => [
                ['identifier' => 'home', 'title' => 'Home'],
            ],
        ]);

        $rows = $this->queryTable('pages', ['uid', 'pid', 'title', 'doktype', 'l10n_parent', 'sys_language_uid', 'hidden'], 'uid');

        $this->assertCount(1, $rows);
        $this->assertSame($uids['home'], (int)$rows[0]['uid']);
        $this->assertSame('Home', $rows[0]['title']);
        $this->assertSame(0, (int)$rows[0]['pid']);
        // The three fields the hook reads, written although the definition
        // declares none of them.
        $this->assertSame(1, (int)$rows[0]['doktype']);
        $this->assertSame(0, (int)$rows[0]['l10n_parent']);
        $this->assertSame(0, (int)$rows[0]['sys_language_uid']);
        // And seeded visible, which DataHandler would not do by itself: without
        // the default a page created through it comes out hidden.
        $this->assertSame(0, (int)$rows[0]['hidden']);

        // And the site configuration TYPO3 writes by itself for a new root page
        // was not written, because the seeder sets DataHandler::$isImporting.
        $this->assertSame(
            [],
            glob(Environment::getConfigPath() . '/sites/autogenerated-*') ?: [],
            'The automatic site configuration was written although seeding suppresses it.',
        );
    }

    /**
     * A suggested uid is honoured even when it is nowhere near the uid the
     * record would have got anyway.
     *
     * A definition declaring 1, 2, 3 in declaration order cannot prove this:
     * that is exactly what DataHandler assigns when it ignores the suggestion
     * entirely, so such a test passes just as well when the mechanism never
     * worked. 4711 cannot be reached by counting.
     */
    #[Test]
    public function aSuggestedUidIsHonouredRatherThanTheNextFreeOne(): void
    {
        $uids = $this->seed([
            'identifier' => 'suggested',
            'title' => 'A suggested uid',
            'pages' => [
                ['identifier' => 'home', 'uid' => 4711, 'title' => 'Home'],
            ],
        ]);

        $this->assertSame(4711, $uids['home']);
        $this->assertSame(
            [4711],
            array_map('intval', array_column($this->queryTable('pages', ['uid'], 'uid'), 'uid')),
        );
    }

    /**
     * Nesting in the definition becomes a `pid` in the database, at every
     * depth.
     */
    #[Test]
    public function nestingBecomesThePidAtEveryDepth(): void
    {
        $uids = $this->seed(self::tree());

        $parents = array_column($this->queryTable('pages', ['uid', 'pid'], 'uid'), 'pid', 'uid');

        $this->assertSame(0, (int)$parents[$uids['home']]);
        $this->assertSame($uids['home'], (int)$parents[$uids['first']]);
        $this->assertSame($uids['home'], (int)$parents[$uids['second']]);
        $this->assertSame($uids['home'], (int)$parents[$uids['third']]);
        // The second level below its own parent, which is what proves the
        // nesting is recursive rather than one level of special case.
        $this->assertSame($uids['third'], (int)$parents[$uids['third-sub']]);
    }

    /**
     * Siblings come out in the order they are declared.
     *
     * A new record is placed at the *top* of its parent, so without the
     * negative "insert after" pid the tree would come back reversed. `sorting`
     * is asserted rather than only the order of the result set, because it is
     * the column the backend and the frontend sort by and the one the chaining
     * actually produces.
     */
    #[Test]
    public function siblingsKeepTheirDeclarationOrder(): void
    {
        $uids = $this->seed(self::tree());

        $sorting = array_column(
            array_values(array_filter(
                $this->queryTable('pages', ['uid', 'pid', 'sorting'], 'sorting'),
                static fn(array $row): bool => (int)$row['pid'] === $uids['home'],
            )),
            'sorting',
            'uid',
        );

        $this->assertSame(
            [$uids['first'], $uids['second'], $uids['third']],
            array_map('intval', array_keys($sorting)),
        );
        $this->assertGreaterThan((int)$sorting[$uids['first']], (int)$sorting[$uids['second']]);
        $this->assertGreaterThan((int)$sorting[$uids['second']], (int)$sorting[$uids['third']]);
    }

    /**
     * Three tables on one page do not disturb each other's order.
     *
     * A negative pid names a record of the *same* table, so the predecessor has
     * to be tracked per table. Chained across tables, the first content element
     * would be placed after the page it belongs to and the first category after
     * a content element - which is not a sorting mistake but a `pid` pointing
     * at the wrong record entirely.
     */
    #[Test]
    public function recordsOfSeveralTablesOnOnePageKeepTheirOwnOrder(): void
    {
        $uids = $this->seed(self::tree());

        $content = $this->queryTable('tt_content', ['uid', 'pid', 'header', 'sorting'], 'sorting');
        $categories = $this->queryTable('sys_category', ['uid', 'pid', 'title', 'sorting'], 'sorting');

        $this->assertSame(['Welcome', 'Text'], array_column($content, 'header'));
        $this->assertSame(['First category', 'Second category'], array_column($categories, 'title'));

        // Every one of them sits on the page that declares it, and the first of
        // each table addresses that page rather than the record before it.
        foreach ([...$content, ...$categories] as $row) {
            $this->assertSame($uids['home'], (int)$row['pid']);
        }
        $this->assertGreaterThan((int)$content[0]['sorting'], (int)$content[1]['sorting']);
        $this->assertGreaterThan((int)$categories[0]['sorting'], (int)$categories[1]['sorting']);
    }

    /**
     * `nav_hide`, `bodytext` and `CType` are ordinary columns, and the seeder
     * has no code for any of them: every key that is not structure is copied
     * into the data map untouched.
     *
     * That is a design decision made of the absence of a branch - a seeder
     * special-casing a field it does not have to will special-case the next one
     * too - and this is what keeps it true.
     */
    #[Test]
    public function fieldsTheSeederKnowsNothingAboutAreWrittenAsDeclared(): void
    {
        $uids = $this->seed(self::tree());

        $pages = array_column($this->queryTable('pages', ['uid', 'nav_hide', 'is_siteroot'], 'uid'), null, 'uid');
        $content = array_column($this->queryTable('tt_content', ['uid', 'CType', 'bodytext'], 'sorting'), null, 'uid');

        $this->assertSame(1, (int)$pages[$uids['first']]['nav_hide']);
        $this->assertSame(0, (int)$pages[$uids['second']]['nav_hide']);
        $this->assertSame(1, (int)$pages[$uids['home']]['is_siteroot']);
        $this->assertSame('header', $content[$uids['home-header']]['CType']);
        $this->assertSame('<p>Body</p>', $content[$uids['home-text']]['bodytext']);
    }

    /**
     * The slug of a page that declares none is generated, and one that declares
     * a slug keeps it.
     *
     * `slug` has an evaluation rather than a default, so a seeder writing rows
     * itself would leave the column empty and the page unreachable. This is the
     * cheapest proof that the write went through DataHandler at all.
     */
    #[Test]
    public function dataHandlerGeneratesTheSlugRatherThanTheSeed(): void
    {
        $uids = $this->seed(self::tree());

        $slugs = array_column($this->queryTable('pages', ['uid', 'slug'], 'uid'), 'slug', 'uid');

        $this->assertSame('/first', $slugs[$uids['first']]);
        $this->assertSame('/second', $slugs[$uids['second']]);
        $this->assertSame('/third/third-sub', $slugs[$uids['third-sub']]);
    }

    /**
     * DataHandler honours a suggested uid only for an admin and ignores it
     * silently otherwise, so the seeder refuses rather than writing a seed with
     * uids other than the ones it declares - which every site configuration
     * pointing at a root page would then be wrong about.
     */
    #[Test]
    public function seedingRefusesWithoutAnAdminBackendUser(): void
    {
        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787075001);

        $this->seed(self::tree(), 2);
    }

    #[Test]
    public function aDefinitionWithoutRecordsIsRefused(): void
    {
        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787075002);

        $this->seed([
            'identifier' => 'empty',
            'title' => 'Nothing to write',
        ]);
    }

    /**
     * An entry in the DataHandler error log becomes an exception rather than a
     * silently incomplete write.
     *
     * DataHandler reports a refused record by logging it and carrying on, so a
     * run that wrote nothing looks exactly like one that wrote everything. The
     * record used here is a `tt_content` on the page tree root: `tt_content`
     * declares no `rootLevel`, so DataHandler refuses it there on both core
     * versions and logs why. The definition is built directly rather than
     * parsed, because the format has no way to express a content element
     * outside a page - which is the point.
     */
    #[Test]
    public function anErrorInTheDataHandlerLogBecomesAnException(): void
    {
        $definition = new SeedDefinition(
            identifier: 'refused',
            title: 'A refused record',
            records: [new SeedRecord('tt_content', 'orphan', ['header' => 'Orphan'])],
        );

        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787075003);

        $this->subject()->seed($definition, $this->setUpBackendUser(1));
    }

    /**
     * The refusal happens before anything is written, so a non-admin cannot
     * leave half a page tree behind.
     */
    #[Test]
    public function aRefusedSeedWritesNothing(): void
    {
        try {
            $this->subject()->seed(
                (new SeedDefinitionParser())->parse(self::tree()),
                $this->setUpBackendUser(2),
            );
            $this->fail('Seeding with a non-admin backend user was not refused.');
        } catch (SeedingFailedException) {
            // Expected, and what happens next is the subject.
        }

        $this->assertSame([], $this->queryTable('pages', ['uid'], 'uid'));
        $this->assertSame([], $this->queryTable('tt_content', ['uid'], 'uid'));
    }
}
