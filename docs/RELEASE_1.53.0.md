# Workspace IDE 1.53.0 — Navigation and loading stability

- Added instant, accent-insensitive local search to the Tools menu, without contacting the server.
- Categories are hidden while a query is active and the original menu arrangement returns when the query is cleared.
- Search resets when the menu reopens. Enter executes only when exactly one enabled result is available.
- Menu repositioning on scroll and resize is limited to one update per animation frame, with a fallback.
- No changes to button IDs, legacy events, routes, permissions, database schema or migrations.
- This release does not implement lazy loading of JavaScript modules or database query optimization. Without real phpBB benchmarks, quantitative performance improvements are not claimed.
