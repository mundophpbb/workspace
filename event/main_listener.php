<?php
namespace mundophpbb\workspace\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mundo phpBB Workspace - Listener Global
 * Gerencia permissões e injeção de assets globais.
 */
class main_listener implements EventSubscriberInterface
{
    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var string */
    protected $phpbb_root_path;

    /**
     * Construtor
     */
    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $phpbb_root_path
    ) {
        $this->helper = $helper;
        $this->template = $template;
        $this->user = $user;
        $this->auth = $auth;
        $this->phpbb_root_path = (string) $phpbb_root_path;
    }

    static public function getSubscribedEvents()
    {
        return [
            'core.page_header' => 'add_workspace_assets',
            'core.permissions' => 'add_permissions',
        ];
    }

    /**
     * Injeta variáveis globais e rotas rápidas para usuários autorizados.
     */
    public function add_workspace_assets($event)
    {
        // Verifica se o usuário está logado e não é bot/convidado
        $is_logged = (!empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] !== ANONYMOUS);
        $has_access = ($is_logged && $this->auth->acl_get('u_workspace_access'));

        if ($has_access)
        {
            // Carrega o arquivo de idioma para que os links no menu (navbar) funcionem
            $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
        }

        // Define os caminhos base de assets
        $board_url = function_exists('generate_board_url') ? (generate_board_url() . '/') : '';
        $assets_path = $board_url . 'ext/mundophpbb/workspace/styles/all';

        $wsp_css_version = 0;
        $wsp_js_version = 0;

        if ($has_access)
        {
            // Caminho do CSS para versão automática
            $css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
            $wsp_css_version = (file_exists($css_file)) ? (int) filemtime($css_file) : time();

            // CORREÇÃO: O diretório padrão do phpBB é 'template' (singular), não 'templates'.
            $js_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/js/wsp_core.js';
            $wsp_js_version = (file_exists($js_file)) ? (int) filemtime($js_file) : time();
        }

        // Sincronização da Whitelist (Deve ser idêntica à do base_controller)
        $allowed = [
            'php','js','css','html','htm','txt','xml',
            'json','yaml','yml','twig','md','sql','ini','htaccess'
        ];

        // Variáveis enviadas para o sistema de templates do phpBB
        $assign = [
            'T_WSP_FORUM_ASSETS' => $assets_path,
            'T_WSP_ASSETS'       => $assets_path,
        ];

        if ($has_access)
        {
            $assign['WSP_CSS_VERSION'] = $wsp_css_version;
            $assign['WSP_JS_VERSION']  = $wsp_js_version;
            $assign['WSP_ALLOWED_EXT'] = implode(',', $allowed);

            // Rota principal da IDE (para links no header/menu)
            $assign['U_WORKSPACE_MAIN'] = $this->helper->route('mundophpbb_workspace_main');

            // Rotas globais úteis para o JavaScript (wspVars)
            // Note: Usamos o terceiro parâmetro 'false' para obter a URL limpa para o AJAX
            $assign['U_WORKSPACE_RENAME_PROJECT'] = $this->helper->route('mundophpbb_workspace_rename_project', [], false);
            $assign['U_WORKSPACE_RENAME_FOLDER']  = $this->helper->route('mundophpbb_workspace_rename_folder', [], false);
        }

        $this->template->assign_vars($assign);
    }

    /**
     * Registra as permissões da IDE no painel administrativo (ACP) do phpBB.
     */
    public function add_permissions($event)
    {
        $this->user->add_lang_ext('mundophpbb/workspace', 'acp/permissions');

        $permissions = [
            'u_workspace_access'     => 'ACL_U_WORKSPACE_ACCESS',
            'u_workspace_create'     => 'ACL_U_WORKSPACE_CREATE',
            'u_workspace_download'   => 'ACL_U_WORKSPACE_DOWNLOAD',
            'u_workspace_manage_own'  => 'ACL_U_WORKSPACE_MANAGE_OWN',
            'u_workspace_manage_all'  => 'ACL_U_WORKSPACE_MANAGE_ALL',
        ];

        foreach ($permissions as $perm => $lang)
        {
            $event->update_subarray('permissions', $perm, [
                'lang' => $lang,
                'cat'  => 'misc',
            ]);
        }
    }
}