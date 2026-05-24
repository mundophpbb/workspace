<?php
/**
 * mundophpbb workspace extension [English]
 *
 * @package   mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
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
    'WSP_SELECT_TO_BEGIN'       => 'Open or create a project to list files.',
    'WSP_ACTIVE_FOLDER'         => 'Folder',
    'WSP_ACTIVE_FOLDER_TITLE'   => 'Currently selected folder',
    'WSP_ROOT'                  => 'Root',
    'WSP_CLOSE'                 => 'Close',
    'WSP_OK'                    => 'OK',
    'WSP_CANCEL'                => 'Cancel',
    'WSP_RENAME'                => 'Rename',
    'WSP_DELETE'                => 'Delete',

    // Editor Welcome
    'WSP_WELCOME_MSG'           => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n *\n * NO FILE OPEN.\n *\n * 1. Select a file from the sidebar.\n * 2. Edit the code.\n * 3. Use CTRL + S to quick save.\n */\n",
    'WSP_EDITOR_START_MSG'      => 'Select a file in the sidebar to begin...',

    // =====================================================
    // States, Initialization & Notifications
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
    'WSP_READY'                 => 'IDE ready for use.',
    'WSP_TIMEOUT'               => 'Timeout Error: Dependencies took too long to load.',
    'WSP_MODULE_LOADED'         => 'Module [%s] loaded.',
    'WSP_MODULE_ERROR'          => 'Error in module [%s]:',

    // =====================================================
    // Projects
    // =====================================================
    'WSP_NEW_PROJECT'           => 'New Project',
    'WSP_OPEN_PROJECT'          => 'Open Project',
    'WSP_RENAME_PROJECT'        => 'Rename Project',
    'WSP_RENAME_PROJECT_TITLE'  => 'New Project name:',
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
    // Revisao colaborativa por arquivo
    'WSP_REVIEW_PANEL' => 'File review',
    'WSP_SELECT_FILE_REVIEW' => 'Select a file to review.',
    'WSP_ADD_COMMENT' => 'Add comment',
    'WSP_COMMENT_LINE_PLACEHOLDER' => 'Line (optional)',
    'WSP_COMMENT_PLACEHOLDER' => 'Write a review comment...',
    'WSP_NO_COMMENTS' => 'No comments on this file.',
    'WSP_LOADING_COMMENTS' => 'Loading comments...',
    'WSP_ERROR_COMMENTS' => 'Could not load comments.',
    'WSP_COMMENT_LINE' => 'Line',
    'WSP_COMMENT_GENERAL' => 'General comment',
    'WSP_COMMENT_RESOLVED' => 'Resolved',
    'WSP_RESOLVE_COMMENT' => 'Resolve',
    'WSP_REOPEN_COMMENT' => 'Reopen',
    'WSP_DELETE_COMMENT' => 'Delete',
    'WSP_CONFIRM_DELETE_COMMENT' => 'Delete this comment?',
    'WSP_COMMENT_ADDED' => 'Comment added.',
    'WSP_ERR_COMMENT_NOT_FOUND' => 'Comment not found.',
    'WSP_ACTIVITY_COMMENT_ADDED' => 'commented on file',
    'WSP_ACTIVITY_COMMENT_RESOLVED' => 'resolved comment',
    'WSP_ACTIVITY_COMMENT_REOPENED' => 'reopened comment',
    'WSP_REVIEW_STATUS_NONE' => 'No review requested',
    'WSP_REVIEW_STATUS_PENDING' => 'Waiting for review',
    'WSP_REVIEW_STATUS_APPROVED' => 'File approved',
    'WSP_REVIEW_STATUS_CHANGES' => 'Changes requested',
    'WSP_REQUEST_REVIEW' => 'Request review',
    'WSP_APPROVE_FILE' => 'Approve',
    'WSP_REQUEST_CHANGES' => 'Request changes',
    'WSP_LOADING_REVIEW_STATUS' => 'Loading review status...',
    'WSP_REVIEW_STATUS_UPDATED' => 'Review status updated.',
    'WSP_ERROR_REVIEW_STATUS' => 'Could not update review status.',
    'WSP_REVIEW_NOTE_PROMPT' => 'Review note:',
    'WSP_ACTIVITY_REVIEW_REQUESTED' => 'requested review',
    'WSP_ACTIVITY_REVIEW_APPROVED' => 'approved file',
    'WSP_ACTIVITY_REVIEW_CHANGES_REQUESTED' => 'requested changes',
    'WSP_ACTIVITY_COMMENT_DELETED' => 'deleted comment',

    // Tools (Search, Replace, Diff, Cache)
    // =====================================================
    'WSP_SEARCH_REPLACE'        => 'Search & Replace',
    'WSP_SEARCH_TERM'           => 'Search term',
    'WSP_REPLACE_TERM'          => 'Replace with',
    'WSP_REPLACE_ALL'           => 'Replace all',
    'WSP_REPLACE_SUCCESS'       => 'Success! %d change(s) made.',
    'WSP_TOOLS_SEARCH_SUCCESS'  => 'Replacement finished: %d files modified.',
    'WSP_TOOLS_SEARCH_NEED_PROJECT' => 'Open a project to use search.',
    'WSP_TOOLS_SEARCH_TERM_REQUIRED' => 'Enter the term you want to search for.',
    'WSP_TOOLS_SEARCH_INTERFACE_ERROR' => 'Search interface not loaded.',
    'WSP_TOOLS_SEARCH_CONFIRM'  => 'Are you sure you want to replace all occurrences in this project?',
    'WSP_DIFF_TITLE'            => 'File Comparison',
    'WSP_DIFF_GENERATE'         => 'Generate comparison',
    'WSP_DIFF_SELECT_ORIG'      => 'Original file',
    'WSP_DIFF_SELECT_MOD'       => 'Modified file',
    'WSP_LABEL_DIFF'            => 'Diff: %s',
    'WSP_TOOLS_DIFF_MIN_FILES'  => 'You need at least 2 files to compare.',
    'WSP_TOOLS_DIFF_SAME_FILES' => 'Choose different files to compare.',
    'WSP_TOOLS_COMPARING'       => 'Comparing...',
    
    // Cache / UI
    'WSP_REFRESH_CACHE'     => 'Purge phpBB cache',
    'WSP_CACHE_CLEANED'     => 'phpBB cache purged successfully.',
    'WSP_TOGGLE_FULLSCREEN' => 'Fullscreen',

    // =====================================================
    // Changelog
    // =====================================================
    'WSP_GENERATE_CHANGELOG'    => 'Consolidate Version',
    'WSP_GENERATE_CHANGELOG_AT' => 'Consolidate Version - %s',
    'WSP_CLEAR_CHANGELOG'       => 'Clear History',
    'WSP_NOTIFY_CHANGELOG_OK'   => 'Changelog consolidated!',
    'WSP_HISTORY_CLEANED'       => 'Project history cleared.',
    'WSP_HISTORY_CLEANED_AT'    => 'Project history cleared at %s',

    // =====================================================
    // Lock / Unlock (Project)
    // =====================================================
    'WSP_PROJECT_LOCK'          => 'Lock project',
    'WSP_PROJECT_UNLOCK'        => 'Unlock project',
    'WSP_PROJECT_LOCKED_MSG'    => "Project locked.\nOnly a Workspace administrator can unlock it.\n",
    'WSP_ERR_PROJECT_LOCKED'    => 'This project is currently locked.',
    'WSP_LOG_PROJECT_LOCKED'    => 'Project locked',
    'WSP_LOG_PROJECT_UNLOCKED'  => 'Project unlocked',

    // =====================================================
    // Upload & Drag & Drop
    // =====================================================
    'WSP_UPLOAD_FILES'          => 'Upload Files',
    'WSP_UPLOADING'             => 'Uploading files...',
    'WSP_UPLOAD_PROCESSING'     => 'Processing upload...',
    'WSP_UPLOAD_LIST_UPDATED'   => 'File tree updated successfully.',
    'WSP_UPLOAD_FAILED'         => 'Upload failed for: %s',
    'WSP_UPLOAD_NEED_PROJECT'   => 'Select a project first.',
    'WSP_UPLOAD_SENDING_COUNT'  => 'Sending %d file(s)...',
    'WSP_UPLOAD_DROP_PROJECT'   => 'Error: You must open a project before dropping files.',

    // =====================================================
    // Prompts and Confirmations
    // =====================================================
    'WSP_PROMPT_NAME'           => 'Enter the name:',
    'WSP_PROMPT_PROJECT_NAME'   => 'New project name:',
    'WSP_PROMPT_FILE_NAME'      => 'File name (e.g., includes/functions.php):',
    'WSP_PROMPT_RENAME_FILE'    => 'New name for the file:',
    'WSP_PROMPT_RENAME_FOLDER'  => 'New folder name:',
    'WSP_UI_ACTION_WARNING'     => 'Warning: This action cannot be undone.',
    'WSP_CONFIRM_DELETE'        => 'Are you sure you want to permanently delete this project?',
    'WSP_CONFIRM_DELETE_PROJ'   => 'Do you want to permanently DELETE this project and all its files?',
    'WSP_CONFIRM_FILE_DELETE'   => 'Are you sure you want to delete this file?',
    'WSP_CONFIRM_DELETE_FILE'   => 'Delete this file permanently?',
    'WSP_CONFIRM_DELETE_FOLDER' => "Delete the folder '%s' and all files and subfolders?",
    'WSP_CONFIRM_CLEAR_CHANGE'  => 'Do you want to clear the entire changelog history?',
    'WSP_CONFIRM_REPLACE_ALL'   => 'Do you want to replace in the entire project?',

    // =====================================================
    // Changelog Log
    // =====================================================
    'WSP_LOG_PROJECT_RENAMED' => 'Project renamed: %s',
    'WSP_LOG_PROJECT_CREATED'   => 'PROJECT CREATED AT %s',
    'WSP_LOG_UPLOAD_UPDATE'     => 'File updated (100%%) - %s',
    'WSP_LOG_UPLOAD_NEW'        => 'New file (Upload): %s',
    'WSP_LOG_FILE_CREATED'      => 'New file: %s',
    'WSP_LOG_FILE_CHANGED'      => 'Changed: %s',
    'WSP_LOG_DIFF_LABEL'        => 'Changes (Diff)',

    // ✅ FIXED: remove "\$" (invalidates sprintf in PHP 8+)
    'WSP_LOG_REPLACE_ACTION'    => "Replacement: '%1\$s' with '%2\$s' in %3\$s",
    'WSP_LOG_FOLDER_MOVE'       => 'Folder moved/renamed: %1$s → %2$s',
    'WSP_LOG_FILE_MOVE_ACTION'  => 'File moved: %1$s → %2$s',
    'WSP_LOG_DELETE_ACTION'     => 'Deleted: %s',
    'WSP_LOG_RENAME_ACTION'     => 'Renamed: %1$s → %2$s',

    'WSP_LOG_CONTENT_MODIFIED_FALLBACK' => '(The content of this file was modified)',

    // =====================================================
    // Errors
    // =====================================================
    'WSP_ERR_PERMISSION'        => 'You do not have permission to access the Workspace.',
    'WSP_ERR_INVALID_ID'        => 'Invalid ID.',
    'WSP_ERR_INVALID_DATA'      => 'Invalid data submitted.',
    'WSP_ERR_INVALID_NAME'      => 'The name cannot be empty.',
    'WSP_ERR_PROJECT_NOT_FOUND' => 'Project not found.',
    'WSP_ERR_FILE_NOT_FOUND'    => 'File not found.',
    'WSP_ERR_FILE_EXISTS'       => 'A file with this name already exists in this location.',
    'WSP_ERR_INVALID_EXT'       => 'File extension not allowed.',
    'WSP_ERR_DELETE_FAILED'     => 'Failed to delete data from the database.',
    'WSP_ERROR_CRITICAL'        => 'Critical failure loading file. Check your connection.',
    'WSP_CRITICAL_ACE'          => 'Critical failure: The ACE editor could not be initialized.',

    // Extra backend errors
    'WSP_ERR_NO_CONTENT'        => 'No content received.',
    'WSP_ERR_CONTENT_PROCESS'   => 'Error processing content.',
    'WSP_ERR_DIFF_LIB_MISSING'  => 'Diff library missing on the server.',
    'WSP_ERR_CACHE_PURGE_FAILED'=> 'Could not purge the phpBB cache.',
    'WSP_ERR_ZIP_NOT_AVAILABLE' => 'The server does not support ZIP (ZipArchive).',
    'WSP_ERR_ZIP_CREATE_FAILED' => 'Could not generate the ZIP file.',

    // =====================================================
    // Modals, UI and Extras
    // =====================================================
    'WSP_MODAL_TITLE_SELECT'    => 'Select Project',
    'WSP_MODAL_TITLE_MOVE'      => 'Move to...',
    'WSP_UI_CANCEL'             => 'Cancel',
    'WSP_UI_CONFIRM'            => 'Confirm',
    'WSP_UI_ROOT_FOCUS'         => 'Focus returned to the project root.',
    'WSP_UI_SELECT_FILE'        => 'Select a file',
    'WSP_UI_SPLITTER_READY'     => 'Screen splitter loaded.',

    'WSP_TYPE_HERE'             => 'Type here...',
    'WSP_SEARCH_PLACEHOLDER'    => 'e.g., function_name or text',
    'WSP_REPLACE_PLACEHOLDER'   => 'New text to replace...',
    'WSP_SEARCH_RESULTS_HINT'   => 'Results will appear here after the search...',

    // Skeleton
    'WSP_GENERATE_SKELETON'     => 'Skeleton Generator',
    'WSP_SKEL_VENDOR'           => 'Vendor',
    'WSP_SKEL_NAME'             => 'Extension Name',
    'WSP_SKEL_VENDOR_PLACEHOLDER' => 'e.g., mundophpbb',
    'WSP_SKEL_NAME_PLACEHOLDER'   => 'e.g., topictranslate',
    'WSP_RUN_GENERATOR'         => 'Generate Skeleton Now',

    // Shortcuts
    'WSP_SHORTCUTS'             => 'Keyboard Shortcuts',
    'WSP_FILTER_EXPLORER'       => 'Filter Explorer',
    'WSP_TOGGLE_CONSOLE'        => 'Toggle Console',
    'WSP_ZEN_MODE'              => 'Zen Mode (Fullscreen)',
    'WSP_SHOW_SHORTCUTS'        => 'Show this shortcuts guide',

    // Themes
    'WSP_CHANGE_THEME'          => 'Change Editor Theme',

    // Buttons
    'WSP_SAVE'                  => 'Save',
    'WSP_SAVE_CHANGES'          => 'Save changes',
    'WSP_SAVE_BTN'              => 'Save',
    'WSP_COPY_BBCODE'           => 'Copy BBCode',
    'WSP_BBCODE_COPIED'         => 'BBCode copied to clipboard!',

    // Extras
    'WSP_EDITOR_LOADING'        => 'The editor is still loading. Please wait...',
    'WSP_ERROR_OPEN_FILE'       => 'Could not open the file.',
    'WSP_ERROR_SAVE'            => 'Could not save the file.',
    'WSP_ERR_SAVE'              => 'Failed to save the file.',
    'WSP_UNSAVED_CHANGES'       => 'There are unsaved changes. Do you want to continue anyway?',
    'WSP_ERROR_PROJECT_CREATE'  => 'Failed to create the project.',
    'WSP_ERR_CRITICAL_ACE'      => 'Critical failure: The ACE editor could not be initialized.',
    'WSP_LOG_BACKUP_UPDATED'    => 'Local backup updated (file %s).',
    'WSP_LOG_FILE_OPEN'         => 'File opened: %s',

    // Collaboration
    'WSP_COLLAB_WORKSPACE'    => 'Collaborative area',
    'WSP_NO_ACTIVE_PROJECT'   => 'No active project',
    'WSP_ROLE_OWNER'          => 'Owner',
    'WSP_ROLE_COLLAB'         => 'Collaborator',
    'WSP_ROLE_VIEWER'         => 'Viewer',
    'WSP_TEAM_SIZE'           => 'Team size',
    'WSP_MEMBERS'             => 'member(s)',
    'WSP_STATUS_OPEN'         => 'Open for collaboration',
    'WSP_STATUS_LOCKED'       => 'Project locked',

    // Advanced collaboration
    'WSP_TEAM_PANEL' => 'Project team',
    'WSP_TEAM_PANEL_DESC' => 'Manage who participates and each person\'s role.',
    'WSP_ADD_MEMBER' => 'Add member',
    'WSP_MEMBER_USERNAME' => 'Username',
    'WSP_INVITE' => 'Invite',
    'WSP_REMOVE_MEMBER' => 'Remove member',
    'WSP_NO_MEMBERS' => 'No members listed.',
    'WSP_ACTIVITY_TITLE' => 'Recent activity',
    'WSP_ACTIVITY_DESC' => 'Timeline of collaborative actions.',
    'WSP_NO_ACTIVITY' => 'No recent activity.',
    'WSP_SYSTEM_USER' => 'System',
    'WSP_ERR_USER_NOT_FOUND' => 'User not found.',
    'WSP_LOG_MEMBER_ADDED' => 'Member added: %1$s (%2$s)',
    'WSP_MEMBER_UPDATED' => 'Member updated.',
    'WSP_CONFIRM_REMOVE_MEMBER' => 'Remove this member from the project?',
    'WSP_ACTIVITY_PROJECT_CREATED' => 'created the project',
    'WSP_ACTIVITY_PROJECT_RENAMED' => 'renamed the project',
    'WSP_ACTIVITY_PROJECT_LOCKED' => 'locked the project',
    'WSP_ACTIVITY_PROJECT_UNLOCKED' => 'unlocked the project',
    'WSP_ACTIVITY_MEMBER_ADDED' => 'added member',
    'WSP_ACTIVITY_MEMBER_ROLE_CHANGED' => 'changed member role',
    'WSP_ACTIVITY_MEMBER_REMOVED' => 'removed member',
    'WSP_ACTIVITY_FILE_UPLOADED_UPDATE' => 'updated by upload',
    'WSP_ACTIVITY_FILE_UPLOADED_NEW' => 'uploaded new file',
    'WSP_ACTIVITY_FILE_CREATED' => 'created file',
    'WSP_ACTIVITY_FILE_SAVED' => 'saved file',
    'WSP_ACTIVITY_FILE_RENAMED' => 'renamed file',
    'WSP_ACTIVITY_FILE_MOVED' => 'moved file',
    'WSP_ACTIVITY_FILE_DELETED' => 'deleted file',

    // Revisao colaborativa por arquivo

    // Tools
    'WSP_DIFF_NO_CHANGES'       => 'No changes',
));


