<?php
namespace mundophpbb\workspace\service;

/**
 * Workspace 1.24.0: evidence-based project health overview.
 * Read-only heuristics. No remote access, project execution, or arbitrary scoring.
 */
final class quality_dashboard
{
	const MAX_ALERTS = 36;

	private $radar;
	private $security;
	private $compatibility;
	private $permissions;

	public function __construct(
		radar_analyzer $radar,
		security_auditor $security,
		compatibility_lab $compatibility,
		permission_auditor $permissions
	) {
		$this->radar = $radar;
		$this->security = $security;
		$this->compatibility = $compatibility;
		$this->permissions = $permissions;
	}

	private function countSeverity(array $items, $severity)
	{
		$count = 0;
		foreach ($items as $item) if (($item['severity'] ?? '') === $severity) $count++;
		return $count;
	}

	private function alert(array &$alerts, $source, $code, $severity, $path, $line, $id)
	{
		if (count($alerts) >= self::MAX_ALERTS) return;
		$alerts[] = [
			'source' => (string) $source,
			'code' => (string) $code,
			'severity' => (string) $severity,
			'path' => (string) $path,
			'line' => max(1, (int) $line),
			'file_id' => max(0, (int) $id),
		];
	}

	/** Canonical source files are bounded by architecture_controller::snapshot(). */
	public function analyze(array $files, array $ids = [], array $snapshotCoverage = [])
	{
		$radar = $this->radar->analyze($files);
		$security = $this->security->analyze($files, $ids);
		$compatibility = $this->compatibility->analyze($files, $ids);
		$permissions = $this->permissions->analyze($files, $ids);
		$radarCounts = $radar['summary'] ?? [];
		$securityCounts = $security['summary']['by_severity'] ?? [];
		$permissionCounts = $permissions['summary'] ?? [];
		$labNotes = $compatibility['notes'] ?? [];
		$labCounts = $compatibility['summary'] ?? [];

		$readme = isset($files['README.md']) || isset($files['README.rst']) || isset($files['README.txt']);
		$changelog = isset($files['CHANGELOG.md']) || isset($files['CHANGELOG.txt']);
		$languages = 0;
		$migrations = 0;
		$tests = 0;
		$workflows = 0;
		foreach (array_keys($files) as $path)
		{
			if (preg_match('#^language/[a-z_]+/#i', $path)) $languages++;
			if (preg_match('#^migrations/.+\.php$#i', $path)) $migrations++;
			if (preg_match('#^tests/.+\.php$#i', $path)) $tests++;
			if (preg_match('#^\.github/workflows/[^/]+\.ya?ml$#i', $path)) $workflows++;
		}
		$snapshotComplete = !empty($snapshotCoverage['complete']);
		$complete = $snapshotComplete && empty($radarCounts['omitted']) &&
			!empty($security['coverage']['complete']) &&
			!empty($compatibility['coverage']['complete']) &&
			!empty($permissions['coverage']['complete']);
		$manifest = $compatibility['manifest'] ?? [];
		$rawManifest = json_decode((string) ($files['composer.json'] ?? ''), true);
		if (!is_array($rawManifest)) $rawManifest = [];
		$version = isset($rawManifest['version']) && is_string($rawManifest['version']) ? $rawManifest['version'] : '';
		if (strlen($version) > 60) $version = substr($version, 0, 60);

		$metrics = [
			'security' => [
				'high' => (int) ($securityCounts['high'] ?? 0),
				'medium' => (int) ($securityCounts['medium'] ?? 0),
				'low' => (int) ($securityCounts['low'] ?? 0),
				'complete' => !empty($security['coverage']['complete']),
			],
			'structure' => [
				'errors' => (int) ($radarCounts['errors'] ?? 0),
				'warnings' => (int) ($radarCounts['warnings'] ?? 0),
				'notices' => (int) ($radarCounts['notices'] ?? 0),
				'complete' => empty($radarCounts['omitted']) && $snapshotComplete,
			],
			'compatibility' => [
				'warnings' => $this->countSeverity($labNotes, 'warning'),
				'indicators' => (int) ($labCounts['indicators'] ?? 0),
				'php_constraint' => (string) ($manifest['php_constraint'] ?? ''),
				'php_min_detected' => (string) ($labCounts['highest_indicator_php_min'] ?? ''),
				'complete' => !empty($compatibility['coverage']['complete']),
			],
			'permissions' => [
				'declared' => (int) ($permissionCounts['defined'] ?? 0),
				'checked' => (int) ($permissionCounts['checked'] ?? 0),
				'external_references' => (int) ($permissionCounts['without_local_definition'] ?? 0),
				'dynamic_checks' => (int) ($permissionCounts['dynamic_checks'] ?? 0),
				'findings' => count($permissions['findings'] ?? []),
				'complete' => !empty($permissions['coverage']['complete']),
			],
			'tests' => [
				'files_present' => $tests,
				'workflows_present' => $workflows,
				'executed' => false,
				'coverage_measured' => false,
			],
			'maintenance' => [
				'readme_present' => $readme,
				'changelog_present' => $changelog,
				'language_files' => $languages,
				'migration_files' => $migrations,
				'files_in_snapshot' => count($files),
			],
		];

		$recommendations = [];
		if ($metrics['security']['high']) $recommendations[] = 'SECURITY_HIGH';
		if ($metrics['security']['medium']) $recommendations[] = 'SECURITY_REVIEW';
		if ($metrics['structure']['errors']) $recommendations[] = 'RADAR_ERRORS';
		if ($metrics['structure']['warnings']) $recommendations[] = 'RADAR_WARNINGS';
		if ($metrics['compatibility']['warnings']) $recommendations[] = 'COMPATIBILITY_REVIEW';
		if ($metrics['permissions']['external_references']) $recommendations[] = 'ACL_REVIEW';
		if (!$tests) $recommendations[] = 'TESTS_MISSING';
		if (!$workflows) $recommendations[] = 'CI_MISSING';
		if (!$readme) $recommendations[] = 'README_MISSING';
		if (!$changelog) $recommendations[] = 'CHANGELOG_MISSING';
		if (!$complete) $recommendations[] = 'PARTIAL_SCAN';

		$alerts = [];
		// Keep each discipline represented even if one analyzer finds many issues.
		$quotas = ['security' => 16, 'radar' => 10, 'compatibility' => 6, 'permissions' => 4];
		$shown = ['security' => 0, 'radar' => 0, 'compatibility' => 0, 'permissions' => 0];
		$eligible = 0;
		foreach ($security['findings'] ?? [] as $item)
		{
			if (!in_array($item['severity'] ?? '', ['high', 'medium'], true)) continue;
			$eligible++;
			if ($shown['security']++ >= $quotas['security']) continue;
			$this->alert($alerts, 'security', $item['code'] ?? '', $item['severity'] ?? '',
				$item['file'] ?? '', $item['line'] ?? 1, $item['file_id'] ?? 0);
		}
		foreach ($radar['issues'] ?? [] as $item)
		{
			if (!in_array($item['severity'] ?? '', ['error', 'warning'], true)) continue;
			$eligible++;
			if ($shown['radar']++ >= $quotas['radar']) continue;
			$file = (string) ($item['file'] ?? '');
			$this->alert($alerts, 'radar', $item['rule'] ?? '', $item['severity'] ?? '',
				$file, $item['line'] ?? 1, $ids[$file] ?? 0);
		}
		foreach ($labNotes as $item)
		{
			if (($item['severity'] ?? '') !== 'warning') continue;
			$eligible++;
			if ($shown['compatibility']++ >= $quotas['compatibility']) continue;
			$this->alert($alerts, 'compatibility', $item['code'] ?? '', 'warning',
				$item['path'] ?? '', $item['line'] ?? 1, $item['file_id'] ?? 0);
		}
		foreach ($permissions['findings'] ?? [] as $item)
		{
			$eligible++;
			if ($shown['permissions']++ >= $quotas['permissions']) continue;
			$this->alert($alerts, 'permissions', $item['code'] ?? '', 'notice',
				$item['path'] ?? '', $item['line'] ?? 1, $item['file_id'] ?? 0);
		}

		return [
			'static_only' => true,
			'complete' => (bool) $complete,
			'identity' => [
				'name' => (string) ($manifest['name'] ?? ''),
				'version' => $version,
			],
			'metrics' => $metrics,
			'recommendations' => array_values(array_unique($recommendations)),
			'alerts' => $alerts,
			'alerts_limited' => $eligible > count($alerts),
			'coverage' => [
				'snapshot' => $snapshotCoverage,
				'security' => $security['coverage'],
				'compatibility' => $compatibility['coverage'],
				'permissions' => $permissions['coverage'],
				'radar_omitted' => (int) ($radarCounts['omitted'] ?? 0),
			],
		];
	}
}
