<?php
/**
 * mundophpbb workspace extension [English]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
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

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct.
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %shere%s' is fine.

$lang = array_merge($lang, array(
	// Common / Branding
	'WSP_TITLE'             => 'phpBB World Workspace',
	'WSP_EXPLORER'          => 'Explorer',
	'WSP_PROJECT_LABEL'     => 'Project',
	'WSP_SELECT_FILE'       => 'Select a file to edit',
	'WSP_LOADING'           => 'Loading...',
	'WSP_PROCESSING'        => 'Processing...',

	// Welcome Message (Editor Area)
	'WSP_WELCOME_MSG'       => "/*\n * PHPBB WORLD WORKSPACE\n * =====================\n * \n * NO FILE OPENED.\n * \n * 1. Select a file from the sidebar.\n * 2. Edit the code (a '*' will appear if there are changes).\n * 3. Use CTRL + S to save quickly.\n */\n",

	// Actions & Status
	'WSP_SAVE_CHANGES'      => 'SAVE CHANGES',
	'WSP_SAVING'            => 'SAVING...',
	'WSP_SAVED'             => 'SAVED!',
	'WSP_OK'                => 'OK',
	'WSP_CANCEL'            => 'Cancel',
	'WSP_CLOSE'             => 'Close',
	'WSP_RENAME'            => 'Rename',
	'WSP_COPIED'            => 'Copied!',

	// Project & File Management
	'WSP_NEW_PROJECT'        => 'New Project',
	'WSP_ADD_FILE'           => 'Add File/Folder',
	'WSP_PROMPT_NAME'        => 'Enter the new project name:',
	'WSP_PROMPT_FILE_NAME'   => 'File name (e.g., includes/functions.php):',
	'WSP_DOWNLOAD_ZIP'       => 'Download Project (ZIP)',
	'WSP_GENERATE_ZIP'       => 'Generating compressed package...',
	'WSP_UPLOADING'          => 'Uploading: ',
	'WSP_NO_PROJECTS'        => 'No projects found.',
	'WSP_DEFAULT_DESC'       => 'Created via Workspace IDE',
	'WSP_FILTER_PLACEHOLDER' => 'Filter files by name...',

	// Diff Tool
	'WSP_DIFF_TITLE'         => 'File Comparison',
	'WSP_DIFF_SELECT_ORIG'   => 'Original File (Old)',
	'WSP_DIFF_SELECT_MOD'    => 'Modified File (New)',
	'WSP_DIFF_GENERATE'      => 'Generate Comparison',
	'WSP_DIFF_GENERATING'    => 'Generating...',
	'WSP_DIFF_BBCODE'        => 'Generated BBCode (Ready for the forum)',
	'WSP_DIFF_PREVIEW'       => 'Diff Preview',
	'WSP_COPY_BBCODE'        => 'Copy BBCode',

	// Search & Replace
	'WSP_SEARCH_REPLACE'     => 'Search and Replace in Project',
	'WSP_SEARCH_FOR'         => 'Search for',
	'WSP_REPLACE_WITH'       => 'Replace with',
	'WSP_REPLACE_ALL'        => 'Replace in All',
	'WSP_REPLACE_SUCCESS'    => 'Success! %d change(s) made.',
	'WSP_SEARCH_NO_RESULTS'  => 'No files found with this term.',
	'WSP_SEARCH_EMPTY_ERR'   => 'Enter a search term.',
	'WSP_REPLACE_ONLY_FILE'  => 'Replace in the open file only: ',
	'WSP_REPLACE_IN_PROJECT' => 'Replace in the entire project: ',

	// Confirmations
	'WSP_CONFIRM_DELETE'       => 'Are you sure you want to delete this project and all its files? This action is irreversible.',
	'WSP_CONFIRM_FILE_DELETE'  => 'Do you really want to delete this file?',
	'WSP_CONFIRM_REPLACE_FILE' => 'Do you want to replace the text ONLY in this open file?',
	'WSP_CONFIRM_REPLACE_ALL'  => 'Do you really want to replace in the ENTIRE project?',

	// Error Messages & Security
	'WSP_ERR_PERMISSION'       => 'You do not have Founder permission to access this tool.',
	'WSP_ERR_INVALID_ID'       => 'The file or project ID is invalid.',
	'WSP_ERR_FILE_NOT_FOUND'   => 'The requested file was not found.',
	'WSP_ERR_FILE_EXISTS'      => 'Error: A file with this name already exists in this project.',
	'WSP_ERR_INVALID_DATA'     => 'The submitted data is invalid.',
	'WSP_ERR_INVALID_NAME'     => 'The name cannot be empty.',
	'WSP_ERR_INVALID_FILES'    => 'Select valid files to generate the Diff.',
	'WSP_ERR_SAVE_FAILED'      => 'Internal error when trying to save the file.',
	'WSP_ERR_SERVER_500'       => "SYSTEM ALERT!\nA 500 server error occurred.\n\nThe 'lib/' folder with the DIFF library probably does not exist or is in the wrong location.",
	'WSP_ERR_COPY'             => 'Copy error: ',
	'WSP_FILE_ELIMINATED'      => '/* File eliminated */',
));