<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\architecture_inspector;
use mundophpbb\workspace\service\permission_auditor;
use mundophpbb\workspace\service\compatibility_lab;
use mundophpbb\workspace\service\security_auditor;
use mundophpbb\workspace\service\upgrade_advisor;
use mundophpbb\workspace\service\release_file_map;
use mundophpbb\workspace\service\quality_dashboard;

/**
 * Version comparison and architecture map for saved projects.
 * All operations are read-only, CSRF checked, authenticated and ACL scoped.
 */
class architecture_controller extends base_controller
{
	const MAX_ROWS = 850;
	const MAX_FILE_BYTES = 262144;
	const MAX_TOTAL_BYTES = 12582912;
	const MAX_DIFF_BYTES = 24576;
	const MAX_DIFF_LINES = 350;

	/** @var architecture_inspector */
	private $inspector;

	/** @var permission_auditor */
	private $permissionAuditor;

	/** @var compatibility_lab */
	private $compatibilityLab;

	/** @var security_auditor|null */
	private $securityAuditor;

	/** @var upgrade_advisor|null */
	private $upgradeAdvisor;

	/** @var quality_dashboard|null */
	private $qualityDashboard;

	public function __construct(
		\phpbb\controller\helper $helper,
		\phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db,
		$table_prefix,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		$phpbb_root_path,
		\mundophpbb\workspace\repository\project_repository $project_repo,
		\mundophpbb\workspace\service\permission_service $permission_service,
		architecture_inspector $inspector,
		permission_auditor $permissionAuditor,
		compatibility_lab $compatibilityLab,
		security_auditor $securityAuditor = null,
		upgrade_advisor $upgradeAdvisor = null,
		quality_dashboard $qualityDashboard = null
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->inspector = $inspector;
		$this->permissionAuditor = $permissionAuditor;
		$this->compatibilityLab = $compatibilityLab;
		$this->securityAuditor = $securityAuditor;
		$this->upgradeAdvisor = $upgradeAdvisor;
		$this->qualityDashboard = $qualityDashboard;
	}

