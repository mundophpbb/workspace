<?php
namespace mundophpbb\workspace\service;

/**
 * Static, read-only review of two SAVED phpBB extension projects.
 * Never executes migration files or modifies the forum database.
 */
final class upgrade_advisor
{
	const MAX_FINDINGS = 120;
	const MAX_MIGRATIONS = 250;
	const MAX_DETAILS_BYTES = 180;

	private $limitedFindings = false;

	public function analyze(array $older, array $newer, array $oldIds = [], array $newIds = [],
		array $unknownOld = [], array $unknownNew = [], $truncatedOld = false, $truncatedNew = false)
	{
		$this->limitedFindings = false;
		$findings = [];
		$totals = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'unverified' => 0];
		$migration = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchecked' => 0];
		$paths = array_unique(array_merge(array_keys($older), array_keys($newer),
			array_keys($unknownOld), array_keys($unknownNew)));
		sort($paths, SORT_STRING);
		$changes = [];
		foreach ($paths as $path)
		{
			$hasOld = array_key_exists($path, $older);
			$hasNew = array_key_exists($path, $newer);
			$unknown = isset($unknownOld[$path]) || isset($unknownNew[$path]) ||
				(!$hasOld && $truncatedOld) || (!$hasNew && $truncatedNew);
			$isMigration = $this->isMigration($path);
			if ($unknown)
			{
				$totals['unverified']++;
				if ($isMigration) $migration['unchecked']++;
				continue;
			}
			$status = !$hasOld ? 'added' : (!$hasNew ? 'removed' :
				(hash('sha256', (string) $older[$path]) === hash('sha256', (string) $newer[$path]) ? 'unchanged' : 'changed'));
			$totals[$status]++;
			if ($status !== 'unchanged') $changes[$path] = $status;
			if (!$isMigration || $status === 'unchanged') continue;
			$migration[$status]++;
			if ($status === 'removed')
			{
				$this->finding($findings, 'MIGRATION_REMOVED', 'high', 'migration', $path, 1,
					0, $oldIds[$path] ?? 0, 'high');
			}
			elseif ($status === 'changed')
			{
				$this->finding($findings, 'MIGRATION_CHANGED', 'high', 'migration', $path, 1,
					$newIds[$path] ?? 0, $oldIds[$path] ?? 0, 'high');
			}
		}

