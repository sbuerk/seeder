# Workflow

From a change in the working copy to a published release.

| Page                                                          | Contents                                                                                                          |
|---------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------|
| [Commit messages](commit-messages.md)                         | TYPO3 core commit message conventions: tags, subject length, body wrapping, footers, referencing core changelogs. |
| [Pull requests](pull-requests.md)                             | Branching, the local pre-flight checklist, review and history hygiene.                                            |
| [Changelog and documentation](changelog-and-documentation.md) | `Documentation/` vs `docs/`, reST changelog entries, rendering, the changelogs shipped with `typo3/cms-core`.     |
| [Releasing](releasing.md)                                     | `setVersion.sh` and `release.sh`, their safety gates, the publish workflow.                                       |

## The short version

- Commit subjects are `[TAG] Imperative summary`, ~52 characters, body wrapped at
  ~72. A model is never credited as an author; substantial AI involvement is
  disclosed with an `AI-assisted:` trailer instead.
- A change is not done until the gates pass for **both** core versions and the
  documentation is updated in the same commit.
- User facing changes need a changelog entry below `Documentation/Changelog/`.
- `--dry-run` is the mode a release is rehearsed in — it is the only one that
  writes nothing. Without `--execute` the remote steps are withheld, but every
  *local* one still runs and leaves branches behind, so a bare run is not a
  rehearsal. → [The three modes](releasing.md#the-three-modes)

## See also

- [Documentation index](../Index.md)
- [Quality gates](../development/quality-gates.md)
- [Development](../development/Index.md)
