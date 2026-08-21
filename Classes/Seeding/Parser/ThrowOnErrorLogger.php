<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Parser;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The logger `SeedDefinitionParser` hands to `YamlFileLoader`, turning what the
 * loader reports back into an exception.
 *
 * `YamlFileLoader::processImports()` catches `ParseException`,
 * `YamlParseException` and `YamlFileLoadingException` around every single
 * import and reports them to its logger instead of letting them out
 * (unchanged in TYPO3 v12.4 and v13.4). For a site configuration that is a
 * reasonable trade - the site still loads, minus an optional include. For a
 * seed definition it is data loss: a typo in an `imports` resource means the
 * pages of that file are silently not seeded, and the import reports success.
 *
 * Raising the report back into an exception is therefore not a workaround but
 * the behaviour this parser needs, and it is the only injection point the
 * loader offers for it.
 *
 * Only error level and above is raised, so a future core version that logs
 * something informational from the loader does not turn a working definition
 * into a failure. The loader logs nothing else today.
 *
 * `#[Exclude]` is mandatory rather than decorative: this class is constructed
 * per parse with the name of the definition being read, and a container trying
 * to autowire that string argument would fail while compiling.
 *
 * It cannot be `readonly` as a class, because `AbstractLogger` is not.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final class ThrowOnErrorLogger extends AbstractLogger
{
    private const FAILING_LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
    ];

    public function __construct(
        private readonly string $source,
    ) {}

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!in_array($level, self::FAILING_LEVELS, true)) {
            return;
        }

        $previous = $context['exception'] ?? null;

        throw new InvalidSeedDefinitionException(
            sprintf('An import of the seed definition "%s" failed: %s', $this->source, $message),
            1787072804,
            $previous instanceof \Throwable ? $previous : null,
        );
    }
}
