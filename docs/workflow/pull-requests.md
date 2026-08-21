# Pull requests

1. Create a topic branch off the branch the change belongs on — for example
   `feature/example-service` or `bugfix/empty-response`. **This branch line is
   `1`**, which carries TYPO3 v12.4 + v13.4; `main` carries the newer supported
   pair. Both release scripts default `--source-branch` to `1`, see
   [Releasing](releasing.md#--source-branch-and-the-key-the-alias-is-stored-under).
2. Keep commits focused; one logical change per commit, following the
   [commit message rules](commit-messages.md).
3. Make sure the quality gates and both test suites pass locally before opening
   the pull request:

   ```bash
   Build/Scripts/runTests.sh -s cgl -n
   Build/Scripts/runTests.sh -s phpstan
   Build/Scripts/runTests.sh -s lintPhp
   Build/Scripts/runTests.sh -s unit
   Build/Scripts/runTests.sh -s unitRandom
   Build/Scripts/runTests.sh -s functional -d sqlite
   Build/Scripts/runTests.sh -s composerValidate
   Build/Scripts/runTests.sh -s checkBom
   Build/Scripts/runTests.sh -s checkExceptionCodes
   Build/Scripts/runTests.sh -s checkMarkdownTables
   Build/Scripts/runTests.sh -s checkTestMethodsPrefix
   Build/Scripts/runTests.sh -s renderDocumentation
   ```

   Repeat all of it for **both** supported TYPO3 versions (`-t 12` and `-t 13`,
   each after the matching `composerUpdate`) — see
   [Dual core setup](../development/dual-core-setup.md#verifying-a-change).

   Add the PHP 8.1 leg when the change adds or moves a class: it needs its own
   `-t 12 -p 8.1 -s composerUpdate` first, and `lintPhp` there is the only run
   that rejects PHP 8.2-only syntax such as a `readonly class`.

   Run the functional suite against at least one other DBMS
   (`-d mariadb -i 10.6`, `mysql`, `postgres`) when the change touches queries,
   schema or TCA — the two core versions do not derive the same columns from
   TCA, see
   [Configuration is the exception](../architecture/core-version-aware-code.md#configuration-is-the-exception).
4. Update the documentation in the same pull request: the
   [`docs/`](../Index.md) page covering what changed, and a changelog entry
   below `Documentation/Changelog/` for user facing changes. See
   [Changelog and documentation](changelog-and-documentation.md).
5. Open the pull request against the branch the topic branch was cut from —
   `1` for a change to the TYPO3 v12 + v13 line, not `main` — describing what
   changes and why. The [CI workflow](../../.github/workflows/ci.yml) runs the
   full matrix for TYPO3 v12 and v13, and comments the rendered documentation on
   the pull request — for a fork as well, see
   [continuous integration](../development/quality-gates.md#continuous-integration).
6. Address review feedback by amending or adding commits; keep the history
   readable — squash fixup commits before the pull request is merged.

## See also

- [Commit messages](commit-messages.md)
- [Quality gates](../development/quality-gates.md)
- [Releasing](releasing.md)
