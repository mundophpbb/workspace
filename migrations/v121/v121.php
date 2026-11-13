<?php
namespace mundophpbb\workspace\migrations\v121;

/**
 * Workspace IDE 1.41.1: register the Diagnostics ACP mode for existing installs.
 *
 * The ACP module_info declaration only describes modes. An explicit migration
 * is required to add the new mode to the admin modules table on upgrade.
 */
class v121 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\mundophpbb\\workspace\\migrations\\v120\\v120'];
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_WORKSPACE_TITLE', [
				'module_basename' => '\\mundophpbb\\workspace\\acp\\workspace_module',
				'modes' => ['diagnostics'],
			]]],
		];
	}
}
