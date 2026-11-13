<?php
namespace mundophpbb\workspace\migrations\v120;

/** 1.35.0: version records linked to immutable release snapshots. */
class v120 extends \phpbb\db\migration\migration
{
	public static function depends_on() { return ['\\mundophpbb\\workspace\\migrations\\v119\\v119']; }
	public function effectively_installed() { return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_release_records'); }
	public function update_schema()
	{
		return ['add_tables' => [ $this->table_prefix . 'workspace_release_records' => [
			'COLUMNS' => [
				'release_id' => ['UINT', null, 'auto_increment'],
				'project_id' => ['UINT', 0],
				'checkpoint_id' => ['UINT', 0],
				'version' => ['VCHAR:40', ''],
				'package_name' => ['VCHAR:180', ''],
				'snapshot_sha' => ['VCHAR:64', ''],
				'notes' => ['TEXT', ''],
				'created_by' => ['UINT', 0],
				'created_time' => ['TIMESTAMP', 0],
			],
			'PRIMARY_KEY' => 'release_id',
			'KEYS' => [
				'project_version' => ['UNIQUE', ['project_id', 'version']],
				'project_history' => ['INDEX', ['project_id', 'release_id']],
			],
		]]];
	}
	public function revert_schema() { return ['drop_tables' => [$this->table_prefix . 'workspace_release_records']]; }
}
