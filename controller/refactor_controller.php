<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\refactor_engine;
use mundophpbb\workspace\service\checkpoint_manager;

/** Workspace 1.18: preview-then-commit literal project-wide refactoring. */
class refactor_controller extends base_controller
{
	private $engine;
	private $checkpoints;

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
		refactor_engine $engine,
		checkpoint_manager $checkpoints
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->engine = $engine;
		$this->checkpoints = $checkpoints;
	}

	private function requestData($apply)
	{
		if ($r = $this->validate_post_form_key()) return ['response' => $r];
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($projectId <= 0 || !$this->permission_service->can_access_workspace() ||
			(int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS)
			return ['response' => $this->json_error('WSP_ERR_PERMISSION')];
		if (empty($this->assert_project_access($projectId, 'edit')['ok']) ||
			($apply && empty($this->assert_project_access($projectId, 'replace')['ok'])))
			return ['response' => $this->json_error('WSP_ERR_PERMISSION')];
		$search = $this->raw_post('search');
		$replacement = $this->raw_post('replace');
		$filter = trim($this->raw_post('path_filter'));
		$case = (int) $this->request->variable('case_sensitive', 1) === 1;
		$whole = (int) $this->request->variable('whole_word', 0) === 1;
		try { refactor_engine::validate($search, $replacement, $filter); }
		catch (\RuntimeException $e) { return ['response' => $this->json_error('WSP_RF_INVALID')]; }
		return compact('projectId', 'search', 'replacement', 'filter', 'case', 'whole');
	}

	/** Use bounded DB reads. No filesystem access and no execution of extension PHP. */
	private function inspect($projectId, $search, $replacement, $filter, $case, $whole)
	{
		$sql = 'SELECT file_id, file_name, file_rev, LENGTH(file_content) AS content_bytes
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $projectId . ' ORDER BY file_id ASC';
		$result = $this->db->sql_query_limit($sql, refactor_engine::MAX_PROJECT_FILES + 1);
		if ($result === false) throw new \RuntimeException('WSP_RF_FAILED');
		$meta = [];
		while ($row = $this->db->sql_fetchrow($result)) $meta[] = $row;
		$this->db->sql_freeresult($result);
		if (count($meta) > refactor_engine::MAX_PROJECT_FILES)
			throw new \RuntimeException('WSP_RF_TOO_MANY_FILES');
		$eligible = [];
		$large = 0;
		foreach ($meta as $row)
		{
			$name = (string) ($row['file_name'] ?? '');
			if (!refactor_engine::candidatePath($name, $filter)) continue;
			// LENGTH is character count on some DBMS. The engine checks real bytes again.
			if ((int) ($row['content_bytes'] ?? 0) > refactor_engine::MAX_FILE_BYTES)
			{
				$large++;
				continue;
			}
			$eligible[(int) $row['file_id']] = $row;
		}
		$rows = [];
		$loaded = 0;
		$totalBytes = 0;
		// Small batches also cap the peak memory used by the DB driver's result set.
		foreach (array_chunk(array_keys($eligible), 8) as $batch)
		{
			$sql = 'SELECT file_id, file_content FROM ' . $this->table_prefix . 'workspace_files
					WHERE project_id = ' . (int) $projectId . ' AND ' . $this->db->sql_in_set('file_id', $batch);
			$result = $this->db->sql_query($sql);
			if ($result === false) throw new \RuntimeException('WSP_RF_FAILED');
			while ($row = $this->db->sql_fetchrow($result))
			{
				$id = (int) $row['file_id'];
				if (!isset($eligible[$id])) continue;
				$loaded++;
				$content = (string) $row['file_content'];
				$bytes = strlen($content);
				if ($bytes > refactor_engine::MAX_FILE_BYTES ||
					$totalBytes + $bytes > refactor_engine::MAX_SCAN_BYTES)
				{
					$large++;
					continue;
				}
				$totalBytes += $bytes;
				$rows[] = [
					'file_id' => $id, 'file_name' => (string) $eligible[$id]['file_name'],
					'file_rev' => (int) $eligible[$id]['file_rev'],
					'file_content' => $content,
				];
			}
			$this->db->sql_freeresult($result);
		}
		if ($loaded !== count($eligible)) throw new \RuntimeException('WSP_RF_CONFLICT');
		usort($rows, static function ($a, $b) { return (int) $a['file_id'] <=> (int) $b['file_id']; });
		$plan = $this->engine->plan($rows, $search, $replacement, $filter, $case, $whole);
		if ($large)
		{
			$plan['summary']['skipped'] += $large;
			$plan['summary']['complete'] = false;
		}
		$plan['token'] = refactor_engine::previewToken($projectId, $search, $replacement, $filter, $case, $whole, $plan);
		return $plan;
	}

	private function fail(\RuntimeException $e)
	{
		$code = (string) $e->getMessage();
		return $this->json_error(strpos($code, 'WSP_RF_') === 0 ? $code : 'WSP_RF_FAILED');
	}

	public function preview()
	{
		$args = $this->requestData(false);
		if (isset($args['response'])) return $args['response'];
		try
		{
			$plan = $this->inspect(...array_values($args));
			return $this->json_success([
				'project_id' => $args['projectId'],
				'preview_token' => $plan['token'],
				'summary' => $plan['summary'], 'files' => $plan['files'],
				'can_replace' => !empty($this->assert_project_access($args['projectId'], 'replace')['ok']),
			]);
		}
		catch (\RuntimeException $e) { return $this->fail($e); }
	}

	public function apply()
	{
		$args = $this->requestData(true);
		if (isset($args['response'])) return $args['response'];
		$token = $this->raw_post('preview_token');
		$fileIds = json_decode($this->raw_post('file_ids'), true);
		if (!preg_match('/^[0-9a-f]{64}$/D', $token) || !is_array($fileIds) || !$fileIds ||
			count($fileIds) > refactor_engine::MAX_CHANGED_FILES)
			return $this->json_error('WSP_RF_PREVIEW_REQUIRED');
		$selected = [];
		foreach ($fileIds as $fileId)
		{
			if (!is_int($fileId) || $fileId <= 0 || isset($selected[$fileId]))
				return $this->json_error('WSP_RF_INVALID');
			$selected[$fileId] = true;
		}
		$pid = $args['projectId'];
		$uid = (int) $this->user->data['user_id'];
		$this->db->sql_transaction('begin');
		try
		{
			$plan = $this->inspect(...array_values($args));
			if (!hash_equals($plan['token'], $token)) throw new \RuntimeException('WSP_RF_CONFLICT');
			if (!$plan['summary']['complete']) throw new \RuntimeException('WSP_RF_INCOMPLETE');
			$updates = [];
			foreach ($selected as $id => $_)
			{
				if (empty($plan['updates'][$id]) || $plan['updates'][$id]['old'] === $plan['updates'][$id]['new'])
					throw new \RuntimeException('WSP_RF_CONFLICT');
				$row = $plan['updates'][$id];
				if (strlen($row['new']) > $this->max_file_bytes())
					throw new \RuntimeException('WSP_RF_TOO_LARGE');
				// A project manager cannot override another collaborator's lock here.
				$lock = $this->project_repo->get_file_lock($id);
				if ($lock && (int) ($lock['user_id'] ?? 0) !== $uid)
					throw new \RuntimeException('WSP_RF_LOCKED');
				$updates[] = $row;
			}
			// Preserve an atomic whole-project restore point when within checkpoint bounds.
			// Larger projects remain recoverable file-by-file through their existing versions.
			$checkpointId = 0;
			try
			{
				$capture = $this->checkpoints->capture($pid);
				$checkpointId = $this->checkpoints->insertCheckpoint($pid, $uid,
					'Before global refactoring', 'pre_refactor', $capture);
			}
			catch (\RuntimeException $e)
			{
				if ($e->getMessage() !== 'WSP_CP_SIZE_LIMIT') throw $e;
			}
			foreach ($updates as $item)
			{
				$id = $item['id'];
				if (!$this->project_repo->create_file_version($pid, $id, $uid,
					$item['path'], $item['old'], 'save', 'Before global refactoring'))
					throw new \RuntimeException('WSP_RF_FAILED');
				if (!$this->project_repo->update_file_content($id, $item['new'], null, $item['rev']))
					throw new \RuntimeException('WSP_RF_CONFLICT');
			}
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($pid, $uid, 'refactor', 'project',
				'global', ['files' => count($updates), 'checkpoint' => $checkpointId]);
			return $this->json_success(['project_id' => $pid, 'updated' => count($updates),
				'checkpoint_id' => $checkpointId, 'matches' => array_sum(array_column($updates, 'count'))]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_RF_FAILED');
		}
	}
}
