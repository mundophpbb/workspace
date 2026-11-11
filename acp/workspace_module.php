<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\workspace\acp;

use mundophpbb\workspace\service\editor_settings;

class workspace_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $config, $request, $template, $user, $phpbb_log;

		$user->add_lang_ext('mundophpbb/workspace', 'workspace');

		$this->tpl_name   = 'acp_workspace_settings';
		$this->page_title = $user->lang('ACP_WORKSPACE_SETTINGS');

		$form_key = 'mundophpbb_workspace_acp_settings';
		add_form_key($form_key);

		$errors   = [];
		$settings = editor_settings::from_config($config);

		if ($request->is_set_post('reset'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->save($config, editor_settings::DEFAULTS);
			$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_WORKSPACE_SETTINGS_RESET');
			trigger_error($user->lang('ACP_WORKSPACE_SETTINGS_RESET_DONE') . adm_back_link($this->u_action));
		}

		if ($request->is_set_post('submit'))
		{
			if (!check_form_key($form_key))
			{
				trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$posted = [];
			foreach (editor_settings::keys() as $key)
			{
				$default = editor_settings::DEFAULTS[$key];
				$posted[$key] = is_int($default)
					? $request->variable($key, $default)
					: $request->variable($key, (string) $default);
			}

			$settings = editor_settings::sanitize($posted);

			$this->save($config, $settings);
			$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_WORKSPACE_SETTINGS_UPDATED');
			trigger_error($user->lang('ACP_WORKSPACE_SETTINGS_SAVED') . adm_back_link($this->u_action));
		}

		$this->assign_form($template, $user, $settings);
	}

	protected function save($config, array $settings)
	{
		foreach (editor_settings::sanitize($settings) as $key => $value)
		{
			$config->set(editor_settings::config_name($key), $value);
		}
	}

	protected function assign_form($template, $user, array $s)
	{
		$board_url = function_exists('generate_board_url') ? generate_board_url() : '';

		$template->assign_vars([
			'U_ACTION'              => $this->u_action,
			'WSP_ACE_URL'           => $board_url . '/ext/mundophpbb/workspace/styles/all/template/ace',
			'WSP_THEME'             => $s['editor_theme'],
			'WSP_KEYBINDING'        => $s['editor_keybinding'],
			'WSP_FONT_SIZE'         => (int) $s['editor_font_size'],
			'WSP_TAB_SIZE'          => (int) $s['editor_tab_size'],
			'WSP_SOFT_TABS'         => (int) $s['editor_soft_tabs'],
			'WSP_WRAP'              => (int) $s['editor_wrap'],
			'WSP_AUTOCOMPLETE'      => (int) $s['editor_autocomplete'],
			'WSP_PRINT_MARGIN'      => (int) $s['editor_print_margin'],
			'WSP_INDENT_GUIDES'     => (int) $s['editor_indent_guides'],
			'WSP_HIGHLIGHT_LINE'    => (int) $s['editor_highlight_line'],
			'WSP_MAX_FILE_KB'       => (int) $s['max_file_kb'],
			'WSP_MAX_FILE_KB_MIN'   => editor_settings::MAX_FILE_KB_MIN,
			'WSP_MAX_FILE_KB_MAX'   => editor_settings::MAX_FILE_KB_MAX,
			'WSP_EPV_ENABLED'       => (int) $s['epv_enabled'],
		]);

		foreach ([
			'ACP_WORKSPACE_THEMES_LIGHT' => editor_settings::LIGHT_THEMES,
			'ACP_WORKSPACE_THEMES_DARK'  => editor_settings::DARK_THEMES,
		] as $group_lang => $themes)
		{
			$template->assign_block_vars('theme_groups', ['LABEL' => $user->lang($group_lang)]);
			foreach ($themes as $theme)
			{
				$template->assign_block_vars('theme_groups.themes', [
					'VALUE'    => $theme,
					'LABEL'    => editor_settings::theme_label($theme),
					'SELECTED' => ($theme === $s['editor_theme']),
				]);
			}
		}

		foreach (editor_settings::KEYBINDINGS as $value => $label)
		{
			$template->assign_block_vars('keybindings', [
				'VALUE'    => $value,
				'LABEL'    => $user->lang($label),
				'SELECTED' => ($value === $s['editor_keybinding']),
			]);
		}

		foreach (editor_settings::TAB_SIZES as $size)
		{
			$template->assign_block_vars('tab_sizes', [
				'VALUE'    => $size,
				'SELECTED' => ($size === (int) $s['editor_tab_size']),
			]);
		}
	}
}
