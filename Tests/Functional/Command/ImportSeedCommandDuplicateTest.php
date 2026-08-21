<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Command\ImportSeedCommand;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `data-factory:import` does when two extensions claim the same identifier.
 *
 * The identifier is the only thing an import is asked for, so a collision is
 * not a display problem here but the one state in which the command cannot know
 * what it was told to do. It refuses with an exit code of its own - "the set
 * cannot be told apart" is a different problem from "no such set", and a script
 * that installs an extension too many has to be able to see the difference.
 *
 * The fixtures are in a separate test class because the set of loaded
 * extensions is what produces the collision, and that is a property of the test
 * instance.
 */
final class ImportSeedCommandDuplicateTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        'tests/seeds-demo',
        'tests/seeds-collision',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/BackendUsers.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function anIdentifierProvidedMoreThanOnceIsRefusedNamingEveryProvider(): void
    {
        $commandTester = new CommandTester($this->get(ImportSeedCommand::class));
        $commandTester->execute(['identifier' => 'demo-pages'], ['interactive' => false]);

        $this->assertSame(ImportSeedCommand::EXIT_UNRESOLVABLE_SET, $commandTester->getStatusCode());
        $display = (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay());
        $this->assertStringContainsString(
            'The seed set identifier "demo-pages" is provided by more than one extension',
            $display,
        );
        $this->assertStringContainsString('tests_seeds_demo', $display);
        $this->assertStringContainsString('tests_seeds_collision', $display);
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $this->assertSame(0, (int)$queryBuilder->count('uid')->from('pages')->executeQuery()->fetchOne());
    }
}
