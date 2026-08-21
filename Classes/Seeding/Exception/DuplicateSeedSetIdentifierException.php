<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Exception;

/**
 * Thrown when more than one active package provides a seed set with the same
 * identifier.
 *
 * A collision is never resolved by letting the first or the last provider win.
 * Both of them declared the identifier on purpose, only one of them can have
 * meant it, and picking one silently means an integrator seeds something other
 * than what they asked for without anything saying so. The message therefore
 * names every provider, and the caller decides what to do about it - which for
 * `data-factory:list` is to report and exit non-zero, and for a lookup by identifier
 * is to refuse.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class DuplicateSeedSetIdentifierException extends SeedingException {}
