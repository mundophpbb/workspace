<?php
namespace mundophpbb\workspace\migrations\v122;
/** Register the administrator-only performance monitor for existing installs. */
class v122 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\mundophpbb\\workspace\\migrations\\v121\\v121'];
	}
	public function update_data()
	{
		return [['module.add', ['acp', 'ACP_WORKSPACE_TITLE', [
			'module_basename' => '\\mundophpbb\\workspace\\acp\\workspace_module',
			'modes' => ['performance'],
		]]]];
	}
}
