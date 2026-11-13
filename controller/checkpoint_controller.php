<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\checkpoint_manager;

/** Project-wide checkpoints: inspect, create, preview, restore and delete. */
class checkpoint_controller extends base_controller
{
	/** @var checkpoint_manager */
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
		checkpoint_manager $checkpoints
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->checkpoints = $checkpoints;
	}

	/** No visitor, no CSRF bypass, and no cross-project access. */
	private function permit($projectId, array $caps)
	{
		if ($r = $this->validate_post_form_key()) return $r;
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS ||
			!$this->permission_service->can_access_workspace()) return $this->json_error('WSP_ERR_PERMISSION');
		foreach ($caps as $cap)
		{
			if (empty($this->assert_project_access($projectId, $cap)['ok']))
				return $this->json_error('WSP_ERR_PERMISSION');
		}
		return null;
	}

	private function projectId() { return (int) $this->request->variable('project_id', 0); }
	private function checkpointId() { return (int) $this->request->variable('checkpoint_id', 0); }

	private function fail(\RuntimeException $exception)
	{
		$key = $exception->getMessage();
		if (strpos($key, 'WSP_CP_') !== 0) $key = 'WSP_CP_FAILED';
		return $this->json_error($key);
	}

	public function listing()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		return $this->json_success(['project_id' => $projectId,
			'checkpoints' => $this->checkpoints->listCheckpoints($projectId),
			'can_manage' => !empty($this->assert_project_access($projectId, 'manage')['ok']),
			'can_restore' => $this->mayRestore($projectId)]);
	}

	private function mayRestore($projectId)
	{
		foreach (['edit', 'upload', 'delete', 'rename_move'] as $cap)
			if (empty($this->assert_project_access($projectId, $cap)['ok'])) return false;
		return true;
	}

	/** Read-only backup integrity audit. Does not create or restore any file. */
	public function audit()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		try
		{
			$current = $this->checkpoints->capture($projectId);
			// Query latest manual point directly; other kinds cannot hide it in a bounded list.
			$sql = 'SELECT checkpoint_id, created_time, label FROM ' . $this->table_prefix .
				'workspace_checkpoints WHERE project_id = ' . (int) $projectId .
				" AND kind = 'manual' ORDER BY checkpoint_id DESC";
			$result = $this->db->sql_query_limit($sql, 1);
			$latest = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			$data = [
				'project_id' => $projectId,
				'current_files' => (int) $current['count'],
				'current_bytes' => (int) $current['bytes'],
				'checkpoint' => null,
				'status' => 'no_manual_checkpoint',
			];
			if ($latest)
			{
				$data['checkpoint'] = [
					'checkpoint_id' => (int) $latest['checkpoint_id'],
					'created_time' => (int) $latest['created_time'],
					'label' => (string) $latest['label'],
				];
				try
				{
					// Fetch verifies each persisted SHA-256 and the full snapshot fingerprint.
					$snapshot = $this->checkpoints->fetchCheckpoint($projectId, (int) $latest['checkpoint_id']);
					$differences = \mundophpbb\workspace\service\checkpoint_manager::diff($current, $snapshot);
					$data['status'] = $differences ? 'changed_since_checkpoint' : 'matches_checkpoint';
					$data['changed_files'] = count($differences);
					$data['snapshot_files'] = (int) $snapshot['count'];
					$data['snapshot_bytes'] = (int) $snapshot['bytes'];
				}
				catch (\RuntimeException $e)
				{
					$data['status'] = 'checkpoint_unreadable';
				}
			}
			return $this->json_success($data);
		}
		catch (\RuntimeException $e)
		{
			return $this->fail($e);
		}
	}

	/** Download project files as a verified portable ZIP; POST + CSRF + edit ACL. */
	public function export_zip()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		try {
			$snapshot = $this->checkpoints->capture($projectId);
			$binary = \mundophpbb\workspace\service\portable_backup::export($snapshot, $projectId);
			return new \Symfony\Component\HttpFoundation\Response($binary, 200, [
				'Content-Type' => 'application/zip',
				'Content-Disposition' => 'attachment; filename="workspace-project-' . $projectId . '-backup.zip"',
				'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
			]);
		} catch (\RuntimeException $e) { return $this->fail($e); }
	}

	/** Validate uploaded backup and preview its difference; does NOT restore. */
	public function inspect_zip()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		$upload = $this->request->file('backup_zip');
		if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
			!is_uploaded_file((string) ($upload['tmp_name'] ?? '')))
			return $this->json_error('WSP_CP_CORRUPTED');
		try {
			$saved = \mundophpbb\workspace\service\portable_backup::inspect(
				$upload['tmp_name'], (int) $upload['size'], $projectId);
			$current = $this->checkpoints->capture($projectId);
			$changes = checkpoint_manager::diff($current, $saved);
			return $this->json_success(['project_id'=>$projectId, 'status'=>'verified',
				'files'=>$saved['count'], 'bytes'=>$saved['bytes'],
				'created_utc'=>$saved['manifest']['created_utc'],
				'fingerprint'=>$saved['manifest']['fingerprint'],
				'changes'=>$changes, 'restoration_available'=>false]);
		} catch (\RuntimeException $e) { return $this->fail($e); }
	}

	/** Re-validate the uploaded ZIP on every step. No temporary server-side backup storage. */
	private function uploadedPortableBackup($projectId)
	{
		$upload = $this->request->file('backup_zip');
		if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
			!is_uploaded_file((string) ($upload['tmp_name'] ?? '')))
			throw new \RuntimeException('WSP_CP_CORRUPTED');
		return \mundophpbb\workspace\service\portable_backup::inspect(
			$upload['tmp_name'], (int) ($upload['size'] ?? 0), $projectId);
	}

	/** Exact bounded selection of changed paths; never accept a client-supplied destination. */
	private function selectedBackupChanges(array $changes)
	{
		$input = json_decode($this->raw_post('selected_paths'), true);
		if (!is_array($input) || !$input || count($input) > checkpoint_manager::MAX_DIFF_ITEMS ||
			array_values($input) !== $input) throw new \RuntimeException('WSP_CP_PREVIEW_REQUIRED');
		$allowed = [];
		foreach ($changes as $change) $allowed[$change['path']] = $change;
		$selected = [];
		foreach ($input as $path)
		{
			if (!is_string($path) || !checkpoint_manager::validPath($path) ||
				!isset($allowed[$path]) || isset($selected[$path]))
				throw new \RuntimeException('WSP_CP_PREVIEW_REQUIRED');
			$selected[$path] = $allowed[$path];
		}
		ksort($selected, SORT_STRING);
		return array_values($selected);
	}

	private function portableRestoreToken($projectId, array $current, array $saved, array $selection)
	{
		return hash('sha256', 'portable-restore-v1:' . (int) $projectId . ':' .
			$current['fingerprint'] . ':' . $saved['manifest']['fingerprint'] . ':' .
			json_encode(array_column($selection, 'path')));
	}

	/** Prepare a selection: permissions, exact change set, lock checks and revision-bound token. */
	public function prepare_zip_restore()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit', 'upload', 'delete', 'rename_move'])) return $r;
		try
		{
			$saved = $this->uploadedPortableBackup($projectId);
			$current = $this->checkpoints->capture($projectId);
			$selected = $this->selectedBackupChanges(checkpoint_manager::diff($current, $saved));
			$this->checkPortableRestoreLocks($selected, $saved);
			return $this->json_success(['project_id' => $projectId,
				'selected' => array_map(function ($row) { return ['path'=>$row['path'], 'action'=>$row['action']]; }, $selected),
				'preview_token' => $this->portableRestoreToken($projectId, $current, $saved, $selected),
				'current_fingerprint' => $current['fingerprint'],
				'backup_fingerprint' => $saved['manifest']['fingerprint'], 'can_restore' => true]);
		}
		catch (\RuntimeException $e) { return $this->fail($e); }
	}

	private function checkPortableRestoreLocks(array $selected, array $saved)
	{
		foreach ($selected as $change)
		{
			if ($change['file_id'] > 0)
			{
				$lock = $this->project_repo->get_file_lock($change['file_id']);
				if ($lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'])
					throw new \RuntimeException('WSP_CP_FILE_LOCKED');
			}
			if ($change['action'] !== 'remove' &&
				strlen($saved['files'][$change['path']]['content']) > $this->max_file_bytes())
				throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
		}
	}

	/** Apply only explicitly selected files, atomically with a pre-restore rollback checkpoint. */
	public function restore_zip()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit', 'upload', 'delete', 'rename_move'])) return $r;
		$provided = $this->raw_post('preview_token');
		if ($this->raw_post('confirmation') !== 'RESTAURAR' ||
			!preg_match('/^[a-f0-9]{64}$/D', $provided))
			return $this->json_error('WSP_CP_PREVIEW_REQUIRED');
		$this->db->sql_transaction('begin');
		try
		{
			// Uploaded bytes are re-read and verified, not trusted from the earlier preview.
			$saved = $this->uploadedPortableBackup($projectId);
			$current = $this->checkpoints->capture($projectId);
			$selected = $this->selectedBackupChanges(checkpoint_manager::diff($current, $saved));
			$expected = $this->portableRestoreToken($projectId, $current, $saved, $selected);
			if (!hash_equals($expected, $provided)) throw new \RuntimeException('WSP_CP_CONFLICT');
			$this->checkPortableRestoreLocks($selected, $saved);
			$backupId = $this->checkpoints->insertCheckpoint($projectId,
				(int) $this->user->data['user_id'], 'Antes de restaurar ZIP', 'pre_restore', $current);
			foreach ($selected as $change)
			{
				$path = $change['path'];
				$before = $current['files'][$path] ?? null;
				$after = $saved['files'][$path] ?? null;
				if ($change['action'] === 'restore')
				{
					if (!$this->project_repo->insert_file($projectId, $path, $after['content'], $after['type']))
						throw new \RuntimeException('WSP_CP_CONFLICT');
				}
				else if ($change['action'] === 'change')
				{
					if (!$this->project_repo->update_file_content($before['id'], $after['content'], $after['type'], $before['rev']))
						throw new \RuntimeException('WSP_CP_CONFLICT');
					$this->project_repo->create_file_version($projectId, $before['id'],
						(int) $this->user->data['user_id'], $path, $before['content'],
						'portable_backup', 'Before ZIP restoration');
				}
				else
				{
					$sql = 'DELETE FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' .
						$projectId . ' AND file_id = ' . (int) $before['id'] . ' AND file_rev = ' . (int) $before['rev'];
					if ($this->db->sql_query($sql) === false || !$this->db->sql_affectedrows())
						throw new \RuntimeException('WSP_CP_CONFLICT');
					foreach (['workspace_comments', 'workspace_file_reviews', 'workspace_file_versions',
						'workspace_file_locks', 'workspace_tasks'] as $table)
					{
						if ($this->db->sql_query('DELETE FROM ' . $this->table_prefix . $table .
							' WHERE project_id = ' . (int) $projectId . ' AND file_id = ' . (int) $before['id']) === false)
							throw new \RuntimeException('WSP_CP_FAILED');
					}
				}
			}
			$recheck = $this->checkpoints->capture($projectId);
			foreach ($selected as $change)
			{
				$path = $change['path'];
				$actual = $recheck['files'][$path] ?? null;
				$desired = $saved['files'][$path] ?? null;
				if (($actual === null) !== ($desired === null) ||
					($actual && ($actual['hash'] !== $desired['hash'] || $actual['type'] !== $desired['type'])))
					throw new \RuntimeException('WSP_CP_CONFLICT');
			}
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($projectId, (int) $this->user->data['user_id'],
				'portable_backup_restored', 'project', (string) $backupId,
				['backup_id'=>$backupId, 'changed'=>count($selected)]);
			return $this->json_success(['changed'=>count($selected), 'backup_id'=>$backupId]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
	}

	/** Optional cross-install migration: only users with project creation ACL. */
	private function permitMigration()
	{
		if ($r = $this->validate_post_form_key()) return $r;
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS ||
			!$this->permission_service->can_create_project())
			return $this->json_error('WSP_ERR_PERMISSION');
		return null;
	}

	/** Validate every entry, digest, size and path; ignore source project's numeric ID. */
	private function migrationUpload()
	{
		return $this->uploadedPortableBackup(null);
	}

	private function migrationName()
	{
		$name = trim($this->request->variable('project_name', '', true));
		if ($name === '' || utf8_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name))
			throw new \RuntimeException('WSP_CP_INVALID_NAME');
		return $name;
	}

	private function migrationToken(array $backup, $name)
	{
		$salt = (string) ($this->user->data['user_form_salt'] ?? '');
		if ($salt === '') throw new \RuntimeException('WSP_CP_PREVIEW_REQUIRED');
		return hash_hmac('sha256', 'workspace-migration-v1:' . (int) $this->user->data['user_id'] . ':' .
			$backup['manifest']['fingerprint'] . ':' . $backup['manifest']['project_id'] . ':' . $name, $salt);
	}

	/** Read-only preview. Files and supplied names never execute on the server. */
	public function migrate_preview()
	{
		if ($r = $this->permitMigration()) return $r;
		try {
			$saved = $this->migrationUpload();
			$name = $this->migrationName();
			if (!$saved['count']) throw new \RuntimeException('WSP_CP_CORRUPTED');
			return $this->json_success([
				'project_name' => $name, 'file_count' => $saved['count'], 'bytes' => $saved['bytes'],
				'source_project_id' => (int) $saved['manifest']['project_id'],
				'fingerprint' => $saved['manifest']['fingerprint'],
				'paths' => array_keys($saved['files']),
				'preview_token' => $this->migrationToken($saved, $name),
				'limitations' => 'files_only',
			]);
		} catch (\RuntimeException $e) { return $this->fail($e); }
	}

	/** Commit a brand-new PRIVATE project, atomically; never overwrite a project. */
	public function migrate_import()
	{
		if ($r = $this->permitMigration()) return $r;
		if ($this->raw_post('confirmation') !== 'IMPORTAR')
			return $this->json_error('WSP_CP_PREVIEW_REQUIRED');
		try {
			$saved = $this->migrationUpload();
			$name = $this->migrationName();
			$token = $this->raw_post('preview_token');
			if (!$saved['count'] || !preg_match('/^[a-f0-9]{64}$/D', $token) ||
				!hash_equals($this->migrationToken($saved, $name), $token))
				throw new \RuntimeException('WSP_CP_PREVIEW_REQUIRED');
			// Re-validate on confirmation; the database transaction is all-or-nothing.
			$this->db->sql_transaction('begin');
			try {
				$row = [
					'project_name' => $name,
					'project_desc' => 'Imported Workspace ZIP (files only)',
					'project_time' => time(),
					'user_id' => (int) $this->user->data['user_id'],
					'project_locked' => 0,
					'collaboration_mode' => 'private',
					'locked_by' => 0,
					'locked_time' => 0,
				];
				if ($this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'workspace_projects ' .
					$this->db->sql_build_array('INSERT', $row)) === false)
					throw new \RuntimeException('WSP_CP_FAILED');
				$newId = (int) $this->db->sql_nextid();
				if ($newId <= 0) throw new \RuntimeException('WSP_CP_FAILED');
				foreach ($saved['files'] as $path => $file) {
					if (!$this->project_repo->insert_file($newId, $path, $file['content'], $file['type']))
						throw new \RuntimeException('WSP_CP_FAILED');
				}
				$check = $this->checkpoints->capture($newId);
				if ($check['count'] !== $saved['count'] ||
					!hash_equals(checkpoint_manager::fingerprint($saved['files'], false),
						checkpoint_manager::fingerprint($check['files'], false)))
					throw new \RuntimeException('WSP_CP_CONFLICT');
				$this->db->sql_transaction('commit');
			} catch (\Throwable $e) {
				$this->db->sql_transaction('rollback');
				throw new \RuntimeException('WSP_CP_FAILED');
			}
			// Do not leak information or fail the import after the transaction committed.
			try { $this->project_repo->log_activity($newId, (int) $this->user->data['user_id'],
				'project_migrated', 'project', $name, ['files' => $saved['count']]); }
			catch (\Throwable $ignored) {}
			return $this->json_success(['project_id' => $newId, 'files' => $saved['count'], 'name' => $name]);
		} catch (\RuntimeException $e) { return $this->fail($e); }
	}

	public function create()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		$label = trim($this->raw_post('label'));
		if ($label === '') return $this->json_error('WSP_CP_INVALID_NAME');
		$this->db->sql_transaction('begin');
		try
		{
			$current = $this->checkpoints->capture($projectId);
			$id = $this->checkpoints->insertCheckpoint($projectId, (int) $this->user->data['user_id'],
				$label, 'manual', $current);
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($projectId, (int) $this->user->data['user_id'],
				'checkpoint_created', 'project', $label, ['checkpoint_id' => $id]);
			return $this->json_success(['checkpoint_id' => $id]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
	}

	public function preview()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['edit'])) return $r;
		try
		{
			$snapshot = $this->checkpoints->fetchCheckpoint($projectId, $this->checkpointId());
			$current = $this->checkpoints->capture($projectId);
			$changes = checkpoint_manager::diff($current, $snapshot);
			return $this->json_success([
				'checkpoint_id' => (int) $snapshot['meta']['checkpoint_id'],
				'project_id' => $projectId,
				'label' => $snapshot['meta']['label'],
				'changes' => $changes,
				'current_file_count' => $current['count'], 'snapshot_file_count' => $snapshot['count'],
				'preview_token' => checkpoint_manager::previewToken($projectId, $this->checkpointId(), $current, $snapshot),
				'can_restore' => $this->mayRestore($projectId),
			]);
		}
		catch (\RuntimeException $e) { return $this->fail($e); }
	}

	public function restore()
	{
		$projectId = $this->projectId();
		$checkpointId = $this->checkpointId();
		if ($r = $this->permit($projectId, ['edit', 'upload', 'delete', 'rename_move'])) return $r;
		$provided = $this->raw_post('preview_token');
		if (!preg_match('/^[a-f0-9]{64}$/D', $provided)) return $this->json_error('WSP_CP_PREVIEW_REQUIRED');

		$this->db->sql_transaction('begin');
		try
		{
			$saved = $this->checkpoints->fetchCheckpoint($projectId, $checkpointId);
			$current = $this->checkpoints->capture($projectId);
			$token = checkpoint_manager::previewToken($projectId, $checkpointId, $current, $saved);
			if (!hash_equals($token, $provided)) throw new \RuntimeException('WSP_CP_CONFLICT');
			$changes = checkpoint_manager::diff($current, $saved);
			if (!$changes)
			{
				$this->db->sql_transaction('commit');
				return $this->json_success(['changed' => 0, 'backup_id' => 0]);
			}
			foreach ($changes as $change)
			{
				if ($change['file_id'] > 0)
				{
					// Checkpoints never bypass a collaborator's lock, even for a project manager.
					$lock = $this->project_repo->get_file_lock($change['file_id']);
					if ($lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'])
						throw new \RuntimeException('WSP_CP_FILE_LOCKED');
				}
				if ($change['action'] !== 'remove' &&
					strlen($saved['files'][$change['path']]['content']) > $this->max_file_bytes())
					throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
			}
			// This rollback checkpoint is persisted in the SAME transaction as all file changes.
			$backupId = $this->checkpoints->insertCheckpoint($projectId,
				(int) $this->user->data['user_id'],
				'Antes de restaurar #' . $checkpointId, 'pre_restore', $current);
			foreach ($changes as $change)
			{
				$path = $change['path'];
				$before = $current['files'][$path] ?? null;
				$after = $saved['files'][$path] ?? null;
				if ($change['action'] === 'restore')
				{
					if (!$this->project_repo->insert_file($projectId, $path, $after['content'], $after['type']))
						throw new \RuntimeException('WSP_CP_CONFLICT');
				}
				else if ($change['action'] === 'change')
				{
					if (!$this->project_repo->update_file_content($before['id'], $after['content'], $after['type'], $before['rev']))
						throw new \RuntimeException('WSP_CP_CONFLICT');
					$this->project_repo->create_file_version($projectId, $before['id'],
						(int) $this->user->data['user_id'], $path, $before['content'], 'checkpoint', 'Before checkpoint restoration');
				}
				else // file added after the snapshot: remove it to recover the earlier state
				{
					$sql = 'DELETE FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' .
						$projectId . ' AND file_id = ' . (int) $before['id'] . ' AND file_rev = ' . (int) $before['rev'];
					if ($this->db->sql_query($sql) === false || !$this->db->sql_affectedrows())
						throw new \RuntimeException('WSP_CP_CONFLICT');
					foreach (['workspace_comments', 'workspace_file_reviews', 'workspace_file_versions',
						'workspace_file_locks', 'workspace_tasks'] as $table)
					{
						if ($this->db->sql_query('DELETE FROM ' . $this->table_prefix . $table .
							' WHERE project_id = ' . (int) $projectId . ' AND file_id = ' . (int) $before['id']) === false)
							throw new \RuntimeException('WSP_CP_FAILED');
					}
				}
			}
			// If an unexpected file was added during restore and is now visible, abort instead.
			$recheck = $this->checkpoints->capture($projectId);
			if (checkpoint_manager::diff($recheck, $saved)) throw new \RuntimeException('WSP_CP_CONFLICT');
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($projectId, (int) $this->user->data['user_id'],
				'checkpoint_restored', 'project', (string) $checkpointId,
				['backup_id' => $backupId, 'changed' => count($changes)]);
			return $this->json_success(['changed' => count($changes), 'backup_id' => $backupId]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
	}

	public function remove()
	{
		$projectId = $this->projectId();
		if ($r = $this->permit($projectId, ['manage'])) return $r;
		$this->db->sql_transaction('begin');
		try
		{
			// A project-scoped lookup protects against cross-project deletion.
			$this->checkpoints->fetchCheckpoint($projectId, $this->checkpointId());
			if (!$this->checkpoints->deleteCheckpoint($projectId, $this->checkpointId()))
				throw new \RuntimeException('WSP_CP_FAILED');
			$this->db->sql_transaction('commit');
			return $this->json_success();
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->fail($e);
		}
	}
}
