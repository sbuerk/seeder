<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Command\ListSeedsCommand;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `seeder:list` does when two extensions claim the same identifier.
 *
 * A collision is not a display problem, it is a state an import must not run
 * in - so the command reports it and exits non-zero. The two sets are still
 * listed: an integrator has to see what is there before deciding which of the
 * two extensions is the one they meant to install.
 *
 * The fixtures are in a separate test class from the ones of
 * {@see ListSeedsCommandTest} because the set of loaded extensions is what
 * produces the collision, and that is a property of the test instance.
 */
final class ListSeedsCommandDuplicateTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
        'tests/seeds-demo',
        'tests/seeds-collision',
    ];

    #[Test]
    public function aDuplicatedIdentifierMakesTheCommandFail(): void
    {
        $this->assertSame(Command::FAILURE, $this->execute()->getStatusCode());
    }

    #[Test]
    public function aDuplicatedIdentifierIsReportedNamingEveryProvider(): void
    {
        $display = $this->execute()->getDisplay();

        $this->assertStringContainsString(
            'The seed set identifier "demo-pages" is provided more than once:',
            $display,
        );
        // The absolute path depends on where the checkout lives, the tail of
        // it does not - and the tail is what tells the two providers apart.
        $this->assertMatchesRegularExpression(
            '#tests_seeds_demo \(\S+/tests_seeds_demo/Configuration/Seeder/basic/config\.yml\)#',
            $display,
        );
        $this->assertMatchesRegularExpression(
            '#tests_seeds_collision \(\S+/tests_seeds_collision/Configuration/Seeder/duplicate/config\.yml\)#',
            $display,
        );
    }

    #[Test]
    public function theSetsWhichAreNotInCollisionAreStillListed(): void
    {
        $display = (string)preg_replace('/[ \t]+/', ' ', $this->execute()->getDisplay());

        $this->assertStringContainsString('demo-content Demo content elements tests_seeds_demo', $display);
    }

    /**
     * Both colliding sets are listed, not only the first one found. Reporting a
     * collision while showing one of its two halves would be the same "first
     * wins" the identifier rules exist to rule out.
     */
    #[Test]
    public function bothCollidingSetsAreListed(): void
    {
        $display = (string)preg_replace('/[ \t]+/', ' ', $this->execute()->getDisplay());

        $this->assertStringContainsString('demo-pages Demo page tree tests_seeds_demo', $display);
        $this->assertStringContainsString(
            'demo-pages A second page tree claiming the same identifier tests_seeds_collision',
            $display,
        );
    }

    private function execute(): CommandTester
    {
        $commandTester = new CommandTester($this->get(ListSeedsCommand::class));
        $commandTester->execute([]);

        return $commandTester;
    }
}
