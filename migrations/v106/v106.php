<?php
namespace mundophpbb\workspace\migrations\v106;

/**
 * Mundo phpBB Workspace - Migration v106
 * Adiciona comentarios de revisao por arquivo.
 */
class v106 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return [
            '\\mundophpbb\\workspace\\migrations\\v105\\v105',
        ];
    }

    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'workspace_comments');
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'workspace_comments' => [
                    'COLUMNS' => [
                        'comment_id'    => ['UINT', null, 'auto_increment'],
                        'project_id'    => ['UINT', 0],
                        'file_id'       => ['UINT', 0],
                        'user_id'       => ['UINT', 0],
                        'line_number'   => ['UINT', 0],
                        'message'       => ['TEXT_UNI', ''],
                        'resolved'      => ['BOOL', 0],
                        'resolved_by'   => ['UINT', 0],
                        'resolved_time' => ['TIMESTAMP', 0],
                        'created_time'  => ['TIMESTAMP', 0],
                        'updated_time'  => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'comment_id',
                    'KEYS' => [
                        'file_status'  => ['INDEX', ['file_id', 'resolved']],
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
                $this->table_prefix . 'workspace_comments',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['mundophpbb_workspace_v106_comments', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['mundophpbb_workspace_v106_comments']],
        ];
    }
}
