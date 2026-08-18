<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\DataMapFactory;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\RecordSeeder;
use SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationSeeder;
use SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationSeedResult;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writing the site configurations a seed set declares, and reporting the site
 * roots that end up with none.
 *
 * The templates live in the "tests/seeds-demo" fixture extension rather than
 * next to this test, and not for tidiness: `YamlFileLoader` resolves through
 * `GeneralUtility::getFileAbsFileName()`, which refuses a path outside the
 * project. In a functional test the project is the test instance, so a template
 * below "Tests/" is unreadable while one below an extension linked into the
 * instance is exactly what a package ships.
 */
final class SiteConfigurationSeederTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
        'tests/seeds-demo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container.
     *
     * It is a private service nothing references yet, so Symfony removes it
     * while compiling the container. Its wiring is proven where it is first
     * injected - the import command - rather than by publishing it for a test.
     * The four collaborators are fetched, because a container instance is what
     * the event listeners of `SiteConfiguration` and `SiteFinder` are attached
     * to and a hand built `SiteFinder` would never see the flush.
     */
    private function subject(): SiteConfigurationSeeder
    {
        return new SiteConfigurationSeeder(
            $this->get(SiteWriter::class),
            $this->get(SiteConfiguration::class),
            $this->get(SiteFinder::class),
            $this->get(ConnectionPool::class),
        );
    }

    /**
     * The directory the fixture set lives in, inside the test instance. Every
     * relative template path of a definition built here resolves against it,
     * exactly as it would for a set discovered from that package.
     */
    private function templateBasePath(): string
    {
        return rtrim(ExtensionManagementUtility::extPath('tests_seeds_demo'), '/') . '/Configuration/Seeder/basic';
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function parse(array $definition): SeedDefinition
    {
        return (new SeedDefinitionParser())->parse($definition, 'test definition', $this->templateBasePath());
    }

    /**
     * @return array{0: array<string, int>, 1: SiteConfigurationSeedResult}
     */
    private function seed(SeedDefinition $definition): array
    {
        $recordSeeder = new RecordSeeder(
            new DataMapFactory(),
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
        );
        $uids = $recordSeeder->seed($definition, $this->setUpBackendUser(1));

        return [$uids, $this->subject()->seed($definition, $uids)];
    }

    /**
     * A page tree with one site root and one sub page, which is what nearly
     * every case below needs.
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private static function treeWithSite(array $site): array
    {
        return [
            'identifier' => 'demo',
            'title' => 'A page tree with a site',
            'pages' => [
                [
                    'identifier' => 'home',
                    'title' => 'Home',
                    'slug' => '/',
                    'is_siteroot' => 1,
                    'children' => [
                        ['identifier' => 'about', 'title' => 'About'],
                    ],
                ],
            ],
            'sites' => [$site],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writtenConfiguration(string $identifier): array
    {
        $file = Environment::getConfigPath() . '/sites/' . $identifier . '/config.yaml';
        $this->assertFileExists($file);

        /** @var array<string, mixed> $configuration */
        $configuration = Yaml::parseFile($file);

        return $configuration;
    }

    /**
     * @return list<string>
     */
    private function automaticSiteConfigurations(): array
    {
        return glob(Environment::getConfigPath() . '/sites/autogenerated-*') ?: [];
    }

    /**
     * A set declaring a site gets that site and nothing else - in particular
     * not the "autogenerated-<uid>" configuration TYPO3 writes by itself for
     * every new root page.
     */
    #[Test]
    public function aSetDeclaringSitesProducesNoAutomaticSiteConfiguration(): void
    {
        [, $result] = $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
        ])));

        $this->assertSame(['main'], $result->writtenSites);
        $this->assertSame([], $this->automaticSiteConfigurations());
        $this->assertSame(
            ['main'],
            array_map('basename', glob(Environment::getConfigPath() . '/sites/*') ?: []),
        );
    }

    /**
     * The uid of the declared root page wins over the template.
     *
     * The template names 999, which is not a page of this installation, so an
     * assertion on the seeded uid alone would pass just as well if the template
     * were written through unchanged.
     */
    #[Test]
    public function theRootPageIdComesFromTheSeededPageRatherThanFromTheTemplate(): void
    {
        [$uids] = $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
        ])));

        $configuration = $this->writtenConfiguration('main');

        $this->assertSame($uids['home'], (int)$configuration['rootPageId']);
        $this->assertNotSame(999, (int)$configuration['rootPageId']);
        // Everything the template declares beside it is written unchanged.
        $this->assertSame('https://template.example.org/', $configuration['base']);
        $this->assertSame('en_US.UTF-8', $configuration['languages'][0]['locale']);
    }

    /**
     * A "base" in the definition overrides the one of the template, which is
     * what lets one set be imported into instances that are reached under
     * different host names.
     */
    #[Test]
    public function aBaseInTheDefinitionOverridesTheTemplate(): void
    {
        $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
            'base' => 'https://seeded.example.com/',
        ])));

        $this->assertSame('https://seeded.example.com/', $this->writtenConfiguration('main')['base']);
    }

    /**
     * A set declaring no sites gets no automatic site configuration either -
     * suppression is unconditional - and the site root it leaves without one is
     * reported rather than left for the user to discover in the frontend.
     */
    #[Test]
    public function aSetWithoutSitesReportsTheSiteRootsNoConfigurationCovers(): void
    {
        [$uids, $result] = $this->seed($this->parse([
            'identifier' => 'demo',
            'title' => 'A page tree without a site',
            'pages' => [
                [
                    'identifier' => 'home',
                    'title' => 'Home',
                    'slug' => '/',
                    'is_siteroot' => 1,
                    'children' => [
                        ['identifier' => 'about', 'title' => 'About'],
                    ],
                ],
            ],
        ]));

        $this->assertSame([], $result->writtenSites);
        $this->assertSame([], $this->automaticSiteConfigurations());
        // The site root, and only it: the sub page below it is not a site root
        // and reporting it would be noise.
        $this->assertSame(['home' => $uids['home']], $result->uncoveredSiteRoots);
    }

    /**
     * The site root a declared configuration covers is not reported, which is
     * the other half of the same question: the report has to be empty in the
     * normal case or it says nothing in the abnormal one.
     */
    #[Test]
    public function aSiteRootCoveredByADeclaredSiteIsNotReported(): void
    {
        [, $result] = $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
        ])));

        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * A seeded record on the page tree root that is not a page type
     * `CreateSiteConfiguration` would have acted on is not reported either.
     *
     * A sysfolder has no frontend, so warning that it has no site would be a
     * warning about nothing - and the report would lose the meaning it has in
     * the test above.
     */
    #[Test]
    public function aSeededRootLevelSysfolderIsNoUncoveredSiteRoot(): void
    {
        [, $result] = $this->seed($this->parse([
            'identifier' => 'storage',
            'title' => 'A storage folder',
            'pages' => [
                ['identifier' => 'storage', 'title' => 'Storage', 'doktype' => 254],
            ],
        ]));

        $this->assertSame([], $result->uncoveredSiteRoots);
    }

    /**
     * A configuration written through `SiteWriter` is visible to `SiteFinder`
     * in the same PHP process, with no cache handling of our own.
     *
     * `getAllSites()` is called **before** seeding on purpose. It fills the
     * runtime cache of `SiteConfiguration` and the root-page-id mapping of
     * `SiteFinder` with the state of an installation that has no sites, so a
     * finder that never heard about the new file would still answer from that.
     * Without the warm-up this test would pass on a cold finder and prove
     * nothing.
     */
    #[Test]
    public function aWrittenConfigurationIsFoundBySiteFinderInTheSameProcess(): void
    {
        $siteFinder = $this->get(SiteFinder::class);
        $this->assertSame([], $siteFinder->getAllSites());

        [$uids] = $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
        ])));

        $site = $siteFinder->getSiteByIdentifier('main');

        $this->assertSame($uids['home'], $site->getRootPageId());
        $this->assertSame($uids['home'], $siteFinder->getSiteByRootPageId($uids['home'])->getRootPageId());
        $this->assertSame('https://template.example.org/', (string)$site->getBase());
    }

    /**
     * A template needs no `rootPageId`, because the seed supplies it, and it
     * needs nothing else either: `Site` substitutes a default language for a
     * configuration that declares none, identically on TYPO3 v13.4 and v14.3.
     *
     * The one thing that is not optional is that `rootPageId` ends up greater
     * than zero - `SiteConfiguration::resolveAllExistingSites()` drops a
     * configuration without one silently, which is why this asserts through
     * `SiteFinder` rather than on the written file.
     */
    #[Test]
    public function aTemplateDeclaringNothingButABaseProducesAUsableSite(): void
    {
        [$uids] = $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'minimal',
            'rootPage' => 'home',
            'template' => 'Sites/minimal',
        ])));

        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('minimal');

        $this->assertSame($uids['home'], $site->getRootPageId());
        $this->assertSame('https://minimal.example.org/', (string)$site->getBase());
        // The default language `Site` substitutes, which the template does not
        // declare. Its locale is not asserted: `Locale` normalises what the
        // core writes there and the normalised form is not the seeder's claim.
        $this->assertSame([0], array_keys($site->getLanguages()));
        $this->assertSame(0, $site->getDefaultLanguage()->getLanguageId());
    }

    /**
     * The `settings.yaml` of a template is written beside the configuration,
     * unchanged.
     *
     * It is asserted on the file rather than through `Site::getSettings()`
     * because a setting that no site set declares is not necessarily kept by
     * the settings resolution, and what this class is responsible for is that
     * the file arrives.
     */
    #[Test]
    public function aTemplateSettingsFileIsWrittenBesideTheConfiguration(): void
    {
        $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'settings',
            'rootPage' => 'home',
            'template' => 'Sites/withsettings',
        ])));

        $this->assertSame(
            ['seeds' => ['demo' => ['greeting' => 'from the template']]],
            Yaml::parseFile(Environment::getConfigPath() . '/sites/settings/settings.yaml'),
        );
    }

    /**
     * A template that carries `dependencies:` is written unchanged and read
     * back into the site entity on both core versions.
     *
     * `dependencies` is often taken for a v14 key. It is not: `Site::__construct()`
     * reads `$configuration['dependencies'] ?? []` into its set list on 13.4
     * and 14.3 alike, and the versions differ only in the fourth argument
     * v14.3 adds to `SiteWriter::createNewBasicSite()`, which this seeder does
     * not call. This is what keeps that true - a core version that starts
     * rejecting or renaming the key fails here rather than in an installation.
     */
    #[Test]
    public function aTemplateCarryingSiteSetDependenciesIsWrittenOnBothCoreVersions(): void
    {
        $this->seed($this->parse(self::treeWithSite([
            'identifier' => 'dependencies',
            'rootPage' => 'home',
            'template' => 'Sites/withdependencies',
        ])));

        $this->assertSame(
            ['tests/a-site-set-that-does-not-exist'],
            $this->writtenConfiguration('dependencies')['dependencies'],
        );
        $this->assertSame(
            ['tests/a-site-set-that-does-not-exist'],
            $this->get(SiteFinder::class)->getSiteByIdentifier('dependencies')->getSets(),
        );
    }

    /**
     * A template directory without a `config.yaml` fails with a message naming
     * the set and the path that was looked at.
     */
    #[Test]
    public function aMissingTemplateFailsNamingTheSetAndThePath(): void
    {
        $definition = $this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
            'template' => 'Sites/does-not-exist',
        ]));

        try {
            $this->seed($definition);
            $this->fail('A site with a missing template was not refused.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787077005, $exception->getCode());
            $this->assertStringContainsString('"demo"', $exception->getMessage());
            $this->assertStringContainsString(
                $this->templateBasePath() . '/Sites/does-not-exist/config.yaml',
                $exception->getMessage(),
            );
        }
    }

    /**
     * An identifier the installation already uses is refused rather than
     * merged into.
     *
     * `SiteWriter::write()` diffs an incoming configuration against the
     * existing file and applies only the changed keys, so writing a template
     * over an existing site produces a hybrid of the two. Seeding the same set
     * twice is the case a user runs into, and it has to say so.
     */
    #[Test]
    public function anIdentifierTheInstallationAlreadyUsesIsRefused(): void
    {
        $definition = $this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'home',
            'base' => 'https://first.example.com/',
        ]));
        $this->seed($definition);

        try {
            $this->seed($definition);
            $this->fail('A site configuration that already exists was overwritten.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787077002, $exception->getCode());
            $this->assertStringContainsString('"main"', $exception->getMessage());
        }

        // And the existing configuration is untouched, which is the point of
        // refusing rather than merging.
        $this->assertSame('https://first.example.com/', $this->writtenConfiguration('main')['base']);
    }

    /**
     * A "rootPage" naming an identifier the definition does not declare is
     * refused by the parser, before anything is written.
     *
     * The check is asserted where it lives rather than repeated in the seeder:
     * a definition that cannot be right is a broken definition, and the earlier
     * it is refused the less of an installation it has already changed.
     */
    #[Test]
    public function aRootPageNoRecordDeclaresIsRefusedByTheParser(): void
    {
        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072875);

        $this->parse(self::treeWithSite([
            'identifier' => 'main',
            'rootPage' => 'nowhere',
        ]));
    }

    /**
     * The whole way through, from the `config.yml` of a set shipped by an
     * extension to a written site: the definition is read from the file, the
     * template path is the default the parser fills in, and it resolves against
     * the directory the set lives in.
     *
     * Everything above builds its definition from an array, so nothing above
     * would notice if the default template path or the base path of a set were
     * wrong.
     */
    #[Test]
    public function aSetShippedByAnExtensionIsSeededFromItsOwnDirectory(): void
    {
        $definition = (new SeedDefinitionParser())->parseFile($this->templateBasePath() . '/config.yml');

        [$uids, $result] = $this->seed($definition);

        $this->assertSame(['main'], $result->writtenSites);
        $this->assertSame([], $result->uncoveredSiteRoots);
        $this->assertSame($uids['home'], (int)$this->writtenConfiguration('main')['rootPageId']);
    }
}
