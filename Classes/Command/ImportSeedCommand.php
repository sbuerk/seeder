<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Command;

use SBUERK\DataFactory\Seeding\DataHandling\OccupiedUid;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\DataFactory\Seeding\DataHandling\SiteConfigurationSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\SiteConfigurationSeedResult;
use SBUERK\DataFactory\Seeding\DataHandling\UidCollisionDetector;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Definition\SeedSiteConfiguration;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedDefinitionNotFoundException;
use SBUERK\DataFactory\Seeding\Exception\SeedingException;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use SBUERK\DataFactory\Seeding\Exception\SeedSetNotFoundException;
use SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use SBUERK\DataFactory\Seeding\SeedSet;
use SBUERK\DataFactory\Seeding\SeedSetRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Imports one seed set into this installation.
 *
 * The command is registered through the `#[AsCommand]` attribute, which TYPO3
 * turns into the `console.command` tag while compiling the container - so
 * neither `Configuration/Commands.php` nor a service definition is needed. It
 * is also where the whole seeding pipeline is first injected, which is what
 * proves its wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2 and this branch
 * supports PHP 8.1 for TYPO3 v12, so immutability is declared per property
 * throughout - and here it could not be declared on the class in any case,
 * because it extends the Symfony `Command`, which is not readonly.
 *
 * ## The order of the run, and why it is that order
 *
 * 1. The **set is resolved** - asked for when no identifier was given and there
 *    is someone to ask, and answered with the near misses when the identifier
 *    names nothing.
 * 2. The **descriptor is parsed and the scenario composed**, before anything is
 *    written. Composing it is what validates the parts of a set that only the
 *    factory can see - an entity item without a `self`, an id declared twice,
 *    a site naming a root page the scenario does not write - so a set that
 *    cannot be written fails before the first row of it exists rather than
 *    halfway through.
 * 3. The **uids are checked** against the installation, per table, and the
 *    refusal names the records in the way. `--force` does not skip the check,
 *    it decides what happens to its result: the suggestions of every colliding
 *    table are given up so the INSERT cannot fail on the primary key.
 * 4. **Nothing is written on a dry run.** The run stops here and reports what
 *    the first three steps found.
 * 5. **The backend user is authenticated**, and refused unless it is an admin.
 * 6. **Files, records, file references and site configurations** are written,
 *    in that order, because each needs the uids of the one before it.
 *
 * ## Exit codes
 *
 * A caller scripting this command has to be able to tell "that set does not
 * exist" from "that set would overwrite something", and `1` for everything
 * cannot. The codes are part of the interface and are documented in the help
 * output as well, see {@see self::configure()}.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsCommand(
    name: 'data-factory:import',
    description: 'Imports a seed set into this installation.',
)]
final class ImportSeedCommand extends Command
{
    /**
     * No identifier was given and there is no terminal to ask on, or an option
     * was given a value that cannot be used. Symfony's own `Command::INVALID`,
     * because that is exactly what it means.
     */
    public const EXIT_INVALID_INPUT = Command::INVALID;

    /**
     * No active extension provides a set of that identifier - or none at all.
     */
    public const EXIT_UNKNOWN_SET = 3;

    /**
     * The set cannot be resolved although something answers to the identifier:
     * more than one extension provides it, or a `config.yml` somewhere in the
     * installation cannot be read and discovery therefore has no complete
     * answer to give.
     */
    public const EXIT_UNRESOLVABLE_SET = 4;

    /**
     * The set was found and is not a valid seed definition.
     */
    public const EXIT_INVALID_DEFINITION = 5;

    /**
     * The set suggests uids this installation already uses. The only failure
     * `--force` overrides.
     */
    public const EXIT_UID_COLLISION = 6;

    /**
     * There is no admin backend user to write as.
     */
    public const EXIT_NO_ADMIN_USER = 7;

    /**
     * The writing itself failed. Nothing that gets this far is the caller's
     * fault, which is why it is one code and not five.
     */
    public const EXIT_SEEDING_FAILED = 8;

