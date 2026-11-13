<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace mundophpbb\workspace\acp;

class workspace_info
{
	public function module()
	{
		return [
			'filename' => '\mundophpbb\workspace\acp\workspace_module',
			'title'    => 'ACP_WORKSPACE_TITLE',
			'modes'    => [
				'performance' => [
					'title' => 'ACP_WORKSPACE_PERFORMANCE',
					'auth' => 'ext_mundophpbb/workspace && acl_a_board',
					'cat' => ['ACP_WORKSPACE_TITLE'],
				],
				'diagnostics' => [
					'title' => 'ACP_WORKSPACE_DIAGNOSTICS',
					'auth' => 'ext_mundophpbb/workspace && acl_a_board',
					'cat' => ['ACP_WORKSPACE_TITLE'],
				],
				'settings' => [
					'title' => 'ACP_WORKSPACE_SETTINGS',
					'auth'  => 'ext_mundophpbb/workspace && acl_a_board',
					'cat'   => ['ACP_WORKSPACE_TITLE'],
				],
			],
		];
	}
}
