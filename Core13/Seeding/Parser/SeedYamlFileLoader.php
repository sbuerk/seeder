<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Core13\Seeding\Parser;

use Psr\Log\LoggerInterface;
use SBUERK\DataFactory\Seeding\Parser\SeedYamlFileLoaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;

/**
 * TYPO3 v13 implementation of {@see SeedYamlFileLoaderInterface}.
 *
 * Both version differences the seam exists for are answered by the core class
 * itself here: the logger is a constructor argument (13.4:
 * YamlFileLoader.php:53-55), and `ALLOW_EMPTY_FILE` turns a file that parses to
 * `null` into an empty array before the "does not contain data" check runs
 * (13.4: YamlFileLoader.php:89-99). The flag also propagates into the files an
 * `imports` pulls in, which the v12 implementation cannot reproduce - see there.
 *
 * The loader is constructed rather than injected although v13 registers it as a
 * container service: the logger is per file, because
 * {@see \SBUERK\DataFactory\Seeding\Parser\ThrowOnErrorLogger} carries the name of
 * the file being read into its message. The container instance is wired with
 * the system logger and would swallow exactly what this extension needs raised.
 *
 * Only the `Core13/` directory is registered in the dependency injection
 * container when running on TYPO3 v13, see `Configuration/Services.php`.
 * `#[AsAlias]` makes this class the default implementation of the interface, so
 * consumers type hint the interface and receive the implementation matching the
 * running TYPO3 version. The alias is public because the functional test
 * fetches it from the container to assert that wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2 and this branch
 * supports PHP 8.1 for TYPO3 v12; there is nothing to declare `readonly` here
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
    public function load(string $fileName, LoggerInterface $importFailureLogger, bool $allowEmptyFile): array
    {
        $flags = YamlFileLoader::PROCESS_IMPORTS;
        if ($allowEmptyFile) {
            $flags |= YamlFileLoader::ALLOW_EMPTY_FILE;
        }

        /** @var array<string, mixed> $content */
        $content = (new YamlFileLoader($importFailureLogger))->load($fileName, $flags);

        return $content;
    }
}
