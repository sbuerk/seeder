<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Unit\Seeding\Parser;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Where a seed set may live, which is the one question `seeder:import` cannot
 * answer from a functional test.
 *
 * `SeedDefinitionParser::parseFile()` runs the entry file through
 * `GeneralUtility::getFileAbsFileName()`, and resolves its `imports` through
 * `YamlFileLoader`, which does the same for every resource. That function
 * answers an absolute path outside `Environment::getProjectPath()` and
 * `Environment::getPublicPath()` with an empty string - and `seeder:import`
 * hands it the absolute `config.yml` that discovery found, which in a Composer
 * installation is below the vendor directory.
 *
 * The functional test instance cannot answer whether that is a problem: the
 * testing framework sets both paths to the instance and links the test
 * extensions into it, so every package path is inside the project by
 * construction. This test builds the layout a Composer installation actually
 * has instead - a project directory, a `public/` below it, and the package in
 * `vendor/` next to it, which is *not* below the public path - and pins both
 * halves of the answer:
 *
 * - a set below the vendor directory of the project is read, including a
 *   relative `imports:` of it, because `isAllowedAbsPath()` accepts anything
 *   below the project path and `typo3/cms-composer-installers` pins the TYPO3
 *   application directory to the Composer root ("Changing app-dir is not
 *   supported any more. TYPO3 application dir will always be set to Composer
 *   root directory") while refusing a public directory outside it;
 * - a set outside the project path is refused, which is the constraint that
 *   remains: it is reachable with a `TYPO3_PATH_APP` pointing somewhere else,
 *   and it is what the import command explains rather than reporting a file
 *   that plainly exists as missing.
 *
 * Only the entry file is confined this way. A **scenario** path is resolved by
 * {@see \SBUERK\Seeder\Seeding\Scenario\ScenarioComposer}, which deliberately
 * does not send an absolute path through `getFileAbsFileName()` - see the
 * resolution tests there.
 */
final class SeedDefinitionParserPathResolutionTest extends UnitTestCase
{
    use SeedYamlFileLoaderTestTrait;

    protected bool $backupEnvironment = true;

    private string $projectPath = '';
    private string $outsidePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $root = Environment::getVarPath() . '/tests/seeder-path-resolution';
        GeneralUtility::rmdir($root, true);
        // Registered before anything is created, and removed with the varPath
        // that is restored by then - the environment this test replaces is put
        // back before the cleanup runs.
        $this->testFilesToDelete[] = $root;

        $this->projectPath = $root . '/project';
        $this->outsidePath = $root . '/elsewhere';
        $this->writeSeedSet($this->projectPath . '/vendor/acme/demo-content/Configuration/Seeder/demo');
        $this->writeSeedSet($this->outsidePath . '/demo-content/Configuration/Seeder/demo');
        GeneralUtility::mkdir_deep($this->projectPath . '/public');

        Environment::initialize(
            Environment::getContext(),
            Environment::isCli(),
            Environment::isComposerMode(),
            $this->projectPath,
            $this->projectPath . '/public',
            $this->projectPath . '/var',
            $this->projectPath . '/config',
            Environment::getCurrentScript(),
            'UNIX',
        );
    }

    #[Test]
    public function aSetInTheVendorDirectoryOfTheProjectIsRead(): void
    {
        $definition = $this->subject()->parseFile(
            $this->projectPath . '/vendor/acme/demo-content/Configuration/Seeder/demo/config.yml',
        );

        $this->assertSame('vendor-demo', $definition->identifier);
        // The vendor directory is below the project path and not below the
        // public path, which is exactly the case an assertion on the public
        // path alone would get wrong.
        $this->assertStringStartsWith(Environment::getProjectPath() . '/vendor/', $definition->basePath);
        $this->assertStringNotContainsString(Environment::getPublicPath() . '/', $definition->basePath);
    }

    #[Test]
    public function aRelativeImportOfSuchASetIsFollowed(): void
    {
        $definition = $this->subject()->parseFile(
            $this->projectPath . '/vendor/acme/demo-content/Configuration/Seeder/demo/config.yml',
        );

        // The import is resolved against the importing file and then checked
        // with "isAllowedAbsPath()" a second time, so a set that is readable
        // and whose imports are not is a possible state - and not this one.
        $this->assertSame(['Imported.yaml', 'Declared.yaml'], $definition->scenarios);
    }

    #[Test]
    public function aSetOutsideTheProjectPathIsRefused(): void
    {
        // The file is there and readable, so the refusal below is the path
        // check and not a missing file - which is the whole point of it, and
        // the reason the message of the command explains itself.
        $this->assertFileExists($this->outsidePath . '/demo-content/Configuration/Seeder/demo/config.yml');

        $this->expectException(SeedDefinitionNotFoundException::class);
        $this->expectExceptionCode(1787072801);

        $this->subject()->parseFile(
            $this->outsidePath . '/demo-content/Configuration/Seeder/demo/config.yml',
        );
    }

    private function writeSeedSet(string $directory): void
    {
        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::writeFile(
            $directory . '/config.yml',
            "identifier: vendor-demo\ntitle: 'A set shipped by a package'\n"
            . "imports:\n  - { resource: Scenarios.yaml }\nscenarios:\n  - Declared.yaml\n",
            true,
        );
        GeneralUtility::writeFile(
            $directory . '/Scenarios.yaml',
            "scenarios:\n  - Imported.yaml\n",
            true,
        );
    }

    /**
     * Built per call rather than in `setUp()`: every test here replaces
     * `Environment` first, and the parser is only meaningful against the
     * environment of the test using it.
     */
    private function subject(): SeedDefinitionParser
    {
        return new SeedDefinitionParser($this->seedYamlFileLoader());
    }
}
