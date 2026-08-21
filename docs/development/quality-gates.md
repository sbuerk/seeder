# Quality gates

The same gates run locally and in the GitHub Actions workflows for TYPO3 v12
and v13. Every one of them must pass for both core versions, each after the
matching `composerUpdate` — see [Dual core setup](dual-core-setup.md).

## The gates

```bash
# Coding guidelines: fix in place ...
Build/Scripts/runTests.sh -s cgl

# ... or check only, without changing files, as CI does.
Build/Scripts/runTests.sh -s cgl -n

# Static analysis (PHPStan, level 8).
Build/Scripts/runTests.sh -s phpstan

# PHP linting.
Build/Scripts/runTests.sh -s lintPhp

# Validate the root composer.json.
Build/Scripts/runTests.sh -s composerValidate

# Ensure UTF-8 files do not contain a BOM.
Build/Scripts/runTests.sh -s checkBom

# Find duplicate or missing exception codes.
Build/Scripts/runTests.sh -s checkExceptionCodes

# Ensure markdown tables are formatted ("-- --fix" formats them).
Build/Scripts/runTests.sh -s checkMarkdownTables

# Ensure test methods do not start with "test".
Build/Scripts/runTests.sh -s checkTestMethodsPrefix
```

| Gate                     | Configuration                                                                                        | Core version dependent |
|--------------------------|------------------------------------------------------------------------------------------------------|------------------------|
| `cgl`                    | [`Build/php-cs-fixer/config.php`](../../Build/php-cs-fixer/config.php)                               | no                     |
| `phpstan`                | `Build/phpstan/Core12/`, `Build/phpstan/Core13/`                                                     | **yes**                |
| `lintPhp`                | —                                                                                                    | no                     |
| `composerValidate`       | `composer.json`                                                                                      | no                     |
| `checkBom`               | [`Build/Scripts/checkUtf8Bom.sh`](../../Build/Scripts/checkUtf8Bom.sh)                               | no                     |
| `checkExceptionCodes`    | [`Build/Scripts/duplicateExceptionCodeCheck.sh`](../../Build/Scripts/duplicateExceptionCodeCheck.sh) | no                     |
| `checkMarkdownTables`    | [`Build/Scripts/checkMarkdownTables.php`](../../Build/Scripts/checkMarkdownTables.php)               | no                     |
| `checkTestMethodsPrefix` | [`Build/Scripts/testMethodPrefixChecker.php`](../../Build/Scripts/testMethodPrefixChecker.php)       | no                     |

## PHPStan

PHPStan runs at **level 8** and is configured **per core version**. Each
configuration analyses only its own core version aware sources —
`Build/phpstan/Core12/phpstan.neon` lists `Classes`, `Configuration`, `Core12`
and `Tests`, and excludes `Tests/*/Core13/*`; `Build/phpstan/Core13/phpstan.neon`
is the mirror image. Analysing the sources of the other core version would report
false positives about API that does not exist there.

