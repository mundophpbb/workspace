<?php
namespace mundophpbb\workspace\controller;

/**
 * Mundo phpBB Workspace - Main Controller
 * Versão 4.2: Coletor Automático de Dicionário i18n & Blindagem de Cache
 * Sincroniza automaticamente todas as chaves de linguagem com o motor JS sem omissões.
 */
class main extends base_controller
{
    /** @var bool Quando não há projeto ativo, carregar arquivos de todos os projetos (modo antigo) */
    protected $load_files_when_no_active_project = false;

    /**
     * Ponto de entrada principal da IDE.
     */
    public function handle()
    {
        // 1. Verificação de permissão geral para o Workspace
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
        }

        // 2. Carrega o ficheiro de tradução oficial
        // Importante: Isso deve acontecer ANTES de montar o dicionário JSON
        $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');

        // 3. Obtém o ID do projeto ativo através da URL (?p=123)
        $active_p_id = (int) $this->request->variable('p', 0);

        // 4. Validação de segurança e gestão de acesso ao projeto
        if ($active_p_id > 0)
        {
            $access = $this->assert_project_access($active_p_id);
            if (!$access['ok'])
            {
                // Se o acesso falhar (ex: projeto de outro user), reseta para evitar fuga de dados
                $active_p_id = 0;
            }
            else
            {
                // Se o utilizador clicar no botão de download, processa a saída ZIP
                if ($this->request->variable('download', 0))
                {
                    return $this->download_project_proxy($active_p_id);
                }
            }
        }

        // 5. Limpeza de variáveis de bloco do phpBB para prevenir duplicados em AJAX
        $this->template->destroy_block_vars('projects');

        // 6. Injeção de ficheiros estáticos (Assets) e mapeamento de rotas
        $this->assign_assets_and_routes($active_p_id);

        // 7. Lista os projetos onde o utilizador tem permissão (Dono ou Admin)
        $projects = $this->fetch_user_projects((int) $this->user->data['user_id']);

