<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Exception\DuplicateSeedSetIdentifierException;
use SBUERK\Seeder\Seeding\SeedSet;
use SBUERK\Seeder\Seeding\SeedSetRepository;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Discovery over the active packages of a real installation.
 *
 * The rules themselves - the order, the directories that are passed over, the
 * "config.yml" that are refused - are covered by the unit test of the same
 * name. What only a functional test can show is the part in between: that the
 * repository is wired to the package manager of the running installation, that
 * a set shipped by an extension is found through it, and that an extension
 * which ships none is not a problem.
 */
final class SeedSetRepositoryTest extends AbstractFunctionalTestCase
{
    /**
     * "tests/example-fixture" has no "Configuration/Seeder/" at all and is
     * loaded on purpose: it is the case every other extension of an
     * installation is in.
     */
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
        'tests/example-fixture',
        'tests/seeds-demo',
        'tests/seeds-collision',
    ];

    #[Test]
    public function everySeedSetOfEveryActiveExtensionIsFound(): void
    {
        // An equality assertion, not a "contains": the order is part of what
        // discovery promises, and a set turning up twice or not at all has to
        // fail here rather than pass a count.
        //
        // The providing extensions come in the order the package manager holds
        // them, which is not the order they are named in
        // $testExtensionsToLoad - and the two sets of one extension follow the
        // names of their directories, "basic/" before "extended/", which is
        // the reverse of sorting them by identifier.
        $this->assertSame(
            [
                'tests_seeds_collision/demo-pages',
                'tests_seeds_demo/demo-pages',
                'tests_seeds_demo/demo-content',
            ],
            $this->describe($this->subject()->findAll()),
        );
    }

    #[Test]
    public function theSameInstallationReturnsTheSameSetsInTheSameOrder(): void
    {
        // The repository holds no discovered state, so a second call cannot
        // return anything else - and if someone ever caches into a property,
        // this is where it shows.
        $subject = $this->subject();

        $this->assertSame($this->describe($subject->findAll()), $this->describe($subject->findAll()));
    }

    #[Test]
    public function anExtensionWithoutASeederDirectoryContributesNothing(): void
    {
        $this->assertTrue(ExtensionManagementUtility::isLoaded('tests_example_fixture'));

        $providers = array_map(
            static fn(SeedSet $seedSet): string => $seedSet->extensionKey,
            $this->subject()->findAll(),
        );

        $this->assertNotContains('tests_example_fixture', $providers);
    }

    #[Test]
    public function aSetCarriesTheMetadataAndThePathsOfItsProvidingExtension(): void
    {
        $seedSet = $this->subject()->findByIdentifier('demo-content');

        $this->assertSame('Demo content elements', $seedSet->title);
        $this->assertSame('The page tree of "demo-pages" with content elements on it.', $seedSet->description);
        $this->assertSame('tests/seeds-demo', $seedSet->packageName);
        $this->assertSame('tests_seeds_demo', $seedSet->extensionKey);
        $this->assertStringEndsWith('/Configuration/Seeder/extended', $seedSet->basePath);
        $this->assertSame($seedSet->basePath . '/config.yml', $seedSet->configFile);
        $this->assertFileExists($seedSet->configFile);
    }

    #[Test]
    public function aCollisionBetweenTwoExtensionsIsRefusedNamingBothOfThem(): void
    {
        try {
            $this->subject()->findByIdentifier('demo-pages');
            $this->fail(sprintf('Expected a %s.', DuplicateSeedSetIdentifierException::class));
        } catch (DuplicateSeedSetIdentifierException $exception) {
            $this->assertStringContainsString('tests_seeds_demo', $exception->getMessage());
            $this->assertStringContainsString('tests_seeds_collision', $exception->getMessage());
        }
    }

    #[Test]
    public function aCollisionBetweenTwoExtensionsIsReportedWithBothProviders(): void
    {
        $duplicates = $this->subject()->findDuplicates();

        $this->assertSame(['demo-pages'], array_keys($duplicates));
        $this->assertSame(
            ['tests_seeds_collision/demo-pages', 'tests_seeds_demo/demo-pages'],
            $this->describe($duplicates['demo-pages']),
        );
    }

    private function subject(): SeedSetRepository
    {
        return $this->get(SeedSetRepository::class);
    }

    /**
     * @param list<SeedSet> $seedSets
     * @return list<string>
     */
    private function describe(array $seedSets): array
    {
        return array_map(
            static fn(SeedSet $seedSet): string => $seedSet->extensionKey . '/' . $seedSet->identifier,
            $seedSets,
        );
    }
}
