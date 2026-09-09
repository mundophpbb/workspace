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

		$filename = $this->request->variable('full_path', '', true);
		$file     = $this->request->file('file');

		$encoded_content = $this->request->variable('file_content', '', true);
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

		// Workspace implementation detail.
		$content = false;

		if ($is_encoded && !empty($encoded_content))
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

		// Keep the original uploaded bytes in storage so the release validator can
		// detect CRLF/CR line endings and non-UTF-8 content accurately.
		// Normalize only for comparisons/diffs/editor workflows.
		$raw_content = (string) $content;
		$content_for_compare = $this->normalize_content($raw_content);
		$ext     = $this->detect_file_type($filename);

		$sql = 'SELECT file_id, project_id, file_name, file_content
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . "
				  AND file_name = '" . $this->db->sql_escape($filename) . "'";

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$this->db->sql_transaction('begin');

		if ($row)
		{
			$old = $this->normalize_content($row['file_content']);

			if ($old !== $content_for_compare)
			{
				if (isset($this->project_repo) && method_exists($this->project_repo, 'create_file_version'))
				{
					$this->project_repo->create_file_version(
						(int) $row['project_id'],
						(int) $row['file_id'],
						(int) $this->user->data['user_id'],
						(string) $row['file_name'],
						$old,
						'upload',
						$this->user->lang('WSP_VERSION_BEFORE_SAVE')
					);
				}

				$this->log_to_changelog(
					$project_id,
					$this->user->lang('WSP_LOG_UPLOAD_UPDATE', $filename),
					$old,
					$content_for_compare
				);
				if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
				{
					$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_uploaded_update', 'file', $filename);
				}
				$this->notify_project_members($project_id, 'file_uploaded_update', 'file', $filename, 'WSP_NOTIFY_FILE_UPLOADED_UPDATE');
			}

			$sql_ary = [
				'file_content' => $raw_content,
				'file_time'    => time(),
				'file_type'    => $ext,
			];

			$sql_update = 'UPDATE ' . $this->table_prefix . 'workspace_files
						   SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
						   WHERE file_id = ' . (int) $row['file_id'];

			$this->db->sql_query($sql_update);
		}
		else
		{
			$this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_UPLOAD_NEW', $filename));
			if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
			{
				$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_uploaded_new', 'file', $filename);
			}
			$this->notify_project_members($project_id, 'file_uploaded_new', 'file', $filename, 'WSP_NOTIFY_FILE_UPLOADED_NEW');

			$file_ary = [
				'project_id'   => (int) $project_id,
				'file_name'    => $filename,
				'file_content' => $raw_content,
				'file_type'    => $ext,
				'file_time'    => time(),
			];

			$this->db->sql_query(
				'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
				$this->db->sql_build_array('INSERT', $file_ary)
			);
		}

		$this->db->sql_transaction('commit');
		return $this->json_success();
	}

	/**
	 * Adds file.
	 */
	public function add_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		$filename   = trim($this->request->variable('name', '', true));

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

		$sql = 'SELECT file_id
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . "
				  AND file_name = '" . $this->db->sql_escape($filename) . "'";

		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($exists)
		{
			return $this->json_error('WSP_ERR_FILE_EXISTS');
		}

		$ext = $this->detect_file_type($filename);

		// Workspace implementation detail.
		$initial_content = ($ext === 'php') ? "<?php\n\n" : "";

		$this->db->sql_transaction('begin');
		$this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_FILE_CREATED', $filename));
		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_created', 'file', $filename);
		}

		$file_ary = [
			'project_id'   => (int) $project_id,
			'file_name'    => $filename,
			'file_content' => $initial_content,
			'file_type'    => $ext,
			'file_time'    => time(),
		];

		$this->db->sql_query(
			'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
			$this->db->sql_build_array('INSERT', $file_ary)
		);

		$file_id = (int) $this->db->sql_nextid();
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

		$sql = 'SELECT file_content, file_name, file_type
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
			'content' => (string) html_entity_decode($content, ENT_QUOTES, 'UTF-8'),
			'name'    => (string) $row['file_name'],
			'type'    => strtolower((string) $row['file_type']),
		]);
	}

	public function save_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id = (int) $this->request->variable('file_id', 0);
		$content = $this->request->variable('content', '', true);

		// Workspace implementation detail.
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$content = $this->normalize_content($content);

		$sql_old = 'SELECT project_id, file_name, file_content
					FROM ' . $this->table_prefix . 'workspace_files
					WHERE file_id = ' . (int) $file_id;

		$result_old = $this->db->sql_query($sql_old);
		$row_old    = $this->db->sql_fetchrow($result_old);
		$this->db->sql_freeresult($result_old);

		if ($row_old && isset($this->project_repo) && method_exists($this->project_repo, 'get_file_lock'))
		{
			$lock = $this->project_repo->get_file_lock($file_id);
			$manage_access = $this->assert_project_access((int) $access['project_id'], 'manage');
			$can_force_lock = (bool) $this->auth->acl_get('u_workspace_manage_all') || !empty($manage_access['ok']);

			if ($lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'] && !$can_force_lock)
			{
				return $this->json_error_msg($this->user->lang('WSP_ERR_FILE_LOCKED_BY', (string) ($lock['username'] ?? '')));
			}
		}

		$this->db->sql_transaction('begin');

		if ($row_old)
		{
			$old = $this->normalize_content((string) $row_old['file_content']);

			if ($old !== $content)
			{
				$this->log_to_changelog(
					(int) $row_old['project_id'],
					$this->user->lang('WSP_LOG_FILE_CHANGED', (string) $row_old['file_name']),
					$old,
					$content
				);
				if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
				{
					$this->project_repo->log_activity((int) $row_old['project_id'], (int) $this->user->data['user_id'], 'file_saved', 'file', (string) $row_old['file_name']);
				}
				$this->notify_project_members((int) $row_old['project_id'], 'file_saved', 'file', (string) $row_old['file_name'], 'WSP_NOTIFY_FILE_SAVED');
			}
		}

		$sql_ary = [
			'file_content' => $content,
			'file_time'    => time(),
		];

		$sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE file_id = ' . (int) $file_id;

		$this->db->sql_query($sql);
		$this->db->sql_transaction('commit');

		return $this->json_success();
	}

	public function rename_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id  = (int) $this->request->variable('file_id', 0);
		$new_name = $this->sanitize_rel_path($this->request->variable('new_name', '', true));

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

		$this->db->sql_transaction('begin');

		$this->log_to_changelog(
			$project_id,
			$this->user->lang('WSP_LOG_RENAME_ACTION', $old_name, $new_name)
		);
		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_renamed', 'file', $old_name . ' -> ' . $new_name);
		}

		$new_ext = $this->detect_file_type($new_name);

		$sql_ary = [
			'file_name' => $new_name,
			'file_type' => $new_ext,
			'file_time' => time(),
		];

		$this->db->sql_query(
			'UPDATE ' . $this->table_prefix . 'workspace_files
			 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			 WHERE file_id = ' . (int) $file_id
		);

		$this->db->sql_transaction('commit');
		return $this->json_success();
	}

	public function move_file()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$file_id  = (int) $this->request->variable('file_id', 0);
		$new_path = $this->sanitize_rel_path($this->request->variable('new_path', '', true));

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

		$this->db->sql_transaction('begin');

		$this->log_to_changelog(
			$project_id,
			$this->user->lang('WSP_LOG_FILE_MOVE_ACTION', $old_name, $new_path)
		);
		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'file_moved', 'file', $old_name . ' -> ' . $new_path);
		}

		$new_ext = $this->detect_file_type($new_path);

		$sql_ary = [
			'file_name' => $new_path,
			'file_type' => $new_ext,
			'file_time' => time(),
		];

		$this->db->sql_query(
			'UPDATE ' . $this->table_prefix . 'workspace_files
			 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			 WHERE file_id = ' . (int) $file_id
		);

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

		$access = $this->assert_file_access($file_id, 'view');
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

		$access = $this->assert_file_access((int) $comment['file_id'], 'view');
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

		$access = $this->assert_file_access((int) $comment['file_id'], 'view');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$can_manage = $this->assert_project_access((int) $comment['project_id'], 'manage');
		$is_author = ((int) $comment['user_id'] === (int) $this->user->data['user_id']);
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
			'content' => (string) html_entity_decode($this->normalize_content((string) $version['file_content']), ENT_QUOTES, 'UTF-8'),
		]);
	}

	/**
	 * Handles restore file version.
	 */
	public function restore_file_version()
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

		$file_id = (int) $version['file_id'];
		$access = $this->assert_file_access($file_id, 'edit');
		if (!$access['ok'])
		{
			return $this->json_error_msg($access['error']);
		}

		$sql_old = 'SELECT project_id, file_name, file_content
					FROM ' . $this->table_prefix . 'workspace_files
					WHERE file_id = ' . (int) $file_id;
		$result_old = $this->db->sql_query($sql_old);
		$row_old = $this->db->sql_fetchrow($result_old);
		$this->db->sql_freeresult($result_old);

		if (!$row_old)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

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

		$sql_ary = [
			'file_content' => $restore_content,
			'file_time'    => time(),
		];
		$ok = $this->db->sql_query(
			'UPDATE ' . $this->table_prefix . 'workspace_files
			 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			 WHERE file_id = ' . (int) $file_id
		);

		if ($ok === false)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		if ($current !== $restore_content)
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
			'content' => (string) html_entity_decode($restore_content, ENT_QUOTES, 'UTF-8'),
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
		$access = $this->assert_file_access($file_id, 'view');
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
		$can_force = (bool) $this->auth->acl_get('u_workspace_manage_all') || !empty($manage_access['ok']);
		$this->project_repo->release_file_lock($file_id, (int) $this->user->data['user_id'], $can_force);
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
		$date   = date('d/m/Y H:i');
		$action = (string) $action;

		if ($old_content !== null) $old_content = $this->normalize_content($old_content);
		if ($new_content !== null) $new_content = $this->normalize_content($new_content);

		$log_entry = "[$date] $action\n";

		if ($old_content !== null && $new_content !== null && $old_content !== $new_content)
		{
			$lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';

			if (file_exists($lib_path . 'Diff.php'))
			{
				require_once($lib_path . 'Diff.php');
				require_once($lib_path . 'Diff/Renderer/Abstract.php');
				require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

				$diff      = new \mundophpbb\workspace\lib\Diff(explode("\n", $old_content), explode("\n", $new_content));
				$renderer  = new \mundophpbb\workspace\lib\Diff_Renderer_Text_Unified();
				$diff_text = $diff->render($renderer);

				if (!empty(trim((string) $diff_text)))
				{
					$log_entry .= $this->user->lang('WSP_LOG_DIFF_LABEL') . ":\n" . $diff_text . "\n";
				}
			}
			else
			{
				$log_entry .= $this->user->lang('WSP_LOG_CONTENT_MODIFIED_FALLBACK') . "\n";
			}
		}

		$changelog_name = 'changelog.txt';

		$sql = 'SELECT file_id, project_id, file_name, file_content
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . "
				  AND file_name = '" . $this->db->sql_escape($changelog_name) . "'";

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			$existing     = $this->normalize_content((string) $row['file_content']);
			$new_content  = $log_entry . str_repeat("-", 40) . "\n" . $existing;

			$sql_ary = [
				'file_content' => $new_content,
				'file_time'    => time(),
			];

			$this->db->sql_query(
				'UPDATE ' . $this->table_prefix . 'workspace_files
				 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				 WHERE file_id = ' . (int) $row['file_id']
			);
		}
		else
		{
			$file_ary = [
				'project_id'   => (int) $project_id,
				'file_name'    => $changelog_name,
				'file_content' => $log_entry . str_repeat("-", 40) . "\n",
				'file_type'    => 'txt',
				'file_time'    => time(),
			];

			$this->db->sql_query(
				'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
				$this->db->sql_build_array('INSERT', $file_ary)
			);
		}
	}
}