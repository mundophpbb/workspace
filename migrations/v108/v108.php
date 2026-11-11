<?php
namespace mundophpbb\workspace\migrations\v108;

/**
 * Workspace component.
 */
class v108 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v107\\v107',
		];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_file_versions');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'workspace_file_versions' => [
					'COLUMNS' => [
						'version_id'    => ['UINT', null, 'auto_increment'],
						'project_id'    => ['UINT', 0],
						'file_id'       => ['UINT', 0],
						'user_id'       => ['UINT', 0],
						'file_name'     => ['VCHAR:255', ''],
						'file_content'  => ['MTEXT_UNI', ''],
						'content_hash'  => ['VCHAR:64', ''],
						'source'        => ['VCHAR:32', 'save'],
						'change_note'   => ['TEXT_UNI', ''],
						'created_time'  => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'version_id',
					'KEYS' => [
						'file_time'     => ['INDEX', ['file_id', 'created_time']],
						'project_time'  => ['INDEX', ['project_id', 'created_time']],
						'user_time'     => ['INDEX', ['user_id', 'created_time']],
						'content_hash'  => ['INDEX', 'content_hash'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'workspace_file_versions',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mundophpbb_workspace_v108_file_versions', 1]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['mundophpbb_workspace_v108_file_versions']],
		];
	}
}