    public function __construct(
        private readonly SeedSetRepository $seedSetRepository,
        private readonly SeedDefinitionParser $seedDefinitionParser,
        private readonly ScenarioComposer $scenarioComposer,
        private readonly UidCollisionDetector $uidCollisionDetector,
        private readonly ScenarioSeeder $scenarioSeeder,
        private readonly SiteConfigurationSeeder $siteConfigurationSeeder,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'identifier',
            InputArgument::OPTIONAL,
            'The seed set to import. Asked for on a terminal when it is omitted.',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Parse, validate and check the set, report what an import would do, and write nothing.',
        );
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Import although the set suggests uids this installation already uses.',
        );
        $this->addOption(
            'root-page',
            null,
            InputOption::VALUE_REQUIRED,
            'The page the set is written below. "0" is the page tree root.',
            '0',
        );
        $this->addOption(
            'base',
            null,
            InputOption::VALUE_REQUIRED,
            'Overrides the "base" of every site configuration the set writes.',
        );
        $this->addOption(
            'no-site-config',
            null,
            InputOption::VALUE_NONE,
            'Skip the site configurations the set declares.',
        );

        $this->setHelp(
            'Imports the pages, records, files and site configurations of one seed set into this'
            . ' installation, through DataHandler and the file storage API - so slugs, sorting,'
            . ' relations, the reference index and the file index are what they would be after an'
            . ' editor had entered the same content.' . PHP_EOL . PHP_EOL
            . 'Without an identifier the command asks which set to import; without a terminal to ask'
            . ' on it lists the available sets and exits non-zero. "data-factory:list" shows the same list.'
            . PHP_EOL . PHP_EOL
            . 'A seed set suggests the uid of every record it writes - the "id" its scenario declares,'
            . ' or one handed out from 10000 upwards - which is what makes a seeded page tree'
            . ' reproducible. The import therefore refuses to run when one of those uids is already'
            . ' used in its table, naming the records in the way, including deleted ones, which occupy'
            . ' their uid as much as any other row. "--force" imports anyway: every record of a table'
            . ' something collides in is then written with a free uid instead of the declared one, and'
            . ' nothing that is in the way is deleted or overwritten. A set declaring site'
            . ' configurations cannot be forced past a collision in "pages", because every site names'
            . ' its root page by that uid.'
            . PHP_EOL . PHP_EOL
            . 'The automatic "autogenerated-<uid>" site configuration TYPO3 writes for a new site root'
            . ' is suppressed for the whole run, whether the set declares site configurations or not.'
            . ' A seeded site root that ends up covered by none is reported - and "--no-site-config"'
            . ' makes that report the point rather than a footnote.' . PHP_EOL . PHP_EOL
            . 'Exit codes:' . PHP_EOL
            . '  0  the set was imported, or the dry run found nothing to complain about' . PHP_EOL
            . sprintf(
                '  %d  no identifier and no terminal to ask on, or an unusable option value%s',
                self::EXIT_INVALID_INPUT,
                PHP_EOL,
            )
            . sprintf('  %d  no active extension provides a set of that identifier%s', self::EXIT_UNKNOWN_SET, PHP_EOL)
            . sprintf(
                '  %d  the set cannot be told apart: the identifier is provided more than once, or a'
                . ' "config.yml" in this installation cannot be read%s',
                self::EXIT_UNRESOLVABLE_SET,
                PHP_EOL,
            )
            . sprintf('  %d  the set is not a valid seed definition%s', self::EXIT_INVALID_DEFINITION, PHP_EOL)
            . sprintf(
                '  %d  the set suggests uids this installation already uses ("--force" overrides)%s',
                self::EXIT_UID_COLLISION,
                PHP_EOL,
            )
            . sprintf(
                '  %d  there is no admin backend user to write as%s',
                self::EXIT_NO_ADMIN_USER,
                PHP_EOL,
            )
            . sprintf('  %d  writing the set failed', self::EXIT_SEEDING_FAILED),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run') === true;
        $force = $input->getOption('force') === true;
        $writeSiteConfigurations = $input->getOption('no-site-config') !== true;
        $base = $input->getOption('base');
        $base = is_string($base) && $base !== '' ? $base : null;

        $rootPageId = $this->resolveRootPageId($io, $input);
        if ($rootPageId === null) {
            return self::EXIT_INVALID_INPUT;
        }

        try {
            $seedSets = $this->seedSetRepository->findAll();
        } catch (SeedingException $exception) {
            // Discovery reads every set of the installation, so a single
            // unreadable "config.yml" stops it - including one belonging to a
            // set nobody asked for. It is reported rather than skipped: a
            // discovery that silently leaves sets out cannot say that an
            // identifier does not exist.
            $io->error($exception->getMessage());
            return self::EXIT_UNRESOLVABLE_SET;
        }

        if ($seedSets === []) {
            $io->error('No active extension provides a seed set, so there is nothing to import.');
            return self::EXIT_UNKNOWN_SET;
        }

        $identifier = $input->getArgument('identifier');
        if (!is_string($identifier) || $identifier === '') {
            $identifier = $this->askForSeedSet($io, $input, $seedSets);
            if ($identifier === null) {
                return self::EXIT_INVALID_INPUT;
            }
        }

        try {
            $seedSet = $this->seedSetRepository->findByIdentifier($identifier);
        } catch (SeedSetNotFoundException $exception) {
            $io->error($exception->getMessage());
            $this->reportNearMisses($io, $identifier, $seedSets);
            return self::EXIT_UNKNOWN_SET;
        } catch (SeedingException $exception) {
            $io->error($exception->getMessage());
            return self::EXIT_UNRESOLVABLE_SET;
        }

        try {
            $definition = $this->seedDefinitionParser->parseFile($seedSet->configFile);
        } catch (SeedDefinitionNotFoundException|InvalidSeedDefinitionException $exception) {
            $io->error($exception->getMessage());
            $hint = $this->unreadablePathHint($seedSet);
            if ($hint !== null) {
                $io->writeln($hint);
            }
            return self::EXIT_INVALID_DEFINITION;
        }

        try {
            // Composed here and not only inside the seeding, so that a scenario
            // the factory refuses is refused before the first file has been
            // copied - and so that a dry run does the same work a real run
            // does, minus the writing. The factory validates while it builds:
            // a missing "self", an id declared twice, an unknown structure.
            $factory = $this->scenarioComposer->compose($definition, $rootPageId);
        } catch (SeedDefinitionNotFoundException|InvalidSeedDefinitionException $exception) {
            $io->error($exception->getMessage());
            return self::EXIT_INVALID_DEFINITION;
        } catch (\LogicException $exception) {
            // What the scenario factory raises for a scenario it cannot build:
            // an entity item without "self", an id assigned twice, a version
            // variant on something that has none. It carries the upstream
            // exception codes, which is why it is not wrapped.
            $io->error(sprintf(
                'The scenario of the seed set "%s" cannot be built: %s',
                $seedSet->identifier,
                $exception->getMessage(),
            ));
            return self::EXIT_INVALID_DEFINITION;
        }

        if ($factory->getDataMapPerWorkspace() === []) {
            $io->error(sprintf('The seed set "%s" contains no records.', $seedSet->identifier));
            return self::EXIT_INVALID_DEFINITION;
        }

        $undeclaredRootPage = $this->undeclaredSiteRootPage($definition, $factory);
        if ($undeclaredRootPage !== null) {
            $io->error($undeclaredRootPage);
            return self::EXIT_INVALID_DEFINITION;
        }

        $undeclaredReference = $this->undeclaredReferenceRecord($definition, $factory);
        if ($undeclaredReference !== null) {
            $io->error($undeclaredReference);
            return self::EXIT_INVALID_DEFINITION;
        }

        $this->describeSeedSet($io, $seedSet, $dryRun);

        // Checked whether or not "--force" was given: forcing does not skip the
        // check, it decides what happens to its result. A suggestion left in
        // for a uid another row holds does not write that record somewhere
        // else, it makes the INSERT fail on the primary key - so the uids that
        // collide are the ones the writing pass is told not to suggest.
        $suggestedUids = $factory->getSuggestedIds();
        $occupied = $this->uidCollisionDetector->detect($suggestedUids);
        $withoutSuggestedUids = [];
        if ($occupied !== []) {
            if (!$force) {
                $this->reportCollisions($io, $seedSet, $occupied);
                return self::EXIT_UID_COLLISION;
            }
            $withoutSuggestedUids = $this->withoutSuggestionsOfCollidingTables($suggestedUids, $occupied);
            if ($writeSiteConfigurations && $definition->sites !== [] && $this->givesUpPageUids($withoutSuggestedUids)) {
                // Every site names its root page by the uid the scenario
                // declares. Giving that uid up means the page is written under
                // whatever the database assigns, and the site would point at a
                // page of somebody else - or at nothing.
                $io->error(sprintf(
                    'The seed set "%s" declares site configurations and suggests page uids this installation'
                    . ' already uses. "--force" gives up the suggested uids of the colliding table, so the root'
                    . ' page of every site would be written under a different uid than the site names. Free the'
                    . ' uids, or import the set with "--no-site-config".',
                    $seedSet->identifier,
                ));
                return self::EXIT_UID_COLLISION;
            }
            $this->reportForcedCollisions($io, $occupied, $withoutSuggestedUids);
        }

        if ($dryRun) {
            $this->reportPlan($io, $definition, $factory, $rootPageId, $writeSiteConfigurations, $output->isVerbose());
            return self::SUCCESS;
        }

        $backendUser = $this->resolveBackendUser($io);
        if ($backendUser === null) {
            return self::EXIT_NO_ADMIN_USER;
        }

        try {
            $seedResult = $this->scenarioSeeder->seed(
                $definition,
                $factory,
                $backendUser,
                $withoutSuggestedUids,
            );
            $siteResult = $this->siteConfigurationSeeder->seed(
                $definition,
                $seedResult,
                $base,
                $writeSiteConfigurations,
            );
        } catch (InvalidSeedDefinitionException $exception) {
            $io->error($exception->getMessage());
            return self::EXIT_INVALID_DEFINITION;
        } catch (SeedingFailedException $exception) {
            $io->error($exception->getMessage());
            return self::EXIT_SEEDING_FAILED;
        }

        $this->reportResult($io, $definition, $seedResult, $siteResult, $rootPageId, $output->isVerbose());

        return self::SUCCESS;
    }

    /**
     * The message for the first site whose `rootPage` no entity of the `pages`
     * table declares, or null when every site names a page the set writes.
     *
     * Checked before anything is written, because the alternative is a run that
     * seeds the whole tree and then refuses to write the site it was imported
     * for.
     */
    private function undeclaredSiteRootPage(SeedDefinition $definition, DataHandlerFactory $factory): ?string
    {
        $suggestedUids = $factory->getSuggestedIds();
        foreach ($definition->sites as $site) {
            if (isset($suggestedUids['pages:' . $site->rootPage])) {
                continue;
            }

            return sprintf(
                'The site "%s" of the seed set "%s" declares the root page %d, which no entity of the "pages"'
                . ' table of its scenario declares as its "id".',
                $site->identifier,
                $definition->identifier,
                $site->rootPage,
            );
        }

        return null;
    }

    /**
     * The message for the first file reference whose record no entity of the
     * scenario declares, or null when every one of them names a record the set
     * writes.
     *
     * Checked here for the same reason the site root is: the references are
     * written in the last pass of a run, so a mistyped uid would surface after
     * the page tree, the content and the files are already in the database.
     */
    private function undeclaredReferenceRecord(SeedDefinition $definition, DataHandlerFactory $factory): ?string
    {
        $suggestedUids = $factory->getSuggestedIds();
        foreach ($definition->references as $reference) {
            if (isset($suggestedUids[$reference->table . ':' . $reference->uid])) {
                continue;
            }

            return sprintf(
                'The seed set "%s" declares a file reference to "%s" on the record %s:%d, which no entity of its'
                . ' scenario declares as its "id".',
                $definition->identifier,
                $reference->file,
                $reference->table,
                $reference->uid,
            );
        }

        return null;
    }

    /**
     * @param array<string, true> $withoutSuggestedUids
     */
    private function givesUpPageUids(array $withoutSuggestedUids): bool
    {
        foreach (array_keys($withoutSuggestedUids) as $suggestion) {
            if (str_starts_with($suggestion, 'pages:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `--root-page`, or `null` when it is not a page this set can be written
     * below.
     *
     * A page that does not exist is refused rather than passed on: DataHandler
     * writes a record below a non-existing page without complaining, and the
     * result is a page tree that is in the database and in no tree.
     */
    private function resolveRootPageId(SymfonyStyle $io, InputInterface $input): ?int
    {
        $rootPage = $input->getOption('root-page');
        if (!is_string($rootPage) && !is_int($rootPage)) {
            $rootPage = '0';
        }
        $rootPage = (string)$rootPage;
        if (preg_match('/^\d+$/', $rootPage) !== 1) {
            $io->error(sprintf(
                'The value "%s" of "--root-page" is not a page uid. Pass the uid of the page the set is'
                . ' written below, or "0" for the page tree root.',
                $rootPage,
            ));
            return null;
        }

        $rootPageId = (int)$rootPage;
        if ($rootPageId === 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // No restrictions: a hidden page is a perfectly good place to seed
        // below, and a deleted one is not - but it exists, and the message for
        // it is the one about a page that is not there.
        $queryBuilder->getRestrictions()->removeAll();
        $exists = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($rootPageId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        if ((int)$exists === 0) {
            $io->error(sprintf('The page %d of "--root-page" does not exist.', $rootPageId));
            return null;
        }

        return $rootPageId;
    }

    /**
     * Asks which set to import, or lists them and gives up.
     *
     * No identifier is not an error: on a terminal the answer is a question,
     * and the list of sets is right there in it. Without one - a deployment
     * script, a pipeline, a hook - there is nobody to ask, so the list is
     * printed and the caller is told what to pass. Guessing a set to import
     * would be the one thing this command must never do.
     *
     * @param list<SeedSet> $seedSets
     */
    private function askForSeedSet(SymfonyStyle $io, InputInterface $input, array $seedSets): ?string
    {
        $choices = [];
        foreach ($seedSets as $seedSet) {
            $choices[$seedSet->identifier] = sprintf('%s (%s)', $seedSet->title, $seedSet->extensionKey);
        }

        if (!$input->isInteractive()) {
            $io->error('No seed set was named, and there is no terminal to ask on.');
            $io->writeln('The seed sets of this installation are:');
            $io->listing(array_map(
                static fn(string $identifier, string $description): string => sprintf('%s - %s', $identifier, $description),
                array_keys($choices),
                array_values($choices),
            ));
            $io->writeln('Pass one of them: <info>data-factory:import <identifier></info>');
            return null;
        }

        $answer = $io->choice('Which seed set do you want to import?', $choices);
        if (!is_string($answer)) {
            return null;
        }
        // The answer is the key of the choice - except for a set of identifiers
        // that are all numeric, which PHP turns into integer array keys and
        // Symfony then answers with the label instead. Mapping back covers that
        // without making the normal case special.
        if (!isset($choices[$answer])) {
            $identifier = array_search($answer, $choices, true);
            $answer = is_string($identifier) ? $identifier : $answer;
        }

        return $answer;
    }

    /**
     * Names the identifiers that look like the one that was asked for.
     *
     * A typo is the overwhelmingly common reason for an identifier not to
     * resolve, and the set that was meant is usually one edit away. Reporting
     * the near misses is what turns a refusal into an answer.
     *
     * @param list<SeedSet> $seedSets
     */
    private function reportNearMisses(SymfonyStyle $io, string $identifier, array $seedSets): void
    {
        $needle = strtolower($identifier);
        /** @var array<string, int> $candidates */
        $candidates = [];
        foreach ($seedSets as $seedSet) {
            $candidate = strtolower($seedSet->identifier);
            $distance = levenshtein($needle, $candidate);
            // Two edits, and a third for every three characters beyond six -
            // so "demo" is not "hero" and "demo-content" still matches
            // "demo-contents". A substring hit counts whatever its distance,
            // which is what finds the half remembered identifier.
            $threshold = max(2, (int)floor(strlen($needle) / 3));
            if ($distance <= $threshold || str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                $candidates[$seedSet->identifier] = $distance;
            }
        }

        if ($candidates === []) {
            $io->writeln('Run <info>data-factory:list</info> to see the seed sets this installation provides.');
            return;
        }

        asort($candidates, SORT_NUMERIC);
        $io->writeln(count($candidates) === 1 ? 'Did you mean:' : 'Did you mean one of these:');
        $io->listing(array_map('strval', array_keys($candidates)));
    }

    /**
     * Explains a set whose file exists and could still not be read.
     *
     * `YamlFileLoader` resolves through `GeneralUtility::getFileAbsFileName()`,
     * which answers an absolute path outside `Environment::getProjectPath()`
     * and `Environment::getPublicPath()` with an empty string - so a set that
     * is on disk and readable produces a "does not exist" that is true only
     * from TYPO3's point of view. In a Composer installation this does not
     * happen: `typo3/cms-composer-installers` pins the application directory to
     * the Composer root ("Changing app-dir is not supported any more") and
     * refuses a public directory outside it, so every package path is below the
     * project path. It happens when the project path was moved out from under
     * the packages afterwards, with `TYPO3_PATH_APP`.
     */
    private function unreadablePathHint(SeedSet $seedSet): ?string
    {
        if (!is_file($seedSet->configFile) || GeneralUtility::getFileAbsFileName($seedSet->configFile) !== '') {
            return null;
        }

        return sprintf(
            'The file exists, and TYPO3 refuses to read it: YAML is only loaded from inside the project'
            . ' path "%s" and the public path "%s", and "%s" is in neither. The extension "%s" is'
            . ' installed outside the project - check "TYPO3_PATH_APP" and the Composer vendor'
            . ' directory of this installation.',
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            $seedSet->configFile,
            $seedSet->extensionKey,
        );
    }

    private function describeSeedSet(SymfonyStyle $io, SeedSet $seedSet, bool $dryRun): void
    {
        $io->writeln(sprintf(
            'Seed set <info>%s</info> - %s (%s)',
            $seedSet->identifier,
            $seedSet->title,
            $seedSet->extensionKey,
        ));
        if ($dryRun) {
            $io->writeln('<comment>Dry run: nothing is written.</comment>');
        }
        $io->writeln('');
    }

    /**
     * @param list<OccupiedUid> $occupied
     */
    private function reportCollisions(SymfonyStyle $io, SeedSet $seedSet, array $occupied): void
    {
        $io->error(sprintf(
            'The seed set "%s" suggests %d uid%s this installation already uses.',
            $seedSet->identifier,
            count($occupied),
            count($occupied) === 1 ? '' : 's',
        ));
        $io->table(
            ['Table', 'Uid', 'Occupied by'],
            array_map(
                static fn(OccupiedUid $record): array => [
                    $record->table,
                    (string)$record->uid,
                    $record->title,
                ],
                $occupied,
            ),
        );
        $io->writeln(
            'Nothing was written. Import into an installation that has those uids free, or pass'
            . ' <info>--force</info> - every record of a table something collides in is then written'
            . ' with a free uid, and a table nothing collides in keeps its declared uids.',
        );
        $io->writeln('A deleted record occupies its uid as much as any other one.');
    }

    /**
     * The suggestions `--force` gives up, which is every uid of a table one of
     * them collides in.
     *
     * Dropping only the colliding suggestion and keeping the others of the same
     * table looks more careful and is not: the record that lost its suggestion
     * is written with the next free uid, and the next free uid may be one the
     * set suggests for a later record of that table - which does not write that
     * record somewhere else, it fails its `INSERT` on the primary key. Giving
     * up a whole table cannot run into that, because a table nothing forces a
     * uid in is written by the auto increment alone. A table that does not
     * collide keeps every uid the set declares.
     *
     * @param array<string, true> $suggestedUids
     * @param list<OccupiedUid> $occupied
     * @return array<string, true>
     */
    private function withoutSuggestionsOfCollidingTables(array $suggestedUids, array $occupied): array
    {
        $collidingTables = [];
        foreach ($occupied as $record) {
            $collidingTables[$record->table] = true;
        }

        $without = [];
        foreach (array_keys($suggestedUids) as $suggestion) {
            $table = explode(':', $suggestion, 2)[0];
            if (isset($collidingTables[$table])) {
                $without[$suggestion] = true;
            }
        }

        return $without;
    }

    /**
     * What `--force` changes, said before it is done.
     *
     * The point of a suggested uid is that a seeded page tree is the same tree
     * in every installation - a site configuration, a TypoScript condition or a
     * test referring to page 11 by its number. Forcing gives that up, and for
     * more records than the ones in the way, so it is worth naming both the
     * collisions and the tables that lose their uids.
     *
     * @param list<OccupiedUid> $occupied
     * @param array<string, true> $withoutSuggestedUids
     */
    private function reportForcedCollisions(SymfonyStyle $io, array $occupied, array $withoutSuggestedUids): void
    {
        $tables = [];
        foreach (array_keys($withoutSuggestedUids) as $suggestion) {
            $tables[explode(':', $suggestion, 2)[0]] = true;
        }
        ksort($tables, SORT_STRING);

        $io->warning(sprintf(
            '%d uid%s of this set %s already used, and "--force" was given: every record of %s is'
            . ' written with a free uid instead of the one the set declares. Nothing that is in the way'
            . ' is deleted or changed.',
            count($occupied),
            count($occupied) === 1 ? '' : 's',
            count($occupied) === 1 ? 'is' : 'are',
            count($tables) === 1
                ? sprintf('the table "%s"', implode('', array_keys($tables)))
                : sprintf('the tables %s', implode(', ', array_map(
                    static fn(string $table): string => sprintf('"%s"', $table),
                    array_keys($tables),
                ))),
        ));
        $io->listing(array_map(
            static fn(OccupiedUid $record): string => $record->title === ''
                ? sprintf('%s:%d is occupied', $record->table, $record->uid)
                : sprintf('%s:%d is occupied by "%s"', $record->table, $record->uid, $record->title),
            $occupied,
        ));
    }

    /**
     * What a real run would do, reported from what the dry run built.
     */
    private function reportPlan(
        SymfonyStyle $io,
        SeedDefinition $definition,
        DataHandlerFactory $factory,
        int $rootPageId,
        bool $writeSiteConfigurations,
        bool $verbose,
    ): void {
        $this->reportRecordCounts($io, $this->countRecords($factory), $rootPageId, true);

        $io->writeln(sprintf('Files to index: %d', count($definition->files)));
        $io->writeln(sprintf('File references to attach: %d', count($definition->references)));
        $io->writeln(sprintf(
            'Site configurations to write: %s',
            $writeSiteConfigurations
                ? ($definition->sites === [] ? '0' : implode(', ', array_map(
                    static fn(SeedSiteConfiguration $site): string => $site->identifier,
                    $definition->sites,
                )))
                : '0 (--no-site-config)',
        ));

        if ($verbose) {
            $this->reportDeclaredUids($io, $factory);
        }

        $io->success(sprintf(
            'Dry run of "%s" finished. Nothing was written - no file, no record and no site'
            . ' configuration.',
            $definition->identifier,
        ));
    }

    private function reportResult(
        SymfonyStyle $io,
        SeedDefinition $definition,
        ScenarioSeedResult $seedResult,
        SiteConfigurationSeedResult $siteResult,
        int $rootPageId,
        bool $verbose,
    ): void {
        $this->reportRecordCounts($io, $seedResult->recordCounts, $rootPageId, false);

        $io->writeln(sprintf('Files indexed: %d', count($seedResult->fileUids)));
        $io->writeln(sprintf('File references attached: %d', count($seedResult->referenceUids)));
        $io->writeln(sprintf(
            'Site configurations written: %s',
            $siteResult->writtenSites === [] ? '0' : implode(', ', $siteResult->writtenSites),
        ));

        if ($verbose && $seedResult->writtenUids !== []) {
            $io->writeln('');
            $io->table(
                ['Declared', 'Uid'],
                array_map(
                    static fn(string $declared, int $uid): array => [$declared, (string)$uid],
                    array_keys($seedResult->writtenUids),
                    array_values($seedResult->writtenUids),
                ),
            );
        }

        $io->success(sprintf(
            'Imported "%s": %d record%s.',
            $definition->identifier,
            $seedResult->recordCount(),
            $seedResult->recordCount() === 1 ? '' : 's',
        ));

        if ($siteResult->uncoveredSiteRoots === []) {
            return;
        }

        $io->warning(sprintf(
            'No site configuration covers %s. Seeding suppresses the "autogenerated-<uid>" site TYPO3'
            . ' writes for a new site root, so the page tree exists and no frontend can render it.'
            . ' Declare the site in the seed set with a "sites:" entry, or write a site configuration'
            . ' for the page.',
            implode(', ', array_map(
                static fn(int $uid): string => sprintf('page %d', $uid),
                $siteResult->uncoveredSiteRoots,
            )),
        ));
    }

    /**
     * The records of the set per table, which is the number a summary is
     * actually about - "47 records" says nothing, "40 pages and 7 content
     * elements" says what was imported.
     *
     * @param array<string, int> $counts
     */
    private function reportRecordCounts(
        SymfonyStyle $io,
        array $counts,
        int $rootPageId,
        bool $dryRun,
    ): void {
        ksort($counts, SORT_STRING);

        $rows = [];
        foreach ($counts as $table => $count) {
            $rows[] = [$table, (string)$count];
        }
        $io->table(['Table', 'Records'], $rows);

        $io->writeln(sprintf(
            '%s below page %d%s.',
            $dryRun ? 'Would be written' : 'Written',
            $rootPageId,
            $rootPageId === 0 ? ' (the page tree root)' : '',
        ));
    }

    /**
     * The uids a dry run would suggest, which is the closest a run without a
     * database write can get to the table of a real one.
     *
     * Every record of a scenario has one - the factory hands out a dynamic uid
     * from 10000 upwards where the entity declares no `id` - which is why this
     * is a complete list and not the "declared or assigned by DataHandler" of a
     * format where the uid was optional.
     */
    private function reportDeclaredUids(SymfonyStyle $io, DataHandlerFactory $factory): void
    {
        $suggestedUids = array_keys($factory->getSuggestedIds());
        if ($suggestedUids === []) {
            return;
        }
        sort($suggestedUids, SORT_NATURAL);

        $rows = [];
        foreach ($suggestedUids as $suggestion) {
            [$table, $uid] = explode(':', $suggestion, 2);
            $rows[] = [$table, $uid];
        }

        $io->writeln('');
        $io->table(['Table', 'Suggested uid'], $rows);
    }

    /**
     * @return array<string, int>
     */
    private function countRecords(DataHandlerFactory $factory): array
    {
        $counts = [];
        foreach ($factory->getDataMapPerWorkspace() as $dataMap) {
            foreach ($dataMap as $tableName => $tableDataMap) {
                $counts[$tableName] = ($counts[$tableName] ?? 0) + count($tableDataMap);
            }
        }

        return $counts;
    }

    /**
     * The backend user the import is written as, or `null` when there is none
     * that may write it.
     *
     * The CLI application creates the `_cli_` backend user object without
     * logging it in, and `Bootstrap::initializeBackendAuthentication()` is what
     * authenticates it - creating the record on first use, with `admin = 1`
     * ({@see \TYPO3\CMS\Core\Authentication\CommandLineUserCreation}). An
     * already authenticated user is left alone, which is what lets a test drive
     * this command as a user of its choosing.
     *
     * Being an admin is not a formality here: DataHandler honours a suggested
     * uid only for an admin and ignores it silently otherwise
     * ({@see ScenarioSeeder::seed()}), so a non-admin run would write a page tree
     * whose uids are not the ones the set declares - and every site
     * configuration pointing at a root page would be wrong. `ScenarioSeeder`
     * refuses that with an exception; asking here is what turns the exception
     * into a sentence and an exit code of its own.
     */
    private function resolveBackendUser(SymfonyStyle $io): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            $io->error(
                'There is no backend user to import as. The seeder writes through DataHandler, which'
                . ' needs one - run this command through the TYPO3 command line application.',
            );
            return null;
        }

        if ((int)($backendUser->user['uid'] ?? 0) === 0) {
            try {
                Bootstrap::initializeBackendAuthentication();
            } catch (\RuntimeException $exception) {
                $io->error(sprintf(
                    'The "_cli_" backend user could not be authenticated: %s',
                    $exception->getMessage(),
                ));
                return null;
            }
            $backendUser = $GLOBALS['BE_USER'] ?? null;
            if (!$backendUser instanceof BackendUserAuthentication) {
                $io->error('The "_cli_" backend user could not be authenticated.');
                return null;
            }
        }

        if (!$backendUser->isAdmin()) {
            $io->error(sprintf(
                'The backend user "%s" is not an administrator. DataHandler honours a suggested uid only'
                . ' for an admin and ignores it silently otherwise, so importing as this user would'
                . ' write the set with different uids than it declares.',
                is_string($backendUser->user['username'] ?? null) ? $backendUser->user['username'] : '',
            ));
            return null;
        }

        return $backendUser;
    }
}
