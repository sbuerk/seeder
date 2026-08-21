# Changelog and documentation

This repository carries documentation in two places, with different audiences:

| Location                                | Audience                                     | Format   | Shipped              |
|-----------------------------------------|----------------------------------------------|----------|----------------------|
| [`Documentation/`](../../Documentation) | Users and integrators of the extension       | reST     | yes                  |
| [`docs/`](../Index.md)                  | Developers and maintainers of the repository | Markdown | no (`export-ignore`) |

Both are updated as part of the change that makes them necessary, never
afterwards.

## Rendering the user documentation

The sources in `Documentation/` are rendered with the official TYPO3 rendering
container:

```bash
Build/Scripts/runTests.sh -s renderDocumentation
```

The rendered output is written to the git-ignored `Documentation-GENERATED-temp/`
directory. Rendering must pass without errors; the
`documentation` job of the [CI workflow](../../.github/workflows/ci.yml) runs the
same command on every pull request, uploads the result as an artifact, and
[comments the link](../../.github/workflows/pr-comment.yml) on the pull request.

### While writing

`watchDocumentation` renders once, then serves the result and re-renders on
every save, so the browser shows the page as it will be published instead of
reST that has to be imagined:

```bash
Build/Scripts/runTests.sh -s watchDocumentation
Build/Scripts/runTests.sh -s watchDocumentation 4711
```

It serves on port `1337` unless a different port is given as the first argument,
and it keeps running until ctrl-c. Files **added** while it runs are not picked
up — restart it after creating a changelog entry. It is a writing aid, not a
gate: `renderDocumentation` is what has to pass, because it fails on the first
error rather than serving the page anyway.

## Changelog entries

User facing changes need a changelog entry below
`Documentation/Changelog/<version>/`, named by change type:

| File pattern        | Use for                                                    |
|---------------------|------------------------------------------------------------|
| `Feature-*.rst`     | New functionality.                                         |
| `Breaking-*.rst`    | Changes requiring action from users of the extension.      |
| `Deprecation-*.rst` | Functionality marked for removal, with the migration path. |
| `Important-*.rst`   | Notable changes that are neither of the above.             |

The entries of a version directory need no registration: its `Index.rst` picks
them up with `:glob:` patterns — one `toctree` per change type, matching
`Breaking-*`, `Feature-*`, `Deprecation-*` and `Important-*`.

A **new version directory** does need one.
`Documentation/Changelog/Index.rst` lists the versions with a plain `toctree`
and no `:glob:`, so add the directory there when the first entry of a new
version is written. Leaving it out fails the `documentation` gate rather than
passing quietly: `renderDocumentation` runs with `--fail-on-error`, and an
unreferenced document is an error.

## The TYPO3 core changelogs

When working on compatibility — adopting a new API, reacting to a deprecation,
preparing for the next major — the authoritative source is the changelog set
shipped with the installed core:

```
.Build/vendor/typo3/cms-core/Documentation/Changelog/
```

Because it ships with the dependency, what is on disk depends on what is
installed — and it reaches only as far as the installed version. A package does
carry the changelogs of all **earlier** versions, so with TYPO3 v13 installed
both `12.*` and `13.*` are there, while a v12 installation has no `13.0/` at
all.

Installing the **highest** supported version therefore gives the complete set in
one step. Switching is only needed to read changelogs *newer* than the installed
version, never older ones. Reading is not running a gate: `composerUpdate` back
to the version you are working on before running anything — see
[Dual core setup](../development/dual-core-setup.md).

The rendered version is at
<https://docs.typo3.org/c/typo3/cms-core/main/en-us/Index.html>.

Read the actual entry rather than relying on memory, and cite it in the commit
message and in any `@todo` left behind for future version work. See
[Commit messages](commit-messages.md#referencing-typo3-behaviour-changes).

## See also

- [Commit messages](commit-messages.md)
- [Pull requests](pull-requests.md)
- [Releasing](releasing.md)
