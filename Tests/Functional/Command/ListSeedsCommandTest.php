<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Command\ListSeedsCommand;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

/**
 * What `data-factory:list` prints for an installation whose seed sets are in order.
 *
 * The command is taken from the container rather than constructed, because the
 * registration is half of what is under test: `#[AsCommand]` has to reach the
 * `console.command` tag, and the repository has to be injected, without a
 * `Configuration/Commands.php` or a service definition anywhere.
 */
final class ListSeedsCommandTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/example-fixture',
        'tests/seeds-demo',
    ];

    #[Test]
    public function theCommandIsRegisteredUnderItsName(): void
    {
        $this->assertTrue($this->get(CommandRegistry::class)->has('data-factory:list'));
    }

    #[Test]
    public function everySeedSetIsListedWithItsTitleAndItsProvidingExtension(): void
    {
        $commandTester = $this->execute();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('Identifier Title Extension', $display);
        $this->assertStringContainsString('demo-pages Demo page tree tests_seeds_demo', $display);
        $this->assertStringContainsString('demo-content Demo content elements tests_seeds_demo', $display);
    }

    #[Test]
    public function theSetsAreListedInDiscoveryOrder(): void
    {
        // "basic/" before "extended/", which is the order of the directories
        // and the reverse of the order of the identifiers.
        $display = $this->normalize($this->execute()->getDisplay());
        $first = strpos($display, 'demo-pages');
        $second = strpos($display, 'demo-content');

        $this->assertIsInt($first);
        $this->assertIsInt($second);
        $this->assertLessThan(
            $second,
            $first,
            'The set of the "basic" directory is listed before the one of the "extended" directory.',
        );
    }

    #[Test]
    public function raisedVerbosityAddsTheBasePathOfEverySet(): void
    {
        $this->assertStringNotContainsString('Base path', $this->execute()->getDisplay());
        $this->assertStringNotContainsString('Configuration/DataFactory/basic', $this->execute()->getDisplay());

        $verboseDisplay = $this->execute(OutputInterface::VERBOSITY_VERBOSE)->getDisplay();

        $this->assertStringContainsString('Base path', $verboseDisplay);
        $this->assertStringContainsString('Configuration/DataFactory/basic', $verboseDisplay);
        $this->assertStringContainsString('Configuration/DataFactory/extended', $verboseDisplay);
    }

    private function execute(int $verbosity = OutputInterface::VERBOSITY_NORMAL): CommandTester
    {
        $commandTester = new CommandTester($this->get(ListSeedsCommand::class));
        $commandTester->execute([], ['verbosity' => $verbosity]);

        return $commandTester;
    }

    /**
     * Collapses the padding of the table, so that a row can be asserted as the
     * sequence of its cells instead of as whatever column width the widest
     * entry happened to produce.
     */
    private function normalize(string $display): string
    {
        return trim((string)preg_replace('/[ \t]+/', ' ', $display));
    }
}