$lang = array_merge($lang, [
    'WSP_VERSION_HISTORY' => 'Version history',
    'WSP_REFRESH_VERSIONS' => 'Refresh versions',
    'WSP_LOADING_VERSIONS' => 'Loading versions...',
    'WSP_NO_VERSIONS' => 'There are no snapshots for this file yet. History is created automatically before each changed save.',
    'WSP_ERROR_VERSIONS' => 'Could not load version history.',
    'WSP_VIEW_VERSION' => 'View',
    'WSP_RESTORE_VERSION' => 'Restore',
    'WSP_CONFIRM_RESTORE_VERSION' => 'Restore this version? The current content will be saved as a snapshot before restoring.',
    'WSP_VERSION_VIEWING' => 'Previous version loaded in view mode. To return to the current content, open the file again.',
    'WSP_VERSION_RESTORED' => 'Version restored successfully.',
    'WSP_ERR_VERSION_NOT_FOUND' => 'Version not found.',
    'WSP_VERSION_BEFORE_SAVE' => 'Automatic snapshot before saving.',
    'WSP_VERSION_BEFORE_RESTORE' => 'Automatic snapshot before restoring.',
    'WSP_LOG_FILE_RESTORED' => 'File restored from previous version: %s',
    'WSP_VERSION_SOURCE_SAVE' => 'Before save',
    'WSP_VERSION_SOURCE_UPLOAD' => 'Upload',
    'WSP_VERSION_SOURCE_RESTORE' => 'Before restore',
    'WSP_VERSION_SOURCE_MANUAL' => 'Manual',
    'WSP_ACTIVITY_FILE_VERSION_RESTORED' => 'restored a previous version of',
    'WSP_VERSION_VIEW_MODE_SAVE_BLOCKED' => 'You are viewing a previous version. Reopen the current file or restore the version before saving.',
    'WSP_LOCK_FILE' => 'Lock file for editing',
    'WSP_UNLOCK_FILE' => 'Release file lock',
    'WSP_FILE_UNLOCKED' => 'File is available for editing.',
    'WSP_FILE_LOCKED_BY_YOU' => 'File locked by you.',
    'WSP_FILE_LOCKED_BY_USER' => 'File locked by %s.',
    'WSP_FILE_LOCKED_SAVE_BLOCKED' => 'This file is locked by another user. Save after it is released.',
    'WSP_ERR_FILE_LOCKED_BY' => 'This file is locked by %s.',
    'WSP_ERROR_FILE_LOCK' => 'Could not update the file lock.',
    'WSP_ACTIVITY_FILE_LOCKED' => 'locked the file',
    'WSP_ACTIVITY_FILE_UNLOCKED' => 'released the file lock',
]);


