# phpBB Workspace IDE

Collaborative IDE for building phpBB extensions in the browser: shared projects, file versions,
locks, reviews, comments and tasks, plus an optional pre-validation with the official phpBB EPV
to speed up the extension review.

* Requires phpBB 3.3.0 or later and PHP 7.4 or later.
* Languages: English and Brazilian Portuguese.
* License: GPL-2.0-only (see `license.txt`).

## Installation

1. Copy the extension to `ext/mundophpbb/workspace`.
2. In the ACP go to *Customise* > *Manage extensions* and enable *phpBB Workspace IDE*.
3. Grant the `u_workspace_*` permissions (*Users' permissions* > *Workspace*) to the users or groups that may use it.

## Adding files

* Drag files or folders onto the file tree, or use *File* > *Upload Files*.
* **Import ZIP** (*File* > *Import ZIP*, or drop a `.zip` on the file tree) sends a whole extension in one
  request. The archive is read in memory (nothing is extracted to the disk), the extension folder is detected
  from `composer.json`, only the file types accepted by the editor are imported, and a report lists anything
  that was skipped. Existing files are updated and their previous content is kept in the file history.
* After a drag-and-drop upload a report stays on screen listing the files that failed (with the reason),
  with a button to retry them.

## Settings

*ACP* > *Extensions* > *Workspace IDE* > *Editor settings*: editor theme, keyboard mode, font size,
tabs, autocompletion, maximum file size and the official EPV options.

The official EPV runs only if it is installed in `phpBB/tools/epv` (download `phpbb/epv` from GitHub
and run `composer install` there). The extension itself never downloads or installs it. The EPV can be
turned off in the same ACP page.

## Third-party software

* [Ace](https://ace.c9.io/) 1.43.6 (BSD 3-Clause), in `styles/all/template/ace/` (license in `styles/all/template/ace/BSD-3-Clause.txt`).
* PHP Diff by Chris Boulton (BSD 3-Clause), in `lib/` (license in the file headers).
