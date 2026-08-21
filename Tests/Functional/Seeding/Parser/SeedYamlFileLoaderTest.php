<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\Parser;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Parser\SeedYamlFileLoaderInterface;
use SBUERK\DataFactory\Seeding\Parser\ThrowOnErrorLogger;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Covers the core version aware seam every YAML file of this extension is read
 * through.
 *
 * `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader` differs between the
 * supported versions in two ways that no compiler catches - it takes its logger
 * through the constructor on v13 and through `setLogger()` on v12, and
 * `ALLOW_EMPTY_FILE` is v13 only - so the call is split into
 * `Core12/Seeding/Parser/SeedYamlFileLoader` and its `Core13/` counterpart
 * behind {@see SeedYamlFileLoaderInterface}. What that split promises is one
 * behaviour on both versions, and that is what is asserted here.
 *
 * The tests carry **no** PHPUnit group on purpose. A group would restrict them
 * to the version they were written on, and the point of the split is that both
 * implementations answer the same way. What differs per version is the class
 * name the container hands out, and that is asserted by computing it from
 * `Typo3Version` rather than by having two test classes. Where an exception
 * message differs between the versions - v12 and v13 word "this file holds no
 * map" differently - the **code** is asserted, because that is what the two
 * core versions have in common and what a caller can act on.
 *
 * The fixtures are addressed as `EXT:` paths: the loader resolves through
 * `GeneralUtility::getFileAbsFileName()`, which refuses a path outside the
 * project, and in a functional test the project is the test instance rather than
 * this repository.
 *
 * The behaviour *around* the loader - which flags a caller asks for, and what it
 * makes of the result - is covered where those callers are:
 * {@see \SBUERK\DataFactory\Tests\Unit\Seeding\Parser\SeedDefinitionParserTest} and
 * {@see \SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling\SiteConfigurationSeederTest}.
 */
final class SeedYamlFileLoaderTest extends AbstractFunctionalTestCase
{
    private const FIXTURES = 'EXT:data_factory/Tests/Functional/Fixtures/Yaml/';

    /**
     * The code TYPO3 raises for a file that parses to something other than a
     * map. Identical on 12.4 and 13.4, the message is not.
     */
    private const NO_DATA_CODE = 1497332874;

    /**
     * The code TYPO3 v13 raises for a syntax error in the top level file. TYPO3
     * v12 lets the raw `Symfony\Component\Yaml\Exception\ParseException` escape
     * instead, which no caller of this seam catches, so the v12 implementation
     * wraps it into this.
     */
    private const SYNTAX_ERROR_CODE = 1740817000;

    #[Test]
    public function registeredImplementationMatchesTheRunningCoreVersion(): void
    {
        $loader = $this->get(SeedYamlFileLoaderInterface::class);

        $this->assertInstanceOf(SeedYamlFileLoaderInterface::class, $loader);
        $this->assertSame(
            sprintf(
                'SBUERK\\DataFactory\\Core%d\\Seeding\\Parser\\SeedYamlFileLoader',
                (new Typo3Version())->getMajorVersion(),
            ),
            $loader::class,
            'The container registered the YAML loader of a different core version than the one running. '
            . 'Only the "Core<major>/" directory of the running version is loaded, see "Configuration/Services.php".',
        );
    }

    /**
     * A file holding nothing a parser can turn into a value is an empty result
     * when the caller allows it.
     *
     * This is the whole reason `$allowEmptyFile` exists: an optional
     * `settings.yaml` shipped with a site template may well be a header comment
     * and nothing else, and the seeder then has no settings to write rather than
     * a broken template. TYPO3 v13 answers it with `ALLOW_EMPTY_FILE`, TYPO3
     * v12 has no such flag and raises instead, so the v12 implementation
     * answers it itself.
     */
    #[Test]
    public function aFileHoldingNothingIsAnEmptyResultWhenEmptyIsAllowed(): void
    {
        $this->assertSame([], $this->subject()->load(self::FIXTURES . 'Nothing.yaml', new NullLogger(), true));
    }

    /**
     * The same file without that permission is an error, on both versions.
     *
     * A `config.yml` that holds nothing describes no seed set, and the answer
     * has to be an exception rather than an empty definition that reports
     * success.
     */
    #[Test]
    public function aFileHoldingNothingIsRejectedWhenEmptyIsNotAllowed(): void
    {
        $this->expectException(YamlParseException::class);
        $this->expectExceptionCode(self::NO_DATA_CODE);

        $this->subject()->load(self::FIXTURES . 'Nothing.yaml', new NullLogger(), false);
    }

    /**
     * A file holding a scalar is rejected even when empty is allowed.
     *
     * The distinction matters and it is the reason the v12 implementation
     * pre-checks the file instead of catching the exception the loader raises:
     * v12 answers "parses to nothing" and "parses to something that is not a
     * map" with the same exception and the same code, while the v13 flag only
     * ever converts the first. A `settings.yaml` holding a bare string is a
     * broken template on v13, and it has to stay one on v12.
     */
    #[Test]
    public function aFileHoldingAScalarIsRejectedEvenWhenEmptyIsAllowed(): void
    {
        $this->expectException(YamlParseException::class);
        $this->expectExceptionCode(self::NO_DATA_CODE);

        $this->subject()->load(self::FIXTURES . 'Scalar.yaml', new NullLogger(), true);
    }

    /**
     * A syntax error arrives as the exception the callers catch.
     *
     * Both call sites of this seam catch `YamlFileLoadingException` and
     * `YamlParseException` and wrap them into a message naming the seed set. On
     * v13 the loader produces the latter; on v12 it lets the raw
     * `Symfony\Component\Yaml\Exception\ParseException` through, which would
     * pass both catches and surface as an uncaught exception instead of a
     * reported one.
     *
     * It is loaded with empty allowed, which is the case where the v12
     * implementation looks at the file itself first - a syntax error must not be
     * mistaken for an empty file on the way.
     */
    #[Test]
    public function aSyntaxErrorIsRaisedAsAYamlParseException(): void
    {
        try {
            $this->subject()->load(self::FIXTURES . 'Broken.yaml', new NullLogger(), true);
            $this->fail('A file that is not YAML was loaded without an error.');
        } catch (YamlParseException $exception) {
            $this->assertSame(self::SYNTAX_ERROR_CODE, $exception->getCode());
            $this->assertStringContainsString('has syntax errors', $exception->getMessage());
        }
    }

    /**
     * The logger reaches the loader, on both versions.
     *
     * The loader catches the failure of a single `imports` entry and reports it
     * to its logger rather than raising it, which for a seed definition is data
     * loss - see {@see ThrowOnErrorLogger}. On v12 `YamlFileLoader` has no
     * constructor, so the logger PHP silently discards there would leave
     * `$this->logger` null and turn this case into a fatal error inside the
     * core class. Nothing about that is a compile error, which is why it is
     * asserted rather than assumed.
     */
    #[Test]
    public function aFailingImportIsReportedToTheLogger(): void
    {
        $file = self::FIXTURES . 'MissingImport.yaml';

        $this->expectException(InvalidSeedDefinitionException::class);
        $this->expectExceptionCode(1787072804);

        $this->subject()->load($file, new ThrowOnErrorLogger($file), false);
    }

    private function subject(): SeedYamlFileLoaderInterface
    {
        $loader = $this->get(SeedYamlFileLoaderInterface::class);
        $this->assertInstanceOf(SeedYamlFileLoaderInterface::class, $loader);

        return $loader;
    }
}