$lang = array_merge($lang, [
    'WSP_NOTIFICATIONS' => 'Notifications',
    'WSP_MARK_ALL_READ' => 'Mark all as read',
    'WSP_LOADING_NOTIFICATIONS' => 'Loading notifications...',
    'WSP_ERROR_NOTIFICATIONS' => 'Could not load notifications.',
    'WSP_NO_NOTIFICATIONS' => 'No notifications.',
    'WSP_NOTIFY_MEMBER_ADDED' => 'added you to the project',
    'WSP_NOTIFY_MEMBER_REMOVED' => 'removed you from the project',
    'WSP_NOTIFY_ROLE_CHANGED' => 'changed your project role',
    'WSP_NOTIFY_TEAM_CHANGED' => 'updated the project team',
    'WSP_NOTIFY_COMMENT_ADDED' => 'commented on file',
    'WSP_NOTIFY_REVIEW_REQUESTED' => 'requested file review',
    'WSP_NOTIFY_REVIEW_APPROVED' => 'approved file',
    'WSP_NOTIFY_REVIEW_CHANGES_REQUESTED' => 'requested changes on file',
    'WSP_NOTIFY_FILE_SAVED' => 'saved changes in',
    'WSP_NOTIFY_VERSION_RESTORED' => 'restored previous version of',
]);


