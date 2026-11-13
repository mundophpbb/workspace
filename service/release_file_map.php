<?php
namespace mundophpbb\workspace\service;

/**
 * Canonical, extension-relative project view shared by release validation and
 * submission ZIP creation. No source contents are modified.
 */
final class release_file_map
{
	public static function canonicalize(array $files)
	{
		$normalized = [];
		foreach ($files as $name => $content)
		{
			$name = self::normalizePath((string) $name);
			if ($name === '')
			{
				continue;
			}
			$normalized[$name] = (string) $content;
		}
		if (!$normalized)
		{
			return [];
		}

		$composer_path = isset($normalized['composer.json']) ? 'composer.json' : '';
		if ($composer_path === '')
		{
			$candidates = [];
			foreach (array_keys($normalized) as $name)
			{
				if (preg_match('#(?:^|/)composer\.json$#i', $name))
				{
					$candidates[] = $name;
				}
			}
			usort($candidates, function ($a, $b) {
				$depth_a = substr_count((string) $a, '/');
				$depth_b = substr_count((string) $b, '/');
				if ($depth_a !== $depth_b)
				{
					return $depth_a <=> $depth_b;
				}
				return strlen((string) $a) <=> strlen((string) $b);
			});
			if ($candidates)
			{
				$composer_path = (string) $candidates[0];
			}
		}

		$root_prefix = '';
		$package_prefix = '';
		$extension_dir = '';
		if ($composer_path !== '')
		{
			$dir = str_replace('\\', '/', dirname($composer_path));
			if ($dir !== '.' && $dir !== '/')
			{
				$root_prefix = trim($dir, '/');
			}
			$composer_data = json_decode((string) $normalized[$composer_path], true);
			if (is_array($composer_data) && !empty($composer_data['name']))
			{
				$package_name = strtolower(trim(str_replace('\\', '/', (string) $composer_data['name']), '/'));
				if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $package_name))
				{
					$package_prefix = $package_name;
					$extension_dir = basename($package_name);
				}
			}
		}

		$known_roots = [
			'acp', 'adm', 'assets', 'auth', 'config', 'controller', 'cron', 'event',
			'language', 'lib', 'migrations', 'notification', 'repository', 'service',
			'styles', 'tests', 'docs', 'vendor', 'node_modules',
		];
		$root_files = ['composer.json', 'ext.php', 'license.txt', 'license', 'readme.md', 'readme.txt'];
		$canonical = [];
		$scores = [];

		foreach ($normalized as $original_name => $content)
		{
			$relative = $original_name;
			$changed = false;

			if ($root_prefix !== '' && (strcasecmp($relative, $root_prefix) === 0 || stripos($relative, $root_prefix . '/') === 0))
			{
				$relative = ltrim(substr($relative, strlen($root_prefix)), '/');
				$changed = true;
			}

			if ($package_prefix !== '')
			{
				$lower = strtolower($relative);
				if (strpos($lower, $package_prefix . '/') === 0)
				{
					$relative = substr($relative, strlen($package_prefix) + 1);
					$changed = true;
				}
				else
				{
					$needle = '/' . $package_prefix . '/';
					$pos = strpos('/' . $lower, $needle);
					if ($pos !== false)
					{
						$relative = substr($relative, $pos + strlen($needle) - 1);
						$relative = ltrim($relative, '/');
						$changed = true;
					}
				}
			}

			$parts = explode('/', $relative);
			$first = strtolower((string) ($parts[0] ?? ''));
			if ($first !== '' && !in_array($first, $known_roots, true) && !in_array(strtolower(basename($relative)), $root_files, true))
			{
				$max = min(3, count($parts) - 1);
				for ($i = 1; $i <= $max; $i++)
				{
					if (in_array(strtolower((string) $parts[$i]), $known_roots, true))
					{
						$relative = implode('/', array_slice($parts, $i));
						$changed = true;
						break;
					}
				}
			}

			if ($extension_dir !== '' && stripos($relative, $extension_dir . '/') === 0)
			{
				$rest = substr($relative, strlen($extension_dir) + 1);
				$rest_first = strtolower((string) strtok($rest, '/'));
				if (in_array($rest_first, $known_roots, true) || in_array(strtolower($rest), $root_files, true))
				{
					$relative = $rest;
					$changed = true;
				}
			}

			$relative = self::normalizePath($relative);
			if ($relative === '')
			{
				continue;
			}

			// Prefer an already extension-relative row over a wrapped duplicate.
			$score = $changed ? 10 : 100;
			if (trim((string) $content) !== '')
			{
				$score += 5;
			}
			if (strpos(self::phpTagProbe((string) $content), '<?php') !== false)
			{
				$score += 2;
			}

			if (!isset($canonical[$relative]) || $score > (int) $scores[$relative])
			{
				$canonical[$relative] = (string) $content;
				$scores[$relative] = $score;
			}
		}

		return $canonical;
	}


	public static function normalizePath($name)
	{
		$name = trim((string) $name, " \t\n\r\0\x0B");
		if ($name === '')
		{
			return '';
		}

		for ($i = 0; $i < 2; $i++)
		{
			$decoded = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
			if ($decoded === $name)
			{
				break;
			}
			$name = $decoded;
		}

		// Decode encoded separators only; leave other percent sequences untouched.
		$name = preg_replace_callback('/%(?:2f|5c)/i', function ($m) {
			return strtolower($m[0]) === '%2f' ? '/' : '\\';
		}, $name);
		$name = str_replace('\\', '/', $name);
		$name = preg_replace('#/+#', '/', $name);
		$name = preg_replace('#^(?:\./)+#', '', $name);
		$name = ltrim($name, '/');
		$name = trim($name);

		$parts = [];
		foreach (explode('/', $name) as $part)
		{
			$part = trim($part);
			if ($part === '' || $part === '.')
			{
				continue;
			}
			if ($part === '..')
			{
				return '';
			}
			$parts[] = $part;
		}

		return implode('/', $parts);
	}


	private static function phpTagProbe($content)
	{
		$content = (string) $content;
		$probe = $content;

		// Imported/legacy rows can contain one or more HTML-encoding layers.
		// Decode only as far as necessary to expose the PHP opening tag.
		for ($i = 0; $i < 3 && strpos($probe, '<?php') === false; $i++)
		{
			$decoded = html_entity_decode($probe, ENT_QUOTES, 'UTF-8');
			if ($decoded === $probe)
			{
				break;
			}
			$probe = $decoded;
		}

		if (strpos($probe, '<?php') !== false)
		{
			return $probe;
		}

		return $content;
	}

}
