<?php
namespace mundophpbb\workspace\migrations\v100;

/**
 * Workspace component.
 */
class v100 extends \phpbb\db\migration\migration
{
	/**
	 * Define as tabelas e colunas (Schema Update)
	 */
	public function update_schema()
	{
		return [
			'add_tables' => [
				// Workspace implementation detail.
				$this->table_prefix . 'workspace_projects' => [
					'COLUMNS' => [
						'project_id'     => ['UINT', null, 'auto_increment'],
						'project_name'   => ['VCHAR:255', ''],
						'project_desc'   => ['TEXT_UNI', ''],
						'project_time'   => ['TIMESTAMP', 0],
						'user_id'        => ['UINT', 0],
						'project_locked' => ['BOOL', 0],
						'collaboration_mode' => ['VCHAR:32', 'private'],
						'locked_by'      => ['UINT', 0],
						'locked_time'    => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'project_id',
					'KEYS' => [
						'user_id'    => ['INDEX', 'user_id'],
						'locked_by'  => ['INDEX', 'locked_by'],
					],
				],

				// Workspace implementation detail.
				$this->table_prefix . 'workspace_files' => [
					'COLUMNS' => [
						'file_id'      => ['UINT', null, 'auto_increment'],
						'project_id'   => ['UINT', 0],
						'file_name'    => ['VCHAR:255', ''],
						'file_content' => ['MTEXT_UNI', ''], // Workspace implementation detail.
						'file_type'    => ['VCHAR:50', 'php'],
						'file_time'    => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'file_id',
					'KEYS' => [
						'project_id' => ['INDEX', 'project_id'],
						'proj_file'  => ['INDEX', ['project_id', 'file_name']],
					],
				],

				// Workspace implementation detail.
				$this->table_prefix . 'workspace_projects_users' => [
					'COLUMNS' => [
						'id'           => ['UINT', null, 'auto_increment'],
						'project_id'   => ['UINT', 0],
						'user_id'      => ['UINT', 0],
						'role'         => ['VCHAR:32', 'collab'],
						'added_by'     => ['UINT', 0],
						'added_time'   => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'id',
					'KEYS' => [
						'proj_user'  => ['UNIQUE', ['project_id', 'user_id']],
						'user_lookup' => ['INDEX', 'user_id'],
					],
				],

				// Workspace implementation detail.
				$this->table_prefix . 'workspace_permissions' => [
					'COLUMNS' => [
						'perm_id'      => ['UINT', null, 'auto_increment'],
						'project_id'   => ['UINT', 0],
						'entity_type'  => ['VCHAR:10', 'user'],
						'entity_id'    => ['UINT', 0],
						'can_view'     => ['BOOL', 1],
						'can_edit'     => ['BOOL', 0],
						'can_manage'   => ['BOOL', 0],
						'can_delete'   => ['BOOL', 0],
						'granted_by'   => ['UINT', 0],
						'granted_time' => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'perm_id',
					'KEYS' => [
						'unique_perm' => ['UNIQUE', ['project_id', 'entity_type', 'entity_id']],
					],
				],
			],
		];
	}

	/**
	 * Handles revert schema.
	 */
	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'workspace_permissions',
				$this->table_prefix . 'workspace_projects_users',
				$this->table_prefix . 'workspace_files',
				$this->table_prefix . 'workspace_projects',
			],
		];
	}

	/**
	 * Updates data.
	 */
	public function update_data()
	{
		return [
			// Workspace implementation detail.
			['permission.add', ['u_workspace_access', true]],
			['permission.add', ['u_workspace_create', true]],
			['permission.add', ['u_workspace_download', true]],
			['permission.add', ['u_workspace_manage_own', true]],
			['permission.add', ['u_workspace_manage_all', true]],

			// Workspace implementation detail.
			['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_access']],
			['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_all']],

			// Workspace implementation detail.
			['custom', [[$this, 'install_diff_bbcode']]],
		];
	}

	/**
	 * Handles revert data.
	 */
	public function revert_data()
	{
		return [
			// Workspace implementation detail.
			['permission.remove', ['u_workspace_access']],
			['permission.remove', ['u_workspace_create']],
			['permission.remove', ['u_workspace_download']],
			['permission.remove', ['u_workspace_manage_own']],
			['permission.remove', ['u_workspace_manage_all']],

			// Remove the custom [diff] BBCode
			['custom', [[$this, 'uninstall_diff_bbcode']]],
		];
	}

	/**
	 * Handles install diff bbcode.
	 */
	public function install_diff_bbcode()
	{
		$this->uninstall_diff_bbcode(); // Workspace implementation detail.
		$bbcode_id = $this->get_free_bbcode_id();
		if (!$bbcode_id) return true;

		$sql_ary = [
			'bbcode_id'           => (int) $bbcode_id,
			'bbcode_tag'          => 'diff',
			'bbcode_match'        => '[diff={TEXT}]{TEXT1}[/diff]',
			'bbcode_tpl'          => '<div class="wsp-diff-box"><div class="wsp-diff-header"><i class="fa fa-code"></i> {TEXT}</div><pre class="wsp-diff-content">{TEXT1}</pre></div>',
			'display_on_posting'  => 0,
			'bbcode_helpline'     => 'Visualizar Diff: [diff=arquivo.php]conteúdo[/diff]',
			'first_pass_match'    => '/\[diff=(.*?)\](.*?)\[\/diff\]/is',
			'first_pass_replace'  => '[diff=$1]$2[/diff]',
			'second_pass_match'   => '',
			'second_pass_replace' => '',
		];

		$this->db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
		return true;
	}

	/**
	 * Handles uninstall diff bbcode.
	 */
	public function uninstall_diff_bbcode()
	{
		$sql = 'DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_tag = 'diff'";
		$this->db->sql_query($sql);
		return true;
	}

	/**
	 * Gets free bbcode id.
	 */
	private function get_free_bbcode_id()
	{
		$sql = 'SELECT bbcode_id FROM ' . BBCODES_TABLE . ' ORDER BY bbcode_id ASC';
		$result = $this->db->sql_query($sql);
		$used = [];
		while ($row = $this->db->sql_fetchrow($result)) {
			$used[(int) $row['bbcode_id']] = true;
		}
		$this->db->sql_freeresult($result);

		for ($i = 1; $i <= 255; $i++) {
			if (empty($used[$i])) return $i;
		}
		return 0;
	}
}