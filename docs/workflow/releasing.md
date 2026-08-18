# Releasing

Two scripts in [`Build/Scripts/`](../../Build/Scripts) drive the release. Both
always operate on the repository root, no matter from where they are called, and
both show all options with `--help`.

## `setVersion.sh` — apply a version

Applies a version and its derived variants to every file carrying one: the
`COMPOSER_ROOT_VERSION` in `Build/Scripts/runTests.sh`, `extra.typo3/cms.version`
and `extra.branch-alias` in `composer.json`, the `version` in `ext_emconf.php`,
the `VERSION` file and — discovered dynamically, none has to exist — the
functional test [fixture extensions](../testing/fixture-extensions.md) below
`Tests/Functional/Fixtures/Extensions/`.

```bash
# Release version 1.2.0 (X.Y.Z, no branch-alias update).
Build/Scripts/setVersion.sh 1.2.0 release

# Next development version after it (X.Y.W-dev, branch-alias X.Y.x-dev).
Build/Scripts/setVersion.sh 1.2.1 post-release

# Force a plain development version, for example when branching.
Build/Scripts/setVersion.sh 1.3.0 dev

# Show every change without touching a file.
Build/Scripts/setVersion.sh 1.2.0 release --dry-run
```

The script only edits working-tree files; it performs no git or network
operations.

It reads and writes `composer.json` with **php**, not with `jq`, so it can also
be run through the container wrapper on a host that has neither:

```bash
Build/Scripts/runTests.sh -s setVersion -- 1.2.0 release
```

Everything after `--` is passed to the script unchanged, `--dry-run` included.
Both ways produce the same result — the wrapper only adds the container.

### `--source-branch`, and the key the alias is stored under

Development happens on one branch, `main`, and both scripts default
`--source-branch` to it:

```bash
SOURCE_BRANCH="main"
```

so nothing has to be passed here. Pass it explicitly only once a maintenance
branch exists and its release is driven from a checkout of another branch.

The key `extra.branch-alias` is stored under is **not** always
`dev-<source-branch>`. Composer derives a version from the branch name before it
consults the alias map, and a branch whose name looks like a version is
normalised to `<name>.x-dev`:

| Branch                    | Derived by composer | Alias key  |
|---------------------------|---------------------|------------|
| `main`                    | `dev-main`          | `dev-main` |
| `1`, were such a line cut | `1.x-dev`           | `1.x-dev`  |

`setVersion.sh` derives the key from the source branch, so this is not something
to get right by hand — but it is worth knowing, because getting it wrong is
**silent**. An alias keyed `dev-1` on a branch named `1` matches no reference
composer ever produces, so it is ignored: the branch does not provide the
version it claims to, and nothing reports it.

`release.sh` uses the source branch for more than the alias: it is the branch it
branches off, refreshes, targets pull requests at with `gh pr create --base`,
and tags. Run with the default from a maintenance branch, it would open the
release pull request against `main`.

## `release.sh` — orchestrate the release

Drives the full two-phase workflow for one release version: branch, apply the
release version, commit `[RELEASE] X.Y.Z`, push, open a pull request, wait for
the checks, merge, tag and push the tag — and afterwards the same for the next
development version with `[TASK] Set version X.Y.W`.

It has two independent safety gates:

```bash
# Print the whole plan, change nothing at all.
Build/Scripts/release.sh 1.2.0 --dry-run

# Run the local steps for real, but only PRINT every remote operation.
Build/Scripts/release.sh 1.2.0

# Actually publish: push, pull request, merge, tag.
Build/Scripts/release.sh 1.2.0 --execute
```

Without `--execute` no push, no pull request, no merge and no tag ever happens,
so a release can safely be rehearsed. `git` and the GitHub CLI (`gh`) have to be
available and authenticated for `--execute`.

Pushing the tag triggers the [`publish`](../../.github/workflows/publish.yml)
workflow, which builds the TER artifact and creates the GitHub release.

The tag has to match the version in `ext_emconf.php`, which is what
`setVersion.sh` keeps in sync: `tailor create-artefact` fails otherwise, on
purpose, so a release cannot disagree with the extension metadata.

Publishing that artifact to the TYPO3 Extension Repository is prepared in the
same workflow but **still commented out**: it needs the extension key registered
in the TER and owned by the token behind the `TYPO3_API_TOKEN` repository
secret, which it authenticates with. Enable the step once both exist. It runs
**after** the GitHub release, so an upload that fails — an expired token, a
version already published — leaves the release and its artifact in place to
retry against instead of losing both.

## Before releasing

- Both core versions green across the full [gate matrix](../development/quality-gates.md).
- Changelog entries for the version in place, see
  [Changelog and documentation](changelog-and-documentation.md).
- `Build/Scripts/runTests.sh -s renderDocumentation` passing.

## See also

- [Pull requests](pull-requests.md)
- [Commit messages](commit-messages.md)
