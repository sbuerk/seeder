<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Command\ImportSeedCommand;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The complete seed set the integrator documentation prints, imported.
 *
 * `Documentation/Configuration/Index.rst` shows the format section by section,
 * and one section shows a whole set at once - a page tree with content, a
 * translation of both, a file, a file reference and a site configuration. That
 * example is this fixture set, and this test is what keeps the two the same
 * thing: {@see theDocumentationPrintsTheseFilesVerbatim()} compares every
 * captioned code block of that section against the file it names, and the
 * remaining tests import the set and assert what the section claims it does.
 *
 * A documented example that is never executed is a documented example that
 * rots. This one cannot: a change to either side turns a test red.
 *
 * Workspaces are the one construct of the format the example leaves out. They
 * need `EXT:workspaces` in the test instance, they have a section and a test
 * class of their own, and a set that seeds a workspace is not what a reader
 * copies to get started.
 */
final class DocumentedSeedSetTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/seeds-import',
    ];

    /**
     * The section of the integrator documentation this fixture belongs to, and
     * the directory of the set, relative to the repository root.
     */
    private const DOCUMENTATION = 'Documentation/Configuration/Index.rst';
    private const CAPTION_PREFIX = 'packages/my_extension/Configuration/DataFactory/complete/';
    private const SET = 'Tests/Functional/Fixtures/Extensions/seeds-import/Configuration/DataFactory/documented';

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/BackendUsers.csv');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);
    }

    #[Test]
    public function theDocumentationPrintsTheseFilesVerbatim(): void
    {
        $root = dirname(__DIR__, 3);
        $blocks = $this->captionedCodeBlocks($root . '/' . self::DOCUMENTATION);

        $expected = [];
        foreach (['config.yml', 'Scenario.yaml', 'Sites/main/config.yaml'] as $file) {
            $expected[self::CAPTION_PREFIX . $file]
                = rtrim((string)file_get_contents($root . '/' . self::SET . '/' . $file), "\n");
        }
        $printed = array_filter(
            $blocks,
            static fn(string $caption): bool => str_starts_with($caption, self::CAPTION_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );

        // Every file of the set is printed, every printed block is a file of
        // the set, and both sides are identical down to the indentation. The
        // caption is the key, so a block that is renamed or a file that is
        // added without being documented fails here rather than quietly
        // stopping to be checked.
        $this->assertSame($expected, $printed);
    }

    #[Test]
    public function theSetImportsWithTheUidsItDeclares(): void
    {
        $this->assertSame(Command::SUCCESS, $this->import()->getStatusCode());

        $this->assertSame([1000, 1100, 1101], array_column($this->rows('pages', 'uid'), 'uid'));
        $this->assertSame([2100, 2101], array_column($this->rows('tt_content', 'uid'), 'uid'));
    }

    #[Test]
    public function theTranslationsSitWhereTheSectionSaysTheyDo(): void
    {
        $this->import();

        // The entities declare `nodeColumnName`, so a translated page is
        // written onto the page its original sits on rather than falling out
        // of the tree - the case the "Translations" section calls the normal
        // one.
        $pages = $this->rows('pages', 'uid', 'pid', 'sys_language_uid', 'l10n_parent');
        $this->assertSame(
            [
                ['uid' => 1000, 'pid' => 0, 'sys_language_uid' => 0, 'l10n_parent' => 0],
                ['uid' => 1100, 'pid' => 1000, 'sys_language_uid' => 0, 'l10n_parent' => 0],
                ['uid' => 1101, 'pid' => 1000, 'sys_language_uid' => 1, 'l10n_parent' => 1100],
            ],
            $pages,
        );
        $this->assertSame(
            [
                ['uid' => 2100, 'pid' => 1100, 'sys_language_uid' => 0, 'l18n_parent' => 0],
                ['uid' => 2101, 'pid' => 1100, 'sys_language_uid' => 1, 'l18n_parent' => 2100],
            ],
            $this->rows('tt_content', 'uid', 'pid', 'sys_language_uid', 'l18n_parent'),
        );
    }

    #[Test]
    public function theFileIsIndexedAndAttachedToTheDeclaredRecord(): void
    {
        $this->import();

        $files = $this->rows('sys_file', 'uid', 'name');
        $this->assertCount(1, $files);
        $this->assertSame('placeholder.svg', $files[0]['name']);

        $this->assertSame(
            [[
                'uid' => 1,
                'uid_local' => $files[0]['uid'],
                'uid_foreign' => 1000,
                'tablenames' => 'pages',
                'fieldname' => 'media',
                'title' => 'The placeholder',
            ]],
            $this->rows('sys_file_reference', 'uid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'title'),
        );
    }

    #[Test]
    public function theSiteIsWrittenWithTheSeededRootPage(): void
    {
        $this->import();

        $configuration = Environment::getConfigPath() . '/sites/documented-main/config.yaml';
        $this->assertFileExists($configuration);

        $written = (string)file_get_contents($configuration);
        $this->assertStringContainsString('rootPageId: 1000', $written);
        $this->assertStringContainsString('https://documented.example.org/', $written);
    }

    private function import(): CommandTester
    {
        $this->setUpBackendUser(1);
        $commandTester = new CommandTester($this->get(ImportSeedCommand::class));
        $commandTester->execute(['identifier' => 'import-documented'], ['interactive' => false]);

        return $commandTester;
    }

    /**
     * Every `code-block` of the documentation that carries a `:caption:`,
     * keyed by that caption and dedented by the four spaces reStructuredText
     * indents a directive body with.
     *
     * @return array<string, string>
     */
    private function captionedCodeBlocks(string $file): array
    {
        $blocks = [];
        $lines = explode("\n", (string)file_get_contents($file));
        $count = count($lines);
        $index = 0;
        // Deliberately not a `for` loop: the body of a directive is consumed
        // by advancing `$index` inside, and a `for` would then skip the line
        // the body stopped at - which is the next `code-block` directive.
        while ($index < $count) {
            if (!str_starts_with(trim($lines[$index]), '..  code-block::')) {
                $index++;
                continue;
            }
            $caption = null;
            $index++;
            while ($index < $count && str_starts_with(trim($lines[$index]), ':')) {
                if (str_starts_with(trim($lines[$index]), ':caption:')) {
                    $caption = trim(substr(trim($lines[$index]), strlen(':caption:')));
                }
                $index++;
            }
            if ($caption === null) {
                continue;
            }
            $body = [];
            while ($index < $count && ($lines[$index] === '' || str_starts_with($lines[$index], '    '))) {
                $body[] = $lines[$index] === '' ? '' : substr($lines[$index], 4);
                $index++;
            }
            $blocks[$caption] = trim(implode("\n", $body), "\n");
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string ...$columns): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): array {
                foreach ($row as $column => $value) {
                    if (is_numeric($value) && !str_contains((string)$value, '.')) {
                        $row[$column] = (int)$value;
                    }
                }

                return $row;
            },
            $rows,
        );
    }
}
