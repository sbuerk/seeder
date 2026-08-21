<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Command\ListSeedsCommand;
use SBUERK\DataFactory\Seeding\Exception\SeedSetNotFoundException;
use SBUERK\DataFactory\Seeding\SeedSetRepository;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An installation in which no extension provides a seed set.
 *
 * This is the state of every installation that has just added this extension,
 * and it is **not** an error: discovery returns nothing, and `data-factory:list` says
 * so and succeeds. A non-zero exit code here would make the extension fail
 * every pipeline that runs the command to see what is available.
 *
 * Both ends of that are asserted in one test class because it is one condition,
 * and because the condition *is* the set of loaded extensions - which is a
 * property of the test instance, so it cannot be arranged inside a test method
 * of a class that loads more.
 */
final class InstallationWithoutSeedSetsTest extends AbstractFunctionalTestCase
{
    /**
     * The extension itself ships no seed set, and "tests/example-fixture" has
     * no "Configuration/DataFactory/" directory at all.
     */
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/example-fixture',
    ];

    #[Test]
    public function discoveryFindsNothingAndDoesNotFail(): void
    {
        $subject = $this->get(SeedSetRepository::class);

        $this->assertSame([], $subject->findAll());
        $this->assertSame([], $subject->findDuplicates());
    }

    #[Test]
    public function aLookupByIdentifierStillReportsWhatIsMissing(): void
    {
        $this->expectException(SeedSetNotFoundException::class);
        $this->expectExceptionCode(1787074410);

        $this->get(SeedSetRepository::class)->findByIdentifier('demo-pages');
    }

    #[Test]
    public function theCommandSaysSoAndSucceeds(): void
    {
        $commandTester = new CommandTester($this->get(ListSeedsCommand::class));
        $commandTester->execute([]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame('No active extension provides a seed set.', trim($commandTester->getDisplay()));
    }
}
