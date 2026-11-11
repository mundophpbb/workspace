<?php
namespace mundophpbb\workspace\migrations\v112;

/**
 * Workspace v112 - Project collaboration mode.
 *
 * New/imported projects remain private by default. Owners can enable
 * collaboration requests by private message when desired.
 */
class v112 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v111\\v111',
		];
	}

	public function effectively_installed()
	{
		$table = $this->table_prefix . 'workspace_projects';

		return $this->db_tools->sql_table_exists($table)
			&& $this->db_tools->sql_column_exists($table, 'collaboration_mode');
	}

	public function update_schema()
	{
		return [];
	}

	public function revert_schema()
	{
		return [];
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'ensure_collaboration_mode_column']]],
		];
	}

	public function revert_data()
	{
		return [];
	}

	public function ensure_collaboration_mode_column()
	{
		$table = $this->table_prefix . 'workspace_projects';

		if (!$this->db_tools->sql_table_exists($table))
		{
			return;
		}

		if (!$this->db_tools->sql_column_exists($table, 'collaboration_mode'))
		{
			$this->db_tools->sql_column_add($table, 'collaboration_mode', ['VCHAR:32', 'private']);
		}

		$sql = 'UPDATE ' . $table . "
				SET collaboration_mode = 'private'
				WHERE collaboration_mode = ''
				   OR collaboration_mode IS NULL";
		$this->db->sql_query($sql);
	}
}
