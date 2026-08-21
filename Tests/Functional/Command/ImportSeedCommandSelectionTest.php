<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Command\ImportSeedCommand;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * How `data-factory:import` decides *what* to import, and how it refuses.
 *
 * These are the six reasons the command exists in this shape rather than in the
 * shape of the one it is extracted from: a missing identifier is a question and
 * not an error, an unknown one is answered with the near misses, and every
 * failure has an exit code of its own so that a script can tell "no such set"
 * from "that would overwrite something".
 */
final class ImportSeedCommandSelectionTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/seeds-import',
        'tests/file-fields',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/BackendUsers.csv');
        // The set of this fixture extension ships a file, and a functional
        // instance has no file storage until one is created: the testing
        // framework makes the "fileadmin/" folder and no "sys_file_storage"
        // record, which a real instance gets from "typo3 setup".
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);
    }

    /**
     * Without a terminal there is nobody to ask, so the sets are listed and the
     * run fails - importing a set nobody named is the one thing this command
     * must never do.
     */
    #[Test]
    public function withoutAnIdentifierAndWithoutATerminalTheSetsAreListedAndTheRunFails(): void
    {
        $this->setUpBackendUser(1);

        $commandTester = $this->execute([], false);

        $this->assertSame(ImportSeedCommand::EXIT_INVALID_INPUT, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('there is no terminal to ask on', $display);
        $this->assertStringContainsString('import-full - Everything the importer writes', $display);
        $this->assertStringContainsString('data-factory:import <identifier>', $display);
        $this->assertSame(0, $this->countPages());
    }

    #[Test]
    public function withoutAnIdentifierAndWithATerminalTheSetToImportIsAskedFor(): void
    {
        $this->setUpBackendUser(1);

        $commandTester = new CommandTester($this->get(ImportSeedCommand::class));
        $commandTester->setInputs(['import-full']);
        $commandTester->execute([], ['interactive' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('Which seed set do you want to import?', $display);
        $this->assertStringContainsString('Imported "import-full"', $display);
        $this->assertSame(2, $this->countPages());
    }

    #[Test]
    public function anUnknownIdentifierSuggestsTheNearMisses(): void
    {
        $commandTester = $this->execute(['identifier' => 'import-fill']);

        $this->assertSame(ImportSeedCommand::EXIT_UNKNOWN_SET, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('No active extension provides a seed set with the identifier', $display);
        $this->assertStringContainsString('Did you mean:', $display);
        $this->assertStringContainsString('import-full', $display);
    }

    #[Test]
    public function anIdentifierThatResemblesNothingPointsAtTheListCommand(): void
    {
        $commandTester = $this->execute(['identifier' => 'wholly-unrelated-nonsense']);

        $this->assertSame(ImportSeedCommand::EXIT_UNKNOWN_SET, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringNotContainsString('Did you mean', $display);
        $this->assertStringContainsString('data-factory:list', $display);
    }

    /**
     * Discovery reads three metadata keys with a plain YAML parse and the
     * import validates the whole set, so a set that lists fine can still be
     * unimportable - and says which key it is.
     */
    #[Test]
    public function aSetThatIsNotAValidDefinitionIsRefused(): void
    {
        $commandTester = $this->execute(['identifier' => 'import-broken']);

        $this->assertSame(ImportSeedCommand::EXIT_INVALID_DEFINITION, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('declares no "scenarios"', $display);
        $this->assertStringContainsString('It is a non-empty list of the scenario files', $display);
        $this->assertSame(0, $this->countPages());
    }

    /**
     * A site names its root page by the uid the scenario declares, and neither
     * file is wrong on its own - only the two together are. The import says so
     * before it writes anything, because the alternative is a run that seeds
     * the whole page tree and then refuses to write the site it was imported
     * for.
     */
    #[Test]
    public function aSiteOnARootPageNoScenarioEntityDeclaresIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->setUpBackendUser(1);

        $commandTester = $this->execute(['identifier' => 'import-undeclared-root']);

        $this->assertSame(ImportSeedCommand::EXIT_INVALID_DEFINITION, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString(
            'The site "import-orphan" of the seed set "import-undeclared-root" declares the root page 32',
            $display,
        );
        $this->assertStringContainsString('which no entity of the "pages" table of its scenario declares', $display);
        // Not even the page the scenario does declare.
        $this->assertSame(0, $this->countPages());
    }

    /**
     * DataHandler honours a suggested uid only for an admin and ignores it
     * silently otherwise, so a non-admin run would write the set with uids it
     * does not declare. The command says so and stops, rather than letting the
     * exception of the seeder reach the terminal as a stack trace.
     */
    #[Test]
    public function aBackendUserThatIsNoAdministratorIsRefused(): void
    {
        $this->setUpBackendUser(2);

        $commandTester = $this->execute(['identifier' => 'import-full']);

        $this->assertSame(ImportSeedCommand::EXIT_NO_ADMIN_USER, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('seeding-editor', $display);
        $this->assertStringContainsString('is not an administrator', $display);
        $this->assertSame(0, $this->countPages());
    }

    /**
     * A dry run answers what a set would do, and answers it without a backend
     * user: it writes nothing, so there is nothing to write as. That it cannot
     * therefore report a missing admin is stated in the help output.
     */
    #[Test]
    public function aDryRunNeedsNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $commandTester = $this->execute(['identifier' => 'import-full', '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame(0, $this->countPages());
    }

    #[Test]
    public function anImportWithoutABackendUserIsRefused(): void
    {
        unset($GLOBALS['BE_USER']);

        $commandTester = $this->execute(['identifier' => 'import-full']);

        $this->assertSame(ImportSeedCommand::EXIT_NO_ADMIN_USER, $commandTester->getStatusCode());
        $this->assertStringContainsString(
            'There is no backend user to import as',
            $this->normalize($commandTester->getDisplay()),
        );
    }

    #[Test]
    public function aRootPageThatDoesNotExistIsRefused(): void
    {
        $this->setUpBackendUser(1);

        $commandTester = $this->execute(['identifier' => 'import-full', '--root-page' => '4711']);

        $this->assertSame(ImportSeedCommand::EXIT_INVALID_INPUT, $commandTester->getStatusCode());
        $this->assertStringContainsString(
            'The page 4711 of "--root-page" does not exist',
            $this->normalize($commandTester->getDisplay()),
        );
        $this->assertSame(0, $this->countPages());
    }

    #[Test]
    public function aRootPageThatIsNoUidIsRefused(): void
    {
        $this->setUpBackendUser(1);

        $commandTester = $this->execute(['identifier' => 'import-full', '--root-page' => 'the-root']);

        $this->assertSame(ImportSeedCommand::EXIT_INVALID_INPUT, $commandTester->getStatusCode());
        $this->assertStringContainsString('is not a page uid', $this->normalize($commandTester->getDisplay()));
    }

    /**
     * The exit codes are part of the interface of this command, so the help
     * output documenting them is not prose that may drift: a code that is added
     * without a line here makes this fail.
     */
    #[Test]
    public function everyExitCodeIsDocumentedInTheHelpOutput(): void
    {
        $help = $this->get(ImportSeedCommand::class)->getHelp();

        $codes = [
            ImportSeedCommand::EXIT_INVALID_INPUT,
            ImportSeedCommand::EXIT_UNKNOWN_SET,
            ImportSeedCommand::EXIT_UNRESOLVABLE_SET,
            ImportSeedCommand::EXIT_INVALID_DEFINITION,
            ImportSeedCommand::EXIT_UID_COLLISION,
            ImportSeedCommand::EXIT_NO_ADMIN_USER,
            ImportSeedCommand::EXIT_SEEDING_FAILED,
        ];
        $this->assertSame($codes, array_values(array_unique($codes)), 'The exit codes are distinct.');
        $this->assertStringContainsString('Exit codes:', $help);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                sprintf('/^  %d  \S/m', $code),
                $help,
                sprintf('The exit code %d is documented in the help output.', $code),
            );
        }
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function execute(array $input, bool $interactive = false): CommandTester
    {
        $commandTester = new CommandTester($this->get(ImportSeedCommand::class));
        $commandTester->execute($input, ['interactive' => $interactive]);

        return $commandTester;
    }

    private function countPages(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')->from('pages')->executeQuery()->fetchOne();
    }

    private function normalize(string $display): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $display));
    }
}
