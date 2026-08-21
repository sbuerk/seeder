<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Exception;

/**
 * Thrown when a seed definition was read but cannot be understood: it is not a
 * map, a required key is missing, a key has the wrong type, an identifier is
 * unusable or used twice, or one of its imports could not be loaded.
 *
 * Every rejection carries its own exception code, so a definition can be
 * pointed at the rule it violates rather than at "invalid".
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class InvalidSeedDefinitionException extends SeedingException {}
