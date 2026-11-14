# Workspace IDE 1.58.1 — Visual update

This update modernizes the editor, forms and modal dialogs based on 1.58.0.

## Changes

- Added `workspace_modern.css` after existing styles to standardize controls without rewriting legacy components.
- Improved inputs, selects and textareas with rounded corners, spacing, contrast and visible focus.
- Harmonized modal, tab, menu and card surfaces, borders and spacing on desktop and narrow screens.
- Removed redundant floating tooltips and `title` display in Workspace controls while retaining ARIA labels.
- Excluded internal Ace Editor fields from form styling to avoid disrupting code editing.
- Added support for `prefers-reduced-motion`.

## Compatibility

- No new migrations or changes to endpoints, permissions, stored data or actions.
- Clear phpBB and browser caches after updating assets.
- Browser and phpBB-theme visual tests are still necessary.

## Checks recorded at the time

- 89 PHP/JavaScript automated tests passed, including a visual/accessibility test.
- Syntax checks covered 141 PHP and 28 JavaScript files.
- This release had not been visually tested on a live phpBB installation at the time of the report.
