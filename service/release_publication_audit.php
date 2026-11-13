<?php
/**
 * Optional release-publication evidence. Advisory only: it never replaces EPV,
 * changes the project, or asserts that remote GitHub workflows passed.
 */
namespace mundophpbb\workspace\service;

class release_publication_audit
{
	public static function inspect(array $files)
	{
		$items = [];
		$add = static function ($key, $status, $file, $detail) use (&$items) {
			$items[] = ['key' => $key, 'status' => $status, 'file' => $file, 'detail' => $detail];
		};
		$composer = isset($files['composer.json']) ? json_decode((string) $files['composer.json'], true) : null;
		$valid = is_array($composer) && json_last_error() === JSON_ERROR_NONE;
		$name = $valid && is_string($composer['name'] ?? null) ? $composer['name'] : '';
		$version = $valid && is_string($composer['version'] ?? null) ? $composer['version'] : '';
		$type = $valid && is_string($composer['type'] ?? null) ? $composer['type'] : '';
		$add('composer', $valid && preg_match('#^[a-z][a-z0-9_-]*/[a-z][a-z0-9_-]*$#', $name) && $type === 'phpbb-extension' ? 'pass' : 'warning', 'composer.json', $valid ? ($name . ' | ' . ($version ?: 'no version')) : 'invalid or missing JSON');
		$add('version', $version !== '' && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) ? 'pass' : 'warning', 'composer.json', $version ?: 'missing version');
		$readme = self::find($files, ['README.md', 'README.txt', 'readme.md']);
		$add('readme', $readme !== '' ? 'pass' : 'warning', $readme ?: 'README.md', $readme !== '' ? 'present' : 'add installation and usage instructions');
		$change = self::find($files, ['CHANGELOG.md', 'changelog.md', 'CHANGELOG.txt', 'changelog.txt']);
		$add('changelog', $change !== '' ? 'pass' : 'warning', $change ?: 'CHANGELOG.md', $change !== '' ? 'present' : 'document changes before publication');
		$match = false;
		if ($change !== '' && $version !== '')
		{
			$escaped = preg_quote($version, '/');
			$match = preg_match('/(?:^|[^0-9A-Za-z])v?' . $escaped . '(?![0-9A-Za-z.])/m', (string) $files[$change]) === 1;
		}
		$add('changelog_version', $version === '' || $change === '' ? 'pending' : ($match ? 'pass' : 'warning'), $change ?: 'CHANGELOG.md', $match ? 'version referenced' : 'verify that the changelog documents this version');
		$license = self::find($files, ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'COPYING']);
		$add('license', $license !== '' || ($valid && !empty($composer['license'])) ? 'pass' : 'warning', $license ?: 'composer.json', $license !== '' ? 'license file present' : 'verify license information');
		$pt = false; $en = false; $workflow = false;
		foreach ($files as $path => $content)
		{
			$p = str_replace('\\', '/', (string) $path);
			if (strpos($p, 'language/pt_br/') === 0 && substr($p, -4) === '.php') $pt = true;
			if (strpos($p, 'language/en/') === 0 && substr($p, -4) === '.php') $en = true;
			if (preg_match('#^\.github/workflows/[^/]+\.ya?ml$#', $p)) $workflow = true;
		}
		$add('languages', $pt && $en ? 'pass' : 'warning', 'language/', 'pt_br: ' . ($pt ? 'yes' : 'no') . '; en: ' . ($en ? 'yes' : 'no'));
		$add('workflow', $workflow ? 'pass' : 'info', '.github/workflows/', $workflow ? 'workflow file present; remote outcome not verified' : 'optional: add GitHub Actions CI workflow');
		$passed = count(array_filter($items, static function ($item) { return $item['status'] === 'pass'; }));
		$warnings = count(array_filter($items, static function ($item) { return $item['status'] === 'warning'; }));
		return ['items' => $items, 'passed' => $passed, 'total' => count($items), 'warnings' => $warnings, 'advisory_only' => true, 'extension' => $name, 'version' => $version];
	}

	private static function find(array $files, array $names)
	{
		foreach ($names as $name) if (array_key_exists($name, $files) && trim((string) $files[$name]) !== '') return $name;
		return '';
	}
}
