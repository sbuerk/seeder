<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Scenario;

use SBUERK\Seeder\Tests\Unit\Seeding\Scenario\ScenarioComposerTest;

/**
 * Shadows `is_readable()` for {@see ScenarioComposer} - and for one path only,
 * everything else is answered by the real function.
 *
 * {@see ScenarioComposerTest::aScenarioThatCannotBeReadIsRejected()} needs a
 * file that exists, is a file, and cannot be read. That state cannot be
 * produced where the gates run: `runTests.sh` starts the PHP container without
 * `--user` under podman, so the test process is root, and `is_readable()` asks
 * `access(2)` with the **real** uid, which root always passes. `chmod(0000)` is
 * ignored for root, and dropping the effective uid with `posix_seteuid()` does
 * not help because `access(2)` looks at the real one. Under docker the same
 * command does pass `--user`, so a permission based fixture would pass in CI
 * and fail on the maintainer's machine, which is worse than not covering the
 * guard at all.
 *
 * PHP resolves an unqualified call in a namespaced file against that namespace
 * first, which is what makes this work - and what makes it fail loudly rather
 * than silently: were the composer to call `\is_readable()` fully qualified,
 * this function would no longer be reached and the test would go red.
 */
function is_readable(string $filename): bool
{
    if ($filename === ScenarioComposerTest::UNREADABLE_SCENARIO) {
        return false;
    }

    return \is_readable($filename);
}
