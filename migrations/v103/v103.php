<?php
namespace mundophpbb\workspace\migrations\v103;

/**
 * Mundo phpBB Workspace - Migration v103
 * - Adiciona a permissão NOVA: u_workspace_view (abrir/visualizar projetos)
 * - Garante que as demais permissões existam (caso upgrade antigo não tenha rodado)
 * - Aplica defaults seguros para Admins
 *
 * Observação:
 * - Evitamos remover ACLs no revert por segurança (upgrades/roles).
 * - ✅ effectively_installed agora também verifica se a permissão NOVA existe
 *   (se você já tinha o marker antigo, mas não tem u_workspace_view, a migration roda de novo).
 */
class v103 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        // depende da sua última migration (ajuste se sua última for outra)
        return [
            '\mundophpbb\workspace\migrations\v102\v102',
        ];
    }

    public function effectively_installed()
    {
        // Se o marker não existe, ainda não instalou
        if (!isset($this->config['mundophpbb_workspace_v103_perms']))
        {
            return false;
        }

        // ✅ Se o marker existe mas a permissão nova NÃO existe, precisa rodar
        $sql = "SELECT auth_option_id
                FROM " . ACL_OPTIONS_TABLE . "
                WHERE auth_option = 'u_workspace_view'";

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return !empty($row);
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
            // 1) GARANTE QUE TODAS AS ACLs EXISTEM
            // ==========================================================
            ['permission.add', ['u_workspace_access']],
            ['permission.add', ['u_workspace_view']],          // ✅ NOVO (abrir/visualizar)
            ['permission.add', ['u_workspace_create']],
            ['permission.add', ['u_workspace_download']],
            ['permission.add', ['u_workspace_edit']],
            ['permission.add', ['u_workspace_upload']],
            ['permission.add', ['u_workspace_rename_move']],
            ['permission.add', ['u_workspace_delete']],
            ['permission.add', ['u_workspace_replace']],
            ['permission.add', ['u_workspace_purge_cache']],
            ['permission.add', ['u_workspace_manage_own']],
            ['permission.add', ['u_workspace_manage_all']],
            ['permission.add', ['u_workspace_lock']],

            // ==========================================================
            // 2) DEFAULTS PARA ADMINS (FULL + STANDARD) — tudo liberado
            // ==========================================================
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_view']],      // ✅
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_edit']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_purge_cache']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_own']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_all']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_lock']],

            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_view']], // ✅
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_create']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_download']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_edit']], // ✅ FIX: era SATANDARD
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_upload']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_rename_move']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_delete']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_replace']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_purge_cache']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_manage_own']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_manage_all']],
            ['permission.permission_set', ['ROLE_ADMIN_STANDARD', 'u_workspace_lock']],

            // ==========================================================
            // 3) DEFAULTS PARA USUÁRIOS (opcional)
            // - Se você quer "registrado só abre e baixa",
            //   deixe isso para o ACP (grupo Registered users), ou descomente abaixo.
            // ==========================================================
            // ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_access']],
            // ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_view']],
            // ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_download']],
            // ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_access']],
            // ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_view']],
            // ['permission.permission_set', ['ROLE_USER_FULL', 'u_workspace_download']],

            // marcador
            ['config.add', ['mundophpbb_workspace_v103_perms', 1]],
        ];
    }

    public function revert_data()
    {
        // Revert seguro:
        // - NÃO removemos ACLs por serem globais do sistema e poderem estar em uso por roles.
        // - removemos apenas o marcador da migration.
        return [
            ['config.remove', ['mundophpbb_workspace_v103_perms']],
        ];
    }
}