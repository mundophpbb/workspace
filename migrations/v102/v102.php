<?php
namespace mundophpbb\workspace\migrations\v102;

/**
 * Mundo phpBB Workspace - Migration v102
 * Adiciona permissões granulares + lock + purge_cache e seta defaults nas roles.
 *
 * Motivo:
 * - v100/v101 já podem estar instaladas; não devemos alterá-las.
 * - v102 garante upgrade limpo e consistente.
 */
class v102 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return [
            '\mundophpbb\workspace\migrations\v101\v101',
        ];
    }

    public function effectively_installed()
    {
        return isset($this->config['mundophpbb_workspace_v102_perms']);
    }

    public function update_schema()
    {
        return [];
    }

    public function revert_schema()
    {
        return [];
    }

    public function update_data()
    {
        return [
            // ==========================================================
            // 1) ADD NOVAS PERMISSÕES (granulares + lock + purge_cache)
            // ==========================================================
            ['permission.add', ['u_workspace_edit']],
            ['permission.add', ['u_workspace_upload']],
            ['permission.add', ['u_workspace_rename_move']],
            ['permission.add', ['u_workspace_delete']],
            ['permission.add', ['u_workspace_replace']],
            ['permission.add', ['u_workspace_purge_cache']],
            ['permission.add', ['u_workspace_lock']],

            // ==========================================================
            // 2) DEFAULTS: ADMINS (FULL + STANDARD)
            // ==========================================================
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_purge_cache']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_lock']],

            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_purge_cache']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_lock']],

            // ==========================================================
            // 3) DEFAULTS: USERS
            // - STANDARD: editar/upload/rename_move/delete (SEM replace)
            // - FULL: inclui replace
            // ==========================================================
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_delete']],

            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_replace']],

            // marcador
            ['config.add', ['mundophpbb_workspace_v102_perms', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['permission.remove', ['u_workspace_edit']],
            ['permission.remove', ['u_workspace_upload']],
            ['permission.remove', ['u_workspace_rename_move']],
            ['permission.remove', ['u_workspace_delete']],
            ['permission.remove', ['u_workspace_replace']],
            ['permission.remove', ['u_workspace_purge_cache']],
            ['permission.remove', ['u_workspace_lock']],

            ['config.remove', ['mundophpbb_workspace_v102_perms']],
        ];
    }
}