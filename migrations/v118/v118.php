<?php
namespace mundophpbb\workspace\migrations\v118;

/** Workspace 1.17.0: project-wide restoration checkpoints. */
class v118 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\mundophpbb\\workspace\\migrations\\v117\\v117'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_checkpoints')
			&& $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_checkpoint_files');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'workspace_checkpoints' => [
					'COLUMNS' => [
						'checkpoint_id' => ['UINT', null, 'auto_increment'],
						'project_id' => ['UINT', 0],
						'user_id' => ['UINT', 0],
						'label' => ['VCHAR:120', ''],
						'kind' => ['VCHAR:20', 'manual'],
						'created_time' => ['TIMESTAMP', 0],
						'file_count' => ['UINT', 0],
						'total_bytes' => ['UINT', 0],
						'fingerprint' => ['VCHAR:64', ''],
					],
					'PRIMARY_KEY' => 'checkpoint_id',
					'KEYS' => [
						'project_recent' => ['INDEX', ['project_id', 'checkpoint_id']],
					],
				],
				$this->table_prefix . 'workspace_checkpoint_files' => [
					'COLUMNS' => [
						'checkpoint_file_id' => ['UINT', null, 'auto_increment'],
						'checkpoint_id' => ['UINT', 0],
						'file_name' => ['VCHAR:255', ''],
						'file_type' => ['VCHAR:50', 'txt'],
						'file_content' => ['MTEXT_UNI', ''],
						'content_hash' => ['VCHAR:64', ''],
					],
					'PRIMARY_KEY' => 'checkpoint_file_id',
					'KEYS' => [
						'checkpoint_id' => ['INDEX', 'checkpoint_id'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return ['drop_tables' => [
			$this->table_prefix . 'workspace_checkpoint_files',
			$this->table_prefix . 'workspace_checkpoints',
		]];
	}
}
