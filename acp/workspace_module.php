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
		$user->add_lang_ext('mundophpbb/workspace', 'info_acp_workspace');

		$this->tpl_name   = 'acp_workspace_settings';
		$this->page_title = $user->lang('ACP_WORKSPACE_SETTINGS');

		if ($mode === 'performance')
		{
			$this->tpl_name = 'acp_workspace_performance';
			$this->page_title = $user->lang('ACP_WORKSPACE_PERFORMANCE');
			$form_key = 'mundophpbb_workspace_perf_probe';
			add_form_key($form_key);
			$template->assign_vars([
				'U_ACTION' => $this->u_action,
				'WSP_PERF_RAN' => false,
			]);
			if ($request->is_set_post('run_probe') || $request->is_set_post('compare_probe'))
			{
				if (!check_form_key($form_key))
				{
					trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
				}
				global $db, $table_prefix;
				$compare = $request->is_set_post('compare_probe');
				$first = $compare ? \mundophpbb\workspace\service\performance_probe::run($db, $table_prefix) : null;
				$data = \mundophpbb\workspace\service\performance_probe::run($db, $table_prefix);
				$comparison = $compare ? \mundophpbb\workspace\service\performance_probe::compare($first, $data) : null;
				$template->assign_vars([
					'WSP_PERF_RAN' => true,
					'WSP_PERF_COMPARISON' => $compare,
					'WSP_PERF_COMPARE_QUERIES' => $comparison ? $comparison['queries'] : 0,
					'WSP_PERF_MS' => number_format($data['elapsed_ms'], 2, '.', ''),
					'WSP_PERF_QUERIES' => $data['queries'],
					'WSP_PERF_MEMORY' => number_format($data['memory_delta_kb'], 1, '.', ''),
					'WSP_PERF_PEAK' => number_format($data['peak_delta_kb'], 1, '.', ''),
				]);
				if ($comparison)
				{
					foreach ($comparison['rows'] as $row)
					{
						$template->assign_block_vars('performance_comparison', [
							'NAME' => $user->lang('ACP_WSP_PERF_TABLE_' . strtoupper($row['key'])),
							'BASELINE' => $row['before_ms'] === null ? '—' : number_format($row['before_ms'], 2, '.', ''),
							'CURRENT' => $row['after_ms'] === null ? '—' : number_format($row['after_ms'], 2, '.', ''),
							'DELTA' => $row['delta_ms'] === null ? '—' : (($row['delta_ms'] > 0 ? '+' : '') . number_format($row['delta_ms'], 2, '.', '')),
							'PERCENT' => $row['percent'] === null ? '—' : (($row['percent'] > 0 ? '+' : '') . number_format($row['percent'], 1, '.', '') . '%'),
							'ATTENTION' => $row['attention'],
							'AVAILABLE' => $row['available'],
						]);
					}
				}
				foreach ($data['rows'] as $row)
				{
					$template->assign_block_vars('performance_rows', [
						'NAME' => $user->lang('ACP_WSP_PERF_TABLE_' . strtoupper($row['key'])),
						'OK' => $row['status'] === 'ok',
						'COUNT' => $row['status'] === 'ok' ? number_format($row['count'], 0, ',', '.') : '—',
						'MS' => $row['ms'] !== null ? number_format($row['ms'], 2, '.', '') : '—',
					]);
				}
			}
			return;
		}

		if ($mode === 'diagnostics')
		{
			$this->tpl_name = 'acp_workspace_diagnostics';
			$this->page_title = $user->lang('ACP_WORKSPACE_DIAGNOSTICS');
			global $phpbb_root_path;
			$root = $phpbb_root_path;
			$checks = \mundophpbb\workspace\service\environment_diagnostics::collect(editor_settings::from_config($config), $root);
			$counts = ['ok' => 0, 'warning' => 0, 'error' => 0, 'disabled' => 0];
			foreach ($checks as $check)
			{
				if (isset($counts[$check['status']])) { $counts[$check['status']]++; }
			}
			$template->assign_vars([
				'WSP_DIAG_OK' => $counts['ok'],
				'WSP_DIAG_WARNING' => $counts['warning'] + $counts['error'],
				'WSP_DIAG_DISABLED' => $counts['disabled'],
			]);
			foreach ($checks as $row)
			{
				$guidance = \mundophpbb\workspace\service\environment_diagnostics::guidance($row);
				$template->assign_block_vars('diagnostics', [
					'NAME' => $user->lang('ACP_WSP_DIAG_' . strtoupper($row['key'])),
					'STATUS' => $user->lang('ACP_WSP_DIAG_STATUS_' . strtoupper($row['status'])),
					'DETAIL' => $row['key'] === 'php' ? $row['detail'] : $user->lang('ACP_WSP_DIAG_DETAIL_' . strtoupper(str_replace('-', '_', $row['detail']))),
					'CLASS' => $row['status'],
					'CAUSE' => $user->lang('ACP_WSP_HELP_CAUSE_' . $guidance['cause']),
					'IMPACT' => $user->lang('ACP_WSP_HELP_IMPACT_' . $guidance['impact']),
					'ACTION' => $user->lang('ACP_WSP_HELP_ACTION_' . $guidance['action']),
				]);
			}
			return;
		}

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
					: $request->variable($key, (string) $default, array_key_exists($key, editor_settings::PUBLIC_TEXT_LIMITS));
			}

			// Unchecked HTML checkboxes do not appear in POST; normalize them to false.
			foreach (['public_show_author', 'public_show_updated', 'public_show_file_count'] as $checkbox)
			{
				$posted[$checkbox] = $request->variable($checkbox, 0);
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
			'WSP_GITHUB_ENABLED' => (int) $s['github_enabled'],
			'WSP_GITHUB_IMPORT_ENABLED' => (int) $s['github_import_enabled'],
			'WSP_PUBLIC_LANDING_ENABLED' => (int) $s['public_landing_enabled'],
			'WSP_PUBLIC_TITLE' => $s['public_title'],
			'WSP_PUBLIC_DESCRIPTION' => $s['public_description'],
			'WSP_PUBLIC_GUEST_MESSAGE' => $s['public_guest_message'],
			'WSP_PUBLIC_THEME' => $s['public_theme'],
			'WSP_PUBLIC_LAYOUT' => $s['public_layout'],
			'WSP_PUBLIC_SORT' => $s['public_sort'],
			'WSP_PUBLIC_PER_PAGE' => (int) $s['public_per_page'],
			'WSP_PUBLIC_SHOW_AUTHOR' => (int) $s['public_show_author'],
			'WSP_PUBLIC_SHOW_UPDATED' => (int) $s['public_show_updated'],
			'WSP_PUBLIC_SHOW_FILE_COUNT' => (int) $s['public_show_file_count'],
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