$lang = array_merge($lang, [
    'WSP_NOTIFY_FILE_UPLOADED_UPDATE' => 'updated by upload',
    'WSP_NOTIFY_FILE_UPLOADED_NEW' => 'uploaded new file',
]);

$lang = array_merge($lang, [
    'WSP_EXPORT_COLLAB_PACKAGE' => 'Export collaborative package',
    'WSP_ACTIVITY_COLLAB_EXPORTED' => 'exported collaborative package',
]);

$lang = array_merge($lang, [
    'WSP_TASK_BOARD' => 'Task board',
    'WSP_TASK_BOARD_DESC' => 'Track pending work, assignees and project progress.',
    'WSP_ADD_TASK' => 'Add task',
    'WSP_DELETE_TASK' => 'Delete task',
    'WSP_TASK_TITLE' => 'Task title',
    'WSP_TASK_DESCRIPTION' => 'Task description',
    'WSP_TASK_UNASSIGNED' => 'Unassigned',
    'WSP_TASK_STATUS_TODO' => 'To do',
    'WSP_TASK_STATUS_DOING' => 'In progress',
    'WSP_TASK_STATUS_DONE' => 'Done',
    'WSP_TASK_PRIORITY_LOW' => 'Low',
    'WSP_TASK_PRIORITY_NORMAL' => 'Normal',
    'WSP_TASK_PRIORITY_HIGH' => 'High',
    'WSP_NO_TASKS' => 'No tasks for this project yet.',
    'WSP_TASK_UPDATED' => 'Task updated.',
    'WSP_CONFIRM_DELETE_TASK' => 'Delete this task?',
    'WSP_ACTIVITY_TASK_CREATED' => 'created task',
    'WSP_ACTIVITY_TASK_UPDATED' => 'updated task',
    'WSP_ACTIVITY_TASK_COMPLETED' => 'completed task',
    'WSP_ACTIVITY_TASK_DELETED' => 'deleted task',
    'WSP_NOTIFY_TASK_ASSIGNED' => 'assigned a task to you',
    'WSP_NOTIFY_TASK_UPDATED' => 'updated a task assigned to you',
]);

