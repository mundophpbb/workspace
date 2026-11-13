<?php
namespace mundophpbb\workspace\migrations\v101;

/**
 * Workspace component.
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
	 * Updates data.
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
			// Workspace implementation detail.
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