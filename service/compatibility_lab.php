<?php
namespace mundophpbb\workspace\service;

/**
 * Static phpBB/PHP compatibility hints. Does not execute, compile, eval,
 * include, require, shell out to or install any user-supplied source code.
 */
final class compatibility_lab
{
	const MAX_FILES = 350;
	const MAX_BYTES = 5242880;
	const MAX_FILE_BYTES = 131072;
	const MAX_INDICATORS = 100;
	const MAX_NOTES = 45;

	public function declaredPhpMinimum($constraint)
	{
		$constraint = trim((string) $constraint);
		// Ambiguous OR ranges must not be described as having a known minimum.
		if (strpos($constraint, '|') !== false || strpos($constraint, ',') !== false) return null;
		if (preg_match('/^(?:>=|\^|~)\s*(\d+)\.(\d+)(?:\.\d+)?(?:\s*<\s*\d+\.\d+(?:\.\d+)?)?$/D', $constraint, $m) ||
			preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/D', $constraint, $m))
			return $m[1] . '.' . $m[2];
		return null;
	}

	private function note(array &$notes, $code, $severity, $path, $fileId = 0)
	{
		if (count($notes) >= self::MAX_NOTES) return;
		$notes[] = ['code' => (string) $code, 'severity' => (string) $severity,
			'path' => (string) $path, 'line' => 1, 'file_id' => (int) $fileId];
	}

	private function add(array &$indicators, array &$seen, $code, $minimum, $path, $line, $id)
	{
		$key = $path . "\0" . $code;
		if (isset($seen[$key]) || count($indicators) >= self::MAX_INDICATORS) return;
		$seen[$key] = true;
		$indicators[] = ['code' => $code, 'php_min' => $minimum,
			'path' => (string) $path, 'line' => (int) $line, 'file_id' => (int) $id];
	}

	private function inspect($source, $path, $id, array &$indicators, array &$seen)
	{
		if (strpos($source, '<?') === false) return;
		$tokens = token_get_all($source);
		$total = count($tokens);
		$line = 1;
		$previous = '';
		$funcMinimum = [
			'str_contains' => '8.0', 'str_starts_with' => '8.0', 'str_ends_with' => '8.0',
			'array_is_list' => '8.1', 'json_validate' => '8.3',
		];
		for ($i = 0; $i < $total; $i++)
		{
			$t = $tokens[$i];
			$text = is_array($t) ? $t[1] : $t;
			$kind = is_array($t) ? token_name($t[0]) : '';
			$startLine = $line;
			$line += substr_count($text, "\n");
			if (in_array($kind, ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'], true)) continue;
			$code = ''; $minimum = '';
			if ($kind === 'T_NULLSAFE_OBJECT_OPERATOR' || $text === '?->' || ($text === '->' && $previous === '?'))
				{ $code = 'nullsafe'; $minimum = '8.0'; }
			else if ($kind === 'T_ATTRIBUTE' || $text === '#[')
				{ $code = 'attribute'; $minimum = '8.0'; }
			else if ($kind === 'T_FN' || ($kind === 'T_STRING' && strtolower($text) === 'fn'))
				{ $code = 'arrow_fn'; $minimum = '7.4'; }
			else if ($kind === 'T_COALESCE_EQUAL' || $text === '??=')
				{ $code = 'coalesce_assign'; $minimum = '7.4'; }
			else if (($kind === 'T_READONLY' || ($kind === 'T_STRING' && strtolower($text) === 'readonly')) &&
				!in_array($previous, ['->', '?->', '::'], true))
				{ $code = 'readonly'; $minimum = '8.1'; }
			else if ($kind === 'T_MATCH' || ($kind === 'T_STRING' && strtolower($text) === 'match'))
			{
				// match (<subject>) distinguishes this keyword from class properties.
				for ($j = $i+1; $j < $total; $j++)
				{
					$n = $tokens[$j]; $nk = is_array($n) ? token_name($n[0]) : '';
					if (in_array($nk, ['T_WHITESPACE', 'T_COMMENT'], true)) continue;
					if ($n === '(' && !in_array($previous, ['->','?->','::','function'], true))
						{ $code = 'match_expr'; $minimum = '8.0'; }
					break;
				}
			}
			else if ($kind === 'T_ENUM' || ($kind === 'T_STRING' && strtolower($text) === 'enum'))
			{
				for ($j = $i+1; $j < $total; $j++)
				{
					$n = $tokens[$j]; $nk = is_array($n) ? token_name($n[0]) : '';
					if (in_array($nk, ['T_WHITESPACE', 'T_COMMENT'], true)) continue;
					if ($nk === 'T_STRING') { $code = 'enum'; $minimum = '8.1'; }
					break;
				}
			}
			else if ($kind === 'T_STRING' && !in_array($previous, ['->','?->','::','function','new'], true))
			{
				$fn = strtolower($text);
				if (isset($funcMinimum[$fn]))
				{
					for ($j = $i+1; $j < $total; $j++)
					{
						$n = $tokens[$j]; $nk = is_array($n) ? token_name($n[0]) : '';
						if (in_array($nk, ['T_WHITESPACE', 'T_COMMENT'], true)) continue;
						if ($n === '(') { $code = $fn; $minimum = $funcMinimum[$fn]; }
						break;
					}
				}
			}
			if ($code !== '') $this->add($indicators, $seen, $code, $minimum, $path, $startLine, $id);
			$previous = strtolower((string) $text);
		}
	}

	/** Advisory checks only; does not resolve or download Composer packages. */
	public function composerAdvice($manifest)
	{
		$issues = [];
		$sections = [];
		foreach (['require', 'require-dev'] as $section)
		{
			if (isset($manifest[$section]) && !is_array($manifest[$section]))
			{
				$issues[] = ['code' => 'section_invalid', 'package' => $section, 'detail' => ''];
				continue;
			}
			$deps = isset($manifest[$section]) && is_array($manifest[$section]) ? $manifest[$section] : [];
			$sections[$section] = $deps;
			foreach ($deps as $package => $constraint)
			{
				if (count($issues) >= 30) break;
				$package = (string) $package;
				if (!is_string($constraint) || trim($constraint) === '' || strlen($constraint) > 240)
					$issues[] = ['code' => 'constraint_invalid', 'package' => substr($package, 0, 120), 'detail' => $section];
				else if (!preg_match('#^(?:php|php-64bit|hhvm|composer(?:-plugin-api|-runtime-api)?|ext-[a-z0-9_-]+|lib-[a-z0-9_-]+|[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*)$#D', $package))
					$issues[] = ['code' => 'name_invalid', 'package' => substr($package, 0, 120), 'detail' => $section];
				else if (trim($constraint) === '*')
					$issues[] = ['code' => 'wildcard', 'package' => substr($package, 0, 120), 'detail' => $section];
				else if (preg_match('/(?:^|\s)(?:dev-master|dev-main|@dev)(?:$|\s)/i', $constraint))
					$issues[] = ['code' => 'unstable', 'package' => substr($package, 0, 120), 'detail' => $section];
			}
		}
		$production = isset($sections['require']) ? $sections['require'] : [];
		$development = isset($sections['require-dev']) ? $sections['require-dev'] : [];
		if (!array_key_exists('php', $production))
			$issues[] = ['code' => 'php_missing', 'package' => 'php', 'detail' => 'require'];
		foreach ($development as $package => $constraint)
		{
			if (count($issues) >= 30) break;
			if (array_key_exists($package, $production))
				$issues[] = ['code' => 'duplicate', 'package' => substr((string) $package, 0, 120), 'detail' => 'require / require-dev'];
		}
		return ['issues' => array_slice($issues, 0, 30), 'truncated' => count($issues) > 30,
			'production_count' => count($production), 'development_count' => count($development),
			'resolved' => false, 'installed_checked' => false];
	}

	/** Bounded proposal only. Never writes a project file. */
	public function composerPatch($raw, $fileId)
	{
		$raw = (string) $raw;
		$empty = ['available' => false, 'changes' => [], 'file_id' => (int) $fileId];
		if (!$fileId || $raw === '' || strlen($raw) > 32768) return $empty;
		$manifest = json_decode($raw, true);
		if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE ||
			!isset($manifest['require']) || !is_array($manifest['require']) ||
			!isset($manifest['require-dev']) || !is_array($manifest['require-dev'])) return $empty;
		$changes = [];
		foreach ($manifest['require-dev'] as $name => $constraint)
		{
			if (!is_string($name) || !is_string($constraint) || !array_key_exists($name, $manifest['require'])) continue;
			if (!is_string($manifest['require'][$name]) || $manifest['require'][$name] !== $constraint) continue;
			unset($manifest['require-dev'][$name]);
			$changes[] = ['code' => 'duplicate_identical', 'package' => substr($name, 0, 120)];
			if (count($changes) >= 20) break;
		}
		if (!$changes) return $empty;
		if (!$manifest['require-dev']) $manifest['require-dev'] = new \stdClass();
		$proposed = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($proposed) || strlen($proposed) > 65536) return $empty;
		return ['available' => true, 'file_id' => (int) $fileId, 'original' => $raw,
			'proposed' => $proposed . "\n", 'changes' => $changes,
			'note' => 'Reformats composer.json; requires manual editor save'];
	}

	/** Canonical saved paths => source text. Snapshot limits are applied upstream as well. */
	public function analyze(array $files, array $ids = [])
	{
		ksort($files, SORT_STRING);
		$notes = []; $indicators = []; $seen = [];
		$raw = isset($files['composer.json']) ? (string) $files['composer.json'] : '';
		$manifest = json_decode($raw, true);
		$validManifest = is_array($manifest);
		$name = $validManifest ? (string) ($manifest['name'] ?? '') : '';
		$validName = (bool) preg_match('#^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$#D', $name);
		$isExtension = $validManifest && ($manifest['type'] ?? '') === 'phpbb-extension';
		$require = $validManifest ? (string) ($manifest['require']['php'] ?? '') : '';
		$phpbb = $validManifest ? (string) ($manifest['extra']['soft-require']['phpbb/phpbb'] ?? '') : '';
		$declaredMin = $this->declaredPhpMinimum($require);
		$manifestId = (int) ($ids['composer.json'] ?? 0);
		if ($raw === '') $this->note($notes, 'composer_missing', 'warning', 'composer.json', $manifestId);
		else if (!$validManifest) $this->note($notes, 'composer_invalid', 'warning', 'composer.json', $manifestId);
		if (!$validName) $this->note($notes, 'name_invalid', 'warning', 'composer.json', $manifestId);
		if (!$isExtension) $this->note($notes, 'type_missing', 'warning', 'composer.json', $manifestId);
		if ($require === '') $this->note($notes, 'php_undeclared', 'notice', 'composer.json', $manifestId);
		else if ($declaredMin === null) $this->note($notes, 'php_complex', 'notice', 'composer.json', $manifestId);
		if ($phpbb === '') $this->note($notes, 'phpbb_undeclared', 'notice', 'composer.json', $manifestId);
		$scanned = 0; $bytes = 0; $skipped = 0; $testFiles = 0; $workflows = 0;
		foreach ($files as $path => $source)
		{
			$path = (string) $path;
			if (preg_match('#^tests/.*\.php$#i', $path)) $testFiles++;
			if (preg_match('#^\.github/workflows/[^/]+\.ya?ml$#i', $path)) $workflows++;
			if (!preg_match('/\.php$/i', $path) || preg_match('#(^|/)(?:vendor|node_modules|ace)/#i', $path)) continue;
			$size = strlen((string) $source);
			if ($scanned >= self::MAX_FILES || $size > self::MAX_FILE_BYTES || $bytes + $size > self::MAX_BYTES)
			{ $skipped++; continue; }
			$scanned++; $bytes += $size;
			$this->inspect((string) $source, $path, (int) ($ids[$path] ?? 0), $indicators, $seen);
		}
		$highest = null;
		foreach ($indicators as $item)
		{
			if ($highest === null || version_compare($item['php_min'], $highest, '>')) $highest = $item['php_min'];
		}
		if ($declaredMin !== null && $highest !== null && version_compare($highest, $declaredMin, '>'))
			$this->note($notes, 'requirement_conflict', 'warning', 'composer.json', $manifestId);
		if (!$testFiles) $this->note($notes, 'tests_missing', 'notice', 'tests/');
		if (!$workflows) $this->note($notes, 'ci_missing', 'notice', '.github/workflows/');
		// Conservative compatibility matrix: only syntax evidence and simple declared
		// minimums. Third-party dependency compatibility cannot be inferred here.
		$versions = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
		$matrix = [];
		foreach ($versions as $version)
		{
			$count = 0;
			foreach ($indicators as $indicator)
				if (version_compare((string) $indicator['php_min'], $version, '>')) $count++;
			$manifestConflict = $declaredMin !== null && version_compare($declaredMin, $version, '>');
			$matrix[] = [
				'php' => $version,
				'detected_conflicts' => $count,
				'below_declared_minimum' => $manifestConflict,
				'status' => $count || $manifestConflict ? 'warning' : 'not_verified',
			];
		}
		$dependencies = [];
		if ($validManifest && isset($manifest['require']) && is_array($manifest['require']))
		{
			foreach ($manifest['require'] as $package => $constraint)
			{
				if (count($dependencies) >= 40) break;
				if (!is_string($package) || !is_string($constraint)) continue;
				if (strlen($package) > 120 || strlen($constraint) > 240) continue;
				$dependencies[] = ['name' => $package, 'constraint' => $constraint,
					'kind' => strncmp($package, 'ext-', 4) === 0 ? 'php_extension' :
						($package === 'php' ? 'php' : 'composer')];
			}
		}
		$dependencyTruncated = $validManifest && isset($manifest['require']) && is_array($manifest['require']) && count($manifest['require']) > 40;
		return [
			'manifest' => ['name' => $validName ? $name : '', 'valid_name' => $validName,
				'type_valid' => $isExtension, 'php_constraint' => $require,
				'phpbb_constraint' => $phpbb, 'declared_php_min' => $declaredMin],
			'compatibility' => ['matrix' => $matrix, 'dependencies' => $dependencies,
				'dependencies_truncated' => $dependencyTruncated, 'phpbb_constraint' => $phpbb,
				'phpbb_constraint_checked' => false, 'dependencies_installed_checked' => false,
				'composer_advice' => $this->composerAdvice($validManifest ? $manifest : []),
				'composer_patch' => $this->composerPatch($raw, $manifestId)],
			'indicators' => $indicators,
			'notes' => $notes,
			'summary' => ['php_files_analyzed' => $scanned, 'indicators' => count($indicators),
				'test_files' => $testFiles, 'ci_workflows' => $workflows,
				'highest_indicator_php_min' => $highest],
			'coverage' => ['complete' => !$skipped && count($indicators) < self::MAX_INDICATORS,
				'skipped_php_files' => $skipped, 'max_indicators_reached' => count($indicators) >= self::MAX_INDICATORS],
			'static_only' => true,
		];
	}
}
