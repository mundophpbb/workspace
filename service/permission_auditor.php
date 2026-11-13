<?php
namespace mundophpbb\workspace\service;

/**
 * 1.15.0: bounded, read-only phpBB ACL source inventory.
 * No project PHP is included, evaluated, compiled, or executed.
 * This is not an ACL decision engine: phpBB's effective ACL depends on groups,
 * forums, roles, admins and extensions, which cannot be determined from source.
 */
final class permission_auditor
{
	const MAX_FILES = 450;
	const MAX_BYTES = 5242880;
	const MAX_FILE_BYTES = 131072;
	const MAX_PERMISSIONS = 180;
	const MAX_EVIDENCE = 12;
	const MAX_FINDINGS = 120;

	/** @var bool */
	private $overflow = false;

	/** @var bool */
	private $findingOverflow = false;

	/** @var bool */
	private $dynamicOverflow = false;

	private function evidence($path, $line, $id, $kind)
	{
		return [
			'path' => (string) $path,
			'line' => (int) $line,
			'file_id' => (int) $id,
			'kind' => (string) $kind,
		];
	}

	private function ensure(array &$found, $key)
	{
		if (!isset($found[$key]))
		{
			if (count($found) >= self::MAX_PERMISSIONS)
			{
				$this->overflow = true;
				return false;
			}
			$found[$key] = [
				'key' => $key,
				'scope' => strtolower(substr($key, 0, 1)),
				'declarations' => [],
				'registrations' => [],
				'display' => [],
				'checks' => [],
				'translations' => [],
			];
		}
		return true;
	}

	private function add(array &$found, $key, $area, $path, $line, $id, $kind)
	{
		if (!preg_match('/^[aumf]_[A-Za-z0-9_]{1,90}$/D', (string) $key)) return;
		if (!$this->ensure($found, $key)) return;
		$point = $this->evidence($path, $line, $id, $kind);
		if (count($found[$key][$area]) < self::MAX_EVIDENCE &&
			!in_array($point, $found[$key][$area], true))
			$found[$key][$area][] = $point;
	}

