<?php
namespace mundophpbb\workspace\migrations\v101;

/**
 * Mundo phpBB Workspace - Migration v101
 * Ajustes de permissões default (roles) sem alterar schema.
 *
 * Motivo:
 * - v100 já cria as tabelas e permissões (permission.add).
 * - v101 apenas aplica permission_set em roles padrão (usuários e admins),
 *   evitando editar o v100 e mantendo upgrades consistentes.
 */
class v101 extends \phpbb\db\migration\migration
{
    /**
     * Esta migration depende da v100 (tabelas + permission.add + bbcode).
     */
    public static function depends_on()
    {
        return [
            '\mundophpbb\workspace\migrations\v100\v100',
        ];
    }

    public function update_schema()
    {
        return [];
    }

    public function revert_schema()
    {
        return [];
    }

    /**
     * Ajusta defaults nas roles.
     *
     * Observação:
     * - Se você NÃO quiser dar permissões para usuários padrão automaticamente,
     *   basta remover os blocos ROLE_USER_* abaixo e deixar só os ROLE_ADMIN_*.
     */
    public function update_data()
    {
        return [
            // ==========================================================
            // Admins: tudo (inclui granulares + lock + purge_cache)
            // ==========================================================
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_own']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_all']],

            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_lock']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_purge_cache']],

            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_manage_own']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_manage_all']],

            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_lock']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_purge_cache']],

            // ==========================================================
            // Usuários padrão: acesso normal (sem manage_all/lock/purge_cache)
            // ==========================================================
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_manage_own']],

            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_replace']],

            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_manage_own']],

            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_replace']],
        ];
    }

    public function revert_data()
    {
        return [];
    }
}