$lang = array_merge($lang, [
    'WSP_VALIDATE_RELEASE' => 'Validate phpBB release',
    'WSP_VALIDATE_RUN_AGAIN' => 'Validate again',
    'WSP_VALIDATOR_WAITING' => 'Click to run the release checklist.',
    'WSP_VALIDATOR_RUNNING' => 'Validating project structure...',
    'WSP_VALIDATOR_READY' => 'Ready for review',
    'WSP_VALIDATOR_NOT_READY' => 'Fixes required',
    'WSP_VALIDATOR_ERRORS' => 'errors',
    'WSP_VALIDATOR_WARNINGS' => 'warnings',
    'WSP_VALIDATOR_CHECKS' => 'checks',
    'WSP_VALIDATOR_FILES' => 'files',
    'WSP_VALIDATOR_NO_CHECKS' => 'No checks returned.',
    'WSP_VALIDATOR_SCOPE_LABEL' => 'Validation scope',
    'WSP_VALIDATOR_SCOPE_NORMAL' => 'Normal validation',
    'WSP_VALIDATOR_SCOPE_FULL' => 'Full validation',
    'WSP_VALIDATOR_SCOPE_PHPBB_EXT_DB' => 'phpBB Extension DB',
    'WSP_VALIDATOR_NO_ISSUES' => 'No issues found.',
    'WSP_VALIDATOR_ISSUES' => 'Issues found',
    'WSP_VALIDATOR_ACTION' => 'How to fix',
    'WSP_VALIDATOR_LINE' => 'Line',
    'WSP_VALIDATOR_EXCERPT' => 'Excerpt',
    'WSP_RELEASE_CHECKLIST' => 'Release checklist',
    'WSP_CLOSE' => 'Close',
    'WSP_ACTIVITY_RELEASE_VALIDATED' => 'ran release validation',
]);

