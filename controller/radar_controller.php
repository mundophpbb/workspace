<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\radar_analyzer;
use mundophpbb\workspace\service\release_file_map;

/** Read-only, authenticated diagnostics endpoint. */
class radar_controller extends base_controller
{
	const MAX_ROWS = 300;
	const MAX_FILE_BYTES = 262144;
	const MAX_TOTAL_BYTES = 4194304;

	/** @var radar_analyzer */
	private $radar;

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
		radar_analyzer $radar
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->radar = $radar;
	}

	public function scan()
	{
		if ($r = $this->validate_post_form_key()) return $r;
		if (!$this->permission_service->can_access_workspace()) return $this->json_error('WSP_ERR_PERMISSION');
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS) return $this->json_error('WSP_ERR_PERMISSION');

		$project_id = (int) $this->request->variable('project_id', 0);
		if ($project_id <= 0) return $this->json_error('WSP_ERR_INVALID_DATA');
		$access = $this->assert_project_access($project_id, 'edit');
		if (empty($access['ok'])) return $this->json_error('WSP_ERR_PERMISSION');

		// First fetch bounded metadata; do not load 300 potentially multi-MB BLOBs.
		// Standard SQL CASE prioritizes extension sources over bundled editor/vendor JS.
		// Important for ZIP imports with hundreds of Ace editor assets.
		$sql = "SELECT file_id, file_name, LENGTH(file_content) AS source_bytes
				FROM " . $this->table_prefix . "workspace_files
				WHERE project_id = " . (int) $project_id . "
				ORDER BY CASE
				  WHEN file_name LIKE '%/ace/%' OR file_name LIKE '%/node_modules/%' THEN 9
				  WHEN file_name LIKE '%.php' THEN 0
				  WHEN file_name LIKE '%.yml' OR file_name LIKE '%.yaml' OR file_name LIKE '%composer.json' THEN 1
				  WHEN file_name LIKE '%.html' OR file_name LIKE '%.twig' THEN 2
				  ELSE 3 END, file_id ASC";
		$result = $this->db->sql_query_limit($sql, self::MAX_ROWS + 1);
		$metadata = [];
		while ($row = $this->db->sql_fetchrow($result)) $metadata[] = $row;
		$this->db->sql_freeresult($result);
		$truncated = count($metadata) > self::MAX_ROWS;
		if ($truncated) $metadata = array_slice($metadata, 0, self::MAX_ROWS);

		$ids = [];
		$names = [];
		$total = 0;
		$skipped = 0;
		foreach ($metadata as $row)
		{
			$id = (int) ($row['file_id'] ?? 0);
			$bytes = (int) ($row['source_bytes'] ?? 0);
			$path = release_file_map::normalizePath((string) ($row['file_name'] ?? ''));
			if ($path === '' || strtolower(basename($path)) === '.placeholder') continue;
			if ($bytes > self::MAX_FILE_BYTES || $total + $bytes > self::MAX_TOTAL_BYTES)
			{
				$skipped++;
				continue;
			}
			$ids[] = $id;
			$names[$id] = $path;
			$total += $bytes;
		}

		$raw = [];
		$sourceIds = [];
		$actualTotalBytes = 0;
		foreach (array_chunk($ids, 40) as $batch)
		{
			$sql = 'SELECT file_id, file_content FROM ' . $this->table_prefix . 'workspace_files
					WHERE project_id = ' . (int) $project_id . ' AND ' . $this->db->sql_in_set('file_id', $batch);
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$id = (int) $row['file_id'];
				if (!isset($names[$id])) continue;
				$name = $names[$id];
				$content = (string) $row['file_content'];
				$actualBytes = strlen($content);
				// LENGTH() counts characters instead of bytes on some databases.
				// Check the actual UTF-8 byte size before committing it to the scan.
				if ($actualBytes > self::MAX_FILE_BYTES || $actualTotalBytes + $actualBytes > self::MAX_TOTAL_BYTES)
				{
					$skipped++;
					continue;
				}
				$actualTotalBytes += $actualBytes;
				// Same rule as existing release normalizer: newest path occurrence wins.
				$raw[$name] = $content;
				$sourceIds[$name] = $id;
			}
			$this->db->sql_freeresult($result);
		}

		$canonical = release_file_map::canonicalize($raw);
		$canonicalIds = [];
		foreach ($canonical as $path => $content)
		{
			$best = 0;
			$bestLength = PHP_INT_MAX;
			foreach ($raw as $original => $originalContent)
			{
				$length = strlen($path);
				if ($originalContent !== $content || $length === 0 || strlen($original) < $length) continue;
				if (strcasecmp($original, $path) !== 0 &&
					(strlen($original) <= $length || strcasecmp(substr($original, -$length - 1), '/' . $path) !== 0)) continue;
				// Prefer an exact path over a wrapped copy.
				$distance = strlen($original) - $length;
				if ($distance < $bestLength) { $bestLength = $distance; $best = (int) $sourceIds[$original]; }
			}
			$canonicalIds[$path] = $best;
		}

		$report = $this->radar->analyze($canonical);
		foreach ($report['issues'] as &$issue)
		{
			$issue['file_id'] = (int) ($canonicalIds[$issue['file']] ?? 0);
		}
		unset($issue);
		$report['summary']['skipped'] = $skipped;
		$report['summary']['truncated'] = (bool) $truncated;
		$report['summary']['analyzed_files'] = count($canonical);
		return $this->json_success($report);
	}
}
