<?php
/** Read-only, admin-only environment diagnostics; intentionally no outgoing network calls. */
namespace mundophpbb\workspace\service;

class environment_diagnostics
{
	/** Maps the existing diagnostic result to fixed, translatable guidance IDs.
	 * No user input, local paths or remote response content enters these IDs.
	 */
	public static function guidance(array $row)
	{
		$key = (string) ($row['key'] ?? '');
		$status = (string) ($row['status'] ?? '');
		$allowed = ['php', 'json', 'openssl', 'mbstring', 'github', 'zip', 'epv'];
		if (!in_array($key, $allowed, true) || !in_array($status, ['ok', 'warning', 'error', 'disabled'], true))
		{
			return ['cause' => 'UNKNOWN', 'impact' => 'UNKNOWN', 'action' => 'UNKNOWN'];
		}
		$suffix = $status === 'ok' ? 'OK' : ($status === 'disabled' ? 'DISABLED' : 'ISSUE');
		return [
			'cause' => strtoupper($key) . '_' . $suffix,
			'impact' => strtoupper($key),
			'action' => strtoupper($key) . '_' . $suffix,
		];
	}

	public static function collect(array $settings, $root, array $runtime = null)
	{
		$runtime = $runtime === null ? [
			'php_version' => PHP_VERSION,
			'extensions' => [
				'json' => extension_loaded('json'),
				'curl' => extension_loaded('curl'),
				'zip' => class_exists('ZipArchive'),
				'openssl' => extension_loaded('openssl'),
				'mbstring' => extension_loaded('mbstring'),
			],
		] : $runtime;
		$rows = [];
		$add = static function ($key, $status, $detail) use (&$rows) {
			$rows[] = ['key' => $key, 'status' => $status, 'detail' => $detail];
		};
		$version = (string) ($runtime['php_version'] ?? '0');
		$add('php', version_compare($version, '7.4', '>=') ? 'ok' : 'error', 'PHP ' . preg_replace('/[^0-9.]/', '', $version));
		$extensions = (array) ($runtime['extensions'] ?? []);
		foreach (['json', 'openssl', 'mbstring'] as $extension)
		{
			$add($extension, !empty($extensions[$extension]) ? 'ok' : 'warning', !empty($extensions[$extension]) ? 'available' : 'unavailable');
		}
		$github = !empty($settings['github_enabled']);
		$import = $github && !empty($settings['github_import_enabled']);
		$curl = !empty($extensions['curl']);
		$zip = !empty($extensions['zip']);
		$add('github', !$github ? 'disabled' : ($curl && !empty($extensions['openssl']) ? 'ok' : 'warning'), !$github ? 'feature-disabled' : ($curl && !empty($extensions['openssl']) ? 'prerequisites-present-not-network-tested' : 'curl-and-openssl-required'));
		$add('zip', !$import ? 'disabled' : ($zip ? 'ok' : 'warning'), !$import ? 'import-disabled' : ($zip ? 'prerequisite-present' : 'ziparchive-required'));
		$epv_enabled = !empty($settings['epv_enabled']);
		$epv = false;
		if ($epv_enabled)
		{
			$root = rtrim((string) $root, '/\\');
			$paths = [
				$root . '/tools/epv/vendor/bin/EPV.php',
				$root . '/tools/epv/vendor/phpbb/epv/src/EPV.php',
				$root . '/vendor/bin/EPV.php',
				$root . '/vendor/phpbb/epv/src/EPV.php',
				$root . '/tools/epv/src/EPV.php',
				$root . '/epv/src/EPV.php',
			];
			if (defined('PHPBB_EPV_PATH') && trim((string) constant('PHPBB_EPV_PATH')) !== '')
			{
				array_unshift($paths, (string) constant('PHPBB_EPV_PATH'));
			}
			foreach ($paths as $path)
			{
				if (is_file($path)) { $epv = true; break; }
			}
		}
		$add('epv', !$epv_enabled ? 'disabled' : ($epv ? 'ok' : 'warning'), !$epv_enabled ? 'feature-disabled' : ($epv ? 'file-found-not-executed' : 'installation-not-detected'));
		return $rows;
	}
}