$lang = array_merge($lang, [
    'WSP_VALIDATOR_FIXABLE' => 'safe fixes',
    'WSP_VALIDATOR_SAFE_FIX_AVAILABLE' => 'Safe automatic fix available',
    'WSP_VALIDATOR_APPLY_SAFE_FIXES' => 'Apply safe fixes',
    'WSP_VALIDATOR_APPLY_SAFE_FIXES_CONFIRM' => 'Apply only the safe automatic fixes? The current content of changed files will be stored in version history before the change.',
    'WSP_VALIDATOR_FIXES_APPLIED' => '%d safe fixes applied.',
    'WSP_ACTIVITY_RELEASE_FIXES_APPLIED' => 'applied safe release fixes',
    'WSP_VALIDATE_RELEASE_SHORT' => 'Validate phpBB',
    'WSP_COLLAB_SHORT' => 'Collaboration',
    'WSP_TOGGLE_COLLAB_PANEL' => 'Show or hide collaboration',
    'WSP_SHOW_COLLAB_PANEL' => 'Show collaboration',
    'WSP_HIDE_COLLAB_PANEL' => 'Hide collaboration',
    'WSP_EDITOR_FOCUS_MODE' => 'Editor focus mode',
    'WSP_COLLAB_TAB_TEAM' => 'Team',
    'WSP_COLLAB_TAB_TASKS' => 'Tasks',
    'WSP_COLLAB_TAB_REVIEW' => 'Review',
    'WSP_COLLAB_TAB_ACTIVITY' => 'Activity',
    'WSP_MENU_NEW' => 'New',
    'WSP_MENU_FILE' => 'File',
    'WSP_MENU_PROJECT' => 'Project',
    'WSP_MENU_EDITOR' => 'Editor',
    'WSP_MENU_LOCKS' => 'Locks',
    'WSP_MENU_EXPORT' => 'Export',
    'WSP_TREE_ACTIONS_HINT' => 'Rename, move and delete file/folder are available from the side tree icons.',
    'WSP_LOCKS_HINT' => 'File lock actions appear when a file is open and editing is allowed.',
]);

