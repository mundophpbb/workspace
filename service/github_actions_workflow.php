<?php
namespace mundophpbb\workspace\service;

/**
 * Generates a reviewed GitHub Actions example, without access tokens or executing code.
 * The file must be committed by the developer to a repository with composer.json at root.
 */
class github_actions_workflow
{
	public static function build($name, $mode, $phpbbBranch)
	{
		$name = (string) $name;
		if (!preg_match('#\A[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*\z#D', $name) ||
			!in_array($mode, ['basic', 'full'], true) ||
			!in_array($phpbbBranch, ['3.3.x', 'master'], true))
		{
			return null;
		}
		$lines = [
			'name: phpBB extension tests',
			'',
			'on:',
			'  push:',
			'  pull_request:',
			'  workflow_dispatch:',
			'',
			'jobs:',
			'  extension-tests:',
			'    name: Extension tests',
			'    uses: phpbb-extensions/test-framework/.github/workflows/tests.yml@' . $phpbbBranch,
			'    with:',
			'      EXTNAME: ' . $name,
			"      PHPBB_BRANCH: '" . $phpbbBranch . "'",
			'      EPV: 1',
		];
		if ($mode === 'basic')
		{
			// Official phpBB extension test framework: basic checks only.
			$lines[] = '      RUN_MYSQL_JOBS: 0';
			$lines[] = '      RUN_PGSQL_JOBS: 0';
			$lines[] = '      RUN_MSSQL_JOBS: 0';
			$lines[] = '      RUN_WINDOWS_JOBS: 0';
		}
		return implode("\n", $lines) . "\n";
	}
}
