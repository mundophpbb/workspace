# Workspace IDE 1.58.3 — Compatibility and validation

This release conservatively addressed false positives in the 1.58.2 Full Validation report (13 errors and 11 warnings).

- Constructor parameter detection uses `token_get_all()` to ignore PHP examples embedded in generator strings/nowdocs.
- Superglobal checks examine `T_VARIABLE` tokens rather than treating regular expressions, comments or snippets as real variable access. CLI tools and test fixtures are excluded from the Request API check.
- Language-key analysis ignores example data in tests and CLI scripts. The hardcoded-message detector does not confuse the technical migration inventory with user-facing messages.
- Template previews use phpBB-compatible `utf8_htmlspecialchars()`. Isolated tests have a test-only shim.
- Generated listener examples no longer emit a placeholder TODO marker.
- Two separate packaging targets were documented: full development source and a distribution ZIP excluding tests/tools to avoid shipping intentionally unsafe test fixtures or global test classes.

## Known limitations

The prior report also identified 106 files indented with spaces, two potential non-English comment files, and five heuristic SQL warnings. Those results were not indiscriminately rewritten or declared resolved; they require individual review and official EPV execution. The server had `proc_open()` disabled, so official full validation was not executed in that environment. The distribution ZIP is only a **submission candidate**, not an approval guarantee.

No migration, database schema, permission, route or layout changes were introduced.
