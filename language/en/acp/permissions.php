<?php
/**
 * mundophpbb workspace extension [ACP Permissions - English]
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = array(); }
$lang = array_merge($lang, array(
    'ACL_U_WORKSPACE_ACCESS' => 'Can access the Workspace IDE',
));