		$manifestOld = $this->manifest($older);
		$manifestNew = $this->manifest($newer);
		$oldPkg = $this->safeScalar($manifestOld['name'] ?? '');
		$newPkg = $this->safeScalar($manifestNew['name'] ?? '');
		$oldVersion = $this->safeScalar($manifestOld['version'] ?? '');
		$newVersion = $this->safeScalar($manifestNew['version'] ?? '');
		$manifestMissing = !is_array($manifestOld) || !is_array($manifestNew);
		if ($manifestMissing)
			$this->finding($findings, 'MANIFEST_INVALID', 'medium', 'compatibility', 'composer.json', 1,
				$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'high');
		else
		{
			if ($oldPkg === '' || $newPkg === '' || $oldPkg !== $newPkg)
				$this->finding($findings, 'PACKAGE_MISMATCH', 'high', 'compatibility', 'composer.json', 1,
					$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'high');
			if (($manifestOld['type'] ?? '') !== 'phpbb-extension' || ($manifestNew['type'] ?? '') !== 'phpbb-extension')
				$this->finding($findings, 'PACKAGE_TYPE', 'medium', 'compatibility', 'composer.json', 1,
					$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'high');
			if ($oldVersion !== '' && $newVersion !== '' &&
				version_compare($newVersion, $oldVersion, '<='))
				$this->finding($findings, 'VERSION_NOT_HIGHER', 'medium', 'compatibility', 'composer.json', 1,
					$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'medium');
			$oldRequire = is_array($manifestOld['require'] ?? null) ? $manifestOld['require'] : [];
			$newRequire = is_array($manifestNew['require'] ?? null) ? $manifestNew['require'] : [];
			$oldExtra = is_array($manifestOld['extra'] ?? null) ? $manifestOld['extra'] : [];
			$newExtra = is_array($manifestNew['extra'] ?? null) ? $manifestNew['extra'] : [];
			$oldSoft = is_array($oldExtra['soft-require'] ?? null) ? $oldExtra['soft-require'] : [];
			$newSoft = is_array($newExtra['soft-require'] ?? null) ? $newExtra['soft-require'] : [];
			foreach ([
				['php', $this->safeScalar($oldRequire['php'] ?? ''), $this->safeScalar($newRequire['php'] ?? '')],
				['phpbb', $this->safeScalar($oldSoft['phpbb/phpbb'] ?? ''),
					$this->safeScalar($newSoft['phpbb/phpbb'] ?? '')],
			] as $constraint)
			{
				if ((string) $constraint[1] !== (string) $constraint[2])
					$this->finding($findings, $constraint[0] === 'php' ? 'PHP_REQUIREMENT' : 'PHPBB_REQUIREMENT',
						'medium', 'compatibility', 'composer.json', 1,
						$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'high',
						$this->detail($constraint[1] . ' -> ' . $constraint[2]));
			}
			$requireOld = $oldRequire;
			$requireNew = $newRequire;
			if (is_array($requireOld) && is_array($requireNew))
				foreach ($requireNew as $dependency => $constraint)
					if ($dependency !== 'php' && (!array_key_exists($dependency, $requireOld) ||
						$requireOld[$dependency] !== $constraint))
						$this->finding($findings, 'DEPENDENCY_CHANGED', 'medium', 'compatibility', 'composer.json', 1,
							$newIds['composer.json'] ?? 0, $oldIds['composer.json'] ?? 0, 'medium', $this->detail($dependency));
		}

