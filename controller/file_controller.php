<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Workspace component.
 */
class file_controller extends base_controller
{
	/**
	 * Handles json error msg.
	 */
	private function json_error_msg($msg)
	{
		return new JsonResponse([
			'success' => false,
			'error'   => (string) $msg,
		]);
	}

	/**
	 * SSOT de acesso ao Workspace (AJAX/JSON).
	 * @return JsonResponse|null
	 */
	private function ensure_workspace_access()
	{
		if ($r = $this->validate_post_form_key())
		{
			return $r;
		}

		if (isset($this->permission_service) && method_exists($this->permission_service, 'can_access_workspace'))
		{
			if (!$this->permission_service->can_access_workspace())
			{
				return $this->json_error('WSP_ERR_PERMISSION');
			}
			return null;
		}

		if (!$this->auth->acl_get('u_workspace_access'))
		{
			return $this->json_error('WSP_ERR_PERMISSION');
		}

		return null;
	}

	/**
	 * Normalizes content.
	 */
	private function normalize_content($content)
	{
		return str_replace(["\r\n", "\r"], "\n", (string) $content);
	}

	/**
	 * Handles detect file type.
	 */
	private function detect_file_type($path)
	{
		$base   = basename(str_replace('\\', '/', (string) $path));
		$base_l = strtolower($base);

		if ($base_l === '.placeholder') { return 'txt'; }
		if ($base_l === 'dockerfile')   { return 'dockerfile'; }
		if ($base_l === 'makefile')     { return 'makefile'; }
		if ($base_l === '.htaccess')    { return 'htaccess'; }

		$ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
		return $ext ?: 'txt';
	}

