<?php
namespace mundophpbb\workspace\migrations\v104;

/**
 * Mundo phpBB Workspace - Migration v104
 *
 * Garante as colunas de travamento de projeto em instalações atualizadas
 * a partir de versões antigas do Workspace.
 *
 * Observação:
 * - Instalações limpas já recebem essas colunas pela v100 atual.
 * - Instalações antigas podem ter a tabela workspace_projects sem esses campos,
 *   porque editar a migration inicial não altera bancos já migrados.
 */
class v104 extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return [
            '\\mundophpbb\\workspace\\migrations\\v103\\v103',
        ];
    }

    public function effectively_installed()
    {
        $table = $this->table_prefix . 'workspace_projects';

        return $this->db_tools->sql_table_exists($table)
            && $this->db_tools->sql_column_exists($table, 'project_locked')
            && $this->db_tools->sql_column_exists($table, 'locked_by')
            && $this->db_tools->sql_column_exists($table, 'locked_time');
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
            ['custom', [[$this, 'ensure_project_lock_columns']]],
        ];
    }

    public function revert_data()
    {
        return [
        ];
    }

    public function ensure_project_lock_columns()
    {
        $table = $this->table_prefix . 'workspace_projects';

        if (!$this->db_tools->sql_table_exists($table))
        {
            return;
        }

        if (!$this->db_tools->sql_column_exists($table, 'project_locked'))
        {
            $this->db_tools->sql_column_add($table, 'project_locked', ['BOOL', 0]);
        }

        if (!$this->db_tools->sql_column_exists($table, 'locked_by'))
        {
            $this->db_tools->sql_column_add($table, 'locked_by', ['UINT', 0]);
        }

        if (!$this->db_tools->sql_column_exists($table, 'locked_time'))
        {
            $this->db_tools->sql_column_add($table, 'locked_time', ['TIMESTAMP', 0]);
        }
    }
}