        foreach ($projects as $row)
        {
            $project_id = (int) $row['project_id'];
            $is_active  = ($active_p_id > 0 && $project_id === (int) $active_p_id);

            $this->template->assign_block_vars('projects', [
                'ID'         => $project_id,
                'NAME'       => $row['project_name'],
                'IS_ACTIVE'  => $is_active,
                // Rota amigável para download direto deste projeto
                'U_DOWNLOAD' => $this->helper->route('mundophpbb_workspace_download', ['project_id' => $project_id]),
            ]);

            // PERFORMANCE: Carrega ficheiros apenas se o projeto estiver selecionado (Lazy Loading)
            if ($this->should_load_files_for_project($active_p_id, $is_active))
            {
                $files = $this->fetch_project_files($project_id);

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

        // 8. Renderização final do Template
        $response = $this->helper->render('workspace_main.html', $this->user->lang('WSP_TITLE'));

        // 9. Blindagem de Cache (Obrigatório para IDEs para evitar exibição de código antigo)
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

        return redirect($this->helper->route('mundophpbb_workspace_download', ['project_id' => $id]));
    }

    /**
     * Mapeia rotas, assets e o dicionário de tradução automático.
     */
    protected function assign_assets_and_routes($active_p_id)
    {
        $board_url    = generate_board_url() . '/';
        $wsp_url_path = $board_url . 'ext/mundophpbb/workspace/styles/all';

        // Cache-busting por mtime (timestamp do ficheiro)
        $js_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/js/wsp_core.js';
        $wsp_js_version = (file_exists($js_file)) ? filemtime($js_file) : time();

        $css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
        $wsp_css_version = (file_exists($css_file)) ? filemtime($css_file) : time();

        /**
         * COLETOR DINÂMICO DE CHAVES (Sincronização Total)
         * Varre todas as chaves carregadas no objeto $this->user->lang.
         * Se a chave começar com 'WSP_', ela é injetada no JSON que o JavaScript lê.
         * Isso garante que chaves como WSP_HISTORY_CLEANED funcionem no JS.
         */
        $wsp_lang_dictionary = [];
        foreach ($this->user->lang as $key => $value)
        {
            if (strpos($key, 'WSP_') === 0)
            {
                $wsp_lang_dictionary[$key] = $value;
            }
        }

        $this->template->assign_vars([
            'T_WSP_ASSETS'       => $wsp_url_path,
            'T_WSP_ACE_PATH'     => $wsp_url_path . '/template/ace',
            'WSP_JS_VERSION'     => $wsp_js_version,
            'WSP_CSS_VERSION'    => $wsp_css_version,
            'ACTIVE_PROJECT_ID'  => (int) $active_p_id,
            'WSP_ALLOWED_EXT'    => implode(',', $this->allowed_extensions),
            'WSP_ROOT_LABEL'     => $this->user->lang('WSP_ROOT'), 
            'WSP_LANG_JSON'      => json_encode($wsp_lang_dictionary), 

            // Mapeamento de Rotas para o wspVars no JavaScript
            'U_WORKSPACE_MAIN'           => $this->helper->route('mundophpbb_workspace_main'),
            'U_WORKSPACE_LOAD'           => $this->helper->route('mundophpbb_workspace_load', [], false),
            'U_WORKSPACE_SAVE'           => $this->helper->route('mundophpbb_workspace_save', [], false),
            'U_WORKSPACE_ADD'            => $this->helper->route('mundophpbb_workspace_add_project', [], false),
            'U_WORKSPACE_ADD_FILE'       => $this->helper->route('mundophpbb_workspace_add_file', [], false),
            'U_WORKSPACE_UPLOAD'         => $this->helper->route('mundophpbb_workspace_upload', [], false),
            'U_WORKSPACE_RENAME'         => $this->helper->route('mundophpbb_workspace_rename_file', [], false),
            'U_WORKSPACE_MOVE'           => $this->helper->route('mundophpbb_workspace_move_file', [], false),
            'U_WORKSPACE_RENAME_PROJECT' => $this->helper->route('mundophpbb_workspace_rename_project', [], false),
            'U_WORKSPACE_RENAME_FOLDER'  => $this->helper->route('mundophpbb_workspace_rename_folder', [], false),
            'U_WORKSPACE_DELETE_FILE'    => $this->helper->route('mundophpbb_workspace_delete_file', [], false),
            'U_WORKSPACE_DELETE'         => $this->helper->route('mundophpbb_workspace_delete_project', [], false),
            'U_WORKSPACE_DELETE_FOLDER'  => $this->helper->route('mundophpbb_workspace_delete_folder', [], false),
            'U_WORKSPACE_CHANGELOG'      => $this->helper->route('mundophpbb_workspace_changelog', [], false),
            'U_WORKSPACE_CLEAR_CHANGELOG' => $this->helper->route('mundophpbb_workspace_clear_changelog', [], false),
            'U_WORKSPACE_DIFF'           => $this->helper->route('mundophpbb_workspace_diff', [], false),
            'U_WORKSPACE_SEARCH'         => $this->helper->route('mundophpbb_workspace_search', [], false),
            'U_WORKSPACE_REPLACE'        => $this->helper->route('mundophpbb_workspace_replace', [], false),
            'U_WORKSPACE_REFRESH_CACHE'  => $this->helper->route('mundophpbb_workspace_refresh_cache', [], false),
            'U_WORKSPACE_DOWNLOAD'       => $this->helper->route('mundophpbb_workspace_download', ['project_id' => 0], false),
        ]);
    }

    /**
     * Obtém os projetos da base de dados filtrados por utilizador.
     */
    protected function fetch_user_projects($user_id)
    {
        $sql = 'SELECT project_id, project_name
                FROM ' . $this->table_prefix . "workspace_projects
                WHERE user_id = " . (int) $user_id . '
                ORDER BY project_name ASC';

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
     * Obtém os ficheiros de um projeto específico.
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
     * Decide se deve ou não carregar a lista de ficheiros.
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