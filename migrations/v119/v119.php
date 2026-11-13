<?php
namespace mundophpbb\workspace\migrations\v119;

/** Workspace 1.27.0: isolated public GitHub project links and shared API budget. */
class v119 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\mundophpbb\\workspace\\migrations\\v118\\v118'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_github_links') &&
			$this->db_tools->sql_table_exists($this->table_prefix . 'workspace_github_budget');
	}

	public function update_schema()
	{
		return ['add_tables' => [
			$this->table_prefix . 'workspace_github_links' => [
				'COLUMNS' => [
					'project_id' => ['UINT', 0],
					'repo_owner' => ['VCHAR:40', ''],
					'repo_name' => ['VCHAR:100', ''],
					'repo_ref' => ['VCHAR:120', ''],
					'head_sha' => ['VCHAR:40', ''],
					'checked_at' => ['TIMESTAMP', 0],
					'imported_sha' => ['VCHAR:40', ''],
					'imported_at' => ['TIMESTAMP', 0],
					'linked_by' => ['UINT', 0],
					'linked_at' => ['TIMESTAMP', 0],
				],
				'PRIMARY_KEY' => 'project_id',
			],
			$this->table_prefix . 'workspace_github_budget' => [
				'COLUMNS' => [
					'budget_id' => ['UINT', 0],
					'window_start' => ['TIMESTAMP', 0],
					'used_count' => ['UINT', 0],
				],
				'PRIMARY_KEY' => 'budget_id',
			],
		]];
	}

	public function update_data()
	{
		return [['custom', [[$this, 'seed_budget']]]];
	}

	public function seed_budget()
	{
		$table = $this->table_prefix . 'workspace_github_budget';
		$result = $this->db->sql_query('SELECT budget_id FROM ' . $table . ' WHERE budget_id = 1');
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row)
			$this->db->sql_query('INSERT INTO ' . $table . ' ' .
				$this->db->sql_build_array('INSERT', ['budget_id' => 1, 'window_start' => time(), 'used_count' => 0]));
	}

	public function revert_schema()
	{
		return ['drop_tables' => [$this->table_prefix . 'workspace_github_budget', $this->table_prefix . 'workspace_github_links']];
	}
}
