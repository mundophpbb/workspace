<?php
namespace mundophpbb\workspace\service;

/**
 * Fast, read-only project diagnostics. Never evaluates project code, loads project
 * PHP classes, invokes the shell, or writes project files. PHP 7.4 compatible.
 *
 * Findings are heuristics, not a replacement for the official EPV.
 */
final class radar_analyzer
{
	const MAX_ISSUES = 120;
	const MAX_PHP_PARSE_BYTES = 262144;

	/** @var array */
	private $issues = [];
	/** @var int */
	private $omitted = 0;

	public function analyze(array $files)
	{
		$this->issues = [];
		$this->omitted = 0;
		$files = release_file_map::canonicalize($files);
		uksort($files, 'strnatcasecmp');

		$composer = null;
		$namespace = '';
		if (!isset($files['composer.json']))
		{
			$this->add('COMPOSER_MISSING', 'error', 'composer.json', 1,
				'composer.json file not found.', 'Add the extension manifest at the project root.');
		}
		else
		{
			$composer = json_decode($files['composer.json'], true);
			if (!is_array($composer) || json_last_error() !== JSON_ERROR_NONE)
			{
				$this->add('COMPOSER_INVALID', 'error', 'composer.json', 1,
					'composer.json contains invalid JSON.', 'Check the syntax of keys, commas and quotes.');
				$composer = null;
			}
			else
			{
				$name = isset($composer['name']) && is_string($composer['name']) ? $composer['name'] : '';
				if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#D', $name))
				{
					$this->add('COMPOSER_NAME', 'error', 'composer.json', $this->findLine($files['composer.json'], '"name"'),
						'Package name missing or not in vendor/extension format.', 'Use a name like vendor/myextension, in lowercase.');
				}
				else
				{
					$namespace = str_replace('/', '\\', $name) . '\\';
				}
				if (($composer['type'] ?? '') !== 'phpbb-extension')
				{
					$this->add('COMPOSER_TYPE', 'warning', 'composer.json', $this->findLine($files['composer.json'], '"type"'),
						'Composer type is not set to phpbb-extension.', 'Set "type": "phpbb-extension".');
				}
			}
		}

		// Token parsing checks syntax without running any project code.
		foreach ($files as $path => $content)
		{
			if (!preg_match('/\.php$/i', $path))
			{
				continue;
			}
			if (strlen($content) > self::MAX_PHP_PARSE_BYTES)
			{
				$this->add('PHP_PARSE_SKIPPED', 'info', $path, 1,
					'PHP file too large for quick syntax analysis.', 'Use the full EPV to examine this file.');
				continue;
			}
			if (substr($content, 0, 3) === "\xEF\xBB\xBF")
			{
				$this->add('PHP_BOM', 'warning', $path, 1,
					'PHP file contains UTF-8 BOM.', 'Remove the BOM to avoid output before headers.');
			}
			// Legacy Workspace rows may store &lt;?php in the database while the
			// editor shows a decoded tag. Probe a temporary copy for that case.
			$parseSource = $content;
			for ($attempt = 0; $attempt < 3 && strpos($parseSource, '<?php') === false; $attempt++)
			{
				$decoded = html_entity_decode($parseSource, ENT_QUOTES, 'UTF-8');
				if ($decoded === $parseSource) break;
				$parseSource = $decoded;
			}
			if (strpos($parseSource, '<?php') === false)
			{
				$this->add('PHP_OPEN_TAG', 'warning', $path, 1,
					'PHP file without a recognizable <?php opening tag.', 'Check the opening tag and possible HTML entities in imported content.');
				continue;
			}
			try
			{
				token_get_all($parseSource, TOKEN_PARSE);
			}
			catch (\ParseError $e)
			{
				$line = max(1, (int) $e->getLine());
				$this->add('PHP_SYNTAX', 'error', $path, $line,
					'Possible PHP syntax error: ' . $this->short($e->getMessage(), 170),
					'Review the indicated section. Analysis uses the PHP version installed on the server.');
			}
		}

		// Resolve extension-local services (other vendors and phpBB classes are intentionally ignored).
		if ($namespace !== '' && isset($files['config/services.yml']))
		{
			$serviceClass = $this->serviceClasses($files['config/services.yml']);
			foreach ($serviceClass as $service)
			{
				$class = ltrim($service['class'], '\\');
				if (stripos($class, $namespace) !== 0)
				{
					continue;
				}
				$relative = str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
				if (!$this->existsPath($files, $relative))
				{
					$this->add('SERVICE_CLASS_MISSING', 'warning', 'config/services.yml', $service['line'],
						'Local service class not found: ' . $this->short($class, 125),
						'Confirm the namespace, capitalization and PHP file path.');
				}
			}
		}

		// Service ids referenced by local routes must be registered in services.yml.
		if ($namespace !== '' && isset($files['config/routing.yml']) && isset($files['config/services.yml']))
		{
			$serviceIds = $this->serviceIds($files['config/services.yml']);
			$prefix = str_replace('\\', '.', rtrim($namespace, '\\')) . '.';
			foreach (preg_split('/\r\n|\n|\r/', $files['config/routing.yml']) as $index => $line)
			{
				if (preg_match('/_controller\s*:\s*["\x27]?([a-zA-Z0-9_.-]+):[a-zA-Z_][a-zA-Z0-9_]*/', $line, $match))
				{
					$service = $match[1];
					if (stripos($service, $prefix) === 0 && !isset($serviceIds[$service]))
					{
						$this->add('ROUTE_SERVICE_MISSING', 'warning', 'config/routing.yml', $index + 1,
							'Route references an unregistered service: ' . $this->short($service, 110),
							'Registre o controller em config/services.yml ou ajuste a rota.');
					}
				}
			}
		}

		// Local service references beginning with @vendor.extension. must be registered.
		if ($namespace !== '' && isset($files['config/services.yml']))
		{
			$ids = $this->serviceIds($files['config/services.yml']);
			$prefix = str_replace('\\', '.', rtrim($namespace, '\\')) . '.';
			foreach (preg_split('/\r\n|\n|\r/', $files['config/services.yml']) as $index => $line)
			{
				if (!preg_match('/^\s*#/', $line) && preg_match_all('/@([a-zA-Z0-9_.-]+)/', $line, $found))
				{
					foreach (array_unique($found[1]) as $ref)
					{
						if (stripos($ref, $prefix) !== 0 || isset($ids[$ref])) continue;
						$this->add('SERVICE_DEP_MISSING', 'warning', 'config/services.yml', $index + 1,
							'Local service dependency not registered: ' . $this->short($ref, 110),
							'Check the service ID and its registration in config/services.yml.');
					}
				}
			}
		}

		// For static Symfony event maps, verify callback methods exist in that listener.
		// This does NOT claim to validate whether a core event name exists in phpBB.
		foreach ($files as $path => $content)
		{
			if (!preg_match('#^event/.*\.php$#i', $path) ||
				!preg_match('/function\s+getSubscribedEvents\s*\(\s*\)\s*\{(.*?)\n\s*\}/s', $content, $block, PREG_OFFSET_CAPTURE)) continue;
			$body = $block[1][0];
			$offset = $block[1][1];
			if (!preg_match_all('/[\x27"][a-zA-Z0-9_.]+[\x27"]\s*=>\s*[\x27"]([a-zA-Z_][a-zA-Z0-9_]*)[\x27"]/', $body, $methods, PREG_OFFSET_CAPTURE)) continue;
			foreach ($methods[1] as $method)
			{
				$name = $method[0];
				if (preg_match('/\bfunction\s+' . preg_quote($name, '/') . '\s*\(/i', $content)) continue;
				$line = 1 + substr_count(substr($content, 0, $offset + $method[1]), "\n");
				$this->add('EVENT_CALLBACK_MISSING', 'warning', $path, $line,
					'Event callback method not found: ' . $name,
					'Implement the method in the listener or correct getSubscribedEvents().');
			}
		}

		// Static ACL strings belonging to this extension (not phpBB/other vendors).
		if ($namespace !== '' && isset($files['config/permissions.yml']))
		{
			$trimmed = rtrim($namespace, '\\');
			$leaf = strtolower((string) substr($trimmed, strrpos($trimmed, '\\') + 1));
			$permissions = $files['config/permissions.yml'];
			foreach ($files as $path => $content)
			{
				if (!preg_match('/\.php$/i', $path) || $path === 'language/en/workspace.php' || $path === 'language/pt_br/workspace.php')
				{
					continue;
				}
				foreach (preg_split('/\r\n|\n|\r/', $content) as $index => $line)
				{
					if (preg_match_all('/->acl_get(?:s|f_global)?\s*\(\s*[\x27"](u_' . preg_quote($leaf, '/') . '_[a-z0-9_]+)[\x27"]/i', $line, $found))
					{
						foreach (array_unique($found[1]) as $permission)
						{
							if (!preg_match('/^\s*' . preg_quote($permission, '/') . '\s*:/m', $permissions))
							{
								$this->add('ACL_UNREGISTERED', 'warning', $path, $index + 1,
									'Possible unregistered permission: ' . $permission,
									'Check config/permissions.yml and the permission migration.');
							}
						}
					}
				}
			}
		}

		// Literal workspace language references: JS WSP.lang('KEY'), PHP ->lang('KEY'), Twig {L_KEY}.
		// Dynamic keys cannot be resolved reliably and are intentionally ignored.
		$langs = [];
		foreach (['en', 'pt_br'] as $locale)
		{
			$keys = [];
			foreach ($files as $path => $content)
			{
				if (preg_match('#^language/' . $locale . '/.*\.php$#i', $path) && preg_match_all('/[\x27"](WSP_[A-Z0-9_]+)[\x27"]\s*=>/', $content, $matched))
				{
					foreach ($matched[1] as $key) $keys[$key] = true;
				}
			}
			$langs[$locale] = $keys;
		}
		if (!empty($langs['en']) || !empty($langs['pt_br']))
		{
			$reported = [];
			foreach ($files as $path => $content)
			{
				if (!preg_match('/\.(?:php|html|twig|js)$/i', $path) || preg_match('#^(?:language|tests|docs)/#i', $path)) continue;
				foreach (preg_split('/\r\n|\n|\r/', $content) as $index => $line)
				{
					preg_match_all('/(?:->lang\s*\(\s*|WSP\.lang\s*\(\s*)[\x27"](WSP_[A-Z0-9_]+)[\x27"]|\{L_(WSP_[A-Z0-9_]+)\}/', $line, $matches, PREG_SET_ORDER);
					foreach ($matches as $m)
					{
						$key = !empty($m[1]) ? $m[1] : (isset($m[2]) ? $m[2] : '');
						foreach (['en', 'pt_br'] as $locale)
						{
							if ($key === '' || substr($key, -1) === '_' || isset($langs[$locale][$key]) || isset($reported[$key][$locale])) continue;
							$reported[$key][$locale] = true;
							$this->add('LANG_MISSING', 'warning', $path, $index + 1,
								'Chave de idioma ' . $key . ' ausente em ' . $locale . '.',
								'Add the key to language/' . $locale . '/workspace.php.');
						}
					}
				}
			}
		}

		$summary = ['errors' => 0, 'warnings' => 0, 'notices' => 0, 'files' => count($files), 'omitted' => $this->omitted];
		foreach ($this->issues as $issue)
		{
			$summary[$issue['severity'] === 'error' ? 'errors' : ($issue['severity'] === 'warning' ? 'warnings' : 'notices')]++;
		}
		return ['summary' => $summary, 'issues' => $this->issues];
	}

