# Releasing

Two scripts in [`Build/Scripts/`](../../Build/Scripts) drive the release. Both
always operate on the repository root, no matter from where they are called, and
both show all options with `--help`.

The split between them is strict: `setVersion.sh` writes version numbers into
working-tree files and performs no git and no network operation at all;
`release.sh` owns every git and GitHub step and calls `setVersion.sh` for the
writing.

## The two lines, and where the scripts run

This branch line is **`1`** — the branch carrying TYPO3 v12.4 + v13.4, which
releases the 1.x line. `main` carries the 2.x line, TYPO3 v13.4 + v14.3. The two
lines are released independently, and neither branch is merged into the other.

Both scripts default `--source-branch` to the branch they sit on — `1` here,
`main` on `main`:

```bash
SOURCE_BRANCH="1"
```

so releasing a line from a checkout of its own branch needs no `--source-branch`
at all.

> [!IMPORTANT]
> **Run the scripts from a checkout of the branch being released.**
> `release.sh` runs `git checkout <source-branch>` while it is itself executing,
> and bash reads a script incrementally, seeking by byte offset. The two copies
> of `release.sh` differ in length — `"main"` versus `"1"`, in the help header
> and in the argument parsing, both of them *before* that checkout — so
> releasing `main` from a `1` checkout swaps `release.sh` and `setVersion.sh`
> underneath the running interpreter and shifts every offset after that point.
> `--source-branch` exists for the branch-alias key and for `setVersion.sh`,
> which touches no git state. It is not a way to release the other line from
> here.

## `setVersion.sh` — apply a version

Applies a version and its derived variants to every file carrying one:

| File                                     | Written                                                                                           |
|------------------------------------------|---------------------------------------------------------------------------------------------------|
| `Build/Scripts/runTests.sh`              | `COMPOSER_ROOT_VERSION`                                                                           |
| `composer.json`                          | `extra.typo3/cms.version`, and `extra.branch-alias` — the latter left untouched for a release     |
| `ext_emconf.php`                         | `version`, never `-dev` suffixed                                                                  |
| `VERSION`                                | the same string as `COMPOSER_ROOT_VERSION`                                                        |
| `Tests/Functional/Fixtures/Extensions/*` | a fixture `ext_emconf.php` version, and a `require`/`require-dev` constraint naming the extension |

The fixture pass is discovered dynamically and nothing has to exist for it. As
things stand it writes **nothing**: no
[fixture extension](../testing/fixture-extensions.md) carries an
`ext_emconf.php`, and none requires the extension itself. A release therefore
changes exactly the first four files, and a diff showing only those is correct.

