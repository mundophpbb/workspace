<?php
namespace mundophpbb\workspace\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mundo phpBB Workspace - Listener Global
 * - Registra permissões (core.permissions)
 * - Injeta vars globais/rotas/versões de assets (core.page_header)
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

    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $phpbb_root_path
    ) {
        $this->helper          = $helper;
        $this->template        = $template;
        $this->user            = $user;
        $this->auth            = $auth;
        $this->phpbb_root_path = (string) $phpbb_root_path;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.page_header' => 'add_workspace_assets',
            'core.permissions' => 'add_permissions',
        ];
    }

    /**
     * Normaliza URL de rota para evitar bugs de URL relativa:
     * - Remove &amp; (para uso em JS)
     * - Remove "./" no início
     * - Garante prefixo "/" quando não for URL absoluta
     * - Evita casos comuns como "app.php/app.php/..."
     */
    private function normalize_route_url($url)
    {
        $url = (string) $url;
        if ($url === '')
        {
            return '';
        }

        $url = str_replace('&amp;', '&', $url);
        $url = preg_replace('#^\./#', '/', $url);

        // Se já for absoluta, não mexe
        if (preg_match('#^https?://#i', $url))
        {
            return $url;
        }

        // Garante / no começo (evita resolver relativo ao path atual)
        if ($url !== '' && $url[0] !== '/')
        {
            $url = '/' . $url;
        }

        // Correção comum de duplicação
        $url = preg_replace('#/app\.php/app\.php/#', '/app.php/', $url);

        return $url;
    }

    /**
     * Injeta variáveis globais e rotas rápidas.
     * Obs: o controller main continua sendo o SSOT do "wspVars completo" da IDE,
     * mas aqui entregamos flags úteis para header/navbar e includes globais.
     */
    public function add_workspace_assets($event)
    {
        $is_logged  = (!empty($this->user->data['user_id']) && (int) $this->user->data['user_id'] !== ANONYMOUS);

        // ✅ Acesso ao Workspace (entrada na IDE) continua sendo u_workspace_access
        // (u_workspace_view é aplicado no gate por projeto, não aqui)
        $has_access = ($is_logged && (bool) $this->auth->acl_get('u_workspace_access'));

        if ($has_access && method_exists($this->user, 'add_lang_ext'))
        {
            $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
        }

        $board_url   = function_exists('generate_board_url') ? (generate_board_url() . '/') : '';
        $assets_path = $board_url . 'ext/mundophpbb/workspace/styles/all';

        // versões (não usa time() para não bustar sempre)
        $wsp_css_version = 0;
        $wsp_js_version  = 0;

        if ($has_access)
        {
            $css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
            $wsp_css_version = (file_exists($css_file)) ? (int) filemtime($css_file) : 0;

            $js_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/js/wsp_core.js';
            $wsp_js_version = (file_exists($js_file)) ? (int) filemtime($js_file) : 0;
        }

        // whitelist (deve bater com base_controller)
        $allowed = [
            'php', 'js', 'ts', 'css', 'scss', 'sass', 'less', 'html', 'htm', 'twig', 'svg',
            'json', 'xml', 'yml', 'yaml', 'sql', 'md', 'csv', 'txt', 'log',
            'c', 'cpp', 'h', 'hpp', 'cs', 'java', 'py', 'rb', 'pl', 'pm', 'lua', 'go', 'rs',
            'kt', 'swift', 'dart', 'r', 'scala',
            'sh', 'bash', 'bat', 'ps1', 'dockerfile', 'makefile',
            'ini', 'htaccess', 'conf',
        ];

        // flags (granulares)
        $can_manage_all = ($has_access && (bool) $this->auth->acl_get('u_workspace_manage_all'));

        // Mantém seu padrão: lock e purge_cache só para admin global + ACL específica
        $can_lock        = ($can_manage_all && (bool) $this->auth->acl_get('u_workspace_lock'));
        $can_purge_cache = ($can_manage_all && (bool) $this->auth->acl_get('u_workspace_purge_cache'));

        $assign = [
            'T_WSP_FORUM_ASSETS' => $assets_path,
            'T_WSP_ASSETS'       => $assets_path,
        ];

        if ($has_access)
        {
            // Rotas globais (normalizadas)
            $u_main   = $this->normalize_route_url($this->helper->route('mundophpbb_workspace_main'));
            $u_lock   = $can_lock ? $this->normalize_route_url($this->helper->route('mundophpbb_workspace_lock_project', [], false)) : '';
            $u_unlock = $can_lock ? $this->normalize_route_url($this->helper->route('mundophpbb_workspace_unlock_project', [], false)) : '';
            $u_cache  = $can_purge_cache ? $this->normalize_route_url($this->helper->route('mundophpbb_workspace_refresh_cache', [], false)) : '';

            $assign = array_merge($assign, [
                'WSP_CSS_VERSION' => $wsp_css_version,
                'WSP_JS_VERSION'  => $wsp_js_version,
                'WSP_ALLOWED_EXT' => implode(',', $allowed),

                // flags globais (use no template)
                'WSP_CAN_MANAGE_ALL'  => $can_manage_all ? 1 : 0,
                'WSP_CAN_LOCK'        => $can_lock ? 1 : 0,
                'WSP_CAN_PURGE_CACHE' => $can_purge_cache ? 1 : 0,

                // rota principal (navbar/menu)
                'U_WORKSPACE_MAIN' => $u_main,

                // rotas globais (somente se puder usar)
                'U_WORKSPACE_LOCK_PROJECT'   => $u_lock,
                'U_WORKSPACE_UNLOCK_PROJECT' => $u_unlock,
                'U_WORKSPACE_REFRESH_CACHE'  => $u_cache,
            ]);
        }

        $this->template->assign_vars($assign);
    }

    /**
     * Registra permissões e categoria no ACP (core.permissions).
     * (Compatível com permissions.yml — manter aqui não prejudica)
     */
    public function add_permissions($event)
    {
        if (method_exists($this->user, 'add_lang_ext'))
        {
            $this->user->add_lang_ext('mundophpbb/workspace', 'acp/permissions');
        }

        // Categoria própria
        $event->update_subarray('categories', 'workspace', 'ACL_CAT_WORKSPACE');

        // Permissões (inclui granulares)
        $permissions = [
            // base
            'u_workspace_access'      => 'ACL_U_WORKSPACE_ACCESS',

            // ✅ NOVO: abrir/visualizar projetos
            'u_workspace_view'        => 'ACL_U_WORKSPACE_VIEW',

            'u_workspace_create'      => 'ACL_U_WORKSPACE_CREATE',
            'u_workspace_download'    => 'ACL_U_WORKSPACE_DOWNLOAD',

            // granulares (I/O + ferramentas + global)
            'u_workspace_lock'        => 'ACL_U_WORKSPACE_LOCK',
            'u_workspace_edit'        => 'ACL_U_WORKSPACE_EDIT',
            'u_workspace_upload'      => 'ACL_U_WORKSPACE_UPLOAD',
            'u_workspace_rename_move' => 'ACL_U_WORKSPACE_RENAME_MOVE',
            'u_workspace_delete'      => 'ACL_U_WORKSPACE_DELETE',
            'u_workspace_replace'     => 'ACL_U_WORKSPACE_REPLACE',
            'u_workspace_purge_cache' => 'ACL_U_WORKSPACE_PURGE_CACHE',

            // gestão
            'u_workspace_manage_own'  => 'ACL_U_WORKSPACE_MANAGE_OWN',
            'u_workspace_manage_all'  => 'ACL_U_WORKSPACE_MANAGE_ALL',
        ];

        foreach ($permissions as $perm => $lang)
        {
            $event->update_subarray('permissions', $perm, [
                'lang' => $lang,
                'cat'  => 'workspace',
            ]);
        }
    }
}