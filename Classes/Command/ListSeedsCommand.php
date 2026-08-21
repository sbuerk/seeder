<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Command;

use SBUERK\DataFactory\Seeding\Exception\SeedingException;
use SBUERK\DataFactory\Seeding\SeedSetRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the seed sets the active extensions of this installation provide.
 *
 * The command is registered through the `#[AsCommand]` attribute, which TYPO3
 * turns into the `console.command` tag while compiling the container - so
 * neither `Configuration/Commands.php` nor a service definition is needed.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2 and this branch
 * supports PHP 8.1 for TYPO3 v12, so immutability is declared per property
 * throughout - and here it could not be declared on the class in any case,
 * because it extends the Symfony `Command`, which is not readonly.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsCommand(
    name: 'data-factory:list',
    description: 'Lists the seed sets provided by the active extensions.',
)]
final class ListSeedsCommand extends Command
{
    public function __construct(
        private readonly SeedSetRepository $seedSetRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Lists identifier, title and providing extension of every seed set found in'
            . ' "Configuration/DataFactory/*/config.yml" of an active extension. Increase the'
            . ' verbosity with -v to add the directory a set lives in.' . PHP_EOL . PHP_EOL
            . 'An identifier provided by more than one extension is reported with all of'
            . ' its providers, and makes the command exit non-zero: the sets cannot be'
            . ' told apart, so neither this command nor an import may pick one of them.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $verbose = $output->isVerbose();

        try {
            $seedSets = $this->seedSetRepository->findAll();
            $duplicates = $this->seedSetRepository->findDuplicates($seedSets);
        } catch (SeedingException $exception) {
            // A "config.yml" that cannot be read or does not name itself is the
            // one failure discovery cannot work around: the set has no name to
            // list it under and none to leave it out by.
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        if ($seedSets === []) {
            // Not an error. An installation nobody ships seed data for is the
            // normal state of most installations.
            $io->writeln('No active extension provides a seed set.');
            return Command::SUCCESS;
        }

        $headers = ['Identifier', 'Title', 'Extension'];
        if ($verbose) {
            $headers[] = 'Base path';
        }

        $rows = [];
        foreach ($seedSets as $seedSet) {
            $row = [$seedSet->identifier, $seedSet->title, $seedSet->extensionKey];
            if ($verbose) {
                $row[] = $seedSet->basePath;
            }
            $rows[] = $row;
        }
        $io->table($headers, $rows);

        if ($duplicates === []) {
            return Command::SUCCESS;
        }

        // Written as plain lines rather than as a SymfonyStyle block: a block
        // is wrapped to the terminal width, and an absolute path broken over
        // two lines is the one thing this report must not do.
        foreach ($duplicates as $identifier => $collidingSeedSets) {
            $output->writeln(sprintf(
                '<error>The seed set identifier "%s" is provided more than once:</error>',
                $identifier,
            ));
            foreach ($collidingSeedSets as $seedSet) {
                $output->writeln(sprintf('  %s (%s)', $seedSet->extensionKey, $seedSet->configFile));
            }
        }
        $output->writeln('');
        $output->writeln(
            'A seed set identifier is declared, and it is globally unique. Rename one of the'
            . ' sets above, or deactivate one of the extensions providing them.',
        );

        return Command::FAILURE;
    }
}
