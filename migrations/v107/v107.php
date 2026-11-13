<?php
namespace mundophpbb\workspace\migrations\v107;

/**
 * Workspace component.
 */
class v107 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v106\\v106',
		];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_file_reviews');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'workspace_file_reviews' => [
					'COLUMNS' => [
						'review_id'     => ['UINT', null, 'auto_increment'],
						'project_id'    => ['UINT', 0],
						'file_id'       => ['UINT', 0],
						'status'        => ['VCHAR:32', 'pending'],
						'requested_by'  => ['UINT', 0],
						'reviewed_by'   => ['UINT', 0],
						'review_note'   => ['TEXT_UNI', ''],
						'created_time'  => ['TIMESTAMP', 0],
						'updated_time'  => ['TIMESTAMP', 0],
						'reviewed_time' => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'review_id',
					'KEYS' => [
						'file_status'  => ['INDEX', ['file_id', 'status']],
						'project_time' => ['INDEX', ['project_id', 'updated_time']],
						'requested_by' => ['INDEX', 'requested_by'],
						'reviewed_by'  => ['INDEX', 'reviewed_by'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'workspace_file_reviews',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mundophpbb_workspace_v107_file_reviews', 1]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['mundophpbb_workspace_v107_file_reviews']],
		];
	}
}
