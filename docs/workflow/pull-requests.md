# Pull requests

1. Create a topic branch off the branch the change belongs on — for example
   `feature/example-service` or `bugfix/empty-response`. **This branch line is
   `main`**, which carries TYPO3 v13.4 + v14.3; `1` carries the 1.x line for
   TYPO3 v12.4 + v13.4. Both release scripts default `--source-branch` to
   `main`, see
   [Releasing](releasing.md#--source-branch-and-the-key-the-alias-is-stored-under).

   Neither branch is merged into the other, so a fix that affects both lines
   needs a pull request on each — written against the core versions that branch
   supports.
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

   Repeat all of it for **both** supported TYPO3 versions (`-t 13` and `-t 14`,
   each after the matching `composerUpdate`) — see
   [Dual core setup](../development/dual-core-setup.md#verifying-a-change).

   Run the functional suite against at least one other DBMS
   (`-d mariadb -i 10.6`, `mysql`, `postgres`) when the change touches queries,
   schema or TCA.
4. Update the documentation in the same pull request: the
   [`docs/`](../Index.md) page covering what changed, and a changelog entry
   below `Documentation/Changelog/` for user facing changes. See
   [Changelog and documentation](changelog-and-documentation.md).
5. Open the pull request against the branch the topic branch was cut from —
   `main` for a change to the TYPO3 v13 + v14 line, `1` for the v12 + v13 line
   — describing what changes and why. The
   [CI workflow](../../.github/workflows/ci.yml) runs the full matrix for TYPO3
   v13 and v14 on this branch, and comments the rendered documentation on the
   pull request —
   for a fork as well, see
   [continuous integration](../development/quality-gates.md#continuous-integration).
6. Address review feedback by amending or adding commits; keep the history
   readable — squash fixup commits before the pull request is merged.

## See also

- [Commit messages](commit-messages.md)
- [Quality gates](../development/quality-gates.md)
- [Releasing](releasing.md)