The two configurations also run **different PHPStan majors** — 1.12 on the v12
leg, 2.x on the v13 leg, because `saschaegerer/phpstan-typo3` has no v12-capable
release above 1.10.2 and that one requires PHPStan 1.x. A finding fixed for one
major is therefore not automatically gone on the other, and both baselines are
separate files for that reason as much as for the API difference. See
[the dependency sets](dual-core-setup.md#the-dependency-sets-differ-by-more-than-the-core).

Both PHPStan suites pass arguments after `--` through to the tool and do not
force an output format, so `-- --error-format=json` or `-- --no-progress` is the
caller's choice.

When PHPStan reports pre-existing findings that cannot be fixed right away, the
baseline can be regenerated per core version — but **prefer fixing the finding**:

```bash
Build/Scripts/runTests.sh -t 12 -s phpstanGenerateBaseline
Build/Scripts/runTests.sh -t 13 -s phpstanGenerateBaseline
```

A growing baseline is a defect, not a configuration. Regenerating it to make a
new finding disappear hides the very problem the gate exists for. Both baselines
are currently empty, and that is the state to keep them in. The two
`@phpstan-ignore` annotations this repository does accept are documented, scoped
and justified in
[Class design](../architecture/class-design.md#the-two-phpstan-ignores-on-injected-readonly-properties);
nothing else may be silenced.

### The one `ignoreErrors` entry, and why it is not in the baseline

`Build/phpstan/Core12/phpstan.neon` carries two `ignoreErrors` entries scoped to
a single file, and they are **deliberately in the configuration rather than in
the baseline** — the file states the full reasoning, this is the summary.

`QueryResultInterface` declares two template types on both supported core
versions. `saschaegerer/phpstan-typo3` 2.x spells it that way as well, so the v13
leg analyses the annotation as written and is green. Only the 1.x line — the
newest one supporting TYPO3 v12, because 2.0.0 and up require
`typo3/cms-core: ^13.4.3` — ships a **stub** declaring a single type parameter,
and a stub wins over the real class docblock. Correcting it with a stub of our
own does not work: its sibling stubs name the interface with one argument too,
PHPStan analyses vendor stubs rather than replacing them, and each correction
moves the error into the next file.

Keeping it in the configuration rather than the baseline is what makes it
self-removing: `reportUnmatchedIgnoredErrors` is left at its default, so the
moment the v12 dependency set goes and the 2.x stub is the only reachable one,
the entry stops matching and PHPStan fails until it is deleted. A baseline entry
would simply be regenerated away and nobody would notice.

## Exception codes

TYPO3 exception codes are unix timestamps taken at the moment the exception is
written, and must be unique across the code base. `checkExceptionCodes` finds
both duplicates and exceptions thrown without a code.

## Test method naming

Test methods must **not** be prefixed with `test`; use the PHPUnit `#[Test]`
attribute and a descriptive method name instead. `checkTestMethodsPrefix`
enforces this:

```php
#[Test]
public function getExtensionKeyReturnsExtensionKey(): void
{
    // ...
}
```

## Markdown table formatting

`checkMarkdownTables` verifies that every table in `./*.md` and `docs/` is
formatted — cells padded so the pipes line up, separator row as wide as its
column.

It is the second gate that can fix what it finds, and the two are inverted
towards each other: `cgl` **fixes** by default and only checks with `-n`, while
`checkMarkdownTables` **checks** by default and only fixes when asked:

```bash
Build/Scripts/runTests.sh -s checkMarkdownTables
Build/Scripts/runTests.sh -s checkMarkdownTables -- --fix
```

The gate exists because the defect is invisible: an unformatted table renders
exactly like a formatted one, so it survives review and only shows up as noise
in the diff of the *next* change to that table. Alignment markers (`:---`,
`---:`, `:---:`) are preserved, and tables inside fenced code blocks are left
alone so a page can show an unformatted one as an example.

Git-ignored files are skipped, and so are the symlinked agent instruction files,
which are checked through their target.
→ [Documentation conventions](../Index.md#conventions-of-this-documentation)

## Continuous integration

[`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) runs everything for
a pull request, with the core version as a **matrix dimension** rather than one
workflow per core version. Every step calls `Build/Scripts/runTests.sh`, so a
gate behaves identically in CI and on a developer machine.

The jobs are staged, cheapest and most likely to fail first:

```
quality ─┐
phpstan ─┼─> unit ─> functional (SQLite) ─> functional (MySQL, MariaDB, Postgres)
lint    ─┘

documentation   (independent, for fast feedback on documentation changes)
```

| Job                 | Matrix                                                      | Runs                                                                            |
|---------------------|-------------------------------------------------------------|---------------------------------------------------------------------------------|
| `quality`           | PHP 8.2, TYPO3 v12 only — 1 job                             | The gates that inspect source files                                             |
| `phpstan`           | PHP 8.2 × both core versions — 2 jobs                       | The one gate configured per core version                                        |
| `lint`              | all 4 PHP versions × both core versions, minus one — 7 jobs | `lintPhp`                                                                       |
| `unit`              | edge PHP versions × both core versions — 4 jobs             | `unit`, `unitRandom`                                                            |
| `functional-sqlite` | edge PHP versions × both core versions — 4 jobs             | `functional -d sqlite`                                                          |
| `functional-dbms`   | edge PHP × both cores × 4 DBMS configurations — 16          | `functional` against each database                                              |
| `documentation`     | — 1 job                                                     | `renderDocumentation`, uploads the rendered result and the pull request context |

Thirty-five jobs in total, and every one of them but `documentation` starts with
its own `composerUpdate`. The four DBMS configurations are three engines —
MariaDB is matrixed twice, at 10.4 and 10.6, because its two lines differ in
what they accept.

`documentation` has no `needs` of its own: it runs immediately and in parallel
with the staged chain, so a change that only touches `Documentation/` is
answered without waiting for the test matrix.

"Edge PHP versions" is **not** the same pair on both legs, because the PHP
matrix is not square: the lower edge is 8.1 on v12 and 8.2 on v13, the upper
edge is 8.4 on both. `lint` runs all four PHP versions and excludes the one pair
that cannot resolve, `(v13, PHP 8.1)` — `typo3/cms-core` 13.4 requires PHP
`^8.2`. PHP 8.1 is therefore exercised in `lint` and `unit` and nowhere else,
which is exactly enough to catch a `readonly class` or a constant in a trait.
→ [The PHP dimension is not square](dual-core-setup.md#the-php-dimension-is-not-square)

Two decisions are worth knowing:

- **The DBMS matrix is gated on SQLite.** It is the expensive part, sixteen jobs
  each starting a database container. Running it only after the same tests pass
  on SQLite for both core versions means a defect that is not DBMS specific is
  reported by four jobs instead of twenty.
- **The version independent gates run once, not per core version and PHP
  version.** They inspect source files rather than the installed core, so
  repeating them tests the same files again. Only `phpstan` is genuinely per
  core version.

### Why CI passes `-b docker`

Every `runTests.sh` invocation in the workflows passes `-b docker`. The script
itself prefers **podman** and only falls back to docker, and that default is
right and stays: podman-only machines are exactly what it is built for.
GitHub hosted runners happen to ship both, and that is the single place this
repository meets a broken combination — their podman/crun pairing has been
observed to abort the *first* container start of a job with

```
Error: OCI runtime error: crun: unknown version specified
```

and exit code 126. It is intermittent, hits any job, and is caused by neither
the runner image nor the testing image changing; a rerun onto another host
clears it. Selecting docker in the workflow avoids crun entirely and leaves the
script default and every local run untouched. **Drop the flag once GitHub stops
producing the mismatch** — it is a workaround for their fleet, not a property of
this repository.

Picking docker there has a consequence worth knowing, and it is why the SQLite
functional mount carries `mode=1777` in addition to `uid`/`gid`: docker runs the
container as `--user $(id -u)` with group 0, and on a runner the tmpfs comes up
`root:root` mode `0755`, so neither the owner nor the group bits apply and every
test fails with `unable to open database file`. Rootless podman is root inside
its user namespace and never saw it.

### The composer cache

The composer **download cache** is shared per PHP and core version, so the
repeated `composerUpdate` resolves against a warm cache instead of downloading
the dependency set again in every job.

It lives in `.cache/` at the repository root, and that location is load-bearing:
`runTests.sh -s composerUpdate` starts with `rm -rf .Build`, so a cache kept
under `.Build/` would be deleted before composer ever reads it. The job would
still save it on the way out, so the cache step looks healthy in the run log
while never once being used — locally the same applies, and every dependency
install re-downloads the whole set. The phpstan result cache sits next to it for
the same reason. **Do not move either back under `.Build/`.**

What is deliberately *not* symmetric is who keeps it: `composerUpdate` deletes
`.cache/` **locally** and keeps it in CI, guarded by the same `IS_CORE_CI` the
rest of the script uses. The two contexts differ in what the cache can collide
with. A CI job starts from an empty checkout, installs once and ends; a working
copy switches between the core versions for months, and that switch exchanges
the **major version of four packages at once**: `phpstan/phpstan`,
`phpstan/phpstan-phpunit`, `saschaegerer/phpstan-typo3` and
`nikic/php-parser` — with `sbuerk/typo3-site-based-test-trait` as a fifth. The
table in [Dual core setup](dual-core-setup.md#the-dependency-sets-differ-by-more-than-the-core)
lists what each `-t` value resolves to.

The local clear is a **precaution rather than a fix for a reproduced defect**.
Switching back and forth four times does not fail today; what it buys is that an
install never resolves against a cache belonging to the other major, which is a
class of failure that costs far more to recognize than the one download it
costs to avoid — of a dependency set that was going to be replaced anyway.

CI is the safety net, not the first run — the gates are cheap enough to run
locally before pushing.

### Commenting on a pull request from a fork

[`.github/workflows/pr-comment.yml`](../../.github/workflows/pr-comment.yml)
posts the link to the rendered documentation as a single comment, updated in
place on every push.

It is a **separate workflow on the `workflow_run` event**, and it has to be. A
pull request from a fork gets a read-only `GITHUB_TOKEN` and no secrets, so a
comment step inside `ci.yml` would work for branches in this repository and
silently fail for exactly the external contributors it is meant to serve.
`workflow_run` fires when `ci.yml` finishes, runs in the context of the default
branch of this repository rather than the fork, and its token may write even
though the token of the run that triggered it could not. No pull request code is
checked out or executed there, which is what makes the write permission safe —
and it is why `pull_request_target` is *not* used.

Two consequences:

1. The file only takes effect once it is **on the default branch**. Changing it
   in a pull request does not change the behaviour of that pull request.
2. `github.event.workflow_run.pull_requests` is empty for a fork, so the pull
   request number travels in the `pull-request-context` artifact written by
   `ci.yml`.

## See also

- [Development environment](environment.md)
- [Dual core setup](dual-core-setup.md)
- [Testing](../testing/Index.md)
- [Pull requests](../workflow/pull-requests.md)
