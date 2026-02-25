<?php
/**
 * mundophpbb workspace extension [ACP Permissions - pt-br]
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
    'ACL_U_WORKSPACE_ACCESS'     => 'Pode acessar a IDE do Workspace',
    'ACL_U_WORKSPACE_CREATE'     => 'Pode criar projetos no Workspace',
    'ACL_U_WORKSPACE_DOWNLOAD'   => 'Pode baixar projetos em ZIP',
    'ACL_U_WORKSPACE_MANAGE_OWN' => 'Pode gerenciar os próprios projetos (renomear/excluir)',
    'ACL_U_WORKSPACE_MANAGE_ALL' => 'Pode gerenciar todos os projetos (admin)',
));