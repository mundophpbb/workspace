<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\checkpoint_manager;
use mundophpbb\workspace\service\release_history;
use mundophpbb\workspace\service\release_fingerprint;
use mundophpbb\workspace\service\release_file_map;

/** Release snapshots are retained independently of the rolling ordinary checkpoints. */
class release_history_controller extends base_controller
{
	private $checkpoints;
	public function __construct(
		\phpbb\controller\helper $helper, \phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db, $table_prefix,
		\phpbb\request\request $request, \phpbb\user $user, \phpbb\auth\auth $auth,
		$phpbb_root_path, \mundophpbb\workspace\repository\project_repository $project_repo,
		\mundophpbb\workspace\service\permission_service $permission_service,
		checkpoint_manager $checkpoints
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->checkpoints = $checkpoints;
	}
	private function permit($projectId)
	{
		if ($r = $this->validate_post_form_key()) return $r;
		if ($projectId <= 0 || (int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS ||
			!$this->permission_service->can_access_workspace() ||
			empty($this->assert_project_access($projectId, 'edit')['ok']))
			return $this->json_error('WSP_ERR_PERMISSION');
		return null;
	}
	public function listing()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit($projectId)) return $r;
		$sql = 'SELECT release_id, checkpoint_id, version, package_name, snapshot_sha, notes, created_by, created_time
			FROM ' . $this->table_prefix . 'workspace_release_records WHERE project_id = ' . (int) $projectId .
			' ORDER BY release_id DESC';
		$result = $this->db->sql_query_limit($sql, release_history::MAX_RELEASES);
		$records = [];
		while ($row = $this->db->sql_fetchrow($result)) $records[] = $row;
		$this->db->sql_freeresult($result);
		return $this->json_success(['project_id' => $projectId, 'releases' => $records]);
	}
	public function create()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit($projectId)) return $r;
		$version = trim($this->raw_post('version'));
		$package = trim($this->raw_post('package_name'));
		$notes = trim($this->raw_post('notes'));
		$validated = strtolower(trim((string) $this->request->variable('validated_fingerprint', '')));
		if ($validated !== '' && !release_fingerprint::is_valid($validated)) return $this->json_error('WSP_RH_INVALID');
		if (!release_history::validVersion($version) || !release_history::validPackageName($package) ||
			!release_history::validNotes($notes)) return $this->json_error('WSP_RH_INVALID');
		// Atomic capture: snapshot and metadata describe the same saved project state.
		$this->db->sql_transaction('begin');
		try
		{
			$table = $this->table_prefix . 'workspace_release_records';
			$result = $this->db->sql_query('SELECT release_id, version FROM ' . $table .
				' WHERE project_id = ' . (int) $projectId);
			$count = 0;
			while ($row = $this->db->sql_fetchrow($result))
			{
				$count++;
				if ($row['version'] === $version) throw new \RuntimeException('WSP_RH_DUPLICATE');
			}
			$this->db->sql_freeresult($result);
			if ($count >= release_history::MAX_RELEASES) throw new \RuntimeException('WSP_RH_LIMIT');
			$capture = $this->checkpoints->capture($projectId);
			if (!$capture['count']) throw new \RuntimeException('WSP_RH_EMPTY');
			if ($validated !== '')
			{
				// Match the validator/download canonical extension-relative file map.
				$map = [];
				foreach ($capture['files'] as $path => $file)
				{
					if (strtolower(basename((string) $path)) === '.placeholder') continue;
					$map[(string) $path] = (string) $file['content'];
				}
				$map = release_file_map::canonicalize($map);
				if (!hash_equals($validated, release_fingerprint::calculate($map)))
					throw new \RuntimeException('WSP_RELEASE_SNAPSHOT_CHANGED');
			}
			$checkpointId = $this->checkpoints->insertCheckpoint($projectId,
				(int) $this->user->data['user_id'], 'Release ' . $version, 'release', $capture);
			$data = [
				'project_id' => $projectId, 'checkpoint_id' => $checkpointId, 'version' => $version,
				'package_name' => $package, 'snapshot_sha' => release_history::fingerprint($capture),
				'notes' => $notes, 'created_by' => (int) $this->user->data['user_id'], 'created_time' => time(),
			];
			if ($this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT', $data)) === false)
				throw new \RuntimeException('WSP_RH_FAILED');
			$id = (int) $this->db->sql_nextid();
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($projectId, (int) $this->user->data['user_id'],
				'release_recorded', 'project', $version, ['release_id' => $id, 'checkpoint_id' => $checkpointId]);
			return $this->json_success(['project_id' => $projectId, 'release_id' => $id, 'checkpoint_id' => $checkpointId]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			$key = $e->getMessage();
			if (strpos($key, 'WSP_RH_') !== 0 && strpos($key, 'WSP_CP_') !== 0) $key = 'WSP_RH_FAILED';
			return $this->json_error($key);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_RH_FAILED');
		}
	}
}
