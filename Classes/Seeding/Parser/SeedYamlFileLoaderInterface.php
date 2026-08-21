<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Parser;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlFileLoadingException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;

/**
 * Reads one YAML file the way this extension needs it read: `imports` followed
 * and inlined, placeholders left alone, and a failing import raised instead of
 * logged.
 *
 * Every YAML file this extension reads - the `config.yml` of a seed set and the
 * `config.yaml` / `settings.yaml` of a site template - goes through
 * `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader`, the loader the core
 * reads its own site configurations with, so that an `imports:` behaves exactly
 * as an integrator expects it to and this extension requires nothing beyond
 * `typo3/cms-core`. Three properties of that call are fixed rather than
 * arguments, because nothing else would be correct here:
 *
 * - **`imports` are processed.** Following them inlines the imported content
 *   into the result, which is what a descriptor split over several files needs
 *   and what a seeded site configuration needs: an `imports` key kept verbatim
 *   would point at paths relative to the template directory and break the
 *   moment the file lands in `config/sites/`.
 * - **Placeholders are not.** A `%…%` fragment naming a key of the descriptor
 *   would be substituted with that key's value, and a title or a description is
 *   content that has to arrive as it was written. In a site configuration
 *   `%env(…)%` is meant to be resolved by the instance every time it reads the
 *   file, so resolving it here would bake this machine's environment into the
 *   seed.
 * - **A failing import raises.** The loader catches the failure of a single
 *   import and reports it to its logger, which is why the logger is an argument
 *   of this method - see {@see ThrowOnErrorLogger}.
 *
 * ## Why this is a core version aware seam
 *
 * Two unrelated differences in the same core class, neither of them visible as
 * a compile error:
 *
 * - **The logger.** On TYPO3 v13 `YamlFileLoader` is a `readonly class` taking
 *   its `LoggerInterface` as a constructor argument. On v12.4 it implements
 *   `LoggerAwareInterface` with `LoggerAwareTrait` and has **no constructor**
 *   (12.4: YamlFileLoader.php:46-48). PHP discards arguments passed to a class
 *   without a constructor, so `new YamlFileLoader($logger)` compiles, runs, and
 *   silently leaves the logger `null` - after which a failing import is not
 *   raised but fatals in `$this->logger->error()` (12.4:
 *   YamlFileLoader.php:171). On v12 the logger has to arrive through
 *   `setLogger()`.
 * - **The empty file.** `YamlFileLoader::ALLOW_EMPTY_FILE` is v13 (13.4:
 *   YamlFileLoader.php:51). v12 has `PROCESS_PLACEHOLDERS` and
 *   `PROCESS_IMPORTS` and nothing else, so naming the constant there is an
 *   `Error`, and an empty file raises `YamlParseException` 1497332874 instead.
 *
 * Both are code, not configuration, so they are split into one implementation
 * per core version below `Core12/` and `Core13/` rather than decided by a
 * conditional - see `docs/architecture/core-version-aware-code.md`.
 *
 * @todo Delete this interface together with both implementations as soon as
 *       TYPO3 v12 support is dropped, and construct `YamlFileLoader` at the two
 *       call sites again: from v13 on the constructor takes the logger and
 *       `ALLOW_EMPTY_FILE` exists, and there is nothing left to abstract over.
 *
 * @internal Part of the seeding implementation, not public API.
 */
interface SeedYamlFileLoaderInterface
{
    /**
     * @param string $fileName Absolute, or an `EXT:` path - both call sites
     *        resolve their path before handing it over, and the loader resolves
     *        whatever is left through `GeneralUtility::getFileAbsFileName()`.
     * @param LoggerInterface $importFailureLogger What the loader reports a
     *        failing `imports` entry to. {@see ThrowOnErrorLogger} turns that
     *        report back into an exception, which is the only reason this is an
     *        argument at all.
     * @param bool $allowEmptyFile Whether a file parsing to nothing is an empty
     *        result rather than an error. It is for the optional
     *        `settings.yaml` of a site template, and it is not for a `config.yml`,
     *        where an empty file means the set describes nothing.
     * @return array<string, mixed>
     * @throws YamlFileLoadingException The file could not be read at all.
     * @throws YamlParseException The file is not YAML, or holds no map and
     *         `$allowEmptyFile` is `false`.
     */
    public function load(string $fileName, LoggerInterface $importFailureLogger, bool $allowEmptyFile): array;
}
