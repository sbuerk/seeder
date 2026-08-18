<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Exception;

/**
 * Base class of every failure this extension raises while reading, validating
 * or writing a seed definition.
 *
 * It is abstract on purpose: a caller catches this class to catch everything
 * the seeder can fail with, and every failure still has to say which kind it
 * is. A `catch (SeedingException)` therefore stays correct when a later
 * subclass is added.
 *
 * It is neither `final` nor `readonly`, because it is a base class and because
 * `\RuntimeException` it extends is not readonly either.
 *
 * @internal Part of the seeding implementation, not public API.
 */
abstract class SeedingException extends \RuntimeException {}
