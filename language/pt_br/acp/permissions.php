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
    // Categoria
    'ACL_CAT_WORKSPACE'            => 'Workspace',

    // Base
    'ACL_U_WORKSPACE_ACCESS'       => 'Pode acessar a IDE do Workspace',

    // ✅ NOVO
    'ACL_U_WORKSPACE_VIEW'         => 'Pode abrir/visualizar projetos no Workspace',

    'ACL_U_WORKSPACE_CREATE'       => 'Pode criar projetos no Workspace',
    'ACL_U_WORKSPACE_DOWNLOAD'     => 'Pode baixar projetos em ZIP',

    // Granulares (I/O)
    'ACL_U_WORKSPACE_LOCK'         => 'Pode trancar/destrancar projetos',
    'ACL_U_WORKSPACE_EDIT'         => 'Pode editar e salvar arquivos',
    'ACL_U_WORKSPACE_UPLOAD'       => 'Pode fazer upload de arquivos',
    'ACL_U_WORKSPACE_RENAME_MOVE'  => 'Pode renomear e mover arquivos/pastas',
    'ACL_U_WORKSPACE_DELETE'       => 'Pode excluir arquivos e pastas',

    // Ferramentas
    'ACL_U_WORKSPACE_REPLACE'      => 'Pode usar a ferramenta de substituir em massa (Replace)',
    'ACL_U_WORKSPACE_PURGE_CACHE'  => 'Pode limpar o cache do fórum via IDE',

    // Gestão
    'ACL_U_WORKSPACE_MANAGE_OWN'   => 'Pode gerenciar os próprios projetos (renomear/excluir)',
    'ACL_U_WORKSPACE_MANAGE_ALL'   => 'Pode gerenciar todos os projetos (admin/superuser)',
));