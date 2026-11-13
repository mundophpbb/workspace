<?php
namespace mundophpbb\workspace\migrations\v111;

/**
 * Workspace component.
 */
class v111 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\mundophpbb\workspace\migrations\v110\v110',
		];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_tasks');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'workspace_tasks' => [
					'COLUMNS' => [
						'task_id'      => ['UINT', null, 'auto_increment'],
						'project_id'   => ['UINT', 0],
						'file_id'      => ['UINT', 0],
						'title'        => ['VCHAR:180', ''],
						'description'  => ['TEXT_UNI', ''],
						'status'       => ['VCHAR:24', 'todo'],
						'priority'     => ['VCHAR:24', 'normal'],
						'assigned_to'  => ['UINT', 0],
						'created_by'   => ['UINT', 0],
						'created_time' => ['TIMESTAMP', 0],
						'updated_by'   => ['UINT', 0],
						'updated_time' => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'task_id',
					'KEYS' => [
						'project_status' => ['INDEX', ['project_id', 'status']],
						'project_file'   => ['INDEX', ['project_id', 'file_id']],
						'assigned'       => ['INDEX', ['assigned_to', 'status']],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'workspace_tasks',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mundophpbb_workspace_v111_tasks', 1]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['mundophpbb_workspace_v111_tasks']],
		];
	}
}
