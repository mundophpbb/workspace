# Workspace IDE for phpBB

Workspace IDE is a phpBB extension for managing project files, reviewing changes, and using developer tools through a permission-controlled interface. This source repository contains the full development package. A distribution candidate should be prepared and validated separately before submitting to phpBB.com.

## Installation and upgrades

1. Back up your database and extension files, and test upgrades on a staging board.
2. Place the extension at `ext/mundophpbb/workspace` and enable it through the ACP.
3. On upgrades, preserve existing extension data and migration history. Do not delete or rename historical migration classes.
4. Clear the phpBB cache and refresh browser assets after replacing extension files.
5. Review the extension's user and group permissions in the ACP before enabling public access.

## Project visibility and collaboration (1.58.6)

Each project can use **Private**, **Requests by PM**, or **Public (read-only)** collaboration. Public visibility does not bypass phpBB ACLs: guests and registered users still require the appropriate Workspace access and view permissions. Public visibility does not grant editing, upload, deletion, management or downloading rights. Test with a guest account and a separately registered account before publication.

## Main features

- Project file editor and file operations, subject to user, group and project permissions.
- Public project catalog with search, filters, ordering, pagination, and read-only viewing; downloads require their own permission.
- Project collaboration, private membership and participation requests.
- Validation, security audit, quality dashboard, migration inventory, and documentation assistance.
- Version history, checkpoints, backups, integrity verification and selective restore.
- Template preview, PHPUnit test generation, refactoring and development utilities.
- Optional GitHub public-repository linking, release comparisons, action/workflow information and controlled import.
- Release preparation, release history and project statistics.

These tools provide checks and previews, not a guarantee that a project is secure or approved for phpBB.com. Verify exported files and reports independently.

## GitHub integration

The GitHub public repositories integration is optional and disabled by default. Configure it under the Workspace ACP settings. Repository links are per project, not tied to a hardcoded developer account. Network access depends on the PHP environment. Importing requires explicit authorization, supported PHP extensions and the applicable project permissions. GitHub status checks can be cached and rate limited.

## Security and permissions

Permissions are declared in `config/permissions.yml` and installed through phpBB migrations. Project visibility never overrides restrictions on file edits, downloads, upload, deletion, or management. Requests that mutate data must remain protected by their existing access checks, CSRF tokens, and revision/locking safeguards.

## Internationalization

Interface labels must be resolved through phpBB language keys, with English under `language/en/` and Brazilian Portuguese under `language/pt_br/`. Standalone development scripts and internal technical comments use English. Intentional Portuguese content in localized text generators is maintained for Brazilian Portuguese output.

## Distribution and validation

`tests/`, `tools/` and `docs/` belong to the development source package and are not part of the intended phpBB.com distribution candidate. Run local checks and the official phpBB Extension Pre-Validator on the **actual distribution ZIP**. A successful PHP syntax check or ZIP integrity test alone does not establish extension approval. Check compatibility, required files, language keys, migrations and permissions on a real phpBB installation.

## Historical release notes

- `docs/RELEASE_1.53.0.md`: tool-menu search and menu positioning.
- `docs/RELEASE_1.58.1.md`: editor, modal and form visual refinements.
- `docs/RELEASE_1.58.3.md`: compatibility and validation heuristics.
- `docs/MIGRATIONS_CONSOLIDADAS.md`: generated migration inventory (v100 through v122 at this version).
- `docs/INTERNATIONALIZATION_AUDIT.md`: maintenance audit scope and limitations.
- `changelog.txt`: short change history for recent versions.

## Third-party code

Review bundled library notices and the extension license before redistribution. No third-party license texts should be altered as part of language normalization.
