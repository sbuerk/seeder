<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Exception;

/**
 * Thrown when no active package provides a seed set with the requested
 * identifier.
 *
 * Kept apart from {@see SeedDefinitionNotFoundException}: that one means a file
 * discovery pointed at is gone, this one means discovery never found the set at
 * all - most often because the providing extension is installed but not
 * activated, which is a different thing to tell the user.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class SeedSetNotFoundException extends SeedingException {}
