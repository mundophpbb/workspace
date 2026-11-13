<?php
namespace mundophpbb\workspace\migrations\v114;

/**
 * Workspace v114 - A file name is unique inside a project.
 *
 * Replaces the plain (project_id, file_name) index with a UNIQUE one so that two
 * concurrent uploads/creates/renames cannot produce duplicate files. Duplicates
 * that already exist were merged by v113.
 */
class v114 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v113\\v113',
		];
	}

	public function effectively_installed()
	{
		$table = $this->table_prefix . 'workspace_files';

		return $this->db_tools->sql_table_exists($table)
			&& $this->db_tools->sql_unique_index_exists($table, 'proj_uq');
	}

	public function update_schema()
	{
		$table = $this->table_prefix . 'workspace_files';

		return [
			'drop_keys' => [
				$table => ['proj_file'],
			],
			'add_unique_index' => [
				$table => [
					'proj_uq' => ['project_id', 'file_name'],
				],
			],
		];
	}

	public function revert_schema()
	{
		$table = $this->table_prefix . 'workspace_files';

		return [
			'drop_keys' => [
				$table => ['proj_uq'],
			],
			'add_index' => [
				$table => [
					'proj_file' => ['project_id', 'file_name'],
				],
			],
		];
	}
}
