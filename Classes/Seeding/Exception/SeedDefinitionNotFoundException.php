<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Exception;

/**
 * Thrown when the file a seed definition was asked for cannot be reached at
 * all: the path does not resolve, names no file, or cannot be read.
 *
 * Kept apart from {@see InvalidSeedDefinitionException} because the two have
 * different causes and different fixes - a path that does not exist is a
 * mistake in the caller or in the discovery, a definition that does not
 * validate is a mistake in the definition.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class SeedDefinitionNotFoundException extends SeedingException {}
