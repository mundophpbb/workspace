<?php
namespace mundophpbb\workspace\controller;

/**
 * Mundo phpBB Workspace - Main Controller
 * Versão 4.5: SSOT wspVars (JSON único) + i18n + Cache hardening + ACL granular
 * - ✅ u_workspace_view (abrir/visualizar)
 * - ✅ lock bloqueia view/download para não-admin
 * Entrega um único objeto window.wspVars consistente com os módulos JS.
 */
class main extends base_controller
{
    /** @var bool Quando não há projeto ativo, carregar arquivos de todos os projetos (modo antigo) */
    protected $load_files_when_no_active_project = false;

    /**
     * Normaliza URL de rota para evitar bugs de URL relativa:
     * - Garante prefixo "/" quando não for URL absoluta
     * - Remove &amp; para JS
     * - Evita casos comuns como "/app.php/app.php/..."
     */
    protected function normalize_route_url($url)
    {
        $url = (string) $url;
        if ($url === '')
        {
            return '';
        }

        $url = str_replace('&amp;', '&', $url);

        // absoluta? não mexe
        if (preg_match('#^https?://#i', $url))
        {
            return $url;
        }

        // remove "./"
        $url = preg_replace('#^\./#', '/', $url);

        // garante "/"
        if ($url !== '' && $url[0] !== '/')
        {
            $url = '/' . $url;
        }

        // correção comum duplicada
        $url = preg_replace('#/app\.php/app\.php/#', '/app.php/', $url);

        return $url;
    }

