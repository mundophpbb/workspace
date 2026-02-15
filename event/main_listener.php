<?php
namespace mundophpbb\workspace\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
    protected $helper;
    protected $template;
    protected $user;
    protected $auth;
    protected $phpbb_root_path;

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
        $this->phpbb_root_path = $phpbb_root_path;
    }

    static public function getSubscribedEvents()
    {
        return [
            'core.page_header' => 'add_workspace_assets',
            'core.permissions' => 'add_permissions',  // Listener para registrar permissões no ACP (estilo 3.3.x)
        ];
    }

    public function add_workspace_assets($event)
    {
        // Carrega o idioma globalmente para que {L_WSP_TITLE} funcione em todo o fórum
        $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');

        $board_url = generate_board_url() . '/';
        $assets_path = $board_url . 'ext/mundophpbb/workspace/styles/all';

        // Versão dinâmica para evitar cache de CSS/JS antigo
        $css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
        $version = (file_exists($css_file)) ? filemtime($css_file) : time();

        $this->template->assign_vars([
            'T_WSP_FORUM_ASSETS' => $assets_path,
            'T_WSP_ASSETS' => $assets_path,
            'WSP_VERSION' => $version,
        ]);

        // Define o link apenas para usuários logados com permissão 'u_workspace_access'
        if ($this->user->data['user_id'] != ANONYMOUS && $this->auth->acl_get('u_workspace_access'))
        {
            $this->template->assign_vars([
                'U_WORKSPACE_MAIN' => $this->helper->route('mundophpbb_workspace_main'),
            ]);
        }
    }

    public function add_permissions($event)
    {
        // Carrega o lang ACP explicitamente durante o evento (doc 3.3.x: para garantir tradução em permissões custom)
        $this->user->add_lang_ext('mundophpbb/workspace', ['acp/permissions']);  // Carrega só o arquivo acp/permissions.php

        // Estilo phpBB 3.3.x: Use update_subarray para adicionar permissões diretamente (sem get/setData)
        $event->update_subarray('permissions', 'u_workspace_access', [
            'lang' => 'ACL_U_WORKSPACE_ACCESS',
            'cat'  => 'misc',  // Categoria custom simples (agrupamento no ACP)
        ]);
    }
}