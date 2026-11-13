<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\checkpoint_manager;
use mundophpbb\workspace\service\release_notes_builder;
use mundophpbb\workspace\service\release_notes_snapshot_reader;

/**
 * 1.19.0: guided, review-before-write release notes based on project checkpoints.
 */
class release_notes_controller extends base_controller
{
	private $checkpoints;
	private $notes;
	private $reader;

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
		checkpoint_manager $checkpoints,
		release_notes_builder $notes,
		release_notes_snapshot_reader $reader
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->checkpoints = $checkpoints;
		$this->notes = $notes;
		$this->reader = $reader;
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

	private function readArgs()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit($projectId)) return ['response' => $r];
		$sourceType = $this->raw_post('source_type', 'checkpoint');
		$sourceId = (int) $this->request->variable('source_id', 0);
		$version = trim($this->raw_post('version'));
		$locale = $this->raw_post('locale', 'pt_br');
		if ($sourceId <= 0 || !in_array($sourceType, ['checkpoint', 'project'], true) ||
			!release_notes_builder::validVersion($version) ||
			!in_array($locale, ['pt_br', 'en'], true))
			return ['response' => $this->json_error('WSP_RN_INVALID_VERSION')];
		if ($sourceType === 'project' && ($sourceId === $projectId ||
			empty($this->assert_project_access($sourceId, 'edit')['ok'])))
			return ['response' => $this->json_error('WSP_ERR_PERMISSION')];
		return compact('projectId', 'sourceType', 'sourceId', 'version', 'locale');
	}

	private function fail(\RuntimeException $e)
	{
		$key = (string) $e->getMessage();
		if (strpos($key, 'WSP_CP_') !== 0 && strpos($key, 'WSP_RN_') !== 0)
			$key = 'WSP_RN_FAILED';
		return $this->json_error($key);
	}

	/** Source snapshot requires its own project ACL when comparing saved projects. */
	private function baseline(array $args)
	{
		return $args['sourceType'] === 'project'
			? $this->reader->capture($args['sourceId'])
			: $this->reader->normalizeCheckpoint($this->checkpoints->fetchCheckpoint(
				$args['projectId'], $args['sourceId']));
	}

	private function targetPath(array $snapshot)
	{
		if (isset($snapshot['files']['CHANGELOG.md']))
			return (string) $snapshot['files']['CHANGELOG.md']['stored_path'];
		if (isset($snapshot['files']['composer.json']))
		{
			$composerPath = (string) $snapshot['files']['composer.json']['stored_path'];
			$directory = str_replace('\\', '/', dirname($composerPath));
			if ($directory !== '.' && $directory !== '')
			{
				$path = $directory . '/CHANGELOG.md';
				if (checkpoint_manager::validPath($path)) return $path;
			}
		}
		return 'CHANGELOG.md';
	}

	/** Read-only endpoint: preview and factual categories without writing a file. */
	public function preview()
	{
		$args = $this->readArgs();
		if (isset($args['response'])) return $args['response'];
		try
		{
			$projectId = $args['projectId'];
			$saved = $this->baseline($args);
			$current = $this->reader->capture($projectId);
			$report = $this->notes->generate($saved, $current, $args['version'], $args['locale'], date('Y-m-d'));
			$targetPath = $this->targetPath($current);
			$target = $this->project_repo->find_file($projectId, $targetPath);
			$canCreate = !empty($this->assert_project_access($projectId, 'rename_move')['ok']);
			return $this->json_success([
				'project_id' => $projectId,
				'source_type' => $args['sourceType'],
				'source_id' => $args['sourceId'],
				'preview_token' => release_notes_builder::token($projectId,
					$args['sourceType'] . ':' . $args['sourceId'], $saved,
					$current, $args['version'], $args['locale']),
				'report' => $report,
				'can_write' => $target ? true : $canCreate,
				'target_exists' => (bool) $target,
			]);
		}
		catch (\RuntimeException $e) { return $this->fail($e); }
		catch (\Exception $e) { return $this->json_error('WSP_RN_FAILED'); }
	}

	/** Atomic, revision-checked write. Existing CHANGELOG.md content is retained. */
	public function apply()
	{
		$args = $this->readArgs();
		if (isset($args['response'])) return $args['response'];
		$projectId = $args['projectId'];
		$provided = $this->raw_post('preview_token');
		$draft = $this->raw_post('draft');
		if (!preg_match('/^[a-f0-9]{64}$/D', $provided))
			return $this->json_error('WSP_RN_PREVIEW_REQUIRED');
		if (trim($draft) === '' || strlen($draft) > release_notes_builder::MAX_DRAFT_BYTES)
			return $this->json_error('WSP_RN_TOO_LARGE');
		$this->db->sql_transaction('begin');
		try
		{
			$saved = $this->baseline($args);
			$current = $this->reader->capture($projectId);
			$token = release_notes_builder::token($projectId, $args['sourceType'] . ':' . $args['sourceId'], $saved,
				$current, $args['version'], $args['locale']);
			if (!hash_equals($token, $provided)) throw new \RuntimeException('WSP_RN_CONFLICT');
			$report = $this->notes->generate($saved, $current, $args['version'], $args['locale'], date('Y-m-d'));
			if (empty($report['summary']['total'])) throw new \RuntimeException('WSP_RN_NO_CHANGES');
			$targetPath = $this->targetPath($current);
			$target = $this->project_repo->find_file($projectId, $targetPath);
			if (!$target && empty($this->assert_project_access($projectId, 'rename_move')['ok']))
				throw new \RuntimeException('WSP_RN_NO_CREATE');
			if ($target)
			{
				$lock = $this->project_repo->get_file_lock((int) $target['file_id']);
				if ($lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'])
					throw new \RuntimeException('WSP_RN_LOCKED');
			}
			$result = release_notes_builder::merge($args['version'], $draft,
				$target ? (string) $target['file_content'] : '');
			if (strlen($result) > $this->max_file_bytes()) throw new \RuntimeException('WSP_RN_TOO_LARGE');
			if ($target)
			{
				if (!$this->project_repo->create_file_version($projectId, (int) $target['file_id'],
					(int) $this->user->data['user_id'], $targetPath, (string) $target['file_content'],
					'save', 'Antes das notas de versão ' . $args['version']))
					throw new \RuntimeException('WSP_RN_FAILED');
				if (!$this->project_repo->update_file_content((int) $target['file_id'], $result, 'md', (int) $target['file_rev']))
					throw new \RuntimeException('WSP_RN_CONFLICT');
				$fileId = (int) $target['file_id'];
			}
			else
			{
				$fileId = (int) $this->project_repo->insert_file($projectId, $targetPath, $result, 'md');
				if ($fileId <= 0) throw new \RuntimeException('WSP_RN_CONFLICT');
			}
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($projectId, (int) $this->user->data['user_id'],
				'release_notes_created', 'file', $targetPath, [
					'version' => $args['version'], 'source_type' => $args['sourceType'], 'source_id' => $args['sourceId'],
					'changes' => (int) $report['summary']['total'],
				]);
			return $this->json_success([
				'project_id' => $projectId, 'file_id' => $fileId,
				'version' => $args['version'], 'changes' => (int) $report['summary']['total'],
			]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_RN_FAILED');
		}
	}
}
