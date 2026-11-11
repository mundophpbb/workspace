<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x [ACP module - en]
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'ACP_WORKSPACE_TITLE' => 'Workspace IDE',
	'ACP_WORKSPACE_SETTINGS' => 'Editor settings',
	'ACP_WORKSPACE_SETTINGS_EXPLAIN' => 'Board-wide defaults for the Workspace code editor (ACE). Changes apply the next time a user opens the Workspace.',
	'ACP_WORKSPACE_SETTINGS_SAVED' => 'The Workspace settings have been saved.',
	'ACP_WORKSPACE_SETTINGS_RESET_DONE' => 'The Workspace settings were restored to their defaults.',
	'ACP_WORKSPACE_GROUP_EDITOR' => 'Editor',
	'ACP_WORKSPACE_GROUP_PREVIEW' => 'Live preview',
	'ACP_WORKSPACE_GROUP_LIMITS' => 'Limits',
	'ACP_WORKSPACE_PREVIEW_EXPLAIN' => 'This preview reflects the options above before you save them.',
	'ACP_WORKSPACE_PREVIEW_ERROR' => 'The preview could not be loaded because the ACE editor files were not found. Check that the ext/mundophpbb/workspace/styles/all/template/ace folder was uploaded completely.',
	'ACP_WORKSPACE_THEME' => 'Color theme',
	'ACP_WORKSPACE_THEME_EXPLAIN' => 'Colors used by the editor.',
	'ACP_WORKSPACE_THEMES_LIGHT' => 'Light themes',
	'ACP_WORKSPACE_THEMES_DARK' => 'Dark themes',
	'ACP_WORKSPACE_KEYBINDING' => 'Keyboard mode',
	'ACP_WORKSPACE_KEYBINDING_EXPLAIN' => 'Keyboard shortcuts style used inside the editor.',
	'ACP_WORKSPACE_KB_DEFAULT' => 'Default',
	'ACP_WORKSPACE_KB_VSCODE' => 'Visual Studio Code',
	'ACP_WORKSPACE_KB_SUBLIME' => 'Sublime Text',
	'ACP_WORKSPACE_KB_VIM' => 'Vim',
	'ACP_WORKSPACE_KB_EMACS' => 'Emacs',
	'ACP_WORKSPACE_FONT_SIZE' => 'Font size',
	'ACP_WORKSPACE_FONT_SIZE_EXPLAIN' => 'Between 10 and 24 pixels.',
	'ACP_WORKSPACE_TAB_SIZE' => 'Tab width',
	'ACP_WORKSPACE_SOFT_TABS' => 'Indent with spaces',
	'ACP_WORKSPACE_SOFT_TABS_EXPLAIN' => 'When disabled the Tab key inserts a tab character. phpBB coding guidelines use tabs for PHP.',
	'ACP_WORKSPACE_WRAP' => 'Wrap long lines',
	'ACP_WORKSPACE_AUTOCOMPLETE' => 'Autocompletion and snippets',
	'ACP_WORKSPACE_AUTOCOMPLETE_EXPLAIN' => 'Suggests words and code snippets while typing.',
	'ACP_WORKSPACE_INDENT_GUIDES' => 'Show indentation guides',
	'ACP_WORKSPACE_HIGHLIGHT_LINE' => 'Highlight the current line',
	'ACP_WORKSPACE_PRINT_MARGIN' => 'Show the print margin',
	'ACP_WORKSPACE_MAX_FILE_KB' => 'Maximum file size',
	'ACP_WORKSPACE_MAX_FILE_KB_EXPLAIN' => 'Largest file that can be saved or uploaded to a project. Between %1$d and %2$d KiB.',
	'ACP_WORKSPACE_RESTORE_DEFAULTS' => 'Restore defaults',
	'ACP_WORKSPACE_RESTORE_DEFAULTS_CONFIRM' => 'Restore all Workspace settings to their default values?',
	'LOG_WORKSPACE_SETTINGS_UPDATED' => '<strong>Altered the Workspace IDE settings</strong>',
	'LOG_WORKSPACE_SETTINGS_RESET' => '<strong>Restored the Workspace IDE settings to their defaults</strong>',
	'ACP_WORKSPACE_GROUP_EPV' => 'Official EPV validator',
	'ACP_WORKSPACE_GROUP_EPV_EXPLAIN' => 'The Workspace validator can run the official phpBB Extension Pre Validator (EPV) before you submit an extension to phpBB.com. Workspace never downloads or installs the EPV: it only runs a copy that you installed in phpBB/tools/epv.',
	'ACP_WORKSPACE_EPV_ENABLED' => 'Run the official EPV in the validator',
	'ACP_WORKSPACE_EPV_ENABLED_EXPLAIN' => 'When disabled, the validator skips the EPV and only runs the built-in checks.',
));