	private function add($rule, $severity, $file, $line, $message, $suggestion)
	{
		if (count($this->issues) >= self::MAX_ISSUES) { $this->omitted++; return; }
		$this->issues[] = [
			'rule' => (string) $rule, 'severity' => (string) $severity,
			'file' => (string) $file, 'line' => max(1, (int) $line),
			'message' => (string) $message, 'suggestion' => (string) $suggestion,
		];
	}

	private function findLine($content, $needle)
	{
		$pos = strpos((string) $content, (string) $needle);
		if ($pos === false) return 1;
		return 1 + substr_count(substr((string) $content, 0, $pos), "\n");
	}

	private function short($value, $limit)
	{
		$value = preg_replace('/\s+/', ' ', (string) $value);
		return strlen($value) > $limit ? substr($value, 0, $limit) . '…' : $value;
	}

	private function existsPath(array $files, $needle)
	{
		foreach ($files as $path => $unused)
		{
			if (strcasecmp((string) $path, (string) $needle) === 0) return true;
		}
		return false;
	}

	private function serviceIds($content)
	{
		$ids = [];
		foreach (preg_split('/\r\n|\n|\r/', (string) $content) as $line)
		{
			if (preg_match('/^ {4}([a-zA-Z0-9_.-]+)\s*:\s*(?:#.*)?$/', $line, $m)) $ids[$m[1]] = true;
		}
		return $ids;
	}

	private function serviceClasses($content)
	{
		$result = [];
		foreach (preg_split('/\r\n|\n|\r/', (string) $content) as $index => $line)
		{
			if (preg_match('/^\s{6,}class:\s*[\x27"]?([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]+)\b/', $line, $m))
			{
				$result[] = ['class' => $m[1], 'line' => $index + 1];
			}
		}
		return $result;
	}
}
