<?php
namespace mundophpbb\workspace\service;

use mundophpbb\workspace\repository\project_repository;

/**
 * Mundo phpBB Workspace - Permission Service
 * Centraliza toda a lógica de autorização da IDE (Single Source of Truth).
 */
class permission_service
{
    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\user */
    protected $user;

    /** @var project_repository */
    protected $project_repo;

    /**
     * Construtor
     */
    public function __construct(\phpbb\auth\auth $auth, \phpbb\user $user, project_repository $project_repo)
    {
        $this->auth = $auth;
        $this->user = $user;
        $this->project_repo = $project_repo;
    }

    /**
     * Verifica se o usuário tem permissão básica de entrada na IDE.
     */
    public function can_access_workspace()
    {
        return (bool) $this->auth->acl_get('u_workspace_access');
    }

    /**
     * Verifica se o usuário pode criar novos projetos.
     */
    public function can_create_project()
    {
        return $this->can_access_workspace()
            && (bool) $this->auth->acl_get('u_workspace_create');
    }

    /**
     * Verifica se o usuário é um Administrador Global do Workspace (Superuser).
     */
    public function can_manage_all()
    {
        return $this->can_access_workspace()
            && (bool) $this->auth->acl_get('u_workspace_manage_all');
    }

    /**
     * Usuário pode visualizar o projeto?
     * @param int $project_id
     * @param int $user_id (0 para o usuário logado)
     */
    public function can_view_project($project_id, $user_id = 0)
    {
        if (!$this->can_access_workspace())
        {
            return false;
        }

        $project_id = (int) $project_id;
        $user_id = (int) ($user_id ?: $this->user->data['user_id']);

        // 1. Administrador global sempre vê tudo
        if ($this->can_manage_all())
        {
            return true;
        }

        // 2. Verifica se há uma role específica na tabela de membros (colaboração)
        $role = $this->project_repo->get_user_role($project_id, $user_id);
        if ($role !== '')
        {
            return true;
        }

        // 3. Fallback: Verifica se é o dono original (legacy owner)
        return ((int) $this->project_repo->get_project_owner_id($project_id) === $user_id);
    }

    /**
     * Retorna a Role efetiva do usuário no projeto (owner|collab|viewer).
     */
    public function get_role($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $user_id = (int) ($user_id ?: $this->user->data['user_id']);

        if ($this->can_manage_all())
        {
            return 'owner';
        }

        $role = $this->project_repo->get_user_role($project_id, $user_id);
        if ($role !== '')
        {
            return $role;
        }

        if ((int) $this->project_repo->get_project_owner_id($project_id) === $user_id)
        {
            return 'owner';
        }

        return '';
    }

    /**
     * Verifica se o projeto está com o Lock (bloqueio) ativado.
     */
    public function is_project_locked($project_id)
    {
        return (bool) $this->project_repo->is_project_locked((int) $project_id);
    }

    /**
     * Pode realizar edições (salvar, upload, renomear arquivos)?
     */
    public function can_edit_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        
        if (!$this->can_view_project($project_id, $user_id))
        {
            return false;
        }

        // Bloqueio de Lock: impede edição, exceto para Administradores Globais
        if ($this->is_project_locked($project_id) && !$this->can_manage_all())
        {
            return false;
        }

        $role = $this->get_role($project_id, $user_id);

        // Apenas donos e colaboradores podem editar
        return in_array($role, ['owner', 'collab'], true);
    }

    /**
     * Pode baixar o ZIP do projeto?
     */
    public function can_download_project($project_id, $user_id = 0)
    {
        if (!$this->can_view_project($project_id, $user_id))
        {
            return false;
        }

        // Checa se o usuário tem a ACL específica para downloads
        if (!(bool) $this->auth->acl_get('u_workspace_download'))
        {
            return false;
        }

        $role = $this->get_role($project_id, $user_id);

        // Visualizadores também podem baixar o código
        return in_array($role, ['owner', 'collab', 'viewer'], true);
    }

    /**
     * Pode gerenciar as configurações do projeto (Deletar, Renomear projeto)?
     */
    public function can_manage_project($project_id, $user_id = 0)
    {
        if ($this->can_manage_all())
        {
            return true;
        }

        if (!(bool) $this->auth->acl_get('u_workspace_manage_own'))
        {
            return false;
        }

        $role = $this->get_role($project_id, $user_id);

        // Apenas o dono (Owner) pode gerenciar a existência do projeto
        return ($role === 'owner');
    }

    /**
     * Pode aplicar ou remover o Lock de colaboração?
     */
    public function can_lock_project($project_id, $user_id = 0)
    {
        if ($this->can_manage_all())
        {
            return true;
        }

        $role = $this->get_role($project_id, $user_id);

        return ($role === 'owner');
    }
}