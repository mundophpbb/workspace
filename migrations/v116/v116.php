<?php
namespace mundophpbb\workspace\migrations\v116;

use mundophpbb\workspace\service\editor_settings;

/**
 * Workspace v116 - ACP switches for the official EPV integration.
 *
 * epv_enabled run the official EPV from the validator (default: on)
 */
class v116 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v115\\v115',
		];
	}

	public function effectively_installed()
	{
		return isset($this->config[editor_settings::config_name('epv_enabled')]);
	}

	public function update_data()
	{
		return [
			['config.add', [editor_settings::config_name('epv_enabled'), editor_settings::DEFAULTS['epv_enabled']]],
		];
	}
}
