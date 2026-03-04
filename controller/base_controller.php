<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\repository\project_repository;
use mundophpbb\workspace\service\permission_service;

/**
 * Mundo phpBB Workspace - Base Controller
 * Versão 4.6: Multilíngue + JSON Helpers + Gate central (SSOT) + Capabilities granulares
 * - ✅ Suporte à nova permissão u_workspace_view (abrir/visualizar projetos)
 * - ✅ Lock bloqueia view/download para não-admin (mas sem “vazar” existência para não-membros)
 */
abstract class base_controller
{
    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var string */
    protected $table_prefix;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var string */
    protected $phpbb_root_path;

    /** @var project_repository */
    protected $project_repo;

    /** @var permission_service */
    protected $permission_service;

    protected $allowed_extensions = [
        'php','js','ts','css','scss','sass','less','html','htm','twig','svg',
        'json','xml','yml','yaml','sql','md','csv','txt','log',
        'c','cpp','h','hpp','cs','java','py','rb','pl','pm','lua','go','rs',
        'kt','swift','dart','r','scala',
        'sh','bash','bat','ps1','dockerfile','makefile',
        'ini','htaccess','conf'
    ];

    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\db\driver\driver_interface $db,
        $table_prefix,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $phpbb_root_path,
        project_repository $project_repo,
        permission_service $permission_service
    ) {
        $this->helper             = $helper;
        $this->template           = $template;
        $this->db                 = $db;
        $this->table_prefix       = (string) $table_prefix;
        $this->request            = $request;
        $this->user               = $user;
        $this->auth               = $auth;
        $this->phpbb_root_path    = (string) $phpbb_root_path;
        $this->project_repo       = $project_repo;
        $this->permission_service = $permission_service;

        if (method_exists($this->user, 'add_lang_ext'))
        {
            $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
        }
    }

    protected function json_error($lang_key, array $params = [])
    {
        $msg = empty($params)
            ? $this->user->lang($lang_key)
            : call_user_func_array([$this->user, 'lang'], array_merge([$lang_key], $params));

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'success' => false,
            'error'   => $msg,
        ]);
    }

    protected function json_success(array $payload = [])
    {
        return new \Symfony\Component\HttpFoundation\JsonResponse(array_merge([
            'success' => true,
        ], $payload));
    }

    protected function is_extension_allowed($filename)
    {
        $filename = (string) $filename;

        $base = basename(str_replace('\\', '/', $filename));
        $base_l = strtolower($base);

        if ($base_l === '.placeholder' || $base_l === '.htaccess' || $base_l === 'changelog.txt'
            || $base_l === 'dockerfile' || $base_l === 'makefile')
        {
            return true;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowed_extensions, true);
    }

    protected function sanitize_rel_path($path)
    {
        $path = (string) $path;
        $path = str_replace("\0", '', $path);
        $path = trim($path);

        if ($path === '')
        {
            return '';
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        if (strpos($path, '/') === 0 || preg_match('#^[a-zA-Z]:/#', $path))
        {
            return '';
        }

        $parts = explode('/', $path);
        $safe  = [];

        foreach ($parts as $seg)
        {
            $seg = trim($seg);

            if ($seg === '' || $seg === '.')
            {
                continue;
            }

            if ($seg === '..')
            {
                return '';
            }

            $safe[] = $seg;
        }

        if (empty($safe))
        {
            return '';
        }

        return implode('/', $safe);
    }

    protected function is_placeholder_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $base = strtolower(basename($path));
        if ($base === '.placeholder')
        {
            return true;
        }

        $suffix = '/.placeholder';
        $path_l = strtolower($path);
        return (strlen($path_l) >= strlen($suffix) && substr($path_l, -strlen($suffix)) === $suffix);
    }

    /**
     * Gate central de segurança para acesso ao PROJETO.
     *
     * Capabilities:
     * - view        (✅ usa u_workspace_view via permission_service/fallback)
     * - edit
     * - upload
     * - rename_move
     * - delete
     * - manage
     * - replace
     * - download
     * - lock
     * - purge_cache (GLOBAL: project_id pode ser 0)
     */
    protected function assert_project_access($project_id, $capability = 'view')
    {
        $project_id = (int) $project_id;
        $capability = (string) $capability;

        // ==========================================================
        // 0) ACL base: acesso ao Workspace (sempre)
        // ==========================================================
        $has_workspace_access = false;

        if ($this->permission_service && method_exists($this->permission_service, 'can_access_workspace'))
        {
            $has_workspace_access = (bool) $this->permission_service->can_access_workspace();
        }
        else
        {
            $uid = (int) ($this->user->data['user_id'] ?? 0);
            $has_workspace_access = ($uid > 0 && (bool) $this->auth->acl_get('u_workspace_access'));
        }

        if (!$has_workspace_access)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        // ==========================================================
        // 1) Purge cache é global (não depende de projeto)
        // ==========================================================
        if ($capability === 'purge_cache')
        {
            if ($this->permission_service && method_exists($this->permission_service, 'can_purge_cache'))
            {
                return $this->permission_service->can_purge_cache()
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
            }

            $is_admin  = (bool) $this->auth->acl_get('u_workspace_manage_all');
            $can_purge = (bool) $this->auth->acl_get('u_workspace_purge_cache');

            return ($is_admin && $can_purge)
                ? ['ok' => true, 'error' => '']
                : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        // ==========================================================
        // 2) Projeto válido + existe?
        // ==========================================================
        if ($project_id <= 0)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')];
        }

        if ($this->project_repo && method_exists($this->project_repo, 'project_exists') && !$this->project_repo->project_exists($project_id))
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PROJECT_NOT_FOUND')];
        }

        // ==========================================================
        // 3) SSOT (permission_service)
        // ==========================================================
        if ($this->permission_service)
        {
            $ps = $this->permission_service;

            // lock tem gate próprio (não depende de view)
            if ($capability === 'lock')
            {
                return (method_exists($ps, 'can_lock_project') && $ps->can_lock_project($project_id))
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
            }

            $is_admin  = method_exists($ps, 'can_manage_all') ? (bool) $ps->can_manage_all() : false;
            $is_locked = method_exists($ps, 'is_project_locked') ? (bool) $ps->is_project_locked($project_id) : false;

            // Evita “vazar existência”: só mostramos "LOCKED" se o usuário é membro/dono (role != '')
            $role = '';
            if (method_exists($ps, 'get_role'))
            {
                $role = (string) $ps->get_role($project_id);
            }

            $locked_message_ok = ($role !== '' && $is_locked && !$is_admin);

            switch ($capability)
            {
                case 'view':
                    if (method_exists($ps, 'can_view_project') && $ps->can_view_project($project_id))
                    {
                        return ['ok' => true, 'error' => ''];
                    }
                    return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'download':
                    if (method_exists($ps, 'can_download_project') && $ps->can_download_project($project_id))
                    {
                        return ['ok' => true, 'error' => ''];
                    }
                    return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'edit':
                    return (method_exists($ps, 'can_edit_project') && $ps->can_edit_project($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'upload':
                    return (method_exists($ps, 'can_upload_project') && $ps->can_upload_project($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'rename_move':
                    return (method_exists($ps, 'can_rename_move_project') && $ps->can_rename_move_project($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'delete':
                    return (method_exists($ps, 'can_delete_project_items') && $ps->can_delete_project_items($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'manage':
                    return (method_exists($ps, 'can_manage_project') && $ps->can_manage_project($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                case 'replace':
                    return (method_exists($ps, 'can_replace_project') && $ps->can_replace_project($project_id))
                        ? ['ok' => true, 'error' => '']
                        : ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

                default:
                    // fallback seguro = view
                    if (method_exists($ps, 'can_view_project') && $ps->can_view_project($project_id))
                    {
                        return ['ok' => true, 'error' => ''];
                    }
                    return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];
            }
        }

        // ==========================================================
        // 4) Fallback (sem permission_service)
        // ==========================================================
        $uid = (int) ($this->user->data['user_id'] ?? 0);
        if ($uid <= 0)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        $is_admin = (bool) $this->auth->acl_get('u_workspace_manage_all');

        // lock gate
        if ($capability === 'lock')
        {
            $can_lock = (bool) $this->auth->acl_get('u_workspace_lock');
            return ($is_admin && $can_lock)
                ? ['ok' => true, 'error' => '']
                : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        // lock state (repo ou SQL)
        $locked = false;
        if ($this->project_repo && method_exists($this->project_repo, 'is_project_locked'))
        {
            $locked = (bool) $this->project_repo->is_project_locked($project_id);
        }
        else
        {
            $sql = 'SELECT project_locked
                    FROM ' . $this->table_prefix . 'workspace_projects
                    WHERE project_id = ' . (int) $project_id;

            $result = $this->db->sql_query($sql);
            $row    = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            $locked = !empty($row['project_locked']);
        }

        // role / membership (repo ou SQL)
        $role = '';
        if ($this->project_repo && method_exists($this->project_repo, 'get_user_role'))
        {
            $role = (string) $this->project_repo->get_user_role($project_id, $uid);
        }
        else
        {
            $sql = 'SELECT user_role
                    FROM ' . $this->table_prefix . 'workspace_projects_users
                    WHERE project_id = ' . (int) $project_id . '
                      AND user_id = ' . (int) $uid;

            $result = $this->db->sql_query($sql);
            $r = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if (!empty($r['user_role']))
            {
                $role = (string) $r['user_role'];
            }
        }

        $owner_id = 0;
        if ($this->project_repo && method_exists($this->project_repo, 'get_project_owner_id'))
        {
            $owner_id = (int) $this->project_repo->get_project_owner_id($project_id);
        }
        else
        {
            $sql = 'SELECT user_id
                    FROM ' . $this->table_prefix . 'workspace_projects
                    WHERE project_id = ' . (int) $project_id;

            $result = $this->db->sql_query($sql);
            $r = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            $owner_id = (int) ($r['user_id'] ?? 0);
        }

        $is_owner = ($owner_id > 0 && $owner_id === $uid);

        // admin global = owner/collab para fins de autorização
        if ($is_admin)
        {
            $role = 'owner';
        }
        else if ($role === '' && $is_owner)
        {
            $role = 'owner';
        }

        // ✅ Regra: lock bloqueia view/download para não-admin
        if ($locked && !$is_admin && in_array($capability, ['view','download','edit','upload','rename_move','delete','manage','replace'], true))
        {
            // não “vaza”: se não for membro/dono, retorna permissão genérica
            if ($role === '')
            {
                return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
            }
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PROJECT_LOCKED')];
        }

        // =========================
        // VIEW/DOWNLOAD: agora pode ser “público destrancado” via u_workspace_view
        // =========================
        if ($capability === 'view')
        {
            // membro/dono sempre pode ver se destrancado (ou admin)
            if ($role !== '')
            {
                return ['ok' => true, 'error' => ''];
            }

            // não-membro: precisa da ACL u_workspace_view
            return (bool) $this->auth->acl_get('u_workspace_view')
                ? ['ok' => true, 'error' => '']
                : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        if ($capability === 'download')
        {
            // exige poder ver + ACL download
            $view_ok = false;

            if ($role !== '')
            {
                $view_ok = true;
            }
            else
            {
                $view_ok = (bool) $this->auth->acl_get('u_workspace_view');
            }

            if (!$view_ok)
            {
                return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
            }

            return (bool) $this->auth->acl_get('u_workspace_download')
                ? ['ok' => true, 'error' => '']
                : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        // A partir daqui: ações de escrita/gestão.
        // Regra: precisa ser membro/dono (role != '') — u_workspace_view não dá escrita.
        if ($role === '' && !$is_admin)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }

        switch ($capability)
        {
            case 'edit':
                if (!$is_admin && !in_array($role, ['owner','collab'], true))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return (bool) $this->auth->acl_get('u_workspace_edit')
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

            case 'upload':
                if (!$is_admin && !in_array($role, ['owner','collab'], true))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return (bool) $this->auth->acl_get('u_workspace_upload')
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

            case 'rename_move':
                if (!$is_admin && !in_array($role, ['owner','collab'], true))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return (bool) $this->auth->acl_get('u_workspace_rename_move')
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

            case 'delete':
                if (!$is_admin && !in_array($role, ['owner','collab'], true))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return (bool) $this->auth->acl_get('u_workspace_delete')
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

            case 'manage':
                if (!$is_admin && $role !== 'owner')
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return (bool) $this->auth->acl_get('u_workspace_manage_own')
                    ? ['ok' => true, 'error' => '']
                    : ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

            case 'replace':
                if (!$is_admin && !in_array($role, ['owner','collab'], true))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                if (!(bool) $this->auth->acl_get('u_workspace_replace') || !(bool) $this->auth->acl_get('u_workspace_edit'))
                {
                    return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
                }
                return ['ok' => true, 'error' => ''];

            default:
                // desconhecida => trata como view (mas aqui já não é view/download/lock/purge_cache)
                return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
        }
    }

    protected function assert_file_access($file_id, $capability = 'view')
    {
        $file_id = (int) $file_id;
        $capability = (string) $capability;

        if (!$file_id)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA'), 'project_id' => null];
        }

        $sql = 'SELECT f.project_id
                FROM ' . $this->table_prefix . 'workspace_files f
                WHERE f.file_id = ' . $file_id;

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_FILE_NOT_FOUND'), 'project_id' => null];
        }

        $project_id = (int) $row['project_id'];

        $proj_check = $this->assert_project_access($project_id, $capability);
        if (!$proj_check['ok'])
        {
            return ['ok' => false, 'error' => $proj_check['error'], 'project_id' => $project_id];
        }

        return ['ok' => true, 'error' => '', 'project_id' => $project_id];
    }
}