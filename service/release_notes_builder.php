<?php
namespace mundophpbb\workspace\service;

/**
 * Workspace 1.19: evidence-only Markdown release notes from two bounded snapshots.
 * No evaluation of project source, natural-language inference, or external requests.
 */
class release_notes_builder
{
	const MAX_DRAFT_BYTES = 65536;
	const MAX_CHANGELOG_BYTES = 262144;

	public static function validVersion($version)
	{
		return is_string($version) && preg_match('/^v?[0-9][a-zA-Z0-9.+_-]{0,39}$/D', $version) === 1;
	}

	public static function locale($locale)
	{
		return $locale === 'en' ? 'en' : 'pt_br';
	}

	public static function token($projectId, $sourceKey, array $saved, array $current, $version, $locale)
	{
		return hash('sha256', json_encode([
			(int) $projectId, (string) $sourceKey,
			(string) $saved['fingerprint'], (string) $current['fingerprint'],
			(string) $version, self::locale($locale)
		]));
	}

	private static function quote($path)
	{
		// Make untrusted stored paths inert in Markdown, even when they contain backticks.
		$path = str_replace(["\r", "\n"], '', (string) $path);
		preg_match_all('/`+/', $path, $runs);
		$max = 0;
		foreach ($runs[0] as $run) $max = max($max, strlen($run));
		$ticks = str_repeat('`', $max + 1);
		return $ticks . $path . $ticks;
	}

	public static function area($path)
	{
		$p = strtolower(str_replace('\\', '/', (string) $path));
		if ($p === 'composer.json') return 'config';
		if (preg_match('#^migrations/#', $p)) return 'migrations';
		if (preg_match('#^config/(?:services|routing|permissions)[^/]*\.ya?ml$#', $p)) return 'config';
		if (preg_match('#^language/#', $p)) return 'languages';
		if (preg_match('#^tests/#', $p)) return 'tests';
		if (preg_match('#^(?:styles|adm)/#', $p)) return 'interface';
		if (preg_match('#^(?:controller|service|event|repository)/#', $p)) return 'code';
		return 'other';
	}

	private static function yamlKeys($content, $mode)
	{
		$keys = [];
		// Limited lexical hints; unknown or dynamic YAML is not interpreted as proof.
		if ($mode === 'routes')
		{
			preg_match_all('/^([a-zA-Z][a-zA-Z0-9_.-]+):\s*(?:#[^\r\n]*)?$/m', $content, $m);
		}
		else
		{
			preg_match_all('/^ {4}([a-zA-Z][a-zA-Z0-9_.-]+):\s*(?:#[^\r\n]*)?$/m', $content, $m);
		}
		foreach ($m[1] as $key) $keys[$key] = true;
		return $keys;
	}

	private static function details($path, $old, $new, $locale)
	{
		$en = $locale === 'en';
		$prefix = strtolower((string) $path);
		if ($prefix === 'composer.json')
		{
			$a = json_decode((string) $old, true);
			$b = json_decode((string) $new, true);
			if (is_array($a) && is_array($b))
			{
				$changes = [];
				foreach (['version', 'require.php'] as $field)
				{
					$before = $field === 'require.php' ? ($a['require']['php'] ?? null) : ($a[$field] ?? null);
					$after = $field === 'require.php' ? ($b['require']['php'] ?? null) : ($b[$field] ?? null);
					if (is_string($before) && is_string($after) && $before !== $after &&
						strlen($before) <= 48 && strlen($after) <= 48 &&
						preg_match('/^[a-zA-Z0-9_.^~<>=*|+ -]+$/D', $before) &&
						preg_match('/^[a-zA-Z0-9_.^~<>=*|+ -]+$/D', $after))
					{
						$changes[] = ($field === 'version' ? ($en ? 'version' : 'versão') : 'PHP') .
							' ' . self::quote($before) . ' → ' . self::quote($after);
					}
				}
				return implode('; ', $changes);
			}
		}
		$mode = ($prefix === 'config/routing.yml' || $prefix === 'config/routing.yaml') ? 'routes' :
			(($prefix === 'config/services.yml' || $prefix === 'config/services.yaml') ? 'services' : '');
		if ($mode !== '' && strlen((string) $old) < 180000 && strlen((string) $new) < 180000)
		{
			$previous = self::yamlKeys((string) $old, $mode);
			$latest = self::yamlKeys((string) $new, $mode);
			$added = array_keys(array_diff_key($latest, $previous));
			$removed = array_keys(array_diff_key($previous, $latest));
			// Never flood notes with a list of dynamic or generated service IDs.
			if (count($added) + count($removed) <= 6)
			{
				$items = [];
				foreach ($added as $key) $items[] = ($en ? 'registered' : 'registrado') . ' ' . self::quote($key);
				foreach ($removed as $key) $items[] = ($en ? 'removed' : 'removido') . ' ' . self::quote($key);
				return implode('; ', $items);
			}
		}
		return '';
	}

