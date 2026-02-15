<?php
namespace mundophpbb\workspace\migrations\v100;

class initial_schema extends \phpbb\db\migration\migration
{
    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'workspace_projects' => [
                    'COLUMNS' => [
                        'project_id' => ['UINT', null, 'auto_increment'],
                        'project_name' => ['VCHAR:255', ''],
                        'project_desc' => ['TEXT_UNI', ''],
                        'project_time' => ['TIMESTAMP', 0],
                        'user_id' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'project_id',
                ],
                $this->table_prefix . 'workspace_files' => [
                    'COLUMNS' => [
                        'file_id' => ['UINT', null, 'auto_increment'],
                        'project_id' => ['UINT', 0],
                        'file_name' => ['VCHAR:255', ''],
                        'file_content' => ['MTEXT_UNI', ''],
                        'file_type' => ['VCHAR:50', 'php'],
                        'file_time' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'file_id',
                    'KEYS' => [
                        'project_id' => ['INDEX', 'project_id'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'workspace_projects',
                $this->table_prefix . 'workspace_files',
            ],
        ];
    }

    public function update_data()
    {
        return [
            // Adiciona a permissão ACL para acesso ao Workspace
            ['permission.add', ['u_workspace_access']],

            // Atribui a permissão ao grupo REGISTERED (usuários comuns) por padrão
            ['permission.permission_set', ['REGISTERED', 'u_workspace_access', 'group']],

            // Atribui ao papel padrão de usuário (para herança)
            ['permission.permission_set', ['ROLE_USER_STANDARD', 'u_workspace_access']],

            // Instala o BBCODE para diff (mantido)
            ['custom', [[$this, 'install_diff_bbcode']]],

            // Força UTF8MB4 para suporte a emojis (mantido)
            ['custom', [[$this, 'force_utf8mb4_storage']]],
        ];
    }

    public function force_utf8mb4_storage()
    {
        $sql_layer = $this->db->get_sql_layer();
        // Forçamos a tentativa se for MySQL/MariaDB
        if (strpos($sql_layer, 'mysql') !== false || strpos($sql_layer, 'mysqli') !== false)
        {
            // Tentativa 1: utf8mb4_unicode_ci (Mais moderno e compatível com emojis)
            $sql = 'ALTER TABLE ' . $this->table_prefix . 'workspace_files
                MODIFY file_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
            try
            {
                $this->db->sql_query($sql);
            }
            catch (\Exception $e)
            {
                // Se falhar, o servidor realmente não suporta utf8mb4
                return false;
            }
        }
        return true;
    }

    public function install_diff_bbcode()
    {
        $bbcode_tag = 'diff';
        $bbcodes_table = $this->table_prefix . 'bbcodes';
        $sql = 'SELECT bbcode_id FROM ' . $bbcodes_table . '
            WHERE bbcode_tag = "' . $this->db->sql_escape($bbcode_tag) . '"';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            $sql_ary = [
                'bbcode_tag' => $bbcode_tag,
                'bbcode_match' => '[diff={TEXT}]{TEXT1}[/diff]',
                'bbcode_tpl' => '<div class="diff-wizard"><div class="diff-header">{TEXT}</div><pre class="diff-content">{TEXT1}</pre></div>',
                'display_on_posting' => 0,
                'bbcode_helpline' => 'MUNDOPHPBB_WORKSPACE_DIFF_HELPLINE',
                'first_pass_match' => '/\[diff=(.*?)\](.*?)\[\/diff\]/is',
                'first_pass_replace' => '[diff=$1]$2[/diff]',
                'second_pass_match' => '',
                'second_pass_replace' => '',
            ];
            $this->db->sql_query('INSERT INTO ' . $bbcodes_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
        }
        return true;
    }
}