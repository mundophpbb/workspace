<?php
namespace mundophpbb\workspace\migrations\v100;

/**
 * Mundo phpBB Workspace - Migration v100
 * Versão 4.2: Integridade Total & Limpeza de Desinstalação
 * Gerencia a infraestrutura de dados, permissões e BBCodes da IDE.
 */
class v100 extends \phpbb\db\migration\migration
{
    /**
     * Define as tabelas e colunas (Schema Update)
     */
    public function update_schema()
    {
        return [
            'add_tables' => [
                // 1. Tabela de Projetos: Armazena o cabeçalho do projeto
                $this->table_prefix . 'workspace_projects' => [
                    'COLUMNS' => [
                        'project_id'     => ['UINT', null, 'auto_increment'],
                        'project_name'   => ['VCHAR:255', ''],
                        'project_desc'   => ['TEXT_UNI', ''],
                        'project_time'   => ['TIMESTAMP', 0],
                        'user_id'        => ['UINT', 0],
                        'project_locked' => ['BOOL', 0],
                        'locked_by'      => ['UINT', 0],
                        'locked_time'    => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'project_id',
                    'KEYS' => [
                        'user_id'    => ['INDEX', 'user_id'],
                        'locked_by'  => ['INDEX', 'locked_by'],
                    ],
                ],

                // 2. Tabela de Arquivos: O "HD" virtual da IDE
                $this->table_prefix . 'workspace_files' => [
                    'COLUMNS' => [
                        'file_id'      => ['UINT', null, 'auto_increment'],
                        'project_id'   => ['UINT', 0],
                        'file_name'    => ['VCHAR:255', ''],
                        'file_content' => ['MTEXT_UNI', ''], // MEDIUMTEXT para códigos longos
                        'file_type'    => ['VCHAR:50', 'php'],
                        'file_time'    => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'file_id',
                    'KEYS' => [
                        'project_id' => ['INDEX', 'project_id'],
                        'proj_file'  => ['INDEX', ['project_id', 'file_name']],
                    ],
                ],

                // 3. Vínculo de Colaboração: Quem pode acessar o quê
                $this->table_prefix . 'workspace_projects_users' => [
                    'COLUMNS' => [
                        'id'           => ['UINT', null, 'auto_increment'],
                        'project_id'   => ['UINT', 0],
                        'user_id'      => ['UINT', 0],
                        'role'         => ['VCHAR:32', 'collab'],
                        'added_by'     => ['UINT', 0],
                        'added_time'   => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'id',
                    'KEYS' => [
                        'proj_user'  => ['UNIQUE', ['project_id', 'user_id']],
                        'user_lookup' => ['INDEX', 'user_id'],
                    ],
                ],

                // 4. Permissões Granulares (ACL de Projeto)
                $this->table_prefix . 'workspace_permissions' => [
                    'COLUMNS' => [
                        'perm_id'      => ['UINT', null, 'auto_increment'],
                        'project_id'   => ['UINT', 0],
                        'entity_type'  => ['VCHAR:10', 'user'],
                        'entity_id'    => ['UINT', 0],
                        'can_view'     => ['BOOL', 1],
                        'can_edit'     => ['BOOL', 0],
                        'can_manage'   => ['BOOL', 0],
                        'can_delete'   => ['BOOL', 0],
                        'granted_by'   => ['UINT', 0],
                        'granted_time' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'perm_id',
                    'KEYS' => [
                        'unique_perm' => ['UNIQUE', ['project_id', 'entity_type', 'entity_id']],
                    ],
                ],
            ],
        ];
    }

    /**
     * Limpeza Total: Remove todas as tabelas na desinstalação (Schema Revert)
     */
    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'workspace_permissions',
                $this->table_prefix . 'workspace_projects_users',
                $this->table_prefix . 'workspace_files',
                $this->table_prefix . 'workspace_projects',
            ],
        ];
    }

    /**
     * Atualização de Dados: Permissões, BBCodes e Otimização UTF8MB4
     */
    public function update_data()
    {
        return [
            // Adição das Permissões de Usuário (U_)
            ['permission.add', ['u_workspace_access', true]],
            ['permission.add', ['u_workspace_create', true]],
            ['permission.add', ['u_workspace_download', true]],
            ['permission.add', ['u_workspace_manage_own', true]],
            ['permission.add', ['u_workspace_manage_all', true]],

            // Atribuição Automática ao Administrador
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_access']],
            ['permission.permission_set', ['ROLE_ADMIN_FULL', 'u_workspace_manage_all']],

            // Chamada de funções customizadas para suporte avançado
            ['custom', [[$this, 'install_diff_bbcode']]],
            ['custom', [[$this, 'optimize_database_storage']]],
        ];
    }

    /**
     * Reversão de Dados: Remove tudo o que foi injetado fora das tabelas
     */
    public function revert_data()
    {
        return [
            // Remove definições de permissão do sistema
            ['permission.remove', ['u_workspace_access']],
            ['permission.remove', ['u_workspace_create']],
            ['permission.remove', ['u_workspace_download']],
            ['permission.remove', ['u_workspace_manage_own']],
            ['permission.remove', ['u_workspace_manage_all']],

            // Remove o BBCode [diff] customizado
            ['custom', [[$this, 'uninstall_diff_bbcode']]],
        ];
    }

    /**
     * Garante que a IDE suporte Emojis e caracteres especiais de código moderno (utf8mb4)
     */
    public function optimize_database_storage()
    {
        $sql_layer = $this->db->get_sql_layer();

        if (strpos($sql_layer, 'mysql') !== false || strpos($sql_layer, 'mysqli') !== false)
        {
            // Converte a coluna de conteúdo para LONGTEXT REAL (suporta arquivos imensos)
            $sql = 'ALTER TABLE ' . $this->table_prefix . "workspace_files 
                    MODIFY file_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $this->db->sql_query($sql);
        }
        return true;
    }

    /**
     * Instala o BBCode [diff] para uso no fórum
     */
    public function install_diff_bbcode()
    {
        $this->uninstall_diff_bbcode(); // Limpeza prévia
        $bbcode_id = $this->get_free_bbcode_id();
        if (!$bbcode_id) return true;

        $sql_ary = [
            'bbcode_id'           => (int) $bbcode_id,
            'bbcode_tag'          => 'diff',
            'bbcode_match'        => '[diff={TEXT}]{TEXT1}[/diff]',
            'bbcode_tpl'          => '<div class="wsp-diff-box"><div class="wsp-diff-header"><i class="fa fa-code"></i> {TEXT}</div><pre class="wsp-diff-content">{TEXT1}</pre></div>',
            'display_on_posting'  => 0,
            'bbcode_helpline'     => 'Visualizar Diff: [diff=arquivo.php]conteúdo[/diff]',
            'first_pass_match'    => '/\[diff=(.*?)\](.*?)\[\/diff\]/is',
            'first_pass_replace'  => '[diff=$1]$2[/diff]',
            'second_pass_match'   => '',
            'second_pass_replace' => '',
        ];

        $this->db->sql_query('INSERT INTO ' . BBCODES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
        return true;
    }

    /**
     * Remove o BBCode do banco de dados na desinstalação
     */
    public function uninstall_diff_bbcode()
    {
        $sql = 'DELETE FROM ' . BBCODES_TABLE . " WHERE bbcode_tag = 'diff'";
        $this->db->sql_query($sql);
        return true;
    }

    /**
     * Encontra um ID livre na tabela de BBCodes do phpBB
     */
    private function get_free_bbcode_id()
    {
        $sql = 'SELECT bbcode_id FROM ' . BBCODES_TABLE . ' ORDER BY bbcode_id ASC';
        $result = $this->db->sql_query($sql);
        $used = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $used[(int) $row['bbcode_id']] = true;
        }
        $this->db->sql_freeresult($result);

        for ($i = 1; $i <= 255; $i++) {
            if (empty($used[$i])) return $i;
        }
        return 0;
    }
}