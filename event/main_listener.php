<?php
namespace mundophpbb\workspace\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
	protected $helper;
	protected $user;
	protected $auth;
	protected $template;
	protected $phpbb_root_path;

	public function __construct(
		\phpbb\controller\helper $helper,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		\phpbb\template\template $template,
		$phpbb_root_path
	) {
		$this->helper = $helper;
		$this->user = $user;
		$this->auth = $auth;
		$this->template = $template;
		$this->phpbb_root_path = $phpbb_root_path;
	}

	static public function getSubscribedEvents()
	{
		return [
			'core.page_header' => 'add_workspace_assets',
		];
	}

	public function add_workspace_assets($event)
	{
		// Carrega o arquivo de idioma para que as chaves {L_...} funcionem no index
		$this->user->add_lang_ext('mundophpbb/workspace', 'workspace');

		// URL base para os assets da extensão
		$board_url = generate_board_url() . '/';
		$assets_path = $board_url . 'ext/mundophpbb/workspace/styles/all';
		
		// LÓGICA DE CACHE BUSTING (Consistente com o Controller)
		$css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
		$version = (file_exists($css_file)) ? filemtime($css_file) : time();

		// Injeta variáveis globais (disponíveis em qualquer página do fórum)
		$this->template->assign_vars([
			'T_WSP_FORUM_ASSETS' => $assets_path,
			'WSP_GLOBAL_VERSION' => $version,
			'WSP_VERSION'        => $version,
		]);

		// Verifica se o utilizador é Fundador para exibir o link da IDE
		if ($this->user->data['user_id'] != ANONYMOUS && $this->user->data['user_type'] == USER_FOUNDER)
		{
			$this->template->assign_vars([
				'U_WORKSPACE_MAIN' => $this->helper->route('mundophpbb_workspace_main'),
			]);
		}
	}
}