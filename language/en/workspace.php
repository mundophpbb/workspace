<?php
/**
 * mundophpbb workspace extension [English]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
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

    // =====================================================
    // Main Interface & Core
    // =====================================================
    'WSP_TITLE'                 => 'Workspace IDE',
    'WSP_EXPLORER'              => 'Explorer',
    'WSP_PROJECT_LABEL'         => 'Project',
    'WSP_SELECT_FILE'           => 'Select a file to edit',
    'WSP_SELECT_TO_BEGIN'       => 'Open or create a project to list the files.',
    'WSP_ACTIVE_FOLDER'         => 'Folder',
    'WSP_ACTIVE_FOLDER_TITLE'   => 'Currently selected folder',
    'WSP_ROOT'                  => 'Root',
    'WSP_CLOSE'                 => 'Close',
    'WSP_OK'                    => 'Confirm',
    'WSP_CANCEL'                => 'Cancel',
    'WSP_RENAME'                => 'Rename',
    'WSP_DELETE'                => 'Delete',

    // Editor Welcome
    'WSP_WELCOME_MSG'           => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n * \n * NO FILE OPEN.\n * \n * 1. Select a file in the sidebar.\n * 2. Edit the code.\n * 3. Use CTRL + S to quickly save.\n */\n",
    'WSP_EDITOR_START_MSG'      => 'Select a file in the sidebar to begin...',

    // =====================================================
    // States, Initialization & Notifications (JS)
    // =====================================================
    'WSP_LOADING'               => 'Loading...',
    'WSP_LOADING_FILE'          => 'Loading file...',
    'WSP_PROCESSING'            => 'Processing...',
    'WSP_SAVING'                => 'Saving...',
    'WSP_SAVING_MSG'            => 'Saving changes...',
    'WSP_SAVED'                 => 'Changes saved!',
    'WSP_SAVED_SHORT'           => 'Saved!',
    'WSP_SAVE_SUCCESS'          => 'File saved successfully!',
    'WSP_COPIED'                => 'Copied!',
    'WSP_INIT_START'            => 'Starting IDE modules...',
    'WSP_READY'                 => 'IDE ready to use.',
    'WSP_TIMEOUT'               => 'Timeout Error: Dependencies took too long to load.',
    'WSP_MODULE_LOADED'         => 'Module [%s] loaded.',
    'WSP_MODULE_ERROR'          => 'Error in module [%s]:',

    // =====================================================
    // Projects
    // =====================================================
    'WSP_NEW_PROJECT'           => 'New Project',
    'WSP_OPEN_PROJECT'          => 'Open Project',
    'WSP_RENAME_PROJECT'        => 'Rename Project',
    'WSP_RENAME_PROJECT_TITLE'  => 'New project name:',
    'WSP_DEFAULT_DESC'          => 'Created via Workspace IDE',
    'WSP_NO_PROJECTS'           => 'No projects found.',
    'WSP_PROJECT_NOT_FOUND'     => 'Project not found.',
    'WSP_EMPTY_PROJECT'         => 'Empty project',
    'WSP_EMPTY_PROJECT_DESC'    => 'This project has no files yet.',
    'WSP_DOWNLOAD_PROJECT'      => 'Download Project (ZIP)',
    'WSP_LABEL_ACTIVE_PROJECT'  => 'Active Project',
    'WSP_LABEL_CLICK_OPEN'      => 'Click to open',

    // =====================================================
    // File Tree
    // =====================================================
    'WSP_TREE_ROOT'             => 'Root',
    'WSP_TREE_NEW_FILE'         => 'New File',
    'WSP_TREE_NEW_FOLDER'       => 'New Folder',
    'WSP_TREE_RENAME'           => 'Rename',
    'WSP_TREE_DELETE'           => 'Delete',
    'WSP_TREE_MOVE'             => 'Move',
    'WSP_ADD_FILE'              => 'New file',
    'WSP_NEW_ROOT_FILE'         => 'New file in root',
    'WSP_NEW_ROOT_FOLDER_TITLE' => 'New Folder',
    'WSP_PROMPT_NEW_FILE'       => 'New file in %s',
    'WSP_PROMPT_NEW_FOLDER'     => 'New subfolder in %s',
    'WSP_PROMPT_ROOT_FILE'      => 'New file in Root:',
    'WSP_PROMPT_ROOT_FOLDER'    => 'New folder in Root:',
    'WSP_DRAG_UPLOAD_HINT'      => 'Drag folders here or use the upload button.',
    'WSP_LABEL_MOVE_ROOT'       => 'Move to Root ( / )',

    // =====================================================
    // Tools (Search, Replace, Diff, Cache)
    // =====================================================
    'WSP_SEARCH_REPLACE'        => 'Search & Replace',
    'WSP_SEARCH_TERM'           => 'Search term',
    'WSP_REPLACE_TERM'          => 'Replace with',
    'WSP_REPLACE_ALL'           => 'Replace all',
    'WSP_REPLACE_SUCCESS'       => 'Success! %d change(s) applied.',
    'WSP_TOOLS_SEARCH_SUCCESS'  => 'Replacement completed: %d files modified.',
    'WSP_TOOLS_SEARCH_NEED_PROJECT'    => 'Open a project to use search.',
    'WSP_TOOLS_SEARCH_TERM_REQUIRED'   => 'Type the term you want to search for.',
    'WSP_TOOLS_SEARCH_INTERFACE_ERROR' => 'Search interface not loaded.',
    'WSP_TOOLS_SEARCH_CONFIRM'         => 'Do you really want to replace all occurrences in this project?',

    'WSP_DIFF_TITLE'            => 'File comparison',
    'WSP_DIFF_GENERATE'         => 'Generate comparison',
    'WSP_DIFF_SELECT_ORIG'      => 'Original file',
    'WSP_DIFF_SELECT_MOD'       => 'Modified file',
    'WSP_LABEL_DIFF'            => 'Diff: %s',
    'WSP_TOOLS_DIFF_MIN_FILES'  => 'You need at least 2 files to compare.',
    'WSP_TOOLS_DIFF_SAME_FILES' => 'Choose different files to compare.',
    'WSP_TOOLS_COMPARING'       => 'Comparing...',

    // =====================================================
    // Changelog (Toolbar + Headers in file)
    // =====================================================
    'WSP_GENERATE_CHANGELOG'    => 'Consolidate Version',
    'WSP_GENERATE_CHANGELOG_AT' => 'Consolidate Version - %s',
    'WSP_CLEAR_CHANGELOG'       => 'Clear History',
    'WSP_NOTIFY_CHANGELOG_OK'   => 'Changelog consolidated!',
    'WSP_HISTORY_CLEANED'       => 'Project history cleared.',
    'WSP_HISTORY_CLEANED_AT'    => 'Project history cleared on %s',

    // Cache / UI
    'WSP_REFRESH_CACHE'         => 'Clear phpBB cache',
    'WSP_CACHE_CLEANED'         => 'phpBB cache cleared successfully.',
    'WSP_TOGGLE_FULLSCREEN'     => 'Fullscreen',

    // =====================================================
    // Upload & Drag & Drop
    // =====================================================
    'WSP_UPLOAD_FILES'          => 'Upload Files',
    'WSP_UPLOADING'             => 'Uploading files...',
    'WSP_UPLOAD_PROCESSING'     => 'Processing upload...',
    'WSP_UPLOAD_LIST_UPDATED'   => 'File tree updated successfully.',
    'WSP_UPLOAD_FAILED'         => 'Upload failed for: %s',
    'WSP_UPLOAD_NEED_PROJECT'   => 'Select a project first.',
    'WSP_UPLOAD_SENDING_COUNT'  => 'Uploading %d file(s)...',
    'WSP_UPLOAD_DROP_PROJECT'   => 'Error: You must open a project before dropping files.',

    // =====================================================
    // Prompts & Confirmations
    // =====================================================
    'WSP_PROMPT_NAME'           => 'Enter the name:',
    'WSP_PROMPT_PROJECT_NAME'   => 'New project name:',
    'WSP_PROMPT_FILE_NAME'      => 'File name (e.g. includes/functions.php):',
    'WSP_PROMPT_RENAME_FILE'    => 'New file name:',
    'WSP_PROMPT_RENAME_FOLDER'  => 'New folder name:',

    'WSP_UI_ACTION_WARNING'     => 'Warning: This action cannot be undone.',
    'WSP_CONFIRM_DELETE'        => 'Are you sure you want to permanently delete this project?',
    'WSP_CONFIRM_DELETE_PROJ'   => 'Do you want to DELETE this project and all its files permanently?',
    'WSP_CONFIRM_FILE_DELETE'   => 'Do you really want to delete this file?',
    'WSP_CONFIRM_DELETE_FILE'   => 'Delete this file permanently?',
    'WSP_CONFIRM_DELETE_FOLDER' => "Delete folder '%s' and all files?",
    'WSP_CONFIRM_CLEAR_CHANGE'  => 'Clear the entire changelog history?',
    'WSP_CONFIRM_REPLACE_ALL'   => 'Replace throughout the entire project?',

    // =====================================================
    // Log Messages (Changelog.txt)
    // =====================================================
    'WSP_LOG_PROJECT_CREATED'   => 'PROJECT CREATED ON %s',
    'WSP_LOG_UPLOAD_UPDATE'     => 'Upload (Update): %s',
    'WSP_LOG_UPLOAD_NEW'        => 'New file (Upload): %s',
    'WSP_LOG_FILE_CREATED'      => 'New file: %s',
    'WSP_LOG_FILE_CHANGED'      => 'Changed: %s',
    'WSP_LOG_DIFF_LABEL'        => 'Changes (Diff)',
    'WSP_LOG_REPLACE_ACTION'    => "Replace: '%1\$s' with '%2\$s' in %3\$s",
    'WSP_LOG_FOLDER_MOVE'       => 'Folder moved/renamed: %1\$s -> %2\$s',
    'WSP_LOG_FILE_MOVE_ACTION'  => 'File moved: %1\$s -> %2\$s',
    'WSP_LOG_DELETE_ACTION'     => 'Deleted: %s',
    'WSP_LOG_RENAME_ACTION'     => 'Renamed: %1\$s -> %2\$s',
    'WSP_LOG_CONTENT_MODIFIED_FALLBACK' => '(The content of this file was modified)',

    // =====================================================
    // System / Backend Errors (PHP + JS)
    // =====================================================
    'WSP_ERR_PERMISSION'        => 'You do not have permission to access Workspace.',
    'WSP_ERR_INVALID_ID'        => 'Invalid ID.',
    'WSP_ERR_INVALID_DATA'      => 'Invalid data sent.',
    'WSP_ERR_INVALID_NAME'      => 'The name cannot be empty.',
    'WSP_ERR_PROJECT_NOT_FOUND' => 'Project not found.',
    'WSP_ERR_FILE_NOT_FOUND'    => 'File not found.',
    'WSP_ERR_FILE_EXISTS'       => 'A file with this name already exists in this location.',
    'WSP_ERR_INVALID_EXT'       => 'File extension not allowed.',
    'WSP_ERR_DELETE_FAILED'     => 'Failed to delete data from the database.',
    'WSP_ERROR_CRITICAL'        => 'Critical failure while loading file. Check your connection.',
    'WSP_CRITICAL_ACE'          => 'Critical failure: ACE editor could not be initialized.',

    // Extra backend errors (for full i18n)
    'WSP_ERR_NO_CONTENT'        => 'No content received.',
    'WSP_ERR_CONTENT_PROCESS'   => 'Error processing content.',
    'WSP_ERR_DIFF_LIB_MISSING'  => 'Diff library missing on the server.',
    'WSP_ERR_CACHE_PURGE_FAILED'=> 'Could not clear phpBB cache.',
    'WSP_ERR_ZIP_NOT_AVAILABLE' => 'The server does not support ZIP (ZipArchive).',
    'WSP_ERR_ZIP_CREATE_FAILED' => 'Could not generate the ZIP file.',

    // =====================================================
    // Modals & UI (JS)
    // =====================================================
    'WSP_MODAL_TITLE_SELECT'    => 'Select Project',
    'WSP_MODAL_TITLE_MOVE'      => 'Move to...',
    'WSP_UI_CANCEL'             => 'Cancel',
    'WSP_UI_CONFIRM'            => 'Confirm',
    'WSP_UI_ROOT_FOCUS'         => 'Focus returned to the project root.',
    'WSP_UI_SELECT_FILE'        => 'Select a file',
    'WSP_UI_SPLITTER_READY'     => 'Screen splitter loaded.',

    // =====================================================
    // Placeholders & Tips
    // =====================================================
    'WSP_TYPE_HERE'             => 'Type here...',
    'WSP_SEARCH_PLACEHOLDER'    => 'e.g. function_name or text',
    'WSP_REPLACE_PLACEHOLDER'   => 'New text to replace...',
    'WSP_SEARCH_RESULTS_HINT'   => 'Results will appear here after searching...',

    // =====================================================
    // Skeleton Generator
    // =====================================================
    'WSP_GENERATE_SKELETON'     => 'Structure Generator (Skeleton)',
    'WSP_SKEL_VENDOR'           => 'Vendor',
    'WSP_SKEL_NAME'             => 'Extension Name',
    'WSP_SKEL_VENDOR_PLACEHOLDER' => 'e.g. mundophpbb',
    'WSP_SKEL_NAME_PLACEHOLDER'   => 'e.g. topictranslate',
    'WSP_RUN_GENERATOR'         => 'Generate Structure Now',

    // =====================================================
    // Shortcuts
    // =====================================================
    'WSP_SHORTCUTS'             => 'Keyboard Shortcuts',
    'WSP_FILTER_EXPLORER'       => 'Filter Explorer',
    'WSP_TOGGLE_CONSOLE'        => 'Toggle Console',
    'WSP_ZEN_MODE'              => 'Zen Mode (Fullscreen)',
    'WSP_SHOW_SHORTCUTS'        => 'Show this shortcuts guide',

    // =====================================================
    // Themes
    // =====================================================
    'WSP_CHANGE_THEME'          => 'Change Editor Theme',

    // =====================================================
    // Action Buttons
    // =====================================================
    'WSP_SAVE'                  => 'Save',
    'WSP_SAVE_CHANGES'          => 'Save changes',
    'WSP_SAVE_BTN'              => 'Save', // alias for compatibility (wsp_editor.js)
    'WSP_COPY_BBCODE'           => 'Copy BBCode',
    'WSP_BBCODE_COPIED'         => 'BBCode copied to clipboard!',

    // =====================================================
    // Extras (JS) - keys used in helper modules
    // =====================================================
    'WSP_EDITOR_LOADING'        => 'The editor is still loading. Please wait...',
    'WSP_ERROR_OPEN_FILE'       => 'Could not open the file.',
    'WSP_ERROR_SAVE'            => 'Could not save the file.',
    'WSP_ERR_SAVE'              => 'Failed to save the file.',
    'WSP_UNSAVED_CHANGES'       => 'There are unsaved changes. Do you want to continue anyway?',
    'WSP_ERROR_PROJECT_CREATE'  => 'Failed to create the project.',
    'WSP_ERR_CRITICAL_ACE'      => 'Critical failure: ACE editor could not be initialized.',
    'WSP_LOG_BACKUP_UPDATED'    => 'Local backup updated (file %s).',
    'WSP_LOG_FILE_OPEN'         => 'File opened: %s',

    // Backend Tools
    'WSP_DIFF_NO_CHANGES'       => 'No changes',

));