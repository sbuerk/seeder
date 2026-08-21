<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Exception\DuplicateSeedSetIdentifierException;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedSetNotFoundException;
use SBUERK\DataFactory\Seeding\SeedSet;
use SBUERK\DataFactory\Seeding\SeedSetRepository;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The rules of discovery, over fake packages pointing at fixture directories.
 *
 * What is under test here is what the repository decides: the order it returns
 * sets in, which directories it passes over, and which "config.yml" it refuses.
 * None of that needs a TYPO3 instance - a package is a path and an extension
 * key, and a mock provides both.
 *
 * That the repository is handed the *active* packages of a real installation,
 * that a real extension shipping a real set is found, and that "data-factory:list"
 * prints what it found is a different claim, and it is covered by the
 * functional tests in "Tests/Functional/Seeding/" and "Tests/Functional/Command/".
 */
final class SeedSetRepositoryTest extends UnitTestCase
{
    /**
     * The fixture packages, as the directory below "Fixtures/Packages/" mapped
     * to the extension key the fake package reports for it.
     */
    private const PACKAGES = [
        'ProviderOne' => 'provider_one',
        'ProviderTwo' => 'provider_two',
        'ProviderNone' => 'provider_none',
        'BrokenYaml' => 'broken_yaml',
        'WithoutIdentifier' => 'without_identifier',
        'WithoutTitle' => 'without_title',
        'NotAMap' => 'not_a_map',
        'ListDescription' => 'list_description',
    ];

    #[Test]
    public function everySeedSetOfEveryPackageIsFound(): void
    {
        $subject = $this->subjectFor('ProviderOne', 'ProviderTwo');

        $this->assertSame(
            [
                'provider_one/zulu',
                'provider_one/alpha',
                'provider_two/zulu',
            ],
            $this->describe($subject->findAll()),
        );
    }

    #[Test]
    public function setsAreOrderedByPackageAndThenByDirectoryName(): void
    {
        // The same packages in the other order. The result follows the package
        // order, and within one package the directory names - "aaa" before
        // "bbb", which is the reverse of what sorting by identifier would give
        // ("alpha" before "zulu").
        $subject = $this->subjectFor('ProviderTwo', 'ProviderOne');

        $this->assertSame(
            [
                'provider_two/zulu',
                'provider_one/zulu',
                'provider_one/alpha',
            ],
            $this->describe($subject->findAll()),
        );
    }

    #[Test]
    public function aPackageWithoutADataFactoryDirectoryIsSkipped(): void
    {
        // "ProviderNone" has a "Configuration/" but no "Configuration/DataFactory/",
        // which is the normal state of nearly every package of an installation.
        $subject = $this->subjectFor('ProviderNone', 'ProviderOne');

        $this->assertSame(
            [
                'provider_one/zulu',
                'provider_one/alpha',
            ],
            $this->describe($subject->findAll()),
        );
    }

    #[Test]
    public function aDirectoryWithoutAConfigFileIsNotASeedSet(): void
    {
        // "ProviderOne" holds a third directory below "Configuration/DataFactory/"
        // which has no "config.yml" in it.
        $subject = $this->subjectFor('ProviderOne');

        $this->assertCount(2, $subject->findAll());
    }

    #[Test]
    public function theMetadataOfASetIsReadFromItsConfigFile(): void
    {
        $subject = $this->subjectFor('ProviderOne');

        $seedSet = $subject->findByIdentifier('alpha');

        $this->assertSame('alpha', $seedSet->identifier);
        $this->assertSame('The set in the second directory', $seedSet->title);
        $this->assertSame('Carries a description, the set next to it does not.', $seedSet->description);
        $this->assertSame('tests/provider-one', $seedSet->packageName);
        $this->assertSame('provider_one', $seedSet->extensionKey);
        $this->assertSame($this->packagePath('ProviderOne') . 'Configuration/DataFactory/bbb', $seedSet->basePath);
        $this->assertSame($this->packagePath('ProviderOne') . 'Configuration/DataFactory/bbb/config.yml', $seedSet->configFile);
    }

    #[Test]
    public function theDescriptionOfASetIsOptional(): void
    {
        $subject = $this->subjectFor('ProviderOne');

        $this->assertSame('', $subject->findByIdentifier('zulu')->description);
    }

    #[Test]
    public function findByIdentifierResolvesTheOnlyProviderOfAnIdentifier(): void
    {
        $subject = $this->subjectFor('ProviderOne', 'ProviderTwo');

        $seedSet = $subject->findByIdentifier('alpha');

        $this->assertSame('alpha', $seedSet->identifier);
        $this->assertSame('provider_one', $seedSet->extensionKey);
    }