	/**
	 * Upload de ficheiros (full_path) + Base64 (firewall safe)
	 */
	public function upload_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);

		// ACL granular: upload
		$access = $this->assert_project_access($project_id, 'upload');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$filename = $this->raw_post('full_path');
		$file     = $this->request->file('file');

		// The base64 payload is not HTML text: read it unescaped.
		$encoded_content = $this->raw_post('file_content', '');
		$is_encoded      = (int) $this->request->variable('is_encoded', 0);

		if (empty($filename) && isset($file['name']))
		{
			$filename = $file['name'];
		}

		$filename = $this->sanitize_rel_path($filename);
		if ($filename === '')
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
		{
			return $this->json_error('WSP_ERR_INVALID_EXT');
		}

		// Size guards run before anything is decoded or read into memory.
		$max_encoded = (int) ceil($this->max_file_bytes() * 4 / 3) + 1024;
		if ($is_encoded && strlen($encoded_content) > $max_encoded)
		{
			return $this->json_error('WSP_ERR_FILE_TOO_LARGE', [(int) ($this->max_file_bytes() / 1024)]);
		}
		if (!$is_encoded && !empty($file['size']) && (int) $file['size'] > $this->max_file_bytes())
		{
			return $this->json_error('WSP_ERR_FILE_TOO_LARGE', [(int) ($this->max_file_bytes() / 1024)]);
		}

		$content = false;

		if ($is_encoded && $encoded_content !== '')
		{
			if (preg_match('/^data:.*?;base64,/', $encoded_content))
			{
				$encoded_content = preg_replace('/^data:.*?;base64,/', '', $encoded_content);
			}

			$content = base64_decode($encoded_content, true);
		}
		else if (isset($file['tmp_name']) && !empty($file['tmp_name']))
		{
			$content = @file_get_contents($file['tmp_name']);
		}
		else
		{
			return $this->json_error('WSP_ERR_NO_CONTENT');
		}

		if ($content === false)
		{
			return $this->json_error('WSP_ERR_CONTENT_PROCESS');
		}

		if ($r = $this->check_file_size($content))
		{
			return $r;
		}

		// Keep the original uploaded bytes in storage so the release validator can
		// detect CRLF/CR line endings and non-UTF-8 content accurately.
		// Normalize only for comparisons/diffs/editor workflows.
		$raw_content = (string) $content;
		$content_for_compare = $this->normalize_content($raw_content);
		$ext = $this->detect_file_type($filename);
		$uid = (int) $this->user->data['user_id'];

		// Two uploads of the same path can race. The unique (project_id, file_name)
		// index guarantees only one INSERT wins; the loser retries as an update.
		// Updates use optimistic locking on file_rev for the same reason.
		for ($attempt = 0; $attempt < 3; $attempt++)
		{
			$this->db->sql_transaction('begin');

			$row = $this->project_repo->find_file($project_id, $filename);

			if ($row)
			{
				if ($r = $this->ensure_file_unlocked((int) $row['file_id'], $project_id))
				{
					$this->db->sql_transaction('rollback');
					return $r;
				}
				if (!$this->project_repo->update_file_content((int) $row['file_id'], $raw_content, $ext, (int) $row['file_rev']))
				{
					$this->db->sql_transaction('rollback');
					continue;
				}

				$old = $this->normalize_content((string) $row['file_content']);

				if ($old !== $content_for_compare)
				{
					$this->project_repo->create_file_version(
						(int) $row['project_id'],
						(int) $row['file_id'],
						$uid,
						(string) $row['file_name'],
						$old,
						'upload',
						$this->user->lang('WSP_VERSION_BEFORE_SAVE')
					);

					$this->log_to_changelog(
						$project_id,
						$this->user->lang('WSP_LOG_UPLOAD_UPDATE', $filename),
						$old,
						$content_for_compare
					);
					$this->project_repo->log_activity($project_id, $uid, 'file_uploaded_update', 'file', $filename);
					$this->notify_project_members($project_id, 'file_uploaded_update', 'file', $filename, 'WSP_NOTIFY_FILE_UPLOADED_UPDATE');
				}
			}
			else
			{
				if (!$this->project_repo->insert_file($project_id, $filename, $raw_content, $ext))
				{
					$this->db->sql_transaction('rollback');
					continue;
				}

				$this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_UPLOAD_NEW', $filename));
				$this->project_repo->log_activity($project_id, $uid, 'file_uploaded_new', 'file', $filename);
				$this->notify_project_members($project_id, 'file_uploaded_new', 'file', $filename, 'WSP_NOTIFY_FILE_UPLOADED_NEW');
			}

			$this->db->sql_transaction('commit');
			return $this->json_success();
		}

		return $this->json_error('WSP_ERR_UPDATE_FAILED');
	}

	/**
	 * Imports a whole ZIP archive into the project with a single request.
	 *
	 * Nothing is extracted to the disk: the archive is read in memory by
	 * zip_importer, paths are sanitised and every file goes through the same
	 * extension and size rules as a normal upload.
	 */
	public function upload_zip()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);

		$access = $this->assert_project_access($project_id, 'upload');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		if (!class_exists('\ZipArchive'))
		{
			return $this->json_error('WSP_ERR_ZIP_NOT_AVAILABLE');
		}

		$file = $this->request->file('zip_file');
		if (empty($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE)
		{
			return $this->json_error('WSP_ZIP_FILE_REQUIRED');
		}
		if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE)
		{
			return $this->json_error('WSP_ERR_ZIP_PHP_LIMIT', [(string) ini_get('upload_max_filesize')]);
		}
		if ((int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
		{
			return $this->json_error('WSP_ERR_ZIP_UPLOAD');
		}

		$importer = new \mundophpbb\workspace\service\zip_importer();
		try
		{
			$result = $importer->read($file['tmp_name'], [$this, 'zip_entry_allowed'], $this->max_file_bytes());
		}
		catch (\RuntimeException $e)
		{
			$keys = [
				'not_zip'  => 'WSP_ERR_ZIP_INVALID',
				'empty'    => 'WSP_ERR_ZIP_EMPTY',
				'too_many' => 'WSP_ERR_ZIP_TOO_MANY',
				'too_big'  => 'WSP_ERR_ZIP_TOO_BIG',
			];
			return $this->json_error(isset($keys[$e->getMessage()]) ? $keys[$e->getMessage()] : 'WSP_ERR_ZIP_INVALID');
		}

		$uid = (int) $this->user->data['user_id'];
		$created = 0;
		$updated = 0;
		$unchanged = 0;
		$failed = [];

		foreach ($result['files'] as $path => $content)
		{
			$path = $this->sanitize_rel_path($path);
			if ($path === '')
			{
				continue;
			}

			$status = $this->store_imported_file($project_id, $path, (string) $content, $uid);
			if ($status === 'created') { $created++; }
			else if ($status === 'updated') { $updated++; }
			else if ($status === 'unchanged') { $unchanged++; }
			else { $failed[] = [$path, $status === 'locked' ? 'locked' : 'failed']; }
		}

		if ($created || $updated)
		{
			$this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_ZIP_IMPORT', $created, $updated));
			$this->project_repo->log_activity($project_id, $uid, 'zip_imported', 'project', '', ['created' => $created, 'updated' => $updated]);
		}

		return $this->json_success([
			'created'   => $created,
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'skipped'   => $result['skipped'],
			'failed'    => $failed,
		]);
	}

	/**
	 * Callback for zip_importer (must be public): is this path an accepted file type?
	 */
	public function zip_entry_allowed($path)
	{
		return $this->is_placeholder_path($path) || $this->is_extension_allowed($path);
	}

	/**
	 * Stores one imported file (insert or update with version history), without per-file notifications.
	 *
	 * @return string created|updated|unchanged|failed
	 */
	private function store_imported_file($project_id, $filename, $raw_content, $uid)
	{
		$ext = $this->detect_file_type($filename);
		$content_for_compare = $this->normalize_content($raw_content);

		for ($attempt = 0; $attempt < 3; $attempt++)
		{
			$this->db->sql_transaction('begin');
			$row = $this->project_repo->find_file($project_id, $filename);

			if ($row)
			{
				if ($this->ensure_file_unlocked((int) $row['file_id'], $project_id))
				{
					$this->db->sql_transaction('rollback');
					return 'locked';
				}
				$old = $this->normalize_content((string) $row['file_content']);
				if ($old === $content_for_compare && (string) $row['file_content'] === $raw_content)
				{
					$this->db->sql_transaction('commit');
					return 'unchanged';
				}

				if (!$this->project_repo->update_file_content((int) $row['file_id'], $raw_content, $ext, (int) $row['file_rev']))
				{
					$this->db->sql_transaction('rollback');
					continue;
				}

				if ($old !== $content_for_compare)
				{
					$this->project_repo->create_file_version(
						(int) $row['project_id'],
						(int) $row['file_id'],
						$uid,
						(string) $row['file_name'],
						$old,
						'upload',
						$this->user->lang('WSP_VERSION_BEFORE_SAVE')
					);
				}

				$this->db->sql_transaction('commit');
				return 'updated';
			}

			if (!$this->project_repo->insert_file($project_id, $filename, $raw_content, $ext))
			{
				$this->db->sql_transaction('rollback');
				continue;
			}

			$this->db->sql_transaction('commit');
			return 'created';
		}

		return 'failed';
	}

	/**
	 * Adds file.
	 */
	public function add_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		$filename   = trim($this->raw_post('name'));

		// Workspace implementation detail.
		$access = $this->assert_project_access($project_id, 'rename_move');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$filename = $this->sanitize_rel_path($filename);
		if (!$project_id || $filename === '')
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
		{
			return $this->json_error('WSP_ERR_INVALID_EXT');
		}

		// Friendly pre-check; the unique index below still decides under concurrency.
		if ($this->project_repo->find_file($project_id, $filename))
		{
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$ext = $this->detect_file_type($filename);

		// Workspace implementation detail.
		$initial_content = ($ext === 'php') ? "<?php\n\n" : "";

		$this->db->sql_transaction('begin');

		// The unique (project_id, file_name) index is the real guard against
		// duplicates; a lost race simply makes this INSERT fail.
		$file_id = $this->project_repo->insert_file($project_id, $filename, $initial_content, $ext);

		if ($file_id <= 0)
		{
			$this->db->sql_transaction('rollback');

			return $this->project_repo->find_file($project_id, $filename)
				? $this->json_error('WSP_ERR_FILE_EXISTS')
				: $this->json_error('WSP_ERR_CREATE_FAILED');
		}

		$this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_FILE_CREATED', $filename));
		$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_created', 'file', $filename);

		$this->db->sql_transaction('commit');

		return $this->json_success(['file_id' => $file_id]);
	}

	public function load_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$access  = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$sql = 'SELECT file_content, file_name, file_type, file_rev
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE file_id = ' . (int) $file_id;

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$content = $this->normalize_content((string) $row['file_content']);

		return $this->json_success([
			'content' => $content,
			'name'    => (string) $row['file_name'],
			'type'    => strtolower((string) $row['file_type']),
			'file_rev' => (int) $row['file_rev'],
		]);
	}

	public function save_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$expected_rev = (int) $this->request->variable('file_rev', -1);

		// Stored raw: request->variable(..., true) would store "<?php" as "&lt;?php".
		$content = $this->raw_post('content', '');

		// Workspace implementation detail.
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		if ($r = $this->check_file_size($content))
		{
			return $r;
		}

		if ($expected_rev < 0)
		{
			return $this->json_error('WSP_ERR_FILE_REV_REQUIRED');
		}

		$content = $this->normalize_content($content);

		$sql_old = 'SELECT project_id, file_name, file_content, file_rev
					FROM ' . $this->table_prefix . 'workspace_files
					WHERE file_id = ' . (int) $file_id;

		$result_old = $this->db->sql_query($sql_old);
		$row_old    = $this->db->sql_fetchrow($result_old);
		$this->db->sql_freeresult($result_old);

		if (!$row_old)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		if ((int) $row_old['file_rev'] !== $expected_rev)
		{
			return $this->json_error('WSP_ERR_FILE_CONFLICT');
		}

		if ($r = $this->ensure_file_unlocked($file_id, (int) $access['project_id'])) { return $r; }

		$project_id = (int) $row_old['project_id'];
		$file_name  = (string) $row_old['file_name'];
		$old        = $this->normalize_content((string) $row_old['file_content']);
		$uid        = (int) $this->user->data['user_id'];

		if ($old === $content)
		{
			return $this->json_success(['file_rev' => $expected_rev, 'unchanged' => true]);
		}

		$this->db->sql_transaction('begin');

		if (!$this->project_repo->update_file_content($file_id, $content, null, $expected_rev))
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_FILE_CONFLICT');
		}

		if ($old !== $content)
		{
			// Keep the previous content so every edit can be inspected and restored.
			$this->project_repo->create_file_version(
				$project_id,
				$file_id,
				$uid,
				$file_name,
				$old,
				'save',
				$this->user->lang('WSP_VERSION_BEFORE_SAVE')
			);

			// Avoid modifying changelog.txt again while saving that very file:
			// a second write would increase file_rev twice and stale the editor.
			if (strtolower(basename($file_name)) !== 'changelog.txt')
			{
				$this->log_to_changelog(
					$project_id,
					$this->user->lang('WSP_LOG_FILE_CHANGED', $file_name),
					$old,
					$content
				);
			}
			$this->project_repo->log_activity($project_id, $uid, 'file_saved', 'file', $file_name);
			$this->notify_project_members($project_id, 'file_saved', 'file', $file_name, 'WSP_NOTIFY_FILE_SAVED');
		}

		$this->db->sql_transaction('commit');

		return $this->json_success(['file_rev' => $expected_rev + 1]);
	}

	public function rename_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id  = (int) $this->request->variable('file_id', 0);
		$new_name = $this->sanitize_rel_path($this->raw_post('new_name'));

		if (!$file_id || $new_name === '')
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if (!$this->is_placeholder_path($new_name) && !$this->is_extension_allowed($new_name))
		{
			return $this->json_error('WSP_ERR_INVALID_EXT');
		}

		// ✅ rename/move = rename_move
		$access = $this->assert_file_access($file_id, 'rename_move');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		// Workspace implementation detail.
		$sql_info = 'SELECT project_id, file_name
					 FROM ' . $this->table_prefix . 'workspace_files
					 WHERE file_id = ' . (int) $file_id;

		$res_info = $this->db->sql_query($sql_info);
		$info     = $this->db->sql_fetchrow($res_info);
		$this->db->sql_freeresult($res_info);

		if (!$info)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$project_id = (int) $info['project_id'];
		$old_name   = (string) $info['file_name'];

		// conflito
		$sql_check = 'SELECT file_id
					  FROM ' . $this->table_prefix . 'workspace_files
					  WHERE project_id = ' . (int) $project_id . "
						AND file_name = '" . $this->db->sql_escape($new_name) . "'
						AND file_id <> " . (int) $file_id;

		$result_check = $this->db->sql_query($sql_check);
		$exists       = $this->db->sql_fetchrow($result_check);
		$this->db->sql_freeresult($result_check);

		if ($exists)
		{
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$new_ext = $this->detect_file_type($new_name);

		if ($r = $this->ensure_file_unlocked($file_id, $project_id)) { return $r; }

		$this->db->sql_transaction('begin');

		// The unique (project_id, file_name) index catches a rename that collided
		// with a concurrent request after the check above.
		if (!$this->project_repo->rename_file($file_id, $new_name, $new_ext))
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$this->log_to_changelog(
			$project_id,
			$this->user->lang('WSP_LOG_RENAME_ACTION', $old_name, $new_name)
		);
		$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_renamed', 'file', $old_name . ' -> ' . $new_name);

		$this->db->sql_transaction('commit');
		return $this->json_success();
	}

	public function move_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id  = (int) $this->request->variable('file_id', 0);
		$new_path = $this->sanitize_rel_path($this->raw_post('new_path'));

		if (!$file_id || $new_path === '')
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if (!$this->is_placeholder_path($new_path) && !$this->is_extension_allowed($new_path))
		{
			return $this->json_error('WSP_ERR_INVALID_EXT');
		}

		// ✅ rename/move = rename_move
		$access = $this->assert_file_access($file_id, 'rename_move');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		// Workspace implementation detail.
		$sql_info = 'SELECT project_id, file_name
					 FROM ' . $this->table_prefix . 'workspace_files
					 WHERE file_id = ' . (int) $file_id;

		$res_info = $this->db->sql_query($sql_info);
		$info     = $this->db->sql_fetchrow($res_info);
		$this->db->sql_freeresult($res_info);

		if (!$info)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$project_id = (int) $info['project_id'];
		$old_name   = (string) $info['file_name'];

		// conflito
		$sql_check = 'SELECT file_id
					  FROM ' . $this->table_prefix . 'workspace_files
					  WHERE project_id = ' . (int) $project_id . "
						AND file_name = '" . $this->db->sql_escape($new_path) . "'
						AND file_id <> " . (int) $file_id;

		$result_check = $this->db->sql_query($sql_check);
		$exists       = $this->db->sql_fetchrow($result_check);
		$this->db->sql_freeresult($result_check);

		if ($exists)
		{
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$new_ext = $this->detect_file_type($new_path);

		if ($r = $this->ensure_file_unlocked($file_id, $project_id)) { return $r; }

		$this->db->sql_transaction('begin');

		if (!$this->project_repo->rename_file($file_id, $new_path, $new_ext))
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$this->log_to_changelog(
			$project_id,
			$this->user->lang('WSP_LOG_FILE_MOVE_ACTION', $old_name, $new_path)
		);
		$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_moved', 'file', $old_name . ' -> ' . $new_path);

		$this->db->sql_transaction('commit');
		return $this->json_success();
	}

	public function delete_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		if (!$file_id)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		// ✅ delete = delete
		$access = $this->assert_file_access($file_id, 'delete');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		// Workspace implementation detail.
		$sql_info = 'SELECT project_id, file_name
					 FROM ' . $this->table_prefix . 'workspace_files
					 WHERE file_id = ' . (int) $file_id;

		$res_info = $this->db->sql_query($sql_info);
		$info     = $this->db->sql_fetchrow($res_info);
		$this->db->sql_freeresult($res_info);

		if (!$info)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$project_id = (int) $info['project_id'];
		$file_name  = (string) $info['file_name'];

		if ($r = $this->ensure_file_unlocked($file_id, $project_id)) { return $r; }

		$this->db->sql_transaction('begin');

		$this->log_to_changelog(
			$project_id,
			$this->user->lang('WSP_LOG_DELETE_ACTION', $file_name)
		);
		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_deleted', 'file', $file_name);
		}

		$this->db->sql_query(
			'DELETE FROM ' . $this->table_prefix . 'workspace_comments
			 WHERE file_id = ' . (int) $file_id
		);

		if (isset($this->project_repo) && method_exists($this->project_repo, 'delete_file_reviews'))
		{
			$this->project_repo->delete_file_reviews($file_id);
		}

		if (isset($this->project_repo) && method_exists($this->project_repo, 'delete_file_tasks'))
		{
			$this->project_repo->delete_file_tasks($file_id);
		}

		$this->db->sql_query(
			'DELETE FROM ' . $this->table_prefix . 'workspace_files
			 WHERE file_id = ' . (int) $file_id
		);

		$this->db->sql_transaction('commit');
		return $this->json_success();
	}



	/**
	 * Lists comments.
	 */
	public function list_comments()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$include_resolved = (int) $this->request->variable('include_resolved', 1);

		$access = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$comments = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_comments'))
			? (array) $this->project_repo->get_file_comments($file_id, (bool) $include_resolved, 100)
			: [];

		return $this->json_success(['comments' => $this->format_comments($comments)]);
	}

	/**
	 * Adds comment.
	 */
	public function add_comment()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$line_number = (int) $this->request->variable('line_number', 0);
		$message = trim($this->request->variable('message', '', true));

		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		if ($message === '' || !isset($this->project_repo) || !method_exists($this->project_repo, 'add_file_comment'))
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		$file_info = method_exists($this->project_repo, 'get_file_info') ? $this->project_repo->get_file_info($file_id) : false;
		if (!$file_info)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$comment_id = $this->project_repo->add_file_comment(
			(int) $file_info['project_id'],
			$file_id,
			(int) $this->user->data['user_id'],
			$line_number,
			$message
		);

		if (!$comment_id)
		{
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		if (method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], 'comment_added', 'file', (string) $file_info['file_name'], ['line' => max(0, $line_number)]);
		}
		$this->notify_project_members((int) $file_info['project_id'], 'comment_added', 'file', (string) $file_info['file_name'], 'WSP_NOTIFY_COMMENT_ADDED', ['line' => max(0, $line_number)]);

		$comments = $this->project_repo->get_file_comments($file_id, true, 100);
		return $this->json_success(['comment_id' => (int) $comment_id, 'comments' => $this->format_comments($comments)]);
	}

	/**
	 * Resolves comment.
	 */
	public function resolve_comment()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$comment_id = (int) $this->request->variable('comment_id', 0);
		$resolved = (int) $this->request->variable('resolved', 1);

		if (!isset($this->project_repo) || !method_exists($this->project_repo, 'get_file_comment'))
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		$comment = $this->project_repo->get_file_comment($comment_id);
		if (!$comment)
		{
			return $this->json_error('WSP_ERR_COMMENT_NOT_FOUND');
		}

		$access = $this->assert_file_access((int) $comment['file_id'], 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$ok = $this->project_repo->set_comment_resolved($comment_id, (bool) $resolved, (int) $this->user->data['user_id']);
		if (!$ok)
		{
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		$file_info = method_exists($this->project_repo, 'get_file_info') ? $this->project_repo->get_file_info((int) $comment['file_id']) : false;
		if ($file_info && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], ((bool) $resolved ? 'comment_resolved' : 'comment_reopened'), 'file', (string) $file_info['file_name'], ['line' => (int) $comment['line_number']]);
		}

		$comments = $this->project_repo->get_file_comments((int) $comment['file_id'], true, 100);
		return $this->json_success(['comments' => $this->format_comments($comments)]);
	}

	/**
	 * Deletes comment.
	 */
	public function delete_comment()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$comment_id = (int) $this->request->variable('comment_id', 0);

		if (!isset($this->project_repo) || !method_exists($this->project_repo, 'get_file_comment'))
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		$comment = $this->project_repo->get_file_comment($comment_id);
		if (!$comment)
		{
			return $this->json_error('WSP_ERR_COMMENT_NOT_FOUND');
		}

		$access = $this->assert_file_access((int) $comment['file_id'], 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$can_manage = $this->assert_project_access((int) $comment['project_id'], 'manage');
		$is_author = ((int) $this->user->data['user_id'] !== ANONYMOUS && (int) $comment['user_id'] === (int) $this->user->data['user_id']);
		if (!$is_author && empty($can_manage['ok']))
		{
			return $this->json_error('WSP_ERR_PERMISSION');
		}

		$file_id = (int) $comment['file_id'];
		$ok = $this->project_repo->delete_file_comment($comment_id);
		if (!$ok)
		{
			return $this->json_error('WSP_ERR_DELETE_FAILED');
		}

		$file_info = method_exists($this->project_repo, 'get_file_info') ? $this->project_repo->get_file_info($file_id) : false;
		if ($file_info && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], 'comment_deleted', 'file', (string) $file_info['file_name']);
		}

		$comments = $this->project_repo->get_file_comments($file_id, true, 100);
		return $this->json_success(['comments' => $this->format_comments($comments)]);
	}


	/**
	 * Gets file review.
	 */
	public function get_file_review()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$access = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$review = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_review'))
			? $this->project_repo->get_file_review($file_id)
			: false;

		return $this->json_success(['review' => $this->format_review($review)]);
	}

	/**
	 * Handles request file review.
	 */
	public function request_file_review()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$note = trim($this->request->variable('note', '', true));

		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$file_info = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_info')) ? $this->project_repo->get_file_info($file_id) : false;
		if (!$file_info || !method_exists($this->project_repo, 'set_file_review'))
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$ok = $this->project_repo->set_file_review((int) $file_info['project_id'], $file_id, 'pending', (int) $this->user->data['user_id'], $note);
		if (!$ok)
		{
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		if (method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], 'review_requested', 'file', (string) $file_info['file_name']);
		}
		$this->notify_project_members((int) $file_info['project_id'], 'review_requested', 'file', (string) $file_info['file_name'], 'WSP_NOTIFY_REVIEW_REQUESTED');

		$review = $this->project_repo->get_file_review($file_id);
		return $this->json_success(['review' => $this->format_review($review)]);
	}

	/**
	 * Sets file review.
	 */
	public function set_file_review()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$status = $this->request->variable('status', 'pending', true);
		$note = trim($this->request->variable('note', '', true));

		$access = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$file_info = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_info')) ? $this->project_repo->get_file_info($file_id) : false;
		if (!$file_info || !method_exists($this->project_repo, 'set_file_review'))
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$project_id = (int) $file_info['project_id'];
		if ($status !== 'pending')
		{
			$can_manage = $this->assert_project_access($project_id, 'manage');
			if (!$can_manage['ok'])
			{
				return $this->json_error_msg($can_manage['error']);
			}
		}
		else
		{
			$can_edit = $this->assert_project_access($project_id, 'edit');
			if (!$can_edit['ok'])
			{
				return $this->json_error_msg($can_edit['error']);
			}
		}

		$status = method_exists($this->project_repo, 'sanitize_review_status') ? $this->project_repo->sanitize_review_status($status) : 'pending';
		$ok = $this->project_repo->set_file_review($project_id, $file_id, $status, (int) $this->user->data['user_id'], $note);
		if (!$ok)
		{
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		if (method_exists($this->project_repo, 'log_activity'))
		{
			$action = ($status === 'approved') ? 'review_approved' : (($status === 'changes_requested') ? 'review_changes_requested' : 'review_requested');
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], $action, 'file', (string) $file_info['file_name']);
		}
		$notify_key = ($status === 'approved') ? 'WSP_NOTIFY_REVIEW_APPROVED' : (($status === 'changes_requested') ? 'WSP_NOTIFY_REVIEW_CHANGES_REQUESTED' : 'WSP_NOTIFY_REVIEW_REQUESTED');
		$this->notify_project_members($project_id, $status, 'file', (string) $file_info['file_name'], $notify_key, ['note' => $note]);

		$review = $this->project_repo->get_file_review($file_id);
		return $this->json_success(['review' => $this->format_review($review)]);
	}


	/**
	 * Lists file versions.
	 */
	public function list_file_versions()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$access = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$versions = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_versions'))
			? (array) $this->project_repo->get_file_versions($file_id, 30)
			: [];

		return $this->json_success(['versions' => $this->format_versions($versions)]);
	}

	/**
	 * Handles view file version.
	 */
	public function view_file_version()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$version_id = (int) $this->request->variable('version_id', 0);
		$version = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_version'))
			? $this->project_repo->get_file_version($version_id)
			: false;

		if (!$version)
		{
			return $this->json_error('WSP_ERR_VERSION_NOT_FOUND');
		}

		$access = $this->assert_file_access((int) $version['file_id'], 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		return $this->json_success([
			'version' => $this->format_version($version, true),
			'content' => (string) $this->normalize_content((string) $version['file_content']),
		]);
	}

	/**
	 * Handles restore file version.
	 */
	public function restore_file_version()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$version_id = (int) $this->request->variable('version_id', 0);
		$expected_rev = (int) $this->request->variable('file_rev', -1);
		if ($expected_rev < 0) { return $this->json_error('WSP_ERR_FILE_REV_REQUIRED'); }
		$version = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_version'))
			? $this->project_repo->get_file_version($version_id)
			: false;

		if (!$version)
		{
			return $this->json_error('WSP_ERR_VERSION_NOT_FOUND');
		}

		$file_id = (int) $version['file_id'];
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$sql_old = 'SELECT project_id, file_name, file_content, file_rev
					FROM ' . $this->table_prefix . 'workspace_files
					WHERE file_id = ' . (int) $file_id;
		$result_old = $this->db->sql_query($sql_old);
		$row_old = $this->db->sql_fetchrow($result_old);
		$this->db->sql_freeresult($result_old);

		if (!$row_old)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		if ((int) $row_old['file_rev'] !== $expected_rev)
		{
			return $this->json_error('WSP_ERR_FILE_CONFLICT');
		}
		if ($r = $this->ensure_file_unlocked($file_id, (int) $access['project_id'])) { return $r; }

		$current = $this->normalize_content((string) $row_old['file_content']);
		$restore_content = $this->normalize_content((string) $version['file_content']);

		$this->db->sql_transaction('begin');

		if ($current !== $restore_content && isset($this->project_repo) && method_exists($this->project_repo, 'create_file_version'))
		{
			$this->project_repo->create_file_version(
				(int) $row_old['project_id'],
				$file_id,
				(int) $this->user->data['user_id'],
				(string) $row_old['file_name'],
				$current,
				'restore',
				$this->user->lang('WSP_VERSION_BEFORE_RESTORE')
			);
		}

		$ok = ($current === $restore_content) ? true
			: $this->project_repo->update_file_content($file_id, $restore_content, null, $expected_rev);

		if (!$ok)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_FILE_CONFLICT');
		}

		if ($current !== $restore_content && strtolower(basename((string) $row_old['file_name'])) !== 'changelog.txt')
		{
			$this->log_to_changelog(
				(int) $row_old['project_id'],
				$this->user->lang('WSP_LOG_FILE_RESTORED', (string) $row_old['file_name']),
				$current,
				$restore_content
			);
		}

		$this->db->sql_transaction('commit');

		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $row_old['project_id'], (int) $this->user->data['user_id'], 'file_version_restored', 'file', (string) $row_old['file_name'], ['version_id' => $version_id]);
		}
		$this->notify_project_members((int) $row_old['project_id'], 'file_version_restored', 'file', (string) $row_old['file_name'], 'WSP_NOTIFY_VERSION_RESTORED', ['version_id' => $version_id]);

		$versions = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_versions'))
			? (array) $this->project_repo->get_file_versions($file_id, 30)
			: [];

		return $this->json_success([
			'content' => $restore_content,
			'file_rev' => $expected_rev + (($current !== $restore_content) ? 1 : 0),
			'versions' => $this->format_versions($versions),
		]);
	}


	public function get_file_lock()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }
		$file_id = (int) $this->request->variable('file_id', 0);
		$access = $this->assert_file_access($file_id, 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}
		$lock = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_lock'))
			? $this->project_repo->get_file_lock($file_id)
			: false;
		return $this->json_success(['lock' => $this->format_file_lock($lock)]);
	}

	public function lock_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }
		$file_id = (int) $this->request->variable('file_id', 0);
		$note = $this->request->variable('note', '', true);
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}
		$file_info = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_info')) ? $this->project_repo->get_file_info($file_id) : false;
		if (!$file_info || !isset($this->project_repo) || !method_exists($this->project_repo, 'set_file_lock'))
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}
		$ok = $this->project_repo->set_file_lock((int) $file_info['project_id'], $file_id, (int) $this->user->data['user_id'], $note);
		if (!$ok)
		{
			$lock = method_exists($this->project_repo, 'get_file_lock') ? $this->project_repo->get_file_lock($file_id) : false;
			return $this->json_error_msg($this->user->lang('WSP_ERR_FILE_LOCKED_BY', (string) ($lock['username'] ?? '')));
		}
		if (method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], 'file_locked', 'file', (string) $file_info['file_name']);
		}
		$lock = $this->project_repo->get_file_lock($file_id);
		return $this->json_success(['lock' => $this->format_file_lock($lock)]);
	}

	public function unlock_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }
		$file_id = (int) $this->request->variable('file_id', 0);
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}
		$file_info = (isset($this->project_repo) && method_exists($this->project_repo, 'get_file_info')) ? $this->project_repo->get_file_info($file_id) : false;
		if (!$file_info || !isset($this->project_repo) || !method_exists($this->project_repo, 'release_file_lock'))
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}
		$manage_access = $this->assert_project_access((int) $access['project_id'], 'manage');
		$can_force = !empty($manage_access['ok']);
		$lock = $this->project_repo->get_file_lock($file_id);
		if (!$lock)
		{
			return $this->json_success(['lock' => $this->format_file_lock(false)]);
		}
		if ((int) $lock['user_id'] !== (int) $this->user->data['user_id'] && !$can_force)
		{
			return $this->json_error('WSP_ERR_FILE_LOCKED_BY', [(string) ($lock['username'] ?? '')]);
		}
		$ok = $this->project_repo->release_file_lock($file_id, (int) $this->user->data['user_id'], $can_force);
		if (!$ok || $this->project_repo->get_file_lock($file_id))
		{
			return $this->json_error('WSP_ERROR_FILE_LOCK');
		}
		if (method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity((int) $file_info['project_id'], (int) $this->user->data['user_id'], 'file_unlocked', 'file', (string) $file_info['file_name']);
		}
		return $this->json_success(['lock' => $this->format_file_lock(false)]);
	}

	private function format_file_lock($row)
	{
		if (!$row)
		{
			return [
				'locked' => 0,
				'lock_id' => 0,
				'project_id' => 0,
				'file_id' => 0,
				'user_id' => 0,
				'username' => '',
				'user_colour' => '',
				'locked_time' => 0,
				'expires_time' => 0,
				'note' => '',
				'mine' => 0,
			];
		}
		return [
			'locked' => 1,
			'lock_id' => (int) ($row['lock_id'] ?? 0),
			'project_id' => (int) ($row['project_id'] ?? 0),
			'file_id' => (int) ($row['file_id'] ?? 0),
			'user_id' => (int) ($row['user_id'] ?? 0),
			'username' => (string) ($row['username'] ?? ''),
			'user_colour' => (string) ($row['user_colour'] ?? ''),
			'locked_time' => (int) ($row['locked_time'] ?? 0),
			'expires_time' => (int) ($row['expires_time'] ?? 0),
			'note' => (string) ($row['note'] ?? ''),
			'mine' => ((int) ($row['user_id'] ?? 0) === (int) $this->user->data['user_id']) ? 1 : 0,
		];
	}

	private function format_versions(array $versions)
	{
		$out = [];
		foreach ($versions as $row)
		{
			$out[] = $this->format_version($row, false);
		}
		return $out;
	}

	private function format_version($row, $include_content = false)
	{
		$out = [
			'version_id' => (int) ($row['version_id'] ?? 0),
			'project_id' => (int) ($row['project_id'] ?? 0),
			'file_id' => (int) ($row['file_id'] ?? 0),
			'user_id' => (int) ($row['user_id'] ?? 0),
			'username' => (string) ($row['username'] ?? ''),
			'user_colour' => (string) ($row['user_colour'] ?? ''),
			'file_name' => (string) ($row['file_name'] ?? ''),
			'content_hash' => (string) ($row['content_hash'] ?? ''),
			'source' => (string) ($row['source'] ?? 'save'),
			'change_note' => (string) ($row['change_note'] ?? ''),
			'created_time' => (int) ($row['created_time'] ?? 0),
		];
		if ($include_content)
		{
			$out['content'] = (string) ($row['file_content'] ?? '');
		}
		return $out;
	}

	private function format_review($row)
	{
		if (!$row)
		{
			return [
				'review_id' => 0,
				'status' => 'none',
				'review_note' => '',
				'requested_by' => 0,
				'requested_username' => '',
				'reviewed_by' => 0,
				'reviewed_username' => '',
				'created_time' => 0,
				'updated_time' => 0,
				'reviewed_time' => 0,
			];
		}

		return [
			'review_id' => (int) ($row['review_id'] ?? 0),
			'project_id' => (int) ($row['project_id'] ?? 0),
			'file_id' => (int) ($row['file_id'] ?? 0),
			'status' => (string) ($row['status'] ?? 'pending'),
			'review_note' => (string) ($row['review_note'] ?? ''),
			'requested_by' => (int) ($row['requested_by'] ?? 0),
			'requested_username' => (string) ($row['requested_username'] ?? ''),
			'requested_user_colour' => (string) ($row['requested_user_colour'] ?? ''),
			'reviewed_by' => (int) ($row['reviewed_by'] ?? 0),
			'reviewed_username' => (string) ($row['reviewed_username'] ?? ''),
			'reviewed_user_colour' => (string) ($row['reviewed_user_colour'] ?? ''),
			'created_time' => (int) ($row['created_time'] ?? 0),
			'updated_time' => (int) ($row['updated_time'] ?? 0),
			'reviewed_time' => (int) ($row['reviewed_time'] ?? 0),
		];
	}


	private function format_comments(array $comments)
	{
		$out = [];
		foreach ($comments as $row)
		{
			$out[] = [
				'comment_id'            => (int) ($row['comment_id'] ?? 0),
				'project_id'             => (int) ($row['project_id'] ?? 0),
				'file_id'                => (int) ($row['file_id'] ?? 0),
				'user_id'                => (int) ($row['user_id'] ?? 0),
				'username'               => (string) ($row['username'] ?? ''),
				'user_colour'            => (string) ($row['user_colour'] ?? ''),
				'line_number'            => (int) ($row['line_number'] ?? 0),
				'message'                => (string) ($row['message'] ?? ''),
				'resolved'               => !empty($row['resolved']) ? 1 : 0,
				'resolved_by'            => (int) ($row['resolved_by'] ?? 0),
				'resolved_username'      => (string) ($row['resolved_username'] ?? ''),
				'resolved_user_colour'   => (string) ($row['resolved_user_colour'] ?? ''),
				'created_time'           => (int) ($row['created_time'] ?? 0),
				'updated_time'           => (int) ($row['updated_time'] ?? 0),
			];
		}
		return $out;
	}

	private function log_to_changelog($project_id, $action, $old_content = null, $new_content = null)
	{
		if ($old_content !== null) { $old_content = $this->normalize_content($old_content); }
		if ($new_content !== null) { $new_content = $this->normalize_content($new_content); }

		$log_entry = $this->changelog_line($action);

		if ($old_content !== null && $new_content !== null && $old_content !== $new_content)
		{
			$lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';

			// The diff algorithm is quadratic: skip it for very large inputs.
			if (!file_exists($lib_path . 'Diff.php') || strlen($old_content) + strlen($new_content) > self::MAX_DIFF_INPUT_BYTES)
			{
				$log_entry .= "\n" . $this->user->lang('WSP_LOG_CONTENT_MODIFIED_FALLBACK');
			}
			else
			{
				require_once($lib_path . 'Diff.php');
				require_once($lib_path . 'Diff/Renderer/Abstract.php');
				require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

				$diff      = new \mundophpbb\workspace\lib\Diff(explode("\n", $old_content), explode("\n", $new_content));
				$renderer  = new \mundophpbb\workspace\lib\Diff_Renderer_Text_Unified();
				$diff_text = trim((string) $diff->render($renderer));

				if ($diff_text !== '')
				{
					if (strlen($diff_text) > self::MAX_DIFF_BYTES)
					{
						$diff_text = substr($diff_text, 0, self::MAX_DIFF_BYTES);
						$pos = strrpos($diff_text, "\n");
						$diff_text = (($pos !== false) ? substr($diff_text, 0, $pos) : $diff_text)
							. "\n" . $this->user->lang('WSP_LOG_DIFF_TRUNCATED');
					}

					$log_entry .= "\n" . $this->user->lang('WSP_LOG_DIFF_LABEL') . ":\n" . $diff_text;
				}
			}
		}

		$this->write_changelog($project_id, $log_entry);
	}
}