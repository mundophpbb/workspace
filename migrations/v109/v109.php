<?php
namespace mundophpbb\workspace\migrations\v109;

/**
 * Mundo phpBB Workspace - Migration v109
 * Adiciona bloqueio colaborativo por arquivo.
 */
class v109 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return [
            '\mundophpbb\workspace\migrations\v108\v108',
        ];
    }

    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_file_locks');
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'workspace_file_locks' => [
                    'COLUMNS' => [
                        'lock_id'      => ['UINT', null, 'auto_increment'],
                        'project_id'   => ['UINT', 0],
                        'file_id'      => ['UINT', 0],
                        'user_id'      => ['UINT', 0],
                        'locked_time'  => ['TIMESTAMP', 0],
                        'expires_time' => ['TIMESTAMP', 0],
                        'note'         => ['TEXT_UNI', ''],
                    ],
                    'PRIMARY_KEY' => 'lock_id',
                    'KEYS' => [
                        'file_id'      => ['UNIQUE', 'file_id'],
                        'project_file' => ['INDEX', ['project_id', 'file_id']],
                        'user_time'    => ['INDEX', ['user_id', 'locked_time']],
                        'expires_time' => ['INDEX', 'expires_time'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'workspace_file_locks',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_workspace_v109_file_locks', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_workspace_v109_file_locks']],
        ];
    }
}
