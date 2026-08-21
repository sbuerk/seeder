<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional;

use SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Base class of all functional tests, taking care that the extension itself is
 * loaded in the test instance.
 *
 * It extends the `FunctionalTestCase` of `sbuerk/typo3-site-based-test-trait`
 * rather than the one of `typo3/testing-framework` directly. That class extends
 * the framework one and adds what a site based test needs, most notably a
 * `setUpFrontendRootPage()` which can set up a root page without creating a
 * `sys_template` record. Having every functional test go through this class
 * means the whole suite gains that without a second base class — see the
 * "Site based tests" page of the developer documentation in "docs/testing/".
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->provideLanguageServiceAsTheConsoleApplicationDoes();
    }

    /**
     * Puts a `LanguageService` into `$GLOBALS['LANG']`, which is what a run of
     * this extension has in production and what a functional test otherwise has
     * not.
     *
     * `DataHandler` resolves the title of a record for the log entry it writes
     * on an insert, and on TYPO3 v12.4 it does so eagerly:
     * `insertDB()` calls `getRecordPropertiesFromRow()`, which reaches
     * `BackendUtility::getRecordTitle()` and from there
     * `BackendUtility::getProcessedValue()`, whose first act is
     * `$lang = static::getLanguageService();`. That method is declared
     * `: LanguageService` and returns `$GLOBALS['LANG']` unguarded on both
     * supported core versions, so an unset global is a `TypeError` and not a
     * missing label.
     *
     * Nothing in production hits that: a seed runs through `seeder:import`, and
     * `TYPO3\CMS\Core\Console\CommandApplication::run()` sets the same global
     * from the same factory - byte identical on 12.4 and 13.4 - before a
     * command is dispatched. A functional test invokes the command through
     * `CommandTester`, which is the one caller that skips the application.
     *
     * Set up here rather than per test class, because it is a property of
     * running `DataHandler` at all and every test in this suite does that,
     * directly or through the command.
     */
    private function provideLanguageServiceAsTheConsoleApplicationDoes(): void
    {
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
    }
}
