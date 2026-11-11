<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\workspace\service;

/**
 * Board-wide editor settings edited in the ACP (Extensions tab).
 *
 * Values live in phpbb_config. Everything read from there, or posted by the
 * ACP form, goes through sanitize() so the editor never receives a theme,
 * key binding or number that is not on the whitelists below.
 */
class editor_settings
{
	const PREFIX = 'mundophpbb_workspace_';

	const MAX_FILE_KB_MIN = 64;
	const MAX_FILE_KB_MAX = 8192;

	/** Setting => default. Keys are stored as PREFIX . 'editor_' . key (see config_name()). */
	const DEFAULTS = [
		'editor_theme'          => 'github_dark',
		'editor_keybinding'     => 'default',
		'editor_font_size'      => 14,
		'editor_tab_size'       => 4,
		'editor_soft_tabs'      => 1,
		'editor_wrap'           => 1,
		'editor_autocomplete'   => 1,
		'editor_print_margin'   => 0,
		'editor_indent_guides'  => 1,
		'editor_highlight_line' => 1,
		'max_file_kb'           => 2048,
		'epv_enabled'           => 1,
	];

	const BOOLEANS = [
		'editor_soft_tabs', 'editor_wrap', 'editor_autocomplete',
		'editor_print_margin', 'editor_indent_guides', 'editor_highlight_line',
		'epv_enabled',
	];

	const LIGHT_THEMES = [
		'chrome', 'clouds', 'cloud9_day', 'cloud_editor', 'crimson_editor', 'dawn',
		'dreamweaver', 'eclipse', 'github', 'github_light_default', 'gruvbox_light_hard',
		'iplastic', 'katzenmilch', 'kuroir', 'solarized_light', 'sqlserver', 'textmate',
		'tomorrow', 'xcode',
	];

	const DARK_THEMES = [
		'ambiance', 'chaos', 'cloud9_night', 'cloud9_night_low_color', 'cloud_editor_dark',
		'clouds_midnight', 'cobalt', 'dracula', 'github_dark', 'gob', 'gruvbox',
		'gruvbox_dark_hard', 'idle_fingers', 'kr_theme', 'merbivore', 'merbivore_soft',
		'mono_industrial', 'monokai', 'nord_dark', 'one_dark', 'pastel_on_dark',
		'solarized_dark', 'terminal', 'tomorrow_night', 'tomorrow_night_blue',
		'tomorrow_night_bright', 'tomorrow_night_eighties', 'twilight', 'vibrant_ink',
	];

	/** value => language key of the label ("default" is the plain ACE handler). */
	const KEYBINDINGS = [
		'default' => 'ACP_WORKSPACE_KB_DEFAULT',
		'vscode'  => 'ACP_WORKSPACE_KB_VSCODE',
		'sublime' => 'ACP_WORKSPACE_KB_SUBLIME',
		'vim'     => 'ACP_WORKSPACE_KB_VIM',
		'emacs'   => 'ACP_WORKSPACE_KB_EMACS',
	];

	const TAB_SIZES = [2, 4, 8];

	public static function config_name($key)
	{
		return self::PREFIX . $key;
	}

	public static function keys()
	{
		return array_keys(self::DEFAULTS);
	}

	public static function theme_label($theme)
	{
		$label = ucwords(str_replace('_', ' ', (string) $theme));

		return str_replace(['Kr Theme', 'Gob', 'Iplastic', 'Sqlserver'], ['KR Theme', 'GOB', 'iPlastic', 'SQL Server'], $label);
	}

	public static function themes()
	{
		return array_merge(self::LIGHT_THEMES, self::DARK_THEMES);
	}

	/**
	 * Returns a complete, valid settings array. Missing or invalid entries fall
	 * back to the defaults, numbers are clamped.
	 *
	 * @param array $raw key => value (config values or posted values)
	 * @return array
	 */
	public static function sanitize(array $raw)
	{
		$out = self::DEFAULTS;

		if (isset($raw['editor_theme']) && in_array((string) $raw['editor_theme'], self::themes(), true))
		{
			$out['editor_theme'] = (string) $raw['editor_theme'];
		}

		if (isset($raw['editor_keybinding']) && array_key_exists((string) $raw['editor_keybinding'], self::KEYBINDINGS))
		{
			$out['editor_keybinding'] = (string) $raw['editor_keybinding'];
		}

		if (isset($raw['editor_font_size']))
		{
			$out['editor_font_size'] = max(10, min(24, (int) $raw['editor_font_size']));
		}

		if (isset($raw['editor_tab_size']) && in_array((int) $raw['editor_tab_size'], self::TAB_SIZES, true))
		{
			$out['editor_tab_size'] = (int) $raw['editor_tab_size'];
		}

		foreach (self::BOOLEANS as $key)
		{
			if (isset($raw[$key]) && in_array((string) $raw[$key], ['0', '1'], true))
			{
				$out[$key] = (int) $raw[$key];
			}
		}

		if (isset($raw['max_file_kb']))
		{
			$out['max_file_kb'] = max(self::MAX_FILE_KB_MIN, min(self::MAX_FILE_KB_MAX, (int) $raw['max_file_kb']));
		}

		return $out;
	}

	/**
	 * Reads the settings from an ArrayAccess config (the phpBB $config object).
	 */
	public static function from_config($config)
	{
		$raw = [];
		foreach (self::keys() as $key)
		{
			$name = self::config_name($key);
			if (isset($config[$name]))
			{
				$raw[$key] = $config[$name];
			}
		}

		return self::sanitize($raw);
	}

	/**
	 * Reads the settings straight from the config table (used by controllers,
	 * which do not receive the config service).
	 */
	public static function from_db($db, $table_prefix)
	{
		$names = [];
		foreach (self::keys() as $key)
		{
			$names[] = self::config_name($key);
		}

		$sql = 'SELECT config_name, config_value
			FROM ' . $table_prefix . 'config
			WHERE ' . $db->sql_in_set('config_name', $names);
		$result = $db->sql_query($sql);
		$raw = [];
		while ($row = $db->sql_fetchrow($result))
		{
			$raw[substr($row['config_name'], strlen(self::PREFIX))] = $row['config_value'];
		}
		$db->sql_freeresult($result);

		return self::sanitize($raw);
	}

	/**
	 * Shape handed to the JavaScript editor (window.wspVars.editor).
	 */
	public static function for_js(array $settings)
	{
		$s = self::sanitize($settings);

		return [
			'theme'          => $s['editor_theme'],
			'keybinding'     => $s['editor_keybinding'],
			'fontSize'       => (int) $s['editor_font_size'],
			'tabSize'        => (int) $s['editor_tab_size'],
			'softTabs'       => (bool) $s['editor_soft_tabs'],
			'wrap'           => (bool) $s['editor_wrap'],
			'autocomplete'   => (bool) $s['editor_autocomplete'],
			'printMargin'    => (bool) $s['editor_print_margin'],
			'indentGuides'   => (bool) $s['editor_indent_guides'],
			'highlightLine'  => (bool) $s['editor_highlight_line'],
		];
	}
}