	/** @return \Symfony\Component\HttpFoundation\JsonResponse|null */
	private function permit(array $ids)
	{
		if ($r = $this->validate_post_form_key()) return $r;
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS) return $this->json_error('WSP_ERR_PERMISSION');
		if (!$this->permission_service->can_access_workspace()) return $this->json_error('WSP_ERR_PERMISSION');
		foreach ($ids as $id)
		{
			if ((int) $id <= 0 || empty($this->assert_project_access((int) $id, 'edit')['ok']))
				return $this->json_error('WSP_ERR_PERMISSION');
		}
		return null;
	}

	/** Bounded snapshot. No source is ever executed or written back. */
	private function snapshot($projectId)
	{
		$projectId = (int) $projectId;
		$sql = 'SELECT file_id, file_name, LENGTH(file_content) AS source_bytes FROM ' .
			$this->table_prefix . 'workspace_files WHERE project_id = ' . (int) $projectId .
			' ORDER BY file_id ASC';
		$result = $this->db->sql_query_limit($sql, self::MAX_ROWS + 1);
		$metadata = [];
		while ($row = $this->db->sql_fetchrow($result)) $metadata[] = $row;
		$this->db->sql_freeresult($result);
		$truncated = count($metadata) > self::MAX_ROWS;
		if ($truncated) $metadata = array_slice($metadata, 0, self::MAX_ROWS);
		$ids = [];
		$names = [];
		$estimate = 0;
		$skipped = 0;
		$skippedNames = [];
		foreach ($metadata as $row)
		{
			$id = (int) ($row['file_id'] ?? 0);
			$bytes = max(0, (int) ($row['source_bytes'] ?? 0));
			$path = release_file_map::normalizePath((string) ($row['file_name'] ?? ''));
			if (!$id || !$path || strtolower(basename($path)) === '.placeholder') continue;
			if ($bytes > self::MAX_FILE_BYTES || $estimate + $bytes > self::MAX_TOTAL_BYTES)
			{
				$skipped++;
				$skippedNames[$path] = true;
				continue;
			}
			$ids[] = $id;
			$names[$id] = $path;
			$estimate += $bytes;
		}
		$raw = [];
		$sourceIds = [];
		$actualTotal = 0;
		foreach (array_chunk($ids, 50) as $batch)
		{
			$sql = 'SELECT file_id, file_content FROM ' . $this->table_prefix .
				'workspace_files WHERE project_id = ' . (int) $projectId . ' AND ' .
				$this->db->sql_in_set('file_id', $batch);
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$id = (int) ($row['file_id'] ?? 0);
				if (!isset($names[$id])) continue;
				$content = (string) ($row['file_content'] ?? '');
				$size = strlen($content);
				if ($size > self::MAX_FILE_BYTES || $actualTotal + $size > self::MAX_TOTAL_BYTES)
				{
					$skipped++;
					$skippedNames[$names[$id]] = true;
					continue;
				}
				$actualTotal += $size;
				$path = $names[$id];
				$raw[$path] = $content;
				$sourceIds[$path] = $id;
			}
			$this->db->sql_freeresult($result);
		}
		$files = release_file_map::canonicalize($raw);
		$allMetadataNames = $raw;
		foreach ($skippedNames as $name => $ignored)
			if (!array_key_exists($name, $allMetadataNames)) $allMetadataNames[$name] = '';
		$allCanonical = release_file_map::canonicalize($allMetadataNames);
		$unknownPaths = array_fill_keys(array_diff(array_keys($allCanonical), array_keys($files)), true);
		$mapped = [];
		foreach ($files as $canonical => $content)
		{
			$best = PHP_INT_MAX;
			foreach ($raw as $original => $candidate)
			{
				if ($candidate !== $content) continue;
				$difference = strlen($original) - strlen($canonical);
				if ($difference < 0) continue;
				if (strcasecmp($original, $canonical) !== 0 &&
					!($difference > 0 && strcasecmp(substr($original, -strlen($canonical) - 1), '/' . $canonical) === 0))
					continue;
				if ($difference < $best)
				{
					$best = $difference;
					$mapped[$canonical] = (int) $sourceIds[$original];
				}
			}
		}
		return [
			'files' => $files,
			'ids' => $mapped,
			'unknown_paths' => $unknownPaths,
			'coverage' => [
				'complete' => !$truncated && !$skipped,
				'truncated' => $truncated,
				'skipped' => $skipped,
				'analyzed' => count($files),
			],
		];
	}

	/** Read-only inventory used by the hypothetical permission simulator. */
	public function permissions()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit([$projectId])) return $r;
		$snapshot = $this->snapshot($projectId);
		return $this->json_success([
			'project_id' => $projectId,
			'audit' => $this->permissionAuditor->analyze($snapshot['files'], $snapshot['ids']),
			'coverage' => $snapshot['coverage'],
		]);
	}

	/** Read-only compatibility hints. Never runs the extension or shell commands. */
	public function testLab()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit([$projectId])) return $r;
		$snapshot = $this->snapshot($projectId);
		return $this->json_success([
			'project_id' => $projectId,
			'lab' => $this->compatibilityLab->analyze($snapshot['files'], $snapshot['ids']),
			'coverage' => $snapshot['coverage'],
		]);
	}

	/** Read-only security heuristics; requires an authenticated project editor. */
	public function securityAudit()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit([$projectId])) return $r;
		if (!$this->securityAuditor) return $this->json_error('WSP_ERR_INVALID_DATA');
		$snapshot = $this->snapshot($projectId);
		return $this->json_success([
			'project_id' => $projectId,
			'audit' => $this->securityAuditor->analyze($snapshot['files'], $snapshot['ids']),
			'coverage' => $snapshot['coverage'],
		]);
	}

	/** 1.24.0: bounded static quality indicators, optionally compare two authorized projects. */
	public function qualityDashboard()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		$compareId = (int) $this->request->variable('compare_id', 0);
		if (!$this->qualityDashboard) return $this->json_error('WSP_ERR_INVALID_DATA');
		if ($compareId === $projectId && $compareId > 0) return $this->json_error('WSP_ARCH_PICK_DIFFERENT');
		$projects = $compareId ? [$projectId, $compareId] : [$projectId];
		if ($r = $this->permit($projects)) return $r;
		$primary = $this->snapshot($projectId);
		$reports = [
			'primary' => $this->qualityDashboard->analyze($primary['files'], $primary['ids'], $primary['coverage']),
		];
		if ($compareId)
		{
			$comparison = $this->snapshot($compareId);
			$reports['comparison'] = $this->qualityDashboard->analyze(
				$comparison['files'], $comparison['ids'], $comparison['coverage']);
			$leftName = (string) ($reports['primary']['identity']['name'] ?? '');
			$rightName = (string) ($reports['comparison']['identity']['name'] ?? '');
			if ($leftName === '' || $rightName === '' || $leftName !== $rightName)
				return $this->json_error('WSP_QL_PACKAGE_MISMATCH');
		}
		return $this->json_success([
			'project_id' => $projectId,
			'compare_id' => $compareId,
			'reports' => $reports,
		]);
	}

	/** Compare two authorized, saved versions without executing project code. */
	public function upgradePlan()
	{
		$before = (int) $this->request->variable('before_id', 0);
		$after = (int) $this->request->variable('after_id', 0);
		if ($r = $this->permit([$before, $after])) return $r;
		if ($before === $after) return $this->json_error('WSP_ARCH_PICK_DIFFERENT');
		if (!$this->upgradeAdvisor) return $this->json_error('WSP_ERR_INVALID_DATA');
		$old = $this->snapshot($before);
		$new = $this->snapshot($after);
		return $this->json_success([
			'before_id' => $before, 'after_id' => $after,
			'upgrade' => $this->upgradeAdvisor->analyze(
				$old['files'], $new['files'], $old['ids'], $new['ids'],
				$old['unknown_paths'], $new['unknown_paths'],
				$old['coverage']['truncated'], $new['coverage']['truncated']),
			'coverage' => ['before' => $old['coverage'], 'after' => $new['coverage']],
		]);
	}

	public function compare()
	{
		$before = (int) $this->request->variable('before_id', 0);
		$after = (int) $this->request->variable('after_id', 0);
		if ($r = $this->permit([$before, $after])) return $r;
		if ($before === $after) return $this->json_error('WSP_ARCH_PICK_DIFFERENT');
		$old = $this->snapshot($before);
		$new = $this->snapshot($after);
		$comparison = $this->inspector->compare($old['files'], $new['files'],
			$old['unknown_paths'], $new['unknown_paths'],
			$old['coverage']['truncated'], $new['coverage']['truncated']);
		return $this->json_success([
			'comparison' => $comparison,
			'before_id' => $before,
			'after_id' => $after,
			'coverage' => ['before' => $old['coverage'], 'after' => $new['coverage']],
		]);
	}

	public function graph()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit([$projectId])) return $r;
		$snapshot = $this->snapshot($projectId);
		return $this->json_success([
			'project_id' => $projectId,
			'graph' => $this->inspector->graph($snapshot['files'], $snapshot['ids']),
			'coverage' => $snapshot['coverage'],
		]);
	}

	public function diff()
	{
		$before = (int) $this->request->variable('before_id', 0);
		$after = (int) $this->request->variable('after_id', 0);
		if ($r = $this->permit([$before, $after])) return $r;
		if ($before === $after) return $this->json_error('WSP_ARCH_PICK_DIFFERENT');
		$rawPath = $this->raw_post('path', '');
		$path = release_file_map::normalizePath($rawPath);
		if ($path === '' || $path !== $rawPath || strlen($path) > 255 ||
			preg_match('/[\x00-\x1f\x7f]/', $path)) return $this->json_error('WSP_ERR_INVALID_DATA');
		$old = $this->snapshot($before);
		$new = $this->snapshot($after);
		if (!array_key_exists($path, $old['files']) && !array_key_exists($path, $new['files']))
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		// When incomplete, an absent file might merely be an oversized/skipped file.
		if ((!array_key_exists($path, $old['files']) &&
				(isset($old['unknown_paths'][$path]) || $old['coverage']['truncated'])) ||
			(!array_key_exists($path, $new['files']) &&
				(isset($new['unknown_paths'][$path]) || $new['coverage']['truncated'])))
			return $this->json_error('WSP_ARCH_INCOMPLETE');
		$left = (string) ($old['files'][$path] ?? '');
		$right = (string) ($new['files'][$path] ?? '');
		if (strlen($left) + strlen($right) > self::MAX_DIFF_BYTES ||
			substr_count($left, "\n") > self::MAX_DIFF_LINES || substr_count($right, "\n") > self::MAX_DIFF_LINES)
			return $this->json_success(['path' => $path, 'too_large' => true, 'patch' => '']);
		if (strpos($left, "\0") !== false || strpos($right, "\0") !== false)
			return $this->json_success(['path' => $path, 'too_large' => true, 'patch' => '']);
		$libPath = __DIR__ . '/../lib/';
		require_once $libPath . 'Diff.php';
		require_once $libPath . 'Diff/Renderer/Abstract.php';
		require_once $libPath . 'Diff/Renderer/Text/Unified.php';
		$renderer = new \mundophpbb\workspace\lib\Diff_Renderer_Text_Unified();
		$diff = new \mundophpbb\workspace\lib\Diff(
			explode("\n", str_replace("\r\n", "\n", $left)),
			explode("\n", str_replace("\r\n", "\n", $right))
		);
		return $this->json_success(['path' => $path, 'too_large' => false,
			'patch' => (string) $diff->render($renderer)]);
	}
}
