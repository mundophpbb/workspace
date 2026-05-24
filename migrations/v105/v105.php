<?php
namespace mundophpbb\workspace\migrations\v105;

/**
 * Mundo phpBB Workspace - Migration v105
 * Adiciona timeline colaborativa de atividades por projeto.
 */
class v105 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return [
            '\\mundophpbb\\workspace\\migrations\\v104\\v104',
        ];
    }

    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_activity');
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'workspace_activity' => [
                    'COLUMNS' => [
                        'activity_id'  => ['UINT', null, 'auto_increment'],
                        'project_id'   => ['UINT', 0],
                        'user_id'      => ['UINT', 0],
                        'action'       => ['VCHAR:80', ''],
                        'object_type'  => ['VCHAR:32', ''],
                        'object_path'  => ['VCHAR:255', ''],
                        'created_time' => ['TIMESTAMP', 0],
                        'metadata'     => ['TEXT_UNI', ''],
                    ],
                    'PRIMARY_KEY' => 'activity_id',
                    'KEYS' => [
                        'project_time' => ['INDEX', ['project_id', 'created_time']],
                        'user_id'      => ['INDEX', 'user_id'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'workspace_activity',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_workspace_v105_activity', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_workspace_v105_activity']],
        ];
    }
}
