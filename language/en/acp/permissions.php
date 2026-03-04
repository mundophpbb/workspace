<?php
/**
 * mundophpbb workspace extension [ACP Permissions - en]
 *
 * @package mundophpbb workspace
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
    // Category
    'ACL_CAT_WORKSPACE'            => 'Workspace',

    // Base
    'ACL_U_WORKSPACE_ACCESS'       => 'Can access the Workspace IDE',

    // ✅ NEW
    'ACL_U_WORKSPACE_VIEW'         => 'Can open/view projects in the Workspace',

    'ACL_U_WORKSPACE_CREATE'       => 'Can create projects in the Workspace',
    'ACL_U_WORKSPACE_DOWNLOAD'     => 'Can download projects as ZIP',

    // Granular (I/O)
    'ACL_U_WORKSPACE_LOCK'         => 'Can lock/unlock projects',
    'ACL_U_WORKSPACE_EDIT'         => 'Can edit and save files',
    'ACL_U_WORKSPACE_UPLOAD'       => 'Can upload files',
    'ACL_U_WORKSPACE_RENAME_MOVE'  => 'Can rename and move files/folders',
    'ACL_U_WORKSPACE_DELETE'       => 'Can delete files and folders',

    // Tools
    'ACL_U_WORKSPACE_REPLACE'      => 'Can use the mass replace tool',
    'ACL_U_WORKSPACE_PURGE_CACHE'  => 'Can purge the forum cache via IDE',

    // Management
    'ACL_U_WORKSPACE_MANAGE_OWN'   => 'Can manage own projects (rename/delete)',
    'ACL_U_WORKSPACE_MANAGE_ALL'   => 'Can manage all projects (admin/superuser)',
));