<?php
/**
 *
 * mundophpbb workspace extension [English]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 *
 */

/**
 * DO NOT CHANGE
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
	'WSP_TITLE'				=> 'Mundo phpBB Workspace',
	'WSP_EXPLORER'			=> 'Explorer',
	'WSP_NEW_PROJECT'		=> 'New Project',
	'WSP_ADD_FILE'			=> 'Add File',
	'WSP_SELECT_FILE'		=> 'Select a file to edit',
	'WSP_SAVE_CHANGES'		=> 'Save Changes',
	'WSP_SAVING'			=> 'Saving...',
	'WSP_SAVED'				=> 'Saved!',
	'WSP_LOADING'			=> 'Loading...',
	'WSP_NO_PROJECTS'		=> 'No projects found.',
	'WSP_PROMPT_NAME'		=> 'Enter the new project name:',
	'WSP_PROMPT_FILE_NAME'	=> 'File name (e.g.: includes/functions.php):',
	'WSP_PROJECT_LABEL'		=> 'Project',
	'WSP_DEFAULT_DESC'		=> 'Created via Workspace IDE',

	// Error Messages & Security Locks
	'WSP_ERR_PERMISSION'	=> 'You do not have Founder permissions to access this tool.',
	'WSP_ERR_INVALID_ID'	=> 'The file or project ID is invalid.',
	'WSP_ERR_FILE_NOT_FOUND'=> 'The requested file was not found.',
	'WSP_ERR_FILE_EXISTS'	=> 'Error: A file with this name already exists in this project.',
	'WSP_ERR_INVALID_DATA'	=> 'The submitted data is invalid.',
	'WSP_ERR_INVALID_NAME'	=> 'The name cannot be empty.',
	'WSP_ERR_INVALID_FILES'	=> 'Select valid files to generate the Diff.',
	'WSP_ERR_SAVE_FAILED'	=> 'Internal error while trying to save the file.',

	// Actions & Confirmations
	'WSP_CONFIRM_DELETE'	=> 'Are you sure you want to delete this project and all its files? This action is irreversible.',
	'WSP_CONFIRM_FILE_DELETE'=> 'Do you really want to delete this file?',
	'WSP_DOWNLOAD_ZIP'		=> 'Download Project (ZIP)',
	'WSP_GENERATE_ZIP'		=> 'Generating compressed package...',
	'WSP_RENAME'			=> 'Rename',
	'WSP_CLOSE'				=> 'Close',

	// Diff & Patch Wizard
	'WSP_DIFF_TITLE'		=> 'File Comparison',
	'WSP_DIFF_SELECT_ORIG'	=> 'Original File (Old)',
	'WSP_DIFF_SELECT_MOD'	=> 'Modified File (New)',
	'WSP_DIFF_GENERATE'		=> 'Generate Comparison',
	'WSP_DIFF_BBCODE'		=> 'Generated BBCode (Ready for forum)',
	'WSP_DIFF_PREVIEW'		=> 'Diff Preview',
	'WSP_COPY_BBCODE'		=> 'Copy BBCode',
	'WSP_COPIED'			=> 'Copied!',

	// Search & Replace
	'WSP_SEARCH_REPLACE'	=> 'Search and Replace in Project',
	'WSP_SEARCH_FOR'		=> 'Search for',
	'WSP_REPLACE_WITH'		=> 'Replace with',
	'WSP_REPLACE_ALL'		=> 'Replace All',
	'WSP_REPLACE_SUCCESS'	=> 'Success! %d files have been modified.',
	'WSP_SEARCH_NO_RESULTS'	=> 'No files found with this term.',
));