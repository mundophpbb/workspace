<?php
namespace mundophpbb\workspace\migrations\v103;

/**
 * Workspace component.
 */
class v103 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		// Workspace implementation detail.
		return [
			'\mundophpbb\workspace\migrations\v102\v102',
		];
	}

	public function effectively_installed()
	{
		// Workspace implementation detail.
		if (!isset($this->config['mundophpbb_workspace_v103_perms']))
		{
			return false;
		}

		// Workspace implementation detail.
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
			// Workspace implementation detail.
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
			// Workspace implementation detail.
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
			// Workspace implementation detail.
			// Workspace implementation detail.
			// Workspace implementation detail.
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
		// Workspace implementation detail.
		// Workspace implementation detail.
		return [
			['config.remove', ['mundophpbb_workspace_v103_perms']],
		];
	}
}