	private function findMatches($pattern, $source, $path, $id, $area, $kind, array &$found, $capture = 1)
	{
		if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) return;
		foreach ($matches[$capture] as $match)
		{
			$line = 1 + substr_count($source, "\n", 0, (int) $match[1]);
			$this->add($found, (string) $match[0], $area, $path, $line, $id, $kind);
		}
	}

	/** Strip comments while retaining original offset positions and newlines. */
	private function withoutComments($source)
	{
		if (strpos($source, '<?php') === false && strpos($source, '<?=') === false) return $source;
		$parts = token_get_all($source);
		$result = '';
		foreach ($parts as $part)
		{
			if (is_array($part) && ($part[0] === T_COMMENT || $part[0] === T_DOC_COMMENT))
			{
				$comment = $part[1];
				$result .= preg_replace('/[^\r\n]/', ' ', $comment);
			}
			else
			{
				$result .= is_array($part) ? $part[1] : $part;
			}
		}
		return $result;
	}

	/**
	 * @param array $files Canonical extension-relative name => saved content
	 * @param array $ids Canonical extension-relative name => current project's file_id
	 */
	public function analyze(array $files, array $ids = [])
	{
		$this->overflow = false;
		$this->findingOverflow = false;
		$this->dynamicOverflow = false;
		ksort($files, SORT_STRING);
		$found = [];
		$scanned = 0;
		$bytes = 0;
		$skipped = 0;
		$dynamic = [];
		$languages = [];
		foreach ($files as $path => $source)
		{
			if ($scanned >= self::MAX_FILES) { $skipped++; continue; }
			$path = (string) $path;
			$source = (string) $source;
			$length = strlen($source);
			if ($length > self::MAX_FILE_BYTES || $bytes + $length > self::MAX_BYTES)
			{
				$skipped++;
				continue;
			}
			$scanned++;
			$bytes += $length;
			$id = (int) ($ids[$path] ?? 0);
			if (preg_match('#^language/([a-z_]+)/.+\.php$#i', $path, $lang))
			{
				if (!isset($languages[$lang[1]])) $languages[$lang[1]] = [];
				if (preg_match_all('/[\'\"](ACL_[A-Z0-9_]+)[\'\"]\s*=>|\$lang\s*\[\s*[\'\"](ACL_[A-Z0-9_]+)[\'\"]\s*\]/', $source, $ls, PREG_SET_ORDER))
				{
					foreach ($ls as $m) $languages[$lang[1]][($m[1] !== '' ? $m[1] : $m[2])] = true;
				}
				continue;
			}
			if ($path === 'config/permissions.yml')
			{
				// Only keys inside the top-level "permissions" section count.
				if (preg_match('/^permissions\s*:\s*$/m', $source, $section, PREG_OFFSET_CAPTURE))
				{
					$offset = (int) $section[0][1] + strlen($section[0][0]);
					$body = substr($source, $offset);
					if (preg_match('/^\S[^\r\n]*:/m', $body, $next, PREG_OFFSET_CAPTURE))
						$body = substr($body, 0, (int) $next[0][1]);
					// Preserve original line numbers when scanning section fragments.
					$linePrefix = substr($source, 0, $offset);
					$this->findMatches('/^\h{2,5}([aumf]_[A-Za-z0-9_]{1,90})\s*:/m',
						str_repeat("\n", substr_count($linePrefix, "\n")) . $body,
						$path, $id, 'declarations', 'permissions_yml', $found);
				}
				continue;
			}
			if (!preg_match('/\.php$/i', $path)) continue;
			$source = $this->withoutComments($source);
			if (preg_match('#(?:^|/)migrations/#', $path))
				$this->findMatches('/[\'\"]permission\.add[\'\"]\s*,\s*\[\s*[\'\"]([aumf]_[A-Za-z0-9_]{1,90})[\'\"]/',
					$source, $path, $id, 'registrations', 'migration', $found);
			if (strpos($source, 'core.permissions') !== false)
			{
				$this->findMatches('/\bupdate_subarray\s*\(\s*[\'\"]permissions[\'\"]\s*,\s*[\'\"]([aumf]_[A-Za-z0-9_]{1,90})[\'\"]/',
					$source, $path, $id, 'display', 'core_permissions', $found);
				$this->findMatches('/\$permissions\s*\[\s*[\'\"]([aumf]_[A-Za-z0-9_]{1,90})[\'\"]\s*\]\s*=/',
					$source, $path, $id, 'display', 'core_permissions', $found);
			}
			// Includes acl_gets, acl_getf and acl_getf_global, but never executes any call.
			$pattern = '/\bacl_get(?:s|f|f_global)?\s*\(\s*([\'\"])((?:[aumf]_[A-Za-z0-9_]{1,90}))\1/';
			$this->findMatches($pattern, $source, $path, $id, 'checks', 'acl_check', $found, 2);
			// acl_gets() accepts several literal permission keys in one call.
			if (preg_match_all('/\bacl_gets\s*\(([^\)]{1,1000})\)/s', $source, $calls, PREG_OFFSET_CAPTURE))
			{
				foreach ($calls[1] as $args)
				{
					if (!preg_match_all('/([\'\"])([aumf]_[A-Za-z0-9_]{1,90})\1/', $args[0], $keys, PREG_OFFSET_CAPTURE)) continue;
					foreach ($keys[2] as $match)
					{
						$line = 1 + substr_count($source, "\n", 0, (int) $args[1] + (int) $match[1]);
						$this->add($found, $match[0], 'checks', $path, $line, $id, 'acl_check');
					}
				}
			}
			// A non-literal argument cannot be simulated; track this explicitly.
			if (preg_match_all('/\bacl_get(?:s|f|f_global)?\s*\(\s*(?![\'\"])([^\s\)]+)/', $source, $dm, PREG_OFFSET_CAPTURE))
			{
				foreach ($dm[0] as $d)
				{
					if (count($dynamic) >= 25) { $this->dynamicOverflow = true; break; }
					$dynamic[] = $this->evidence($path,
						1 + substr_count($source, "\n", 0, (int) $d[1]), $id, 'dynamic_acl');
				}
			}
		}
		$findings = [];
		ksort($found, SORT_STRING);
		$summary = ['total' => 0, 'defined' => 0, 'registered' => 0,
			'checked' => 0, 'without_local_definition' => 0, 'unreferenced' => 0,
			'dynamic_checks' => count($dynamic)];
		foreach ($found as $key => &$item)
		{
			$label = 'ACL_' . strtoupper($key);
			foreach ($languages as $locale => $set)
				if (isset($set[$label])) $item['translations'][] = $locale;
			$item['declared'] = !empty($item['declarations']) || !empty($item['registrations']) || !empty($item['display']);
			$item['registered'] = !empty($item['registrations']);
			$item['checked'] = !empty($item['checks']);
			$summary['total']++;
			if ($item['declared']) $summary['defined']++;
			if ($item['registered']) $summary['registered']++;
			if ($item['checked']) $summary['checked']++;
			if ($item['checked'] && !$item['declared'])
			{
				$summary['without_local_definition']++;
				if (count($findings) < self::MAX_FINDINGS)
				{
					$finding = $item['checks'][0];
					$findings[] = ['code' => 'external_reference', 'level' => 'notice',
						'key' => $key, 'path' => $finding['path'], 'line' => $finding['line'], 'file_id' => $finding['file_id']];
				}
			}
			if ($item['declared'] && !$item['checked'])
			{
				$summary['unreferenced']++;
				if (count($findings) < self::MAX_FINDINGS)
				{
					$point = ($item['registrations'] ?: ($item['declarations'] ?: $item['display']))[0];
					$findings[] = ['code' => 'no_literal_check', 'level' => 'notice',
						'key' => $key, 'path' => $point['path'], 'line' => $point['line'], 'file_id' => $point['file_id']];
				}
			}
			if ($item['declared'] && !$item['translations'] && count($findings) < self::MAX_FINDINGS)
			{
				$point = ($item['declarations'] ?: ($item['registrations'] ?: $item['display']))[0];
				$findings[] = ['code' => 'no_language_label', 'level' => 'notice',
					'key' => $key, 'path' => $point['path'], 'line' => $point['line'], 'file_id' => $point['file_id']];
			}
		}
		unset($item);
		// Some findings may have been omitted to respect the UI response cap.
		$possibleFindings = 0;
		foreach ($found as $item)
		{
			if ($item['checked'] && !$item['declared']) $possibleFindings++;
			if ($item['declared'] && !$item['checked']) $possibleFindings++;
			if ($item['declared'] && !$item['translations']) $possibleFindings++;
		}
		$this->findingOverflow = $possibleFindings > self::MAX_FINDINGS;
		return [
			'permissions' => array_values($found),
			'findings' => $findings,
			'dynamic' => $dynamic,
			'summary' => $summary,
			'coverage' => [
				'complete' => $skipped === 0 && !$this->overflow && !$this->findingOverflow && !$this->dynamicOverflow,
				'scanned' => $scanned,
				'skipped' => $skipped,
				'byte_count' => $bytes,
				'max_keys' => self::MAX_PERMISSIONS,
				'keys_truncated' => $this->overflow,
				'findings_truncated' => $this->findingOverflow,
				'dynamic_truncated' => $this->dynamicOverflow,
			],
		];
	}
}