		// A previously installed migration must stay immutable. Only NEW migration methods are scanned
		// for database changes; revert_schema() may legitimately contain drop directives on uninstall.
		$examinedMigrations = 0;
		foreach ($changes as $path => $status)
		{
			if (!$this->isMigration($path) || $status !== 'added') continue;
			if (++$examinedMigrations > self::MAX_MIGRATIONS) break;
			$source = (string) $newer[$path];
			$methods = $this->methodBodies($source);
			if (!preg_match('/function\s+depends_on\s*\(/i', $source))
				$this->finding($findings, 'DEPENDENCIES_UNDECLARED', 'medium', 'migration', $path, 1,
					$newIds[$path] ?? 0, 0, 'medium');
			foreach (['update_schema', 'update_data'] as $method)
			{
				if (!isset($methods[$method])) continue;
				$info = $methods[$method];
				$body = $info['body'];
				$startLine = $info['line'];
				$patterns = $method === 'update_schema' ? [
					'SCHEMA_DROP' => '/[\'\"](?:drop_tables|drop_columns|drop_indexes)[\'\"]/i',
					'SCHEMA_CHANGE' => '/[\'\"](?:change_columns|change_tables)[\'\"]/i',
				] : [
					'ACL_REMOVE' => '/[\'\"]permission\.(?:remove|permission_unset)[\'\"]/i',
					'CONFIG_REMOVE' => '/[\'\"]config\.remove[\'\"]/i',
				];
				$patterns['RAW_SQL'] = '/\b(?:DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b|->\s*sql_query\s*\(/i';
				foreach ($patterns as $code => $pattern)
				{
					if (!preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE)) continue;
					$line = $startLine + substr_count(substr($body, 0, $match[0][1]), "\n");
					$severity = $code === 'SCHEMA_CHANGE' ? 'medium' : 'high';
					$this->finding($findings, $code, $severity,
						$code === 'ACL_REMOVE' ? 'permissions' : 'database', $path, $line,
						$newIds[$path] ?? 0, 0, 'medium');
				}
			}
		}
		if ($migration['added'] > self::MAX_MIGRATIONS)
			$this->finding($findings, 'MIGRATION_LIMIT', 'medium', 'migration', 'migrations/', 1, 0, 0, 'high');

		foreach (['config/services.yml' => 'SERVICE_REMOVED', 'config/routing.yml' => 'ROUTE_REMOVED'] as $path => $code)
		{
			if (!isset($changes[$path]) || $changes[$path] === 'added') continue;
			if (array_key_exists($path, $older) && !array_key_exists($path, $newer))
			{
				$this->finding($findings, $code, 'medium', 'api', $path, 1,
					0, $oldIds[$path] ?? 0, 'high', 'entire file removed');
				continue;
			}
			if (!array_key_exists($path, $older) || !array_key_exists($path, $newer)) continue;
			$oldKeys = $this->yamlKeys($older[$path], $path === 'config/services.yml');
			$newKeys = $this->yamlKeys($newer[$path], $path === 'config/services.yml');
			foreach (array_slice(array_diff($oldKeys, $newKeys), 0, 20) as $key)
				$this->finding($findings, $code, 'medium', 'api', $path, 1,
					$newIds[$path] ?? 0, $oldIds[$path] ?? 0, 'medium', $this->detail($key));
		}
		foreach ($changes as $path => $status)
		{
			if ($status !== 'removed') continue;
			if (preg_match('#^(?:controller|service|event)/.+\.php$#', $path))
				$this->finding($findings, 'CODE_REMOVED', 'medium', 'api', $path, 1,
					0, $oldIds[$path] ?? 0, 'high');
			elseif (preg_match('#^language/[^/]+/permissions_[^/]+\.php$#', $path))
				$this->finding($findings, 'ACL_LANGUAGE_REMOVED', 'medium', 'permissions', $path, 1,
					0, $oldIds[$path] ?? 0, 'high');
		}
		if (isset($changes['config/permissions.yml']))
			$this->finding($findings, 'ACL_CONFIG_CHANGED', 'medium', 'permissions', 'config/permissions.yml', 1,
				$newIds['config/permissions.yml'] ?? 0, $oldIds['config/permissions.yml'] ?? 0, 'medium');
		$hasAclChanges = false;
		foreach ($findings as $item)
			if ($item['category'] === 'permissions') { $hasAclChanges = true; break; }
		$complete = !$truncatedOld && !$truncatedNew && !$unknownOld && !$unknownNew &&
			$totals['unverified'] === 0 && $migration['added'] <= self::MAX_MIGRATIONS && !$this->limitedFindings;
		if (!$complete)
			$this->finding($findings, 'INCOMPLETE_SNAPSHOT', 'medium', 'coverage', '', 0, 0, 0, 'high');
		if ($this->limitedFindings) $complete = false;
		$rank = ['low' => 1, 'medium' => 2, 'high' => 3];
		$risk = 'low';
		$bySeverity = ['high' => 0, 'medium' => 0, 'low' => 0];
		foreach ($findings as $finding)
		{
			$bySeverity[$finding['severity']]++;
			if ($rank[$finding['severity']] > $rank[$risk]) $risk = $finding['severity'];
		}
		// No "safe to upgrade" verdict: static inspection cannot verify runtime conditions.
		return [
			'static_only' => true,
			'complete' => $complete,
			'risk' => $risk,
			'summary' => ['files' => $totals, 'migrations' => $migration,
				'findings' => count($findings), 'by_severity' => $bySeverity],
			'identity' => ['before_name' => $oldPkg, 'after_name' => $newPkg,
				'before_version' => $oldVersion, 'after_version' => $newVersion],
			'findings' => $findings,
			'plan' => ['BACKUP_DB', 'BACKUP_FILES', 'TEST_STAGING',
				'REVIEW_MIGRATIONS', 'VERIFY_REQUIREMENTS',
				$hasAclChanges ? 'VERIFY_ACL' : 'VERIFY_ACCESS',
				'UPDATE_ACP', 'SMOKE_TESTS', 'ROLLBACK_NOTICE'],
			'recommendation' => (!$complete || $bySeverity['high'] > 0) ? 'MANUAL_REVIEW' : 'TEST_FIRST',
		];
	}

	private function safeScalar($value)
	{
		return is_scalar($value) ? (string) $value : '';
	}

	private function manifest(array $files)
	{
		if (!isset($files['composer.json'])) return null;
		$value = json_decode((string) $files['composer.json'], true);
		return is_array($value) ? $value : null;
	}

	private function isMigration($path)
	{
		return preg_match('#^migrations/(?:[^/]+/)*[^/]+\.php$#i', (string) $path) === 1;
	}

	private function finding(array &$rows, $code, $severity, $category, $file, $line,
		$newFileId = 0, $oldFileId = 0, $confidence = 'medium', $detail = '')
	{
		if (count($rows) >= self::MAX_FINDINGS)
		{
			$this->limitedFindings = true;
			$rank = ['low' => 1, 'medium' => 2, 'high' => 3];
			$weakest = null;
			foreach ($rows as $index => $row)
			{
				if ($weakest === null || $rank[$row['severity']] < $rank[$rows[$weakest]['severity']])
					$weakest = $index;
			}
			if ($weakest === null || $rank[$severity] <= $rank[$rows[$weakest]['severity']]) return;
			unset($rows[$weakest]);
			$rows = array_values($rows);
		}
		$rows[] = [
			'code' => $code, 'severity' => $severity, 'category' => $category,
			'file' => (string) $file, 'line' => (int) $line,
			'new_file_id' => (int) $newFileId, 'old_file_id' => (int) $oldFileId,
			'confidence' => $confidence, 'detail' => (string) $detail,
		];
	}

	private function detail($value)
	{
		// No snippets of project code or database data in findings.
		$value = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) $value);
		return substr($value, 0, self::MAX_DETAILS_BYTES);
	}

	/** Extract only declared update method bodies, not uninstall/revert methods. */
	private function methodBodies($source)
	{
		$tokens = token_get_all((string) $source);
		$found = [];
		$size = count($tokens);
		for ($i = 0; $i < $size; ++$i)
		{
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
			$name = '';
			for ($j = $i + 1; $j < $size; ++$j)
			{
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING)
				{
					$name = strtolower($tokens[$j][1]); break;
				}
				if ($tokens[$j] === '(') break;
			}
			if (!in_array($name, ['update_schema', 'update_data'], true)) continue;
			while ($j < $size && $tokens[$j] !== '{') $j++;
			if ($j === $size) continue;
			$line = is_array($tokens[$i]) ? (int) $tokens[$i][2] : 1;
			for ($s = $i; $s <= $j; ++$s)
				$line += substr_count(is_array($tokens[$s]) ? $tokens[$s][1] : $tokens[$s], "\n");
			$body = ''; $depth = 1;
			for ($k = $j + 1; $k < $size; ++$k)
			{
				$part = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
				if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_COMMENT, T_DOC_COMMENT], true))
					$part = preg_replace('/[^\r\n]/', ' ', $part);
				if (!is_array($tokens[$k]) && $part === '{') $depth++;
				if (!is_array($tokens[$k]) && $part === '}' && --$depth === 0) break;
				$body .= $part;
			}
			$found[$name] = ['body' => $body, 'line' => $line];
		}
		return $found;
	}

	private function yamlKeys($content, $nestedServices = false)
	{
		$lines = preg_split('/\r\n|\r|\n/', (string) $content);
		$level = $nestedServices ? 4 : 0;
		$keys = [];
		foreach ($lines as $line)
		{
			if (!preg_match('/^([ ]*)([A-Za-z_][A-Za-z0-9_.-]*):(?:\s|$)/', $line, $m)) continue;
			$indent = strlen($m[1]);
			if ($nestedServices && $indent === 0 && $m[2] !== 'services') continue;
			if ($nestedServices && $indent === 0) continue;
			if ($nestedServices && $indent > 0 && $indent < $level) $level = $indent;
			if (!$nestedServices && $indent !== 0) continue;
			if ($nestedServices && $indent !== $level) continue;
			$keys[] = $m[2];
		}
		return array_values(array_unique($keys));
	}
}
