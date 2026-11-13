<?php
namespace mundophpbb\workspace\migrations\v110;

/**
 * Workspace component.
 */
class v110 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\mundophpbb\workspace\migrations\v109\v109',
		];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_notifications');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'workspace_notifications' => [
					'COLUMNS' => [
						'notification_id' => ['UINT', null, 'auto_increment'],
						'project_id'      => ['UINT', 0],
						'user_id'         => ['UINT', 0],
						'actor_id'        => ['UINT', 0],
						'event'           => ['VCHAR:64', ''],
						'object_type'     => ['VCHAR:32', ''],
						'object_path'     => ['VCHAR:255', ''],
						'message_key'     => ['VCHAR:128', ''],
						'metadata'        => ['TEXT_UNI', ''],
						'is_read'         => ['BOOL', 0],
						'created_time'    => ['TIMESTAMP', 0],
						'read_time'       => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'notification_id',
					'KEYS' => [
						'user_read_time' => ['INDEX', ['user_id', 'is_read', 'created_time']],
						'project_time'   => ['INDEX', ['project_id', 'created_time']],
						'actor_time'     => ['INDEX', ['actor_id', 'created_time']],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'workspace_notifications',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['mundophpbb_workspace_v110_notifications', 1]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['mundophpbb_workspace_v110_notifications']],
		];
	}
}
