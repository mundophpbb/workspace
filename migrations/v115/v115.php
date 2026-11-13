<?php
namespace mundophpbb\workspace\migrations\v115;

use mundophpbb\workspace\service\editor_settings;

/**
 * Workspace v115 - ACP settings page (Extensions tab).
 *
 * Adds the editor settings to phpbb_config and the "Workspace IDE" module to
 * the ACP "Extensions" tab.
 */
class v115 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v114\\v114',
		];
	}

	public function effectively_installed()
	{
		return isset($this->config[editor_settings::config_name('editor_theme')]);
	}

	public function update_data()
	{
		$data = [];

		foreach (editor_settings::DEFAULTS as $key => $default)
		{
			// The EPV options belong to v116.
			if (strpos($key, 'epv_') === 0)
			{
				continue;
			}

			$data[] = ['config.add', [editor_settings::config_name($key), $default]];
		}

		$data[] = ['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_WORKSPACE_TITLE']];
		$data[] = ['module.add', ['acp', 'ACP_WORKSPACE_TITLE', [
			'module_basename' => '\\mundophpbb\\workspace\\acp\\workspace_module',
			'modes'           => ['settings'],
		]]];

		return $data;
	}
}
