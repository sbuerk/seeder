<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Core12\Seeding\Parser;

use Psr\Log\LoggerInterface;
use SBUERK\Seeder\Seeding\Parser\SeedYamlFileLoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TYPO3 v12 implementation of {@see SeedYamlFileLoaderInterface}.
 *
 * The v12 `YamlFileLoader` does the same work as the v13 one, but it is missing
 * three things the seam promises. Each is answered here, and each is answered by
 * reproducing what v13 does rather than by inventing behaviour of our own - the
 * point of the seam is that a seed set behaves identically on both versions.
 *
 * ## 1. The logger arrives through the setter
 *
 * v12 `YamlFileLoader` implements `LoggerAwareInterface` and has no constructor
 * (12.4: YamlFileLoader.php:46-48). Passing the logger to `new` would compile
 * and be discarded, leaving `$this->logger` `null`, and a failing `imports`
 * entry would then fatal in `$this->logger->error()` (12.4:
 * YamlFileLoader.php:171) instead of being raised by
 * {@see \SBUERK\Seeder\Seeding\Parser\ThrowOnErrorLogger}.
 *
 * ## 2. An empty file is answered before the loader sees it
 *
 * `ALLOW_EMPTY_FILE` is v13. On v12 a file parsing to `null` raises
 * `YamlParseException` 1497332874 (12.4: YamlFileLoader.php:85-90), which for
 * the optional `settings.yaml` of a site template is the wrong answer - v13
 * hands back `[]` and the seeder skips the file.
 *
 * The check is done up front rather than by catching that exception, because the
 * v12 message covers two cases the v13 flag distinguishes: a file parsing to
 * `null` *and* a file parsing to a scalar. Only the first is "empty"; a
 * `settings.yaml` holding a bare string is a broken template on v13 as well and
 * has to stay an error. Reading and parsing the file twice is the price, it is
 * paid only for a file the caller declared optional, and
 * {@see \SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationSeeder} is the only
 * caller that does.
 *
 * `getFileContents()` of the loader is mirrored exactly, empty string for an
 * unreadable file included (12.4: YamlFileLoader.php:107-110), so that a missing
 * or unreadable optional file lands on the same answer here as it does on v13.
 *
 * **What this cannot reproduce:** v13 passes the flag down into the files an
 * `imports` pulls in, so an empty *imported* file is `[]` there. Here only the
 * named file is pre-checked; an empty imported file still fails inside
 * `processImports()`, is reported to the logger and is therefore raised. That is
 * the one behaviour of `ALLOW_EMPTY_FILE` this class does not have, and closing
 * it would mean reimplementing `processImports()`.
 *
 * ## 3. A syntax error becomes the exception the callers catch
 *
 * v13 wraps the `Symfony\Component\Yaml\Exception\ParseException` of the
 * top level file into a `YamlParseException` with code 1740817000 (13.4:
 * YamlFileLoader.php:80-87). v12 has no such wrapping and lets the raw
 * `ParseException` escape `load()` - past the `catch (YamlFileLoadingException|
 * YamlParseException)` of both call sites, which would turn a mistyped seed
 * definition from a reported error into an uncaught exception. The message and
 * the code below are v13's verbatim, so the wrapped message a user finally reads
 * is the same on both versions. Only the top level file is affected: v12
 * `processImports()` catches a `ParseException` of an imported file itself and
 * reports it to the logger.
 *
 * Only the `Core12/` directory is registered in the dependency injection
 * container when running on TYPO3 v12, see `Configuration/Services.php`.
 * `#[AsAlias]` makes this class the default implementation of the interface, so
 * consumers type hint the interface and receive the implementation matching the
 * running TYPO3 version. The alias is public because the functional test
 * fetches it from the container to assert that wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2, and this branch
 * supports PHP 8.1 for TYPO3 v12 - there is nothing to declare `readonly` here
 * anyway, because the service is stateless and has no dependencies. See
 * `docs/architecture/class-design.md`.
 *
 * @todo Remove together with {@see SeedYamlFileLoaderInterface} when TYPO3 v12
 *       support is dropped.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsAlias(id: SeedYamlFileLoaderInterface::class, public: true)]
final class SeedYamlFileLoader implements SeedYamlFileLoaderInterface
{
    /**
     * The code TYPO3 v13 raises a top level syntax error under. Repeated rather
     * than imported: it is a literal inside the v13 `YamlFileLoader` and does
     * not exist as a constant anywhere.
     */
    private const SYNTAX_ERROR_CODE = 1740817000;

    public function load(string $fileName, LoggerInterface $importFailureLogger, bool $allowEmptyFile): array
    {
        if ($allowEmptyFile && $this->parsesToNothing($fileName)) {
            return [];
        }

        $loader = new YamlFileLoader();
        $loader->setLogger($importFailureLogger);

        try {
            /** @var array<string, mixed> $content */
            $content = $loader->load($fileName, YamlFileLoader::PROCESS_IMPORTS);
        } catch (ParseException $exception) {
            throw new YamlParseException(
                'YAML file "' . $fileName . '" has syntax errors: ' . $exception->getMessage(),
                self::SYNTAX_ERROR_CODE,
                $exception,
            );
        }

        return $content;
    }

    /**
     * Whether the file holds nothing a YAML parser can turn into a value, which
     * is the one case TYPO3 v13 answers with an empty array.
     *
     * A path the loader cannot resolve at all is deliberately **not** "nothing".
     * `getFileAbsFileName()` answering an empty string is what makes the loader
     * raise `YamlFileLoadingException`, and that has to stay the answer.
     *
     * A file that resolves but cannot be read is the other way round, and on
     * purpose: `getFileContents()` of the loader answers it with an empty string
     * (12.4: YamlFileLoader.php:107-110), an empty string parses to `null`, and
     * v13 therefore hands back `[]` for it under `ALLOW_EMPTY_FILE`. The quirk
     * is reproduced rather than corrected, so that both versions answer alike.
     *
     * A syntax error is not "nothing" either. It is left to the loader, which
     * raises it, and {@see self::load()} turns it into what v13 raises.
     */
    private function parsesToNothing(string $fileName): bool
    {
        $absoluteFileName = GeneralUtility::getFileAbsFileName($fileName);
        if ($absoluteFileName === '') {
            return false;
        }

        $content = is_readable($absoluteFileName) ? (string)file_get_contents($absoluteFileName) : '';

        try {
            return Yaml::parse($content) === null;
        } catch (ParseException) {
            return false;
        }
    }
}