    /**
     * Ponto de entrada principal da IDE.
     */
    public function handle()
    {
        // 1) Permissão geral do Workspace (SSOT com fallback)
        if (isset($this->permission_service) && method_exists($this->permission_service, 'can_access_workspace'))
        {
            if (!$this->permission_service->can_access_workspace())
            {
                trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
            }
        }
        else
        {
            if (!$this->auth->acl_get('u_workspace_access'))
            {
                trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
            }
        }

        // 2) Carrega linguagem (antes do dicionário JSON)
        $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');

        // 3) Projeto ativo via URL (?p=123)
        $active_p_id = (int) $this->request->variable('p', 0);

        // 4) Segurança + lock info do projeto ativo
        $active_project_lock = [
            'project_locked' => false,
            'locked_by'      => 0,
            'locked_time'    => 0,
        ];

        if ($active_p_id > 0)
        {
            // ✅ Gate central: view (já inclui u_workspace_view + regra lock->view)
            $access = $this->assert_project_access($active_p_id, 'view');
            $access_ok = !empty($access['ok']);

            if (!$access_ok)
            {
                // evita leak de projeto na URL
                $active_p_id = 0;
            }
            else
            {
                if (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_lock_info'))
                {
                    $active_project_lock = (array) $this->project_repo->get_project_lock_info($active_p_id);
                }

                // Download via proxy (?download=1)
                if ($this->request->variable('download', 0))
                {
                    // ✅ Gate central: download (já inclui u_workspace_download e também view/lock)
                    $d = $this->assert_project_access($active_p_id, 'download');
                    if (empty($d['ok']))
                    {
                        trigger_error(!empty($d['error']) ? $d['error'] : $this->user->lang('WSP_ERR_PERMISSION'));
                    }

                    return $this->download_project_proxy($active_p_id);
                }
            }
        }

        // 5) Limpa blocos para evitar duplicados (segurança em refresh/AJAX)
        $this->template->destroy_block_vars('projects');

        // 6) Assets + rotas + SSOT wspVars
        $this->assign_assets_and_routes($active_p_id, $active_project_lock);

        // 7) Lista projetos do usuário (e/ou destrancados, se tiver u_workspace_view)
        $projects = $this->fetch_user_projects((int) ($this->user->data['user_id'] ?? 0));

        foreach ($projects as $row)
        {
            $project_id = (int) $row['project_id'];
            $is_active  = ($active_p_id > 0 && $project_id === (int) $active_p_id);

            // ✅ Flags por projeto (para UI/controle de clique/download)
            $can_open = false;
            $can_download = false;

            if (isset($this->permission_service))
            {
                if (method_exists($this->permission_service, 'can_view_project'))
                {
                    $can_open = (bool) $this->permission_service->can_view_project($project_id);
                }
                else
                {
                    $tmp = $this->assert_project_access($project_id, 'view');
                    $can_open = !empty($tmp['ok']);
                }

                if (method_exists($this->permission_service, 'can_download_project'))
                {
                    $can_download = (bool) $this->permission_service->can_download_project($project_id);
                }
                else
                {
                    $tmp = $this->assert_project_access($project_id, 'download');
                    $can_download = !empty($tmp['ok']);
                }
            }
            else
            {
                $tmp = $this->assert_project_access($project_id, 'view');
                $can_open = !empty($tmp['ok']);

                $tmp = $this->assert_project_access($project_id, 'download');
                $can_download = !empty($tmp['ok']);
            }

            $u_download = $can_download
                ? $this->normalize_route_url($this->helper->route('mundophpbb_workspace_download', ['project_id' => $project_id]))
                : '';

            $this->template->assign_block_vars('projects', [
                'ID'          => $project_id,
                'NAME'        => $row['project_name'],
                'IS_ACTIVE'   => $is_active,

                'IS_LOCKED'   => !empty($row['project_locked']),
                'LOCKED_BY'   => (int) ($row['locked_by'] ?? 0),
                'LOCKED_TIME' => (int) ($row['locked_time'] ?? 0),

                // ✅ novos flags
                'CAN_OPEN'     => $can_open ? 1 : 0,
                'CAN_DOWNLOAD' => $can_download ? 1 : 0,

                'U_DOWNLOAD'  => $u_download,
            ]);

            // Lazy loading de arquivos: só do projeto ativo
            if ($this->should_load_files_for_project($active_p_id, $is_active))
            {
                // safety: se por algum motivo não puder abrir, não carrega files
                if (!$can_open)
                {
                    continue;
                }

                $files = isset($this->project_repo)
                    ? (array) $this->project_repo->get_project_files($project_id)
                    : (array) $this->fetch_project_files($project_id);

                foreach ($files as $f_row)
                {
                    $this->template->assign_block_vars('projects.files', [
                        'F_ID'   => (int) $f_row['file_id'],
                        'F_NAME' => basename($f_row['file_name']),
                        'F_PATH' => $f_row['file_name'],
                        'F_TYPE' => strtolower($f_row['file_type']),
                    ]);
                }
            }
        }

        // 8) Render
        $response = $this->helper->render('workspace_main.html', $this->user->lang('WSP_TITLE'));

        // 9) No-cache (IDE)
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /**
     * Proxy para o controlador de download (ZIP)
     */
    protected function download_project_proxy($project_id)
    {
        $id = (int) $project_id;
        if ($id <= 0)
        {
            trigger_error($this->user->lang('WSP_ERR_INVALID_DATA'));
        }

        return redirect($this->normalize_route_url($this->helper->route('mundophpbb_workspace_download', ['project_id' => $id])));
    }

    /**
     * Mapeia rotas, assets e SSOT wspVars (JSON único).
     */
    protected function assign_assets_and_routes($active_p_id, array $active_project_lock = [])
    {
        $board_url = function_exists('generate_board_url') ? (generate_board_url() . '/') : '';
        $wsp_url_path = $board_url . 'ext/mundophpbb/workspace/styles/all';
        $ace_path     = $wsp_url_path . '/template/ace';

        // Cache-busting por mtime
        $js_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/js/wsp_core.js';
        $wsp_js_version = (file_exists($js_file)) ? (int) filemtime($js_file) : 0;

        $css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
        $wsp_css_version = (file_exists($css_file)) ? (int) filemtime($css_file) : 0;

        // Coletor WSP_* (somente valores escalares)
        $wsp_lang_dictionary = [];
        foreach ((array) $this->user->lang as $key => $value)
        {
            if (strpos((string) $key, 'WSP_') === 0 && (is_scalar($value) || $value === null))
            {
                $wsp_lang_dictionary[$key] = $value;
            }
        }

        // Lock do projeto ativo
        $active_locked = !empty($active_project_lock['project_locked']);

        // Admin global
        $can_manage_all = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
            ? (bool) $this->permission_service->can_manage_all()
            : (bool) $this->auth->acl_get('u_workspace_manage_all');

        // ==========================
        // ✅ Permissões do PROJETO ATIVO
        // ==========================
        $active_can_view        = 0;
        $active_can_edit        = 0;
        $active_can_upload      = 0;
        $active_can_rename_move = 0;
        $active_can_delete      = 0;
        $active_can_manage      = 0;
        $active_can_replace     = 0;
        $active_can_lock        = 0;
        $active_can_download    = 0;

        $uid = (int) ($this->user->data['user_id'] ?? 0);

        if ((int) $active_p_id > 0)
        {
            if (isset($this->permission_service))
            {
                $active_can_view        = (int) $this->permission_service->can_view_project($active_p_id);
                $active_can_edit        = (int) $this->permission_service->can_edit_project($active_p_id);
                $active_can_upload      = (int) $this->permission_service->can_upload_project($active_p_id);
                $active_can_rename_move = (int) $this->permission_service->can_rename_move_project($active_p_id);
                $active_can_delete      = (int) $this->permission_service->can_delete_project_items($active_p_id);
                $active_can_manage      = (int) $this->permission_service->can_manage_project($active_p_id);
                $active_can_replace     = (int) $this->permission_service->can_replace_project($active_p_id);
                $active_can_lock        = (int) $this->permission_service->can_lock_project($active_p_id);
                $active_can_download    = (int) $this->permission_service->can_download_project($active_p_id);
            }
            else
            {
                // Fallback (sem service): owner/admin apenas
                $is_owner = (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_owner_id'))
                    ? ((int) $this->project_repo->get_project_owner_id($active_p_id) === $uid)
                    : false;

                if ($can_manage_all)
                {
                    $active_can_view        = 1;
                    $active_can_edit        = 1;
                    $active_can_upload      = 1;
                    $active_can_rename_move = 1;
                    $active_can_delete      = 1;
                    $active_can_manage      = 1;
                    $active_can_replace     = 1;
                    $active_can_lock        = (int) $this->auth->acl_get('u_workspace_lock');
                    $active_can_download    = 1;
                }
                else if ($is_owner && !$active_locked)
                {
                    $active_can_view        = (int) $this->auth->acl_get('u_workspace_view');
                    $active_can_edit        = (int) $this->auth->acl_get('u_workspace_edit');
                    $active_can_upload      = (int) $this->auth->acl_get('u_workspace_upload');
                    $active_can_rename_move = (int) $this->auth->acl_get('u_workspace_rename_move');
                    $active_can_delete      = (int) $this->auth->acl_get('u_workspace_delete');
                    $active_can_manage      = (int) $this->auth->acl_get('u_workspace_manage_own');
                    $active_can_replace     = ((int) $this->auth->acl_get('u_workspace_replace') && (int) $this->auth->acl_get('u_workspace_edit')) ? 1 : 0;
                    $active_can_lock        = 0;
                    $active_can_download    = (int) $this->auth->acl_get('u_workspace_download');
                }
            }
        }

        // ==========================
        // ✅ Permissão GLOBAL (purge cache)
        // ==========================
        $can_purge_cache = 0;
        if (isset($this->permission_service) && method_exists($this->permission_service, 'can_purge_cache'))
        {
            $can_purge_cache = (int) $this->permission_service->can_purge_cache();
        }
        else
        {
            $can_purge_cache = ((int) $this->auth->acl_get('u_workspace_manage_all') && (int) $this->auth->acl_get('u_workspace_purge_cache')) ? 1 : 0;
        }

        // helper de rota normalizada para o JS
        $route = function ($name, array $params = []) {
            $u = (string) $this->helper->route((string) $name, $params);
            return $this->normalize_route_url($u);
        };

        // ✅ SSOT: objeto único que o JS lê
        $wsp_vars = [
            // Core
            'basePath'        => $ace_path,
            'allowedExt'      => implode(',', $this->allowed_extensions),
            'activeProjectId' => (int) $active_p_id,
            'lang'            => $wsp_lang_dictionary,

            // Lock/perms
            'canManageAll'     => $can_manage_all ? 1 : 0,
            'activeLocked'     => $active_locked ? 1 : 0,
            'activeLockedBy'   => (int) ($active_project_lock['locked_by'] ?? 0),
            'activeLockedTime' => (int) ($active_project_lock['locked_time'] ?? 0),

            // ✅ flags do projeto ativo
            'activeCanView'        => (int) $active_can_view,
            'activeCanEdit'        => (int) $active_can_edit,
            'activeCanUpload'      => (int) $active_can_upload,
            'activeCanRenameMove'  => (int) $active_can_rename_move,
            'activeCanDelete'      => (int) $active_can_delete,
            'activeCanManage'      => (int) $active_can_manage,
            'activeCanReplace'     => (int) $active_can_replace,
            'activeCanLock'        => (int) $active_can_lock,
            'activeCanDownload'    => (int) $active_can_download,

            // global
            'canPurgeCache'        => (int) $can_purge_cache,

            // URLs
            'mainUrl'          => $route('mundophpbb_workspace_main'),
            'loadUrl'          => $route('mundophpbb_workspace_load', []),
            'saveUrl'          => $route('mundophpbb_workspace_save', []),
            'addUrl'           => $route('mundophpbb_workspace_add_project', []),
            'addFileUrl'       => $route('mundophpbb_workspace_add_file', []),
            'uploadUrl'        => $route('mundophpbb_workspace_upload', []),
            'renameUrl'        => $route('mundophpbb_workspace_rename_file', []),
            'moveFileUrl'      => $route('mundophpbb_workspace_move_file', []),
            'renameProjectUrl' => $route('mundophpbb_workspace_rename_project', []),
            'renameFolderUrl'  => $route('mundophpbb_workspace_rename_folder', []),
            'deleteFileUrl'    => $route('mundophpbb_workspace_delete_file', []),
            'deleteUrl'        => $route('mundophpbb_workspace_delete_project', []),
            'deleteFolderUrl'  => $route('mundophpbb_workspace_delete_folder', []),
            'changelogUrl'     => $route('mundophpbb_workspace_changelog', []),
            'clearChangelogUrl'=> $route('mundophpbb_workspace_clear_changelog', []),
            'diffUrl'          => $route('mundophpbb_workspace_diff', []),
            'searchUrl'        => $route('mundophpbb_workspace_search', []),
            'replaceUrl'       => $route('mundophpbb_workspace_replace', []),
            'refreshCacheUrl'  => $route('mundophpbb_workspace_refresh_cache', []),
            'downloadUrl'      => $route('mundophpbb_workspace_download', ['project_id' => 0]),
            'lockProjectUrl'   => $route('mundophpbb_workspace_lock_project', []),
            'unlockProjectUrl' => $route('mundophpbb_workspace_unlock_project', []),

            // Compat legado
            'WSP_CAN_MANAGE_ALL'     => $can_manage_all ? 1 : 0,
            'WSP_ACTIVE_LOCKED'      => $active_locked ? 1 : 0,
            'WSP_ACTIVE_LOCKED_BY'   => (int) ($active_project_lock['locked_by'] ?? 0),
            'WSP_ACTIVE_LOCKED_TIME' => (int) ($active_project_lock['locked_time'] ?? 0),
        ];

        // JSON seguro pra embutir no <script>
        $json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        $this->template->assign_vars([
            // Assets
            'T_WSP_ASSETS'    => $wsp_url_path,
            'T_WSP_ACE_PATH'  => $ace_path,
            'WSP_JS_VERSION'  => $wsp_js_version,
            'WSP_CSS_VERSION' => $wsp_css_version,

            // Compat
            'ACTIVE_PROJECT_ID'       => (int) $active_p_id,
            'WSP_ALLOWED_EXT'         => implode(',', $this->allowed_extensions),
            'WSP_ROOT_LABEL'          => $this->user->lang('WSP_ROOT'),
            'WSP_LANG_JSON'           => json_encode($wsp_lang_dictionary, $json_flags),

            'WSP_ACTIVE_LOCKED'       => $active_locked ? 1 : 0,
            'WSP_ACTIVE_LOCKED_BY'    => (int) ($active_project_lock['locked_by'] ?? 0),
            'WSP_ACTIVE_LOCKED_TIME'  => (int) ($active_project_lock['locked_time'] ?? 0),
            'WSP_CAN_MANAGE_ALL'      => $can_manage_all ? 1 : 0,

            // SSOT único pro frontend
            'WSP_VARS_JSON' => json_encode($wsp_vars, $json_flags),
        ]);
    }

    /**
     * Projetos do usuário / admin global
     *
     * ✅ FIX IMPORTANTE:
     * - Se o usuário tiver u_workspace_view, ele pode abrir projetos destrancados mesmo sem ser membro.
     * - Logo, precisamos listar também todos os projetos destrancados (project_locked=0),
     *   além dos projetos dele e dos projetos onde ele é membro.
     */
    protected function fetch_user_projects($user_id)
    {
        $user_id = (int) $user_id;

        $can_manage_all = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
            ? (bool) $this->permission_service->can_manage_all()
            : (bool) $this->auth->acl_get('u_workspace_manage_all');

        if ($can_manage_all)
        {
            $sql = 'SELECT project_id, project_name, project_locked, locked_by, locked_time
                    FROM ' . $this->table_prefix . "workspace_projects
                    ORDER BY project_name ASC";
        }
        else
        {
            $can_view_public = (bool) $this->auth->acl_get('u_workspace_view');

            if ($can_view_public)
            {
                // ✅ Lista:
                // - todos destrancados (p.project_locked = 0)
                // - OU projetos do usuário (p.user_id)
                // - OU projetos em que ele é membro (pu.user_id)
                $sql = 'SELECT DISTINCT p.project_id, p.project_name, p.project_locked, p.locked_by, p.locked_time
                        FROM ' . $this->table_prefix . 'workspace_projects p
                        LEFT JOIN ' . $this->table_prefix . "workspace_projects_users pu
                            ON pu.project_id = p.project_id
                            AND pu.user_id = $user_id
                        WHERE p.project_locked = 0
                           OR p.user_id = $user_id
                           OR pu.user_id = $user_id
                        ORDER BY p.project_name ASC";
            }
            else
            {
                // modo antigo: só dono/membro
                $sql = 'SELECT DISTINCT p.project_id, p.project_name, p.project_locked, p.locked_by, p.locked_time
                        FROM ' . $this->table_prefix . 'workspace_projects p
                        LEFT JOIN ' . $this->table_prefix . "workspace_projects_users pu
                            ON pu.project_id = p.project_id
                            AND pu.user_id = $user_id
                        WHERE p.user_id = $user_id
                           OR pu.user_id = $user_id
                        ORDER BY p.project_name ASC";
            }
        }

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    /**
     * Fallback: arquivos do projeto
     */
    protected function fetch_project_files($project_id)
    {
        $sql = 'SELECT file_id, file_name, file_type
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . '
                ORDER BY file_name ASC';

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    /**
     * Decide se carrega arquivos no template
     */
    protected function should_load_files_for_project($active_p_id, $is_active)
    {
        if ((int) $active_p_id > 0)
        {
            return (bool) $is_active;
        }

        return (bool) $this->load_files_when_no_active_project;
    }
}