	/** Returns a factual, editable draft with per-file changes and limited hints. */
	public function generate(array $baseline, array $current, $version, $locale, $day)
	{
		if (!self::validVersion($version)) throw new \RuntimeException('WSP_RN_INVALID_VERSION');
		$locale = self::locale($locale);
		$en = $locale === 'en';
		if (!preg_match('/^\d{4}-\d\d-\d\d$/D', (string) $day)) throw new \RuntimeException('WSP_RN_FAILED');
		$old = (array) ($baseline['files'] ?? []);
		$new = (array) ($current['files'] ?? []);
		$paths = array_unique(array_merge(array_keys($old), array_keys($new)));
		sort($paths, SORT_STRING);
		$groups = ['added' => [], 'modified' => [], 'removed' => []];
		$summary = ['added' => 0, 'modified' => 0, 'removed' => 0, 'total' => 0];
		foreach ($paths as $path)
		{
			// The technical audit and published notes are not release changes.
			if (in_array(strtolower(basename((string) $path)), ['changelog.txt', 'changelog.md', '.placeholder'], true)) continue;
			$hasOld = isset($old[$path]);
			$hasNew = isset($new[$path]);
			if ($hasOld && $hasNew && ($old[$path]['hash'] ?? '') === ($new[$path]['hash'] ?? '') &&
				($old[$path]['type'] ?? '') === ($new[$path]['type'] ?? '')) continue;
			$action = !$hasOld ? 'added' : (!$hasNew ? 'removed' : 'modified');
			$details = self::details($path, $hasOld ? $old[$path]['content'] : '',
				$hasNew ? $new[$path]['content'] : '', $locale);
			$groups[$action][] = [
				'path' => (string) $path, 'action' => $action, 'area' => self::area($path),
				'details' => $details,
			];
			$summary[$action]++;
			$summary['total']++;
		}
		$sections = $en ? [
			'added' => 'Added files', 'modified' => 'Modified files', 'removed' => 'Removed files'
		] : [
			'added' => 'Arquivos adicionados', 'modified' => 'Arquivos modificados',
			'removed' => 'Arquivos removidos'
		];
		$areaNames = $en ? [
			'config' => 'Configuration', 'migrations' => 'Migrations', 'languages' => 'Translations',
			'tests' => 'Tests', 'interface' => 'Interface', 'code' => 'Source code', 'other' => 'Other'
		] : [
			'config' => 'Configuração', 'migrations' => 'Migrações', 'languages' => 'Traduções',
			'tests' => 'Testes', 'interface' => 'Interface', 'code' => 'Código-fonte', 'other' => 'Outros'
		];
		$lines = ['## [' . $version . '] - ' . $day, ''];
		if (!$summary['total'])
		{
			$lines[] = $en ? '_No saved file changes were detected compared to this checkpoint._' :
				'_Nenhuma alteração nos arquivos salvos foi detectada em relação ao ponto selecionado._';
			$lines[] = '';
		}
		foreach ($sections as $action => $heading)
		{
			if (!$groups[$action]) continue;
			$lines[] = '### ' . $heading;
			$lines[] = '';
			foreach ($areaNames as $area => $category)
			{
				foreach ($groups[$action] as $row)
				{
					if ($row['area'] !== $area) continue;
					$line = '- **' . $category . '** — ' . self::quote($row['path']);
					if ($row['details'] !== '') $line .= ' (' . $row['details'] . ')';
					$lines[] = $line;
				}
			}
			$lines[] = '';
		}
		$draft = implode("\n", $lines);
		if (strlen($draft) > self::MAX_DRAFT_BYTES)
			throw new \RuntimeException('WSP_RN_TOO_LARGE');
		return ['summary' => $summary, 'items' => array_merge($groups['added'], $groups['modified'], $groups['removed']),
			'draft' => $draft, 'source' => 'comparison'];
	}

	public static function merge($version, $draft, $existing)
	{
		if (!self::validVersion($version)) throw new \RuntimeException('WSP_RN_INVALID_VERSION');
		$draft = trim(str_replace(["\r\n", "\r"], "\n", (string) $draft));
		$existing = str_replace(["\r\n", "\r"], "\n", (string) $existing);
		if ($draft === '' || strlen($draft) > self::MAX_DRAFT_BYTES || strpos($draft, "\0") !== false)
			throw new \RuntimeException('WSP_RN_TOO_LARGE');
		if (!preg_match('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}(?:\n|$)/', $draft))
			throw new \RuntimeException('WSP_RN_INVALID_DRAFT');
		if (preg_match('/^##\s+\[?' . preg_quote($version, '/') . '\]?(?=\s|$)/m', $existing))
			throw new \RuntimeException('WSP_RN_DUPLICATE');
		// Preserve the existing title, if present, and add newest notes directly below it.
		if (preg_match('/\A(#[^\r\n]*\n)(?:\s*\n)*/', $existing, $m))
		{
			$merged = rtrim($m[1], "\n") . "\n\n" . $draft . "\n\n" .
				ltrim(substr($existing, strlen($m[0])), "\n");
		}
		else
		{
			$merged = "# Changelog\n\n" . $draft . "\n\n" . ltrim($existing, "\n");
		}
		if (strlen($merged) > self::MAX_CHANGELOG_BYTES) throw new \RuntimeException('WSP_RN_TOO_LARGE');
		return rtrim($merged, "\n") . "\n";
	}
}