    #[Test]
    public function findByIdentifierRefusesADuplicatedIdentifierNamingEveryProvider(): void
    {
        $subject = $this->subjectFor('ProviderOne', 'ProviderTwo');

        try {
            $subject->findByIdentifier('zulu');
            $this->fail(sprintf('Expected a %s.', DuplicateSeedSetIdentifierException::class));
        } catch (DuplicateSeedSetIdentifierException $exception) {
            $this->assertSame(1787074411, $exception->getCode());
            // Naming only one of them would be the same as picking one.
            $this->assertStringContainsString('provider_one', $exception->getMessage());
            $this->assertStringContainsString('provider_two', $exception->getMessage());
        }
    }

    #[Test]
    public function findByIdentifierRejectsAnIdentifierNoPackageProvides(): void
    {
        $subject = $this->subjectFor('ProviderOne');

        $this->expectException(SeedSetNotFoundException::class);
        $this->expectExceptionCode(1787074410);

        $subject->findByIdentifier('nothing-provides-this');
    }

    #[Test]
    public function findDuplicatesNamesEveryProviderOfACollidingIdentifier(): void
    {
        $subject = $this->subjectFor('ProviderOne', 'ProviderTwo');

        $duplicates = $subject->findDuplicates();

        $this->assertSame(['zulu'], array_keys($duplicates));
        $this->assertSame(
            ['provider_one/zulu', 'provider_two/zulu'],
            $this->describe($duplicates['zulu']),
        );
    }

    #[Test]
    public function findDuplicatesIsEmptyWithoutACollision(): void
    {
        $subject = $this->subjectFor('ProviderOne');

        $this->assertSame([], $subject->findDuplicates());
    }

    #[Test]
    public function findDuplicatesLooksAtTheSetsItIsGiven(): void
    {
        // The packages provide no collision at all, the argument does. This is
        // what lets a command report on exactly the sets it listed instead of
        // reading the installation a second time.
        $subject = $this->subjectFor('ProviderOne');
        $seedSet = new SeedSet('given', 'Given', '', 'tests/given', 'given', '/given', '/given/config.yml');

        $duplicates = $subject->findDuplicates([$seedSet, $seedSet]);

        $this->assertSame(['given'], array_keys($duplicates));
    }

    #[Test]
    public function aConfigFileWhichIsNotReadableYamlIsRejected(): void
    {
        $subject = $this->subjectFor('BrokenYaml');

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787074401);

        $subject->findAll();
    }

    #[Test]
    public function aConfigFileWhichIsNotAMapIsRejected(): void
    {
        $subject = $this->subjectFor('NotAMap');

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787074402);

        $subject->findAll();
    }

    #[Test]
    public function aSetWithoutAnIdentifierIsRejected(): void
    {
        // It cannot be listed, and it cannot be left out of a listing either:
        // there is nothing to name it by. Deriving the identifier from the
        // directory name is what this refusal exists to prevent.
        $subject = $this->subjectFor('WithoutIdentifier');

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787074403);

        $subject->findAll();
    }

    #[Test]
    public function aSetWithoutATitleIsRejected(): void
    {
        $subject = $this->subjectFor('WithoutTitle');

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787074404);

        $subject->findAll();
    }

    #[Test]
    public function aDescriptionWhichIsNotAStringIsRejected(): void
    {
        $subject = $this->subjectFor('ListDescription');

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787074405);

        $subject->findAll();
    }

    /**
     * A repository over fake packages for the named fixture directories, in the
     * order they are named - the package order is an input of discovery, so a
     * test has to be able to choose it.
     */
    private function subjectFor(string ...$packageDirectories): SeedSetRepository
    {
        $packages = [];
        foreach ($packageDirectories as $packageDirectory) {
            $package = $this->createMock(PackageInterface::class);
            $package->method('getPackagePath')->willReturn($this->packagePath($packageDirectory));
            $package->method('getPackageKey')->willReturn(self::PACKAGES[$packageDirectory]);
            $package->method('getValueFromComposerManifest')->willReturn(
                'tests/' . str_replace('_', '-', self::PACKAGES[$packageDirectory]),
            );
            $packages[] = $package;
        }

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn($packages);

        return new SeedSetRepository($packageManager);
    }

    /**
     * A package path ends with a slash, exactly as the one of a real package
     * does - the repository has to cope with it.
     */
    private function packagePath(string $packageDirectory): string
    {
        return __DIR__ . '/Fixtures/Packages/' . $packageDirectory . '/';
    }

    /**
     * Reduces sets to "<extension key>/<identifier>", which is what the order
     * assertions are about and short enough to read in a failure message.
     *
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
