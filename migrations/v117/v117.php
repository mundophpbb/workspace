<?php
namespace mundophpbb\workspace\migrations\v117;

use mundophpbb\workspace\service\editor_settings;

/**
 * Workspace v117 - the extension no longer downloads or installs the EPV.
 *
 * Removes the epv_auto_install option that v1.8.1 - v1.8.4 may have created.
 */
class v117 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v116\\v116',
		];
	}

	public function effectively_installed()
	{
		return !isset($this->config[editor_settings::PREFIX . 'epv_auto_install']);
	}

	public function update_data()
	{
		return [
			['config.remove', [editor_settings::PREFIX . 'epv_auto_install']],
		];
	}
}
