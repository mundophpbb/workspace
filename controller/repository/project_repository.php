<?php
namespace mundophpbb\workspace\repository;

/**
 * Mundo phpBB Workspace - Project Repository
 * Camada de abstração de dados (DBAL) para projetos e membros.
 */
class project_repository
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var string */
    protected $table_prefix;

    /**
     * Construtor
     */
    public function __construct(\phpbb\db\driver\driver_interface $db, $table_prefix)
    {
        $this->db = $db;
        $this->table_prefix = (string) $table_prefix;
    }

    /**
     * Retorna o ID do dono (owner) do projeto.
     */
    public function get_project_owner_id($project_id)
    {
        $sql = 'SELECT user_id
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . (int) $project_id;

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (int) $row['user_id'] : 0;
    }

    /**
     * Retorna a lista de arquivos de um projeto de forma ordenada.
     * Centralizado aqui para garantir que a Sidebar sempre receba o mesmo padrão.
     */
    public function get_project_files($project_id)
    {
        $sql = 'SELECT file_id, file_name, file_type, file_time
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . '
                ORDER BY file_name ASC';

        $result = $this->db->sql_query($sql);
        $rows = $this->db->sql_fetchrowset($result);
        $this->db->sql_freeresult($result);

        return $rows ?: [];
    }

    /**
     * Retorna role do usuário no projeto (tabela workspace_projects_users).
     */
    public function get_user_role($project_id, $user_id)
    {
        $sql = 'SELECT role
                FROM ' . $this->table_prefix . 'workspace_projects_users
                WHERE project_id = ' . (int) $project_id . '
                  AND user_id = ' . (int) $user_id;

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (string) $row['role'] : '';
    }

    /**
     * Adiciona um novo membro/colaborador ao projeto.
     */
    public function add_member($project_id, $user_id, $role, $added_by = 0)
    {
        $sql_ary = [
            'project_id' => (int) $project_id,
            'user_id'    => (int) $user_id,
            'role'       => $this->sanitize_role($role),
            'added_by'   => (int) $added_by,
            'added_time' => time(),
        ];

        $sql = 'INSERT INTO ' . $this->table_prefix . 'workspace_projects_users ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);

        return true;
    }

    /**
     * Atualiza ou Insere (Upsert) um membro.
     */
    public function upsert_member($project_id, $user_id, $role, $added_by = 0)
    {
        $project_id = (int) $project_id;
        $user_id = (int) $user_id;
        $role = $this->sanitize_role($role);

        $existing_role = $this->get_user_role($project_id, $user_id);

        if ($existing_role !== '')
        {
            $sql = 'UPDATE ' . $this->table_prefix . "workspace_projects_users
                    SET role = '" . $this->db->sql_escape($role) . "'
                    WHERE project_id = $project_id
                      AND user_id = $user_id";
            $this->db->sql_query($sql);
            return true;
        }

        return $this->add_member($project_id, $user_id, $role, $added_by);
    }

    /**
     * Verifica se o projeto está bloqueado.
     */
    public function is_project_locked($project_id)
    {
        $sql = 'SELECT project_locked
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . (int) $project_id;

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (bool) $row['project_locked'] : false;
    }

    /**
     * Retorna detalhes completos do Lock do projeto.
     */
    public function get_project_lock_info($project_id)
    {
        $sql = 'SELECT project_locked, locked_by, locked_time
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . (int) $project_id;

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return [
                'project_locked' => false,
                'locked_by'      => 0,
                'locked_time'    => 0,
            ];
        }

        return [
            'project_locked' => (bool) $row['project_locked'],
            'locked_by'      => (int) $row['locked_by'],
            'locked_time'    => (int) $row['locked_time'],
        ];
    }

    /**
     * Limpa a role de acordo com os padrões permitidos.
     */
    public function sanitize_role($role)
    {
        $role = strtolower(trim((string) $role));
        $allowed = ['owner', 'collab', 'viewer'];
        
        return in_array($role, $allowed, true) ? $role : 'viewer';
    }
}