`ext_emconf.php` never carries the `-dev` suffix because
`tailor create-artefact` compares the tag against it — see
[After the tag](#after-the-tag-the-publish-workflow).

```bash
# Release version 1.2.0 (X.Y.Z, no branch-alias update).
Build/Scripts/setVersion.sh 1.2.0 release

# Next development version after it (X.Y.W-dev, branch-alias X.Y.x-dev).
Build/Scripts/setVersion.sh 1.2.1 post-release

# Force a plain development version. Identical in effect to "post-release" —
# the difference is intent, not behaviour. Name the branch when cutting a new
# one or when raising the minor, because the alias key is derived from it.
Build/Scripts/setVersion.sh 1.1.0 dev --source-branch=1
Build/Scripts/setVersion.sh 3.0.0 dev --source-branch=2

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

The key `extra.branch-alias` is stored under is **not** always
`dev-<source-branch>`. Composer derives a version from the branch name before it
consults the alias map, and a branch whose name looks like a version is
normalised to `<name>.x-dev`:

| Branch | Derived by composer | Alias key  |
|--------|---------------------|------------|
| `main` | `dev-main`          | `dev-main` |
| `1`    | `1.x-dev`           | `1.x-dev`  |

That is why `composer.json` on this branch carries `"1.x-dev": "1.0.x-dev"` and
not `"dev-1"`, while the one on `main` carries `"dev-main": "2.0.x-dev"`.
`setVersion.sh` derives the key from the source branch, so this is not something
to get right by hand — but it is worth knowing, because getting it wrong is
**silent**. An alias keyed `dev-1` on a branch named `1` matches no reference
composer ever produces, so it is ignored: the branch does not provide the
version it claims to, and nothing reports it.

The alias map is **replaced**, not merged, so a stale key from a renamed branch
cannot linger.

`release.sh` uses the source branch for more than the alias: it is the branch it
branches off, refreshes, targets pull requests at with `gh pr create --base`,
and tags. Passing `--source-branch=main` from a `1` checkout is therefore *not*
the same as working on `main` — see the note in
[The two lines](#the-two-lines-and-where-the-scripts-run).

## `release.sh` — orchestrate the release

Drives the full two-phase workflow for one release version:

| Phase | Branch              | Commit                     | Then                                                                                                                                          |
|-------|---------------------|----------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | `release-X.Y.Z`     | `[RELEASE] X.Y.Z`          | push, pull request against the source branch, watch the checks, admin rebase-merge, fast-forward the source branch, tag `X.Y.Z`, push the tag |
| 2     | `set-version-X.Y.W` | `[TASK] Set version X.Y.W` | push, pull request, watch the checks, admin rebase-merge                                                                                      |

`W` is `Z + 1`: a release is followed by the next **patch** development
version on the same line, which is what a released branch is next expected to
produce — see
[The next development version](#the-next-development-version). The tag is a
plain lightweight tag, created on the source branch after the release pull
request has been merged and the local source branch fast-forwarded onto it.

That is a deliberate choice rather than an oversight, and it is made once,
because a published tag cannot be converted without force moving it:

- **Lightweight** — `git tag X.Y.Z` — is a ref and nothing else. It carries no
  tagger, no date and no message of its own, so "when was 1.0.0 released" is
  answered by the commit it points at rather than by the tag.
- **Annotated** — `git tag -a -m …` — would carry all three. It needs no key.
  The only thing this pipeline would gain is that `git describe` finds the tag
  without `--tags`, which nothing here does.
- **Signed** — `git tag -s` — is an annotated tag plus a GPG or SSH signature.
  It needs a signing key on whichever machine cuts the release and a published
  public key for anyone to verify it. That is infrastructure this project does
  not have, and requiring it of a releaser buys nothing while the release
  artefacts are built and published by GitHub Actions rather than uploaded by
  hand.

Nothing downstream reads the difference: `publish.yml` matches on the tag
**name**, and `tailor create-artefact` takes the version from
:file:`ext_emconf.php`. Switching to annotated tags later is a one line change
to `release.sh` and applies to the next tag, not to the ones already out.

### The three modes

There are two independent gates, and therefore three modes. The middle one is
the one that is easy to misread:

| Invocation                                 | Local steps           | Remote steps         | Leaves behind                                            |
|--------------------------------------------|-----------------------|----------------------|----------------------------------------------------------|
| `Build/Scripts/release.sh 1.2.0 --dry-run` | printed               | printed              | nothing at all                                           |
| `Build/Scripts/release.sh 1.2.0`           | **executed for real** | printed, `[skipped]` | two local branches and a checkout on `set-version-X.Y.W` |
| `Build/Scripts/release.sh 1.2.0 --execute` | executed              | executed             | the release                                              |

**`--dry-run` changes nothing.** Every step, local and remote, is printed, and
`setVersion.sh` is invoked with `--dry-run` forwarded, so not a byte is written.
This is the mode to read the plan in, and the only rehearsal worth running
before a real release.

**The bare mode runs every *local* step for real.** It checks out the source
branch, creates `release-X.Y.Z`, rewrites the version files, commits
`[RELEASE] X.Y.Z`, then creates `set-version-X.Y.W` and commits
`[TASK] Set version X.Y.W`. Only push, pull request, checks, merge, tag and tag
push are printed instead of run — the remote is never touched and no tag is
created, and the source branch itself is never modified. But the working copy is
left on `set-version-X.Y.W` with two extra local branches, and the **next run
fails**: `git checkout -b release-X.Y.Z` refuses an existing branch, and under
`set -euo pipefail` the script aborts there, after the pre-flight has already
printed "ok". Clean up before the real run:

```bash
git checkout <source-branch>
git branch -D release-X.Y.Z set-version-X.Y.W
```

Two more consequences of the bare mode. It does **not** fetch — `git fetch` and
`git pull --rebase` are remote operations and are skipped — so it rehearses
against the local state of the source branch. And a dirty working tree is only
warned about, while phase 1 commits with `git add -A`, so anything untracked and
not git-ignored lands in the rehearsal commit.

### The pre-flight, and the one hole in it

Before anything happens, `release.sh` checks three things:

| Check                      | Behaviour                                                                        |
|----------------------------|----------------------------------------------------------------------------------|
| Inside a git work tree     | Fatal otherwise, in every mode.                                                  |
| Tag `X.Y.Z` does not exist | Fatal otherwise, in every mode — **local refs only**.                            |
| Working tree clean         | Fatal with `--execute`; a warning otherwise, so the flow can still be rehearsed. |

The tag check reads local refs, and the fetch runs *after* it and only with
`--execute`. A tag that already exists on `origin` but not locally therefore
passes the pre-flight, and the run only fails once the fetch has brought it in
and `git tag X.Y.Z` refuses it — which is after the release pull request has
been merged. **Run `git fetch --all --prune --tags` before every release.**

The clean-tree check earns its keep because of the `git add -A` in phase 1: it
is what keeps a stray untracked file out of the `[RELEASE]` commit.

Neither `gh` authentication nor the merge prerequisites below are part of the
pre-flight; only the presence of `git` and `gh` on `PATH` is checked.

### What the merge needs

Both phases merge with `gh pr merge --rebase --delete-branch --admin`. Three
preconditions follow from that, and are worth confirming once, before the first
release:

- the authenticated `gh` account is an **administrator** of the repository —
  `gh auth status` shows who that is;
- **rebase merging is enabled** in the repository settings; no other merge
  method is attempted as a fallback;
- the branch ruleset lets administrators bypass it, which is what `--admin`
  uses.

None of the three is checked up front. Each of them fails at the merge step,
which is the worst place to find out — see
[When it stops half way](#when-it-stops-half-way).

### How long it takes

Both phases block on `gh pr checks --watch --interval 10 --fail-fast`, and the
pull request runs the **complete**
[CI matrix](../../.github/workflows/ci.yml) — 35 jobs on `1`, 36 on `main` as
the matrix stands, each starting with its own `composerUpdate`, with the sixteen
DBMS jobs gated behind the SQLite ones, which are gated behind the unit ones.
Budget for that **twice** per release, and do not interrupt the script: it has
no resume.

### When it stops half way

`release.sh` is a straight line under `set -e` and has no resume flag and no
phase selector. `--fail-fast` means a single red job aborts the release *after*
the branch has been pushed and the pull request opened. Nothing is lost — the
source branch is untouched and no tag exists — but the script cannot be re-run
as it stands, because `release-X.Y.Z` now exists locally and on the remote, and
a second run dies at `git checkout -b`.

Recover by hand rather than by re-running. These are exactly the commands
`release.sh` would have run next; the script owns no state beyond them.

```bash
# 1. Fix the cause on release-X.Y.Z and push until the pull request is green.
gh pr merge --rebase --delete-branch --admin

# 2. Tag the merged source branch.
git checkout <source-branch>
git pull --ff-only origin <source-branch>
git tag X.Y.Z
git push origin X.Y.Z

# 3. Phase 2 by hand.
git checkout -b set-version-X.Y.W <source-branch>
Build/Scripts/setVersion.sh X.Y.W post-release --source-branch=<source-branch>
git commit -am "[TASK] Set version X.Y.W"
git push -u origin set-version-X.Y.W
gh pr create --fill --base <source-branch> --title "[TASK] Set version X.Y.W"
gh pr merge --rebase --delete-branch --admin
```

If it aborts *before* the push — a failed pre-flight, a missing tool — nothing
happened remotely, and the [bare-mode cleanup](#the-three-modes) is all that is
needed before trying again.

### The next development version

Phase 2 sets the next **patch** as the development version: after `2.0.0` the
branch carries `2.0.1-dev` with the branch alias `2.0.x-dev`, after `1.0.0` it
carries `1.0.1-dev` with `1.0.x-dev`. That is by design — a line that has just
been released is next expected to produce a patch of it — and nothing has to be
done after a release to complete it.

Raising a line to a minor or a major instead is an editorial decision, taken
when that line is known to be collecting features or breaking changes rather
than fixes. It is an ordinary pull request of its own:

```bash
Build/Scripts/setVersion.sh 1.1.0 dev --source-branch=1
git commit -am "[TASK] Set version 1.1.0"
```

`dev` writes `1.1.0-dev` into `VERSION`, `composer.json` and
`Build/Scripts/runTests.sh`, `1.1.0` into `ext_emconf.php`, and sets
`extra.branch-alias` to `1.x-dev: 1.1.x-dev`. The same command with a different
`--source-branch` is what cuts a new maintenance branch — that is the case the
alias-key rule above exists for.

## After the tag: the publish workflow

Pushing the tag triggers the [`publish`](../../.github/workflows/publish.yml)
workflow. It runs for **every** tag and rejects anything that is not a bare
`MAJOR.MINOR.PATCH` — no `v` prefix — then reads the extension key out of
`composer.json` at run time, builds the upload artifact with
`tailor create-artefact` and creates a GitHub release named `[RELEASE] X.Y.Z` with `data_factory_X.Y.Z.zip` and `LICENSE`
attached. Both files must exist: `fail_on_unmatched_files` is on.

`tailor create-artefact` fails when the tag does not match the `version` in
`ext_emconf.php`. That is deliberate, and it is the reason a release cannot
disagree with the extension metadata — `setVersion.sh` is what keeps the two in
sync.

Release notes are generated by GitHub from the commits since the previous
release. The **first** tag of the repository has no predecessor, so its notes
list the whole history of the branch; the next one is diffed against it even if
the two belong to different lines. Edit the release afterwards where that is not
what is wanted.

Publishing the artifact to the TYPO3 Extension Repository is the last step of
the same workflow. It depends on two things that live outside this repository:
the extension key `data_factory` registered in the TER, and the
`TYPO3_API_TOKEN` repository secret belonging to an account that owns that key.
Both exist, which is why the step is enabled — it was commented out for as long
as they did not, and neither is something the workflow can check for itself.

It runs **after** the GitHub release, so an upload that fails — an expired
token, a version already published, a key transferred away — leaves the release
and its artifact in place to retry against instead of losing both.

## Before releasing

- The release is cut from the branch that carries the line being released:
  `main` for 2.x, `1` for 1.x — and it is run from a **checkout** of that
  branch, see [The two lines](#the-two-lines-and-where-the-scripts-run).
- `git fetch --all --prune --tags`, so the local refs the pre-flight reads match
  the remote.
- The working tree is clean. `--execute` refuses a dirty one, and for good
  reason.
- `gh auth status` shows an authenticated administrator of the repository, and
  the [merge prerequisites](#what-the-merge-needs) hold.
- `ext_emconf.php` carries the right `state`. `setVersion.sh` rewrites `version`
  and nothing else, so `'state' => 'alpha'` survives a release and is what the
  TER and the Extension Manager then show. Set it in the commit that prepares
  the release, or leave it deliberately.
- Changelog entries for the version are in place, and the version directory is
  listed in `Documentation/Changelog/Index.rst` — that `toctree` has no
  `:glob:` and is the one place a new version has to be registered by hand. The
  entries inside a version directory are picked up by the `:glob:` patterns of
  its own `Index.rst` and need no listing. See
  [Changelog and documentation](changelog-and-documentation.md).
- Both core versions green across the full
  [gate matrix](../development/quality-gates.md), each after its own
  `composerUpdate`.
- `Build/Scripts/runTests.sh -s renderDocumentation` passing.

## The first release of both lines

`1.0.0` from `1` and `2.0.0` from `main` are the first tags this repository ever
carries. Run them **in that order** and one after the other, not in parallel —
`release.sh` switches branches in the working copy, so two runs cannot share
one checkout anyway.

```bash
# 1.x first. On a checkout of branch 1, --source-branch is already the default.
git checkout 1
git fetch --all --prune --tags
Build/Scripts/release.sh 1.0.0 --dry-run
Build/Scripts/release.sh 1.0.0 --execute

# Then 2.x, once the publish workflow of 1.0.0 has finished.
git checkout main
git fetch --all --prune --tags
Build/Scripts/release.sh 2.0.0 --dry-run
Build/Scripts/release.sh 2.0.0 --execute
```

The order decides which release GitHub marks **Latest**: a release is created
with `make_latest` defaulting to true, so the one created *last* wins, whatever
its version number. Tagging `1.0.0` first leaves `2.0.0` as Latest and as what
`/releases/latest` resolves to. Reversed, the 1.x maintenance release would sit
on the repository front page. If it does land wrong:

```bash
gh release edit 2.0.0 --latest
```

The same order also gives the better release notes: `1.0.0` has no predecessor
and lists the whole history of branch `1`, and `2.0.0` is then diffed against
`1.0.0` across two lines that were never merged into each other. Both are worth
editing by hand afterwards.

Afterwards both branches sit at the next **patch** of their line —
`1.0.1-dev` and `2.0.1-dev` — which is where they belong. Raising either to a
minor is a separate decision and a separate pull request, see
[The next development version](#the-next-development-version).

## See also

- [Pull requests](pull-requests.md)
- [Commit messages](commit-messages.md)
- [Changelog and documentation](changelog-and-documentation.md)
