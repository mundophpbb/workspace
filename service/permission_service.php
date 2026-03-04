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

    public function __construct(\phpbb\auth\auth $auth, \phpbb\user $user, project_repository $project_repo)
    {
        $this->auth = $auth;
        $this->user = $user;
        $this->project_repo = $project_repo;
    }

    /**
     * Retorna o user_id efetivo.
     */
    protected function uid($user_id = 0)
    {
        $uid = (int) ($user_id ?: ($this->user->data['user_id'] ?? 0));
        return $uid > 0 ? $uid : 0;
    }

    /**
     * Helper ACL (mantém tudo centralizado)
     */
    protected function acl($key)
    {
        return (bool) $this->auth->acl_get($key);
    }

    /**
     * Verifica se o usuário tem permissão básica de entrada na IDE.
     */
    public function can_access_workspace()
    {
        $uid = $this->uid();
        if ($uid <= 0)
        {
            return false;
        }

        return $this->acl('u_workspace_access');
    }

    /**
     * Verifica se o usuário pode criar novos projetos.
     */
    public function can_create_project()
    {
        return $this->can_access_workspace() && $this->acl('u_workspace_create');
    }

    /**
     * Verifica se o usuário é Administrador Global do Workspace.
     */
    public function can_manage_all()
    {
        return $this->can_access_workspace() && $this->acl('u_workspace_manage_all');
    }

    /**
     * Retorna a role efetiva do usuário no projeto (owner|collab|viewer|'' se sem acesso).
     */
    public function get_role($project_id, $user_id = 0)
    {
        if (!$this->can_access_workspace())
        {
            return '';
        }

        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if ($project_id <= 0 || $uid <= 0)
        {
            return '';
        }

        if (!$this->project_repo->project_exists($project_id))
        {
            return '';
        }

        // Admin global tem poder de owner para fins de autorização
        if ($this->can_manage_all())
        {
            return 'owner';
        }

        // tabela de membros tem precedência
        $role = (string) $this->project_repo->get_user_role($project_id, $uid);
        if ($role !== '')
        {
            return $role; // repo sanitiza owner/collab/viewer
        }

        // fallback: dono original
        if ((int) $this->project_repo->get_project_owner_id($project_id) === $uid)
        {
            return 'owner';
        }

        return '';
    }

    /**
     * Verifica se o projeto está com Lock.
     */
    public function is_project_locked($project_id)
    {
        if (!$this->can_access_workspace())
        {
            return false;
        }

        return (bool) $this->project_repo->is_project_locked((int) $project_id);
    }

    /**
     * Usuário pode visualizar/abrir o projeto?
     *
     * ✅ Regras finais (robustas):
     * - Precisa ter acesso ao workspace
     * - Admin global sempre pode (mesmo lockado)
     * - Para não-admin:
     *    - Se lockado => NÃO pode abrir
     *    - Se destrancado => pode abrir se:
     *        a) for membro/dono (role owner/collab/viewer) OU
     *        b) tiver a ACL u_workspace_view (abrir destrancados mesmo sem ser membro)
     */
    public function can_view_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
        {
            return false;
        }

        if (!$this->project_repo->project_exists($project_id))
        {
            return false;
        }

        // Admin global sempre pode ver (mesmo lockado)
        if ($this->can_manage_all())
        {
            return true;
        }

        // Lock bloqueia leitura/abertura para não-admin
        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        // Se é membro/dono, pode abrir (desde que destrancado)
        $role = $this->get_role($project_id, $uid);
        if ($role !== '')
        {
            return true;
        }

        // Caso não seja membro: precisa da permissão explícita de "abrir/visualizar"
        return $this->acl('u_workspace_view');
    }

    /**
     * Pode editar/salvar conteúdo (Ace/Save).
     * Requer ACL u_workspace_edit + role (owner/collab) e respeita lock (exceto admin).
     */
    public function can_edit_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        // Admin global ignora lock e ignora granular (pode tudo)
        if ($this->can_manage_all())
        {
            return true;
        }

        // Precisa da ACL granular
        if (!$this->acl('u_workspace_edit'))
        {
            return false;
        }

        // Lock bloqueia edição para não-admin (redundante aqui, mas seguro)
        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        // Só owner/collab podem editar
        $role = $this->get_role($project_id, $uid);
        return in_array($role, ['owner', 'collab'], true);
    }

    /**
     * Pode fazer upload.
     * Requer ACL u_workspace_upload + role (owner/collab) e respeita lock (exceto admin).
     */
    public function can_upload_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if (!$this->acl('u_workspace_upload'))
        {
            return false;
        }

        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        $role = $this->get_role($project_id, $uid);
        return in_array($role, ['owner', 'collab'], true);
    }

    /**
     * Pode renomear/mover arquivos e pastas.
     * Requer ACL u_workspace_rename_move + role (owner/collab) e respeita lock (exceto admin).
     */
    public function can_rename_move_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if (!$this->acl('u_workspace_rename_move'))
        {
            return false;
        }

        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        $role = $this->get_role($project_id, $uid);
        return in_array($role, ['owner', 'collab'], true);
    }

    /**
     * Pode excluir arquivos e pastas.
     * Requer ACL u_workspace_delete + role (owner/collab) e respeita lock (exceto admin).
     */
    public function can_delete_project_items($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if (!$this->acl('u_workspace_delete'))
        {
            return false;
        }

        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        $role = $this->get_role($project_id, $uid);
        return in_array($role, ['owner', 'collab'], true);
    }

    /**
     * Pode usar Replace em massa.
     * Requer ACL u_workspace_replace e também precisa poder editar (pois escreve).
     */
    public function can_replace_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if (!$this->acl('u_workspace_replace'))
        {
            return false;
        }

        // Replace escreve -> exige edit também
        return $this->can_edit_project($project_id, $uid);
    }

    /**
     * Alias útil: qualquer escrita (edit ou manage do projeto).
     */
    public function can_write_project($project_id, $user_id = 0)
    {
        return $this->can_edit_project($project_id, $user_id)
            || $this->can_manage_project($project_id, $user_id);
    }

    /**
     * Pode baixar ZIP do projeto.
     * Requer ACL u_workspace_download (exceto admin).
     *
     * ✅ Regras:
     * - Admin global sempre pode baixar (mesmo lockado)
     * - Não-admin: precisa poder ver/abrir (logo: destrancado) + ACL download
     * - NÃO exige ser membro (pois u_workspace_view pode liberar acesso público a destrancados)
     */
    public function can_download_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
        {
            return false;
        }

        // Admin global sempre pode baixar
        if ($this->can_manage_all())
        {
            return true;
        }

        // precisa poder abrir/ver (inclui regra do lock + u_workspace_view quando não for membro)
        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        // precisa da ACL download
        if (!$this->acl('u_workspace_download'))
        {
            return false;
        }

        return true;
    }

    /**
     * Pode gerenciar o projeto (rename/delete do projeto, etc.)?
     * Requer ACL u_workspace_manage_own, role=owner e respeita lock (exceto admin).
     */
    public function can_manage_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        if (!$this->acl('u_workspace_manage_own'))
        {
            return false;
        }

        return ($this->get_role($project_id, $uid) === 'owner');
    }

    /**
     * Lock/Unlock do projeto:
     * ✅ SOMENTE ADMIN GLOBAL do Workspace + ACL específica u_workspace_lock.
     */
    public function can_lock_project($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
        {
            return false;
        }

        // Somente admin global
        if (!$this->can_manage_all())
        {
            return false;
        }

        return $this->acl('u_workspace_lock');
    }

    /**
     * Purge cache do fórum via IDE:
     * ✅ GLOBAL: SOMENTE ADMIN GLOBAL + ACL u_workspace_purge_cache.
     */
    public function can_purge_cache()
    {
        return $this->can_manage_all() && $this->acl('u_workspace_purge_cache');
    }

    /**
     * (Opcional/futuro) Pode gerenciar membros do projeto?
     * Mantido compatível: admin sempre; owner com manage_own e sem lock.
     */
    public function can_manage_members($project_id, $user_id = 0)
    {
        $project_id = (int) $project_id;
        $uid = $this->uid($user_id);

        if (!$this->can_view_project($project_id, $uid))
        {
            return false;
        }

        if ($this->can_manage_all())
        {
            return true;
        }

        if ($this->is_project_locked($project_id))
        {
            return false;
        }

        if (!$this->acl('u_workspace_manage_own'))
        {
            return false;
        }

        return ($this->get_role($project_id, $uid) === 'owner');
    }
}