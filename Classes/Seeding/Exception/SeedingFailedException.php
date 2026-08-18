<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Exception;

/**
 * Thrown when a seed definition is understood but cannot be written: the
 * backend user is not an admin, the definition has nothing to write, or
 * `DataHandler` logged an error while writing it.
 *
 * Kept apart from {@see InvalidSeedDefinitionException} because the definition
 * is not what is wrong here - the same definition may write without a word on
 * the next instance. The distinction is the same one a caller needs: a broken
 * definition is fixed in the package shipping it, a failed write is fixed in
 * the installation it was written into.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class SeedingFailedException extends SeedingException {}
