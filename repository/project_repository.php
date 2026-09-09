<?php
namespace mundophpbb\workspace\repository;

/**
 * Workspace component.
 */
class project_repository
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var string */
	protected $table_prefix;

	public function __construct(\phpbb\db\driver\driver_interface $db, $table_prefix)
	{
		$this->db = $db;
		$this->table_prefix = (string) $table_prefix;
	}

	/**
	 * Handles t.
	 */
	protected function t($name)
	{
		return $this->table_prefix . (string) $name;
	}

	/**
	 * Handles project exists.
	 */
	public function project_exists($project_id)
	{
		$sql = 'SELECT project_id
				FROM ' . $this->t('workspace_projects') . '
				WHERE project_id = ' . (int) $project_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (bool) $row;
	}

	/**
	 * Gets project owner id.
	 */
	public function get_project_owner_id($project_id)
	{
		$sql = 'SELECT user_id
				FROM ' . $this->t('workspace_projects') . '
				WHERE project_id = ' . (int) $project_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (int) $row['user_id'] : 0;
	}

	/**
	 * Gets project files.
	 */
	public function get_project_files($project_id)
	{
		$sql = 'SELECT file_id, file_name, file_type, file_time
				FROM ' . $this->t('workspace_files') . '
				WHERE project_id = ' . (int) $project_id . '
				ORDER BY file_name ASC';

		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows ?: [];
	}

	/**
	 * Gets user role.
	 */
	public function get_user_role($project_id, $user_id)
	{
		$sql = 'SELECT role
				FROM ' . $this->t('workspace_projects_users') . '
				WHERE project_id = ' . (int) $project_id . '
				  AND user_id = ' . (int) $user_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (string) $row['role'] : '';
	}

	/**
	 * Adds member.
	 */
	public function add_member($project_id, $user_id, $role, $added_by = 0)
	{
		$sql_ary = [
			'project_id' => (int) $project_id,
			'user_id'    => (int) $user_id,
			'role'       => $this->sanitize_role($role),
			'added_by'   => (int) $added_by,
			'added_time' => time(),
		];

		$sql = 'INSERT INTO ' . $this->t('workspace_projects_users') . ' ' .
			   $this->db->sql_build_array('INSERT', $sql_ary);

		$ok = $this->db->sql_query($sql);

		return ($ok !== false);
	}

	/**
	 * Handles upsert member.
	 */
	public function upsert_member($project_id, $user_id, $role, $added_by = 0)
	{
		$project_id = (int) $project_id;
		$user_id    = (int) $user_id;
		$role       = $this->sanitize_role($role);

		$existing_role = $this->get_user_role($project_id, $user_id);

		if ($existing_role !== '')
		{
			$sql_ary = [
				'role'       => $role,
				'added_by'   => (int) $added_by,
				'added_time' => time(),
			];

			$sql = 'UPDATE ' . $this->t('workspace_projects_users') . '
					SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
					WHERE project_id = ' . (int) $project_id . '
					  AND user_id = ' . (int) $user_id;

			$ok = $this->db->sql_query($sql);
			return ($ok !== false);
		}

		return $this->add_member($project_id, $user_id, $role, $added_by);
	}

	/**
	 * Checks whether it is project locked.
	 */
	public function is_project_locked($project_id)
	{
		$sql = 'SELECT project_locked
				FROM ' . $this->t('workspace_projects') . '
				WHERE project_id = ' . (int) $project_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (bool) $row['project_locked'] : false;
	}

	/**
	 * Gets project lock info.
	 */
	public function get_project_lock_info($project_id)
	{
		$sql = 'SELECT project_locked, locked_by, locked_time
				FROM ' . $this->t('workspace_projects') . '
				WHERE project_id = ' . (int) $project_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return [
				'project_locked' => false,
				'locked_by'      => 0,
				'locked_time'    => 0,
			];
		}

		return [
			'project_locked' => (bool) $row['project_locked'],
			'locked_by'      => (int) $row['locked_by'],
			'locked_time'    => (int) $row['locked_time'],
		];
	}

	/**
	 * Sets project lock.
	 */
	public function set_project_lock($project_id, $locked, $locked_by = 0)
	{
		$project_id = (int) $project_id;
		$locked     = (bool) $locked;
		$locked_by  = (int) $locked_by;

		$sql_ary = [
			'project_locked' => $locked ? 1 : 0,
			'locked_by'      => $locked ? $locked_by : 0,
			'locked_time'    => $locked ? time() : 0,
		];

		$sql = 'UPDATE ' . $this->t('workspace_projects') . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE project_id = ' . (int) $project_id;

		$ok = $this->db->sql_query($sql);

		return ($ok !== false);
	}


	/**
	 * Finds user by name.
	 */
	public function find_user_by_name($username)
	{
		$username = trim((string) $username);
		if ($username === '')
		{
			return false;
		}

		$clean = function_exists('utf8_clean_string') ? utf8_clean_string($username) : strtolower($username);

		$sql = 'SELECT user_id, username, user_colour
				FROM ' . USERS_TABLE . "
				WHERE username_clean = '" . $this->db->sql_escape($clean) . "'
				   OR username = '" . $this->db->sql_escape($username) . "'";

		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Gets project members.
	 */
	public function get_project_members($project_id)
	{
		$project_id = (int) $project_id;
		if ($project_id <= 0)
		{
			return [];
		}

		$members = [];

		$sql = 'SELECT p.user_id, u.username, u.user_colour
				FROM ' . $this->t('workspace_projects') . ' p
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.user_id
				WHERE p.project_id = ' . (int) $project_id;
		$result = $this->db->sql_query($sql);
		$owner = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($owner && (int) $owner['user_id'] > 0)
		{
			$members[(int) $owner['user_id']] = [
				'user_id'     => (int) $owner['user_id'],
				'username'    => (string) ($owner['username'] ?: 'User #' . (int) $owner['user_id']),
				'user_colour' => (string) ($owner['user_colour'] ?? ''),
				'role'        => 'owner',
				'added_by'    => 0,
				'added_time'  => 0,
				'is_owner'    => 1,
			];
		}

		$sql = 'SELECT pu.user_id, pu.role, pu.added_by, pu.added_time, u.username, u.user_colour
				FROM ' . $this->t('workspace_projects_users') . ' pu
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = pu.user_id
				WHERE pu.project_id = ' . (int) $project_id . '
				ORDER BY u.username_clean ASC';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$uid = (int) $row['user_id'];
			if ($uid <= 0)
			{
				continue;
			}

			if (isset($members[$uid]) && $members[$uid]['role'] === 'owner')
			{
				continue;
			}

			$members[$uid] = [
				'user_id'     => $uid,
				'username'    => (string) ($row['username'] ?: 'User #' . $uid),
				'user_colour' => (string) ($row['user_colour'] ?? ''),
				'role'        => $this->sanitize_role($row['role']),
				'added_by'    => (int) $row['added_by'],
				'added_time'  => (int) $row['added_time'],
				'is_owner'    => 0,
			];
		}
		$this->db->sql_freeresult($result);

		return array_values($members);
	}

	/**
	 * Removes member.
	 */
	public function remove_member($project_id, $user_id)
	{
		$project_id = (int) $project_id;
		$user_id = (int) $user_id;
		if ($project_id <= 0 || $user_id <= 0 || $this->get_project_owner_id($project_id) === $user_id)
		{
			return false;
		}

		$sql = 'DELETE FROM ' . $this->t('workspace_projects_users') . '
				WHERE project_id = ' . (int) $project_id . '
				  AND user_id = ' . (int) $user_id;
		$ok = $this->db->sql_query($sql);

		return ($ok !== false);
	}

	/**
	 * Handles log activity.
	 */
	public function log_activity($project_id, $user_id, $action, $object_type = '', $object_path = '', array $metadata = [])
	{
		$project_id = (int) $project_id;
		$user_id = (int) $user_id;
		$action = trim((string) $action);

		if ($project_id <= 0 || $action === '')
		{
			return false;
		}

		$sql_ary = [
			'project_id'  => $project_id,
			'user_id'     => $user_id,
			'action'      => $action,
			'object_type' => substr((string) $object_type, 0, 32),
			'object_path' => substr((string) $object_path, 0, 255),
			'created_time'=> time(),
			'metadata'    => !empty($metadata) ? json_encode($metadata) : '',
		];

		$ok = $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_activity') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		);

		return ($ok !== false);
	}

	/**
	 * Gets recent activity.
	 */
	public function get_recent_activity($project_id, $limit = 12)
	{
		$project_id = (int) $project_id;
		$limit = max(1, min(50, (int) $limit));
		if ($project_id <= 0)
		{
			return [];
		}

		$sql = 'SELECT a.*, u.username, u.user_colour
				FROM ' . $this->t('workspace_activity') . ' a
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id
				WHERE a.project_id = ' . (int) $project_id . '
				ORDER BY a.created_time DESC, a.activity_id DESC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows ?: [];
	}



	/**
	 * Gets file info.
	 */
	public function get_file_info($file_id)
	{
		$sql = 'SELECT file_id, project_id, file_name
				FROM ' . $this->t('workspace_files') . '
				WHERE file_id = ' . (int) $file_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Gets file comments.
	 */
	public function get_file_comments($file_id, $include_resolved = true, $limit = 100)
	{
		$file_id = (int) $file_id;
		$limit = max(1, min(200, (int) $limit));

		if ($file_id <= 0)
		{
			return [];
		}

		$where = 'c.file_id = ' . $file_id;
		if (!$include_resolved)
		{
			$where .= ' AND c.resolved = 0';
		}

		$sql = 'SELECT c.*, u.username, u.user_colour, r.username AS resolved_username, r.user_colour AS resolved_user_colour
				FROM ' . $this->t('workspace_comments') . ' c
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = c.user_id
				LEFT JOIN ' . USERS_TABLE . ' r ON r.user_id = c.resolved_by
				WHERE ' . $where . '
				ORDER BY c.resolved ASC, c.line_number ASC, c.created_time ASC, c.comment_id ASC';

		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows ?: [];
	}

	/**
	 * Adds file comment.
	 */
	public function add_file_comment($project_id, $file_id, $user_id, $line_number, $message)
	{
		$project_id = (int) $project_id;
		$file_id = (int) $file_id;
		$user_id = (int) $user_id;
		$line_number = max(0, (int) $line_number);
		$message = trim((string) $message);

		if ($project_id <= 0 || $file_id <= 0 || $user_id <= 0 || $message === '')
		{
			return 0;
		}

		if (utf8_strlen($message) > 4000)
		{
			$message = utf8_substr($message, 0, 4000);
		}

		$sql_ary = [
			'project_id'    => $project_id,
			'file_id'       => $file_id,
			'user_id'       => $user_id,
			'line_number'   => $line_number,
			'message'       => $message,
			'resolved'      => 0,
			'resolved_by'   => 0,
			'resolved_time' => 0,
			'created_time'  => time(),
			'updated_time'  => time(),
		];

		$ok = $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_comments') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		);

		return ($ok !== false) ? (int) $this->db->sql_nextid() : 0;
	}

	/**
	 * Gets file comment.
	 */
	public function get_file_comment($comment_id)
	{
		$sql = 'SELECT *
				FROM ' . $this->t('workspace_comments') . '
				WHERE comment_id = ' . (int) $comment_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Sets comment resolved.
	 */
	public function set_comment_resolved($comment_id, $resolved, $user_id)
	{
		$comment_id = (int) $comment_id;
		$resolved = (bool) $resolved;
		$user_id = (int) $user_id;

		if ($comment_id <= 0)
		{
			return false;
		}

		$sql_ary = [
			'resolved'      => $resolved ? 1 : 0,
			'resolved_by'   => $resolved ? $user_id : 0,
			'resolved_time' => $resolved ? time() : 0,
			'updated_time'  => time(),
		];

		$ok = $this->db->sql_query(
			'UPDATE ' . $this->t('workspace_comments') . '
			 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			 WHERE comment_id = ' . (int) $comment_id
		);

		return ($ok !== false);
	}

	/**
	 * Deletes file comment.
	 */
	public function delete_file_comment($comment_id)
	{
		$ok = $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_comments') . '
			 WHERE comment_id = ' . (int) $comment_id
		);

		return ($ok !== false);
	}


	/**
	 * Gets file review.
	 */
	public function get_file_review($file_id)
	{
		$file_id = (int) $file_id;
		if ($file_id <= 0)
		{
			return false;
		}

		$sql = 'SELECT r.*, req.username AS requested_username, req.user_colour AS requested_user_colour,
					   rev.username AS reviewed_username, rev.user_colour AS reviewed_user_colour
				FROM ' . $this->t('workspace_file_reviews') . ' r
				LEFT JOIN ' . USERS_TABLE . ' req ON req.user_id = r.requested_by
				LEFT JOIN ' . USERS_TABLE . ' rev ON rev.user_id = r.reviewed_by
				WHERE r.file_id = ' . (int) $file_id . '
				ORDER BY r.updated_time DESC, r.review_id DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Sets file review.
	 */
	public function set_file_review($project_id, $file_id, $status, $user_id, $note = '')
	{
		$project_id = (int) $project_id;
		$file_id = (int) $file_id;
		$user_id = (int) $user_id;
		$status = $this->sanitize_review_status($status);
		$note = trim((string) $note);

		if ($project_id <= 0 || $file_id <= 0 || $user_id <= 0)
		{
			return false;
		}

		if (utf8_strlen($note) > 4000)
		{
			$note = utf8_substr($note, 0, 4000);
		}

		$current = $this->get_file_review($file_id);
		$now = time();

		$sql_ary = [
			'project_id'   => $project_id,
			'file_id'      => $file_id,
			'status'       => $status,
			'review_note'  => $note,
			'updated_time' => $now,
		];

		if ($status === 'pending')
		{
			$sql_ary['requested_by'] = $user_id;
			$sql_ary['reviewed_by'] = 0;
			$sql_ary['reviewed_time'] = 0;
		}
		else
		{
			$sql_ary['reviewed_by'] = $user_id;
			$sql_ary['reviewed_time'] = $now;
		}

		if ($current)
		{
			return $this->db->sql_query(
				'UPDATE ' . $this->t('workspace_file_reviews') . '
				 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				 WHERE review_id = ' . (int) $current['review_id']
			) !== false;
		}

		$sql_ary['created_time'] = $now;
		if (!isset($sql_ary['requested_by']))
		{
			$sql_ary['requested_by'] = $user_id;
		}
		if (!isset($sql_ary['reviewed_by']))
		{
			$sql_ary['reviewed_by'] = 0;
		}
		if (!isset($sql_ary['reviewed_time']))
		{
			$sql_ary['reviewed_time'] = 0;
		}

		return $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_file_reviews') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		) !== false;
	}

	/**
	 * Deletes file reviews.
	 */
	public function delete_file_reviews($file_id)
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_reviews') . '
			 WHERE file_id = ' . (int) $file_id
		) !== false;
	}


	/**
	 * Creates file version.
	 */
	public function create_file_version($project_id, $file_id, $user_id, $file_name, $file_content, $source = 'save', $change_note = '')
	{
		$project_id = (int) $project_id;
		$file_id = (int) $file_id;
		$user_id = (int) $user_id;
		$file_name = substr((string) $file_name, 0, 255);
		$file_content = (string) $file_content;
		$source = strtolower(trim((string) $source));
		$change_note = trim((string) $change_note);

		if ($project_id <= 0 || $file_id <= 0 || $file_name === '')
		{
			return 0;
		}

		$allowed_sources = ['save', 'upload', 'restore', 'manual'];
		if (!in_array($source, $allowed_sources, true))
		{
			$source = 'save';
		}

		if (utf8_strlen($change_note) > 4000)
		{
			$change_note = utf8_substr($change_note, 0, 4000);
		}

		$hash = hash('sha256', $file_content);
		$last = $this->get_last_file_version($file_id);
		if ($last && (string) ($last['content_hash'] ?? '') === $hash)
		{
			return (int) $last['version_id'];
		}

		$sql_ary = [
			'project_id'   => $project_id,
			'file_id'      => $file_id,
			'user_id'      => $user_id,
			'file_name'    => $file_name,
			'file_content' => $file_content,
			'content_hash' => $hash,
			'source'       => $source,
			'change_note'  => $change_note,
			'created_time' => time(),
		];

		$ok = $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_file_versions') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		);

		return ($ok !== false) ? (int) $this->db->sql_nextid() : 0;
	}

	/**
	 * Gets last file version.
	 */
	public function get_last_file_version($file_id)
	{
		$sql = 'SELECT *
				FROM ' . $this->t('workspace_file_versions') . '
				WHERE file_id = ' . (int) $file_id . '
				ORDER BY created_time DESC, version_id DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Gets file versions.
	 */
	public function get_file_versions($file_id, $limit = 25)
	{
		$file_id = (int) $file_id;
		$limit = max(1, min(100, (int) $limit));
		if ($file_id <= 0)
		{
			return [];
		}

		$sql = 'SELECT v.version_id, v.project_id, v.file_id, v.user_id, v.file_name, v.content_hash, v.source, v.change_note, v.created_time,
					   u.username, u.user_colour
				FROM ' . $this->t('workspace_file_versions') . ' v
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = v.user_id
				WHERE v.file_id = ' . (int) $file_id . '
				ORDER BY v.created_time DESC, v.version_id DESC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows ?: [];
	}

	/**
	 * Gets file version.
	 */
	public function get_file_version($version_id)
	{
		$sql = 'SELECT v.*, u.username, u.user_colour
				FROM ' . $this->t('workspace_file_versions') . ' v
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = v.user_id
				WHERE v.version_id = ' . (int) $version_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: false;
	}

	/**
	 * Deletes file versions.
	 */
	public function delete_file_versions($file_id)
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_versions') . '
			 WHERE file_id = ' . (int) $file_id
		) !== false;
	}


	/**
	 * Handles cleanup expired file locks.
	 */
	public function cleanup_expired_file_locks()
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_locks') . '
			 WHERE expires_time > 0 AND expires_time < ' . time()
		) !== false;
	}

	/**
	 * Gets file lock.
	 */
	public function get_file_lock($file_id)
	{
		$this->cleanup_expired_file_locks();
		$sql = 'SELECT l.*, u.username, u.user_colour
				FROM ' . $this->t('workspace_file_locks') . ' l
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = l.user_id
				WHERE l.file_id = ' . (int) $file_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: false;
	}

	/**
	 * Sets file lock.
	 */
	public function set_file_lock($project_id, $file_id, $user_id, $note = '', $ttl = 7200)
	{
		$project_id = (int) $project_id;
		$file_id = (int) $file_id;
		$user_id = (int) $user_id;
		$note = trim((string) $note);
		$ttl = max(300, min(86400, (int) $ttl));
		if ($project_id <= 0 || $file_id <= 0 || $user_id <= 0)
		{
			return false;
		}

		$current = $this->get_file_lock($file_id);
		if ($current && (int) $current['user_id'] !== $user_id)
		{
			return false;
		}

		$sql_ary = [
			'project_id'   => $project_id,
			'file_id'      => $file_id,
			'user_id'      => $user_id,
			'locked_time'  => time(),
			'expires_time' => time() + $ttl,
			'note'         => utf8_substr($note, 0, 1000),
		];

		if ($current)
		{
			return $this->db->sql_query(
				'UPDATE ' . $this->t('workspace_file_locks') . '
				 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				 WHERE file_id = ' . (int) $file_id . ' AND user_id = ' . (int) $user_id
			) !== false;
		}

		return $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_file_locks') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		) !== false;
	}

	/**
	 * Handles release file lock.
	 */
	public function release_file_lock($file_id, $user_id = 0, $force = false)
	{
		$where = 'file_id = ' . (int) $file_id;
		if (!$force)
		{
			$where .= ' AND user_id = ' . (int) $user_id;
		}
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_locks') . ' WHERE ' . $where
		) !== false;
	}

	/**
	 * Deletes file locks.
	 */
	public function delete_file_locks($file_id)
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_locks') . '
			 WHERE file_id = ' . (int) $file_id
		) !== false;
	}

	/**
	 * Deletes project file locks.
	 */
	public function delete_project_file_locks($project_id)
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_file_locks') . '
			 WHERE project_id = ' . (int) $project_id
		) !== false;
	}


	/**
	 * Creates notification.
	 */
	public function create_notification($project_id, $user_id, $actor_id, $event, $object_type = '', $object_path = '', $message_key = '', array $metadata = [])
	{
		$project_id = (int) $project_id;
		$user_id = (int) $user_id;
		$actor_id = (int) $actor_id;
		$event = substr(trim((string) $event), 0, 64);

		if ($project_id <= 0 || $user_id <= 0 || $event === '')
		{
			return 0;
		}

		$sql_ary = [
			'project_id'   => $project_id,
			'user_id'      => $user_id,
			'actor_id'     => $actor_id,
			'event'        => $event,
			'object_type'  => substr((string) $object_type, 0, 32),
			'object_path'  => substr((string) $object_path, 0, 255),
			'message_key'  => substr((string) $message_key, 0, 128),
			'metadata'     => !empty($metadata) ? json_encode($metadata) : '',
			'is_read'      => 0,
			'created_time' => time(),
			'read_time'    => 0,
		];

		$ok = $this->db->sql_query(
			'INSERT INTO ' . $this->t('workspace_notifications') . ' ' .
			$this->db->sql_build_array('INSERT', $sql_ary)
		);

		return ($ok !== false) ? (int) $this->db->sql_nextid() : 0;
	}

	/**
	 * Handles notify project members.
	 */
	public function notify_project_members($project_id, $actor_id, $event, $object_type = '', $object_path = '', $message_key = '', array $metadata = [], $include_actor = false)
	{
		$project_id = (int) $project_id;
		$actor_id = (int) $actor_id;
		$created = 0;

		if ($project_id <= 0)
		{
			return 0;
		}

		foreach ($this->get_project_members($project_id) as $member)
		{
			$uid = (int) ($member['user_id'] ?? 0);
			if ($uid <= 0)
			{
				continue;
			}
			if (!$include_actor && $uid === $actor_id)
			{
				continue;
			}
			if ($this->create_notification($project_id, $uid, $actor_id, $event, $object_type, $object_path, $message_key, $metadata))
			{
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Handles notify user.
	 */
	public function notify_user($project_id, $user_id, $actor_id, $event, $object_type = '', $object_path = '', $message_key = '', array $metadata = [])
	{
		return $this->create_notification($project_id, $user_id, $actor_id, $event, $object_type, $object_path, $message_key, $metadata);
	}

	/**
	 * Gets unread notification count.
	 */
	public function get_unread_notification_count($user_id)
	{
		$sql = 'SELECT COUNT(notification_id) AS total
				FROM ' . $this->t('workspace_notifications') . '
				WHERE user_id = ' . (int) $user_id . '
				  AND is_read = 0';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (int) $row['total'] : 0;
	}

	/**
	 * Gets user notifications.
	 */
	public function get_user_notifications($user_id, $limit = 20)
	{
		$user_id = (int) $user_id;
		$limit = max(1, min(100, (int) $limit));
		if ($user_id <= 0)
		{
			return [];
		}

		$sql = 'SELECT n.*, p.project_name, a.username AS actor_username, a.user_colour AS actor_user_colour
				FROM ' . $this->t('workspace_notifications') . ' n
				LEFT JOIN ' . $this->t('workspace_projects') . ' p ON p.project_id = n.project_id
				LEFT JOIN ' . USERS_TABLE . ' a ON a.user_id = n.actor_id
				WHERE n.user_id = ' . (int) $user_id . '
				ORDER BY n.is_read ASC, n.created_time DESC, n.notification_id DESC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $rows ?: [];
	}

	/**
	 * Handles mark notifications read.
	 */
	public function mark_notifications_read($user_id, $notification_id = 0)
	{
		$user_id = (int) $user_id;
		$notification_id = (int) $notification_id;
		if ($user_id <= 0)
		{
			return false;
		}

		$where = 'user_id = ' . $user_id . ' AND is_read = 0';
		if ($notification_id > 0)
		{
			$where .= ' AND notification_id = ' . $notification_id;
		}

		$sql_ary = [
			'is_read' => 1,
			'read_time' => time(),
		];

		return $this->db->sql_query(
			'UPDATE ' . $this->t('workspace_notifications') . '
			 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
			 WHERE ' . $where
		) !== false;
	}

	/**
	 * Deletes project notifications.
	 */
	public function delete_project_notifications($project_id)
	{
		return $this->db->sql_query(
			'DELETE FROM ' . $this->t('workspace_notifications') . '
			 WHERE project_id = ' . (int) $project_id
		) !== false;
	}


	/**
	 * Sanitizes review status.
	 */
	public function sanitize_review_status($status)
	{
		$status = strtolower(trim((string) $status));
		$allowed = ['pending', 'approved', 'changes_requested'];
		return in_array($status, $allowed, true) ? $status : 'pending';
	}


	/**
	 * Sanitizes role.
	 */
	public function sanitize_role($role)
	{
		$role = strtolower(trim((string) $role));
		$allowed = ['owner', 'collab', 'viewer'];

		return in_array($role, $allowed, true) ? $role : 'viewer';
	}

	/**
	 * Sanitizes task status.
	 */
	public function sanitize_task_status($status)
	{
		$status = (string) $status;
		$allowed = ['todo', 'doing', 'done'];
		return in_array($status, $allowed, true) ? $status : 'todo';
	}

	/**
	 * Sanitizes task priority.
	 */
	public function sanitize_task_priority($priority)
	{
		$priority = (string) $priority;
		$allowed = ['low', 'normal', 'high'];
		return in_array($priority, $allowed, true) ? $priority : 'normal';
	}

	/**
	 * Gets project tasks.
	 */
	public function get_project_tasks($project_id, $limit = 100)
	{
		$limit = max(1, min(200, (int) $limit));
		$sql = 'SELECT t.*, au.username AS assigned_username, au.user_colour AS assigned_user_colour,
					   cu.username AS created_username, cu.user_colour AS created_user_colour,
					   f.file_name
				FROM ' . $this->t('workspace_tasks') . ' t
				LEFT JOIN ' . USERS_TABLE . ' au ON au.user_id = t.assigned_to
				LEFT JOIN ' . USERS_TABLE . ' cu ON cu.user_id = t.created_by
				LEFT JOIN ' . $this->t('workspace_files') . ' f ON f.file_id = t.file_id
				WHERE t.project_id = ' . (int) $project_id . "
				ORDER BY CASE t.status WHEN 'doing' THEN 0 WHEN 'todo' THEN 1 ELSE 2 END ASC, CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC, t.updated_time DESC";

		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $this->format_task_row($row);
		}
		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Gets task.
	 */
	public function get_task($task_id)
	{
		$sql = 'SELECT t.*, au.username AS assigned_username, au.user_colour AS assigned_user_colour,
					   cu.username AS created_username, cu.user_colour AS created_user_colour,
					   f.file_name
				FROM ' . $this->t('workspace_tasks') . ' t
				LEFT JOIN ' . USERS_TABLE . ' au ON au.user_id = t.assigned_to
				LEFT JOIN ' . USERS_TABLE . ' cu ON cu.user_id = t.created_by
				LEFT JOIN ' . $this->t('workspace_files') . ' f ON f.file_id = t.file_id
				WHERE t.task_id = ' . (int) $task_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? $this->format_task_row($row) : null;
	}

	/**
	 * Adds task.
	 */
	public function add_task($project_id, $file_id, $title, $description, $priority, $assigned_to, $created_by)
	{
		$now = time();
		$sql_ary = [
			'project_id'   => (int) $project_id,
			'file_id'      => max(0, (int) $file_id),
			'title'        => (string) $title,
			'description'  => (string) $description,
			'status'       => 'todo',
			'priority'     => $this->sanitize_task_priority($priority),
			'assigned_to'  => max(0, (int) $assigned_to),
			'created_by'   => (int) $created_by,
			'created_time' => $now,
			'updated_by'   => (int) $created_by,
			'updated_time' => $now,
		];

		$ok = $this->db->sql_query('INSERT INTO ' . $this->t('workspace_tasks') . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
		return ($ok !== false) ? (int) $this->db->sql_nextid() : 0;
	}

	/**
	 * Updates task.
	 */
	public function update_task($task_id, array $fields, $updated_by)
	{
		$sql_ary = [];

		if (array_key_exists('title', $fields))
		{
			$sql_ary['title'] = (string) $fields['title'];
		}
		if (array_key_exists('description', $fields))
		{
			$sql_ary['description'] = (string) $fields['description'];
		}
		if (array_key_exists('status', $fields))
		{
			$sql_ary['status'] = $this->sanitize_task_status($fields['status']);
		}
		if (array_key_exists('priority', $fields))
		{
			$sql_ary['priority'] = $this->sanitize_task_priority($fields['priority']);
		}
		if (array_key_exists('assigned_to', $fields))
		{
			$sql_ary['assigned_to'] = max(0, (int) $fields['assigned_to']);
		}
		if (array_key_exists('file_id', $fields))
		{
			$sql_ary['file_id'] = max(0, (int) $fields['file_id']);
		}

		if (!$sql_ary)
		{
			return true;
		}

		$sql_ary['updated_by'] = (int) $updated_by;
		$sql_ary['updated_time'] = time();

		$ok = $this->db->sql_query('UPDATE ' . $this->t('workspace_tasks') . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE task_id = ' . (int) $task_id);

		return ($ok !== false);
	}

	/**
	 * Deletes task.
	 */
	public function delete_task($task_id)
	{
		$ok = $this->db->sql_query('DELETE FROM ' . $this->t('workspace_tasks') . ' WHERE task_id = ' . (int) $task_id);
		return ($ok !== false);
	}

	/**
	 * Deletes project tasks.
	 */
	public function delete_project_tasks($project_id)
	{
		return $this->db->sql_query('DELETE FROM ' . $this->t('workspace_tasks') . ' WHERE project_id = ' . (int) $project_id) !== false;
	}

	public function delete_file_tasks($file_id)
	{
		return $this->db->sql_query('DELETE FROM ' . $this->t('workspace_tasks') . ' WHERE file_id = ' . (int) $file_id) !== false;
	}

	/**
	 * Handles format task row.
	 */
	protected function format_task_row(array $row)
	{
		return [
			'task_id' => (int) ($row['task_id'] ?? 0),
			'project_id' => (int) ($row['project_id'] ?? 0),
			'file_id' => (int) ($row['file_id'] ?? 0),
			'file_name' => (string) ($row['file_name'] ?? ''),
			'title' => (string) ($row['title'] ?? ''),
			'description' => (string) ($row['description'] ?? ''),
			'status' => $this->sanitize_task_status($row['status'] ?? 'todo'),
			'priority' => $this->sanitize_task_priority($row['priority'] ?? 'normal'),
			'assigned_to' => (int) ($row['assigned_to'] ?? 0),
			'assigned_username' => (string) ($row['assigned_username'] ?? ''),
			'assigned_user_colour' => (string) ($row['assigned_user_colour'] ?? ''),
			'created_by' => (int) ($row['created_by'] ?? 0),
			'created_username' => (string) ($row['created_username'] ?? ''),
			'created_user_colour' => (string) ($row['created_user_colour'] ?? ''),
			'created_time' => (int) ($row['created_time'] ?? 0),
			'updated_by' => (int) ($row['updated_by'] ?? 0),
			'updated_time' => (int) ($row['updated_time'] ?? 0),
		];
	}


	/**
	 * Sanitizes collaboration mode.
	 */
	public function sanitize_collaboration_mode($mode)
	{
		$mode = (string) $mode;
		$allowed = ['private', 'pm_request'];
		return in_array($mode, $allowed, true) ? $mode : 'private';
	}

	/**
	 * Gets collaboration mode.
	 */
	public function get_collaboration_mode($project_id)
	{
		$sql = 'SELECT collaboration_mode
				FROM ' . $this->t('workspace_projects') . '
				WHERE project_id = ' . (int) $project_id;

		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? $this->sanitize_collaboration_mode($row['collaboration_mode'] ?? 'private') : 'private';
	}

	/**
	 * Sets collaboration mode.
	 */
	public function set_collaboration_mode($project_id, $mode)
	{
		$sql_ary = [
			'collaboration_mode' => $this->sanitize_collaboration_mode($mode),
		];

		$ok = $this->db->sql_query('UPDATE ' . $this->t('workspace_projects') . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE project_id = ' . (int) $project_id);

		return ($ok !== false);
	}

}