$lang = array_merge($lang, [
    'WSP_COLLAB_MODE' => 'Collaboration mode',
    'WSP_COLLAB_MODE_PRIVATE' => 'Private',
    'WSP_COLLAB_MODE_PM_REQUEST' => 'Requests by PM',
    'WSP_COLLAB_MODE_UPDATED' => 'Collaboration mode updated.',
    'WSP_REQUEST_COLLABORATION' => 'Request collaboration',
    'WSP_REQUEST_ROLE' => 'Desired role',
    'WSP_REQUEST_MESSAGE' => 'Message to the project owner',
    'WSP_REQUEST_MESSAGE_PLACEHOLDER' => 'Explain how you want to help with this project.',
    'WSP_COLLAB_REQUEST_SENT' => 'Collaboration request sent by private message.',
    'WSP_COLLAB_REQUEST_SAVED_NO_PM' => 'The request was recorded, but the private message could not be sent in this environment.',
    'WSP_ERR_COLLAB_REQUEST_FAILED' => 'Could not send the collaboration request.',
    'WSP_ERR_COLLAB_REQUESTS_DISABLED' => 'This project is not accepting collaboration requests.',
    'WSP_ERR_ALREADY_MEMBER' => 'You are already a member of this project.',
    'WSP_PM_COLLAB_SUBJECT' => 'Workspace collaboration request: %s',
    'WSP_PM_COLLAB_BODY' => "Hello,\n\n%s would like to collaborate on the Workspace project \"%s\".\n\nDesired role: %s\n\nMessage:\n%s\n\nPlease open the project team panel if you want to add this user as a member.",
    'WSP_PM_COLLAB_NO_MESSAGE' => '(No additional message.)',
    'WSP_NOTIFY_COLLAB_REQUESTED' => 'requested collaboration on your project',
    'WSP_LOG_COLLAB_MODE_CHANGED' => 'Collaboration mode changed to %s',
    'WSP_ACTIVITY_COLLABORATION_REQUESTED' => 'requested collaboration on project',
    'WSP_ACTIVITY_COLLABORATION_MODE_CHANGED' => 'changed collaboration mode to',
]);
