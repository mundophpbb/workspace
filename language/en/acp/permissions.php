<?php
/**
 * mundophpbb workspace extension [ACP Permissions - English]
 */
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACL_U_WORKSPACE_ACCESS'     => 'Can access the Workspace IDE',
    'ACL_U_WORKSPACE_CREATE'     => 'Can create Workspace projects',
    'ACL_U_WORKSPACE_DOWNLOAD'   => 'Can download projects as ZIP',
    'ACL_U_WORKSPACE_MANAGE_OWN' => 'Can manage own Workspace projects (rename/delete)',
    'ACL_U_WORKSPACE_MANAGE_ALL' => 'Can manage all Workspace projects (admin)',
]);