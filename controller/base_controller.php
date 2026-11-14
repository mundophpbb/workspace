<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\editor_settings;

use mundophpbb\workspace\repository\project_repository;
use mundophpbb\workspace\service\permission_service;

/**
 * Workspace component.
 */
abstract class base_controller
{
	/** Default maximum size (bytes) of a single stored file; the ACP setting max_file_kb overrides it. */
	const MAX_FILE_BYTES = 2097152;

	/** @var int|null Per-request cache of the configured limit. */
	private $max_file_bytes_cache = null;

	/** Maximum size (bytes) of one diff written to changelog.txt. */
	const MAX_DIFF_BYTES = 20480;

	/** Diffs are skipped when old + new content together exceed this many bytes (the algorithm is quadratic). */
	const MAX_DIFF_INPUT_BYTES = 400000;

	/** @var \phpbb\controller\helper */
	protected $helper;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var string */
	protected $table_prefix;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var string */
	protected $phpbb_root_path;

	/** @var project_repository */
	protected $project_repo;

	/** @var permission_service */
	protected $permission_service;

	protected $allowed_extensions = [
		'php','js','ts','css','scss','sass','less','html','htm','twig','svg',
		'json','xml','yml','yaml','sql','md','csv','txt','log',
		'c','cpp','h','hpp','cs','java','py','rb','pl','pm','lua','go','rs',
		'kt','swift','dart','r','scala',
		'sh','bash','bat','ps1','dockerfile','makefile',
		'ini','htaccess','conf'
	];

	public function __construct(
		\phpbb\controller\helper $helper,
		\phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db,
		$table_prefix,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		$phpbb_root_path,
		project_repository $project_repo,
		permission_service $permission_service
	) {
		$this->helper             = $helper;
		$this->template           = $template;
		$this->db                 = $db;
		$this->table_prefix       = (string) $table_prefix;
		$this->request            = $request;
		$this->user               = $user;
		$this->auth               = $auth;
		$this->phpbb_root_path    = (string) $phpbb_root_path;
		$this->project_repo       = $project_repo;
		$this->permission_service = $permission_service;

		if (method_exists($this->user, 'add_lang_ext'))
		{
			$this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
		}
	}

	protected function json_error($lang_key, array $params = [])
	{
		$msg = empty($params)
			? $this->user->lang($lang_key)
			: call_user_func_array([$this->user, 'lang'], array_merge([$lang_key], $params));

		return new \Symfony\Component\HttpFoundation\JsonResponse([
			'success' => false,
			'error'   => $msg,
		]);
	}

	/**
	 * Reads a POST value exactly as sent.
	 *
	 * request->variable($name, '', true) runs htmlspecialchars() on the value, which
	 * is right for text that will be rendered as HTML but wrong for file contents:
	 * "<?php" would be stored as "&lt;?php". File content, search and replace terms
	 * must therefore always come through this method and be stored raw.
	 */
	protected function raw_post($name, $default = '')
	{
		$value = $this->request->raw_variable($name, $default, \phpbb\request\request_interface::POST);

		if (!is_string($value))
		{
			return (string) $default;
		}

		$value = str_replace("\0", '', $value);

		if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8'))
		{
			$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		}

		return $value;
	}

	/**
	 * Returns a JSON error when $content is larger than the per-file limit.
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse|null
	 */
	protected function check_file_size($content)
	{
		if (strlen((string) $content) > $this->max_file_bytes())
		{
			return $this->json_error('WSP_ERR_FILE_TOO_LARGE', [(int) ($this->max_file_bytes() / 1024)]);
		}

		return null;
	}

	/**
	 * Per-file size limit in bytes, as configured in the ACP (Workspace IDE > Editor settings).
	 */
	protected function max_file_bytes()
	{
		if ($this->max_file_bytes_cache === null)
		{
			$settings = editor_settings::from_db($this->db, $this->table_prefix);
			$this->max_file_bytes_cache = (int) $settings['max_file_kb'] * 1024;
		}

		return $this->max_file_bytes_cache;
	}

	/**
	 * Prepends an entry to the project's changelog.txt (capped, retry-safe).
	 */
	protected function write_changelog($project_id, $entry)
	{
		$marker = $this->user->lang('WSP_LOG_TRUNCATED', (int) (project_repository::MAX_CHANGELOG_BYTES / 1024));

		return $this->project_repo->append_changelog((int) $project_id, (string) $entry, $marker);
	}

	/**
	 * Formats a "[date] message" changelog line.
	 */
	protected function changelog_line($message)
	{
		return '[' . date('d/m/Y H:i') . '] ' . (string) $message;
	}

	protected function json_success(array $payload = [])
	{
		return new \Symfony\Component\HttpFoundation\JsonResponse(array_merge([
			'success' => true,
		], $payload));
	}


	/**
	 * Validate the phpBB form token for POST requests.
	 *
	 * GET endpoints remain unaffected because they do not alter state.
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse|null
	 */
	protected function validate_post_form_key()
	{
		$method = strtoupper((string) $this->request->server('REQUEST_METHOD'));

		if ($method === 'POST')
		{
			if (!function_exists('check_form_key') || !check_form_key('mundophpbb_workspace'))
			{
				return $this->json_error('FORM_INVALID');
			}
		}

		return null;
	}

	protected function is_extension_allowed($filename)
	{
		$filename = (string) $filename;

		$base = basename(str_replace('\\', '/', $filename));
		$base_l = strtolower($base);

		if ($base_l === 'phpunit.xml.dist' || $base_l === '.placeholder' || $base_l === '.htaccess' || $base_l === 'changelog.txt'
			|| $base_l === 'dockerfile' || $base_l === 'makefile')
		{
			return true;
		}

		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		return in_array($extension, $this->allowed_extensions, true);
	}

	protected function sanitize_rel_path($path)
	{
		$path = (string) $path;
		// File names are stored raw, so characters that could break out of HTML
		// (and control characters) are dropped here instead of being escaped.
		$path = preg_replace('/[<>"\x00-\x1f\x7f]/', '', $path);
		$path = trim($path);

		if ($path === '')
		{
			return '';
		}

		$path = str_replace('\\', '/', $path);
		$path = preg_replace('#/+#', '/', $path);

		if (strpos($path, '/') === 0 || preg_match('#^[a-zA-Z]:/#', $path))
		{
			return '';
		}

		$parts = explode('/', $path);
		$safe  = [];

		foreach ($parts as $seg)
		{
			$seg = trim($seg);

			if ($seg === '' || $seg === '.')
			{
				continue;
			}

			if ($seg === '..')
			{
				return '';
			}

			$safe[] = $seg;
		}

		if (empty($safe))
		{
			return '';
		}

		return implode('/', $safe);
	}

	protected function is_placeholder_path($path)
	{
		$path = str_replace('\\', '/', (string) $path);
		$base = strtolower(basename($path));
		if ($base === '.placeholder')
		{
			return true;
		}

		$suffix = '/.placeholder';
		$path_l = strtolower($path);
		return (strlen($path_l) >= strlen($suffix) && substr($path_l, -strlen($suffix)) === $suffix);
	}

	/**
	 * Handles assert project access.
	 */
	protected function assert_project_access($project_id, $capability = 'view')
	{
		$project_id = (int) $project_id;
		$capability = (string) $capability;

		// ==========================================================
		// 0) Base ACL: access to Workspace (always)
		// ==========================================================
		$has_workspace_access = false;

		if ($this->permission_service && method_exists($this->permission_service, 'can_access_workspace'))
		{
			$has_workspace_access = (bool) $this->permission_service->can_access_workspace();
		}
		else
		{
			$uid = (int) ($this->user->data['user_id'] ?? 0);
			$has_workspace_access = ($uid > 0 && (bool) $this->auth->acl_get('u_workspace_access'));
		}

		if (!$has_workspace_access)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		// ==========================================================
		// Workspace implementation detail.
		// ==========================================================
		if ($capability === 'purge_cache')
		{
			if ($this->permission_service && method_exists($this->permission_service, 'can_purge_cache'))
			{
				return $this->permission_service->can_purge_cache()
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
			}

			$is_admin  = (bool) $this->auth->acl_get('u_workspace_manage_all');
			$can_purge = (bool) $this->auth->acl_get('u_workspace_purge_cache');

			return ($is_admin && $can_purge)
				? ['ok' => true, 'error' => '']
				: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		// ==========================================================
		// Workspace implementation detail.
		// ==========================================================
		if ($project_id <= 0)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')];
		}

		if ($this->project_repo && method_exists($this->project_repo, 'project_exists') && !$this->project_repo->project_exists($project_id))
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PROJECT_NOT_FOUND')];
		}

		// ==========================================================
		// 3) SSOT (permission_service)
		// ==========================================================
		if ($this->permission_service)
		{
			$ps = $this->permission_service;

			// Workspace implementation detail.
			if ($capability === 'lock')
			{
				return (method_exists($ps, 'can_lock_project') && $ps->can_lock_project($project_id))
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
			}

			$is_admin  = method_exists($ps, 'can_manage_all') ? (bool) $ps->can_manage_all() : false;
			$is_locked = method_exists($ps, 'is_project_locked') ? (bool) $ps->is_project_locked($project_id) : false;

			// Workspace implementation detail.
			$role = '';
			if (method_exists($ps, 'get_role'))
			{
				$role = (string) $ps->get_role($project_id);
			}

			$locked_message_ok = ($role !== '' && $is_locked && !$is_admin);

			switch ($capability)
			{
				case 'view':
					if (method_exists($ps, 'can_view_project') && $ps->can_view_project($project_id))
					{
						return ['ok' => true, 'error' => ''];
					}
					return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'download':
					if (method_exists($ps, 'can_download_project') && $ps->can_download_project($project_id))
					{
						return ['ok' => true, 'error' => ''];
					}
					return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'edit':
					return (method_exists($ps, 'can_edit_project') && $ps->can_edit_project($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'upload':
					return (method_exists($ps, 'can_upload_project') && $ps->can_upload_project($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'rename_move':
					return (method_exists($ps, 'can_rename_move_project') && $ps->can_rename_move_project($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'delete':
					return (method_exists($ps, 'can_delete_project_items') && $ps->can_delete_project_items($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'manage':
					return (method_exists($ps, 'can_manage_project') && $ps->can_manage_project($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				case 'replace':
					return (method_exists($ps, 'can_replace_project') && $ps->can_replace_project($project_id))
						? ['ok' => true, 'error' => '']
						: ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];

				default:
					// fallback seguro = view
					if (method_exists($ps, 'can_view_project') && $ps->can_view_project($project_id))
					{
						return ['ok' => true, 'error' => ''];
					}
					return ['ok' => false, 'error' => $locked_message_ok ? $this->user->lang('WSP_ERR_PROJECT_LOCKED') : $this->user->lang('WSP_ERR_PERMISSION')];
			}
		}

		// ==========================================================
		// Workspace implementation detail.
		// ==========================================================
		$uid = (int) ($this->user->data['user_id'] ?? 0);
		if ($uid <= 0)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		$is_admin = (bool) $this->auth->acl_get('u_workspace_manage_all');

		// lock gate
		if ($capability === 'lock')
		{
			$can_lock = (bool) $this->auth->acl_get('u_workspace_lock');
			return ($is_admin && $can_lock)
				? ['ok' => true, 'error' => '']
				: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		// lock state (repo ou SQL)
		$locked = false;
		if ($this->project_repo && method_exists($this->project_repo, 'is_project_locked'))
		{
			$locked = (bool) $this->project_repo->is_project_locked($project_id);
		}
		else
		{
			$sql = 'SELECT project_locked
					FROM ' . $this->table_prefix . 'workspace_projects
					WHERE project_id = ' . (int) $project_id;

			$result = $this->db->sql_query($sql);
			$row    = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			$locked = !empty($row['project_locked']);
		}

		// role / membership (repo ou SQL)
		$role = '';
		if ($this->project_repo && method_exists($this->project_repo, 'get_user_role'))
		{
			$role = (string) $this->project_repo->get_user_role($project_id, $uid);
		}
		else
		{
			$sql = 'SELECT user_role
					FROM ' . $this->table_prefix . 'workspace_projects_users
					WHERE project_id = ' . (int) $project_id . '
					  AND user_id = ' . (int) $uid;

			$result = $this->db->sql_query($sql);
			$r = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!empty($r['user_role']))
			{
				$role = (string) $r['user_role'];
			}
		}

		$owner_id = 0;
		if ($this->project_repo && method_exists($this->project_repo, 'get_project_owner_id'))
		{
			$owner_id = (int) $this->project_repo->get_project_owner_id($project_id);
		}
		else
		{
			$sql = 'SELECT user_id
					FROM ' . $this->table_prefix . 'workspace_projects
					WHERE project_id = ' . (int) $project_id;

			$result = $this->db->sql_query($sql);
			$r = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			$owner_id = (int) ($r['user_id'] ?? 0);
		}

		$is_owner = ($owner_id > 0 && $owner_id === $uid);

		// Workspace implementation detail.
		if ($is_admin)
		{
			$role = 'owner';
		}
		else if ($role === '' && $is_owner)
		{
			$role = 'owner';
		}

		// Workspace implementation detail.
		if ($locked && !$is_admin && in_array($capability, ['view','download','edit','upload','rename_move','delete','manage','replace'], true))
		{
			// Workspace implementation detail.
			if ($role === '')
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
			}
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PROJECT_LOCKED')];
		}

		// =========================
		// Workspace implementation detail.
		// Workspace implementation detail.
		// =========================
		if ($capability === 'view')
		{
			if ($role !== '')
			{
				return ['ok' => true, 'error' => ''];
			}

			$mode = 'private';
			if ($this->project_repo && method_exists($this->project_repo, 'get_collaboration_mode'))
			{
				$mode = (string) $this->project_repo->get_collaboration_mode($project_id);
			}

			return (in_array($mode, ['pm_request', 'public'], true) && (bool) $this->auth->acl_get('u_workspace_view'))
				? ['ok' => true, 'error' => '']
				: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		if ($capability === 'download')
		{
			if ($role === '' && !$is_admin)
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
			}

			return (bool) $this->auth->acl_get('u_workspace_download')
				? ['ok' => true, 'error' => '']
				: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		// Workspace implementation detail.
		// Workspace implementation detail.
		if ($role === '' && !$is_admin)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}

		switch ($capability)
		{
			case 'edit':
				if (!$is_admin && !in_array($role, ['owner','collab'], true))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return (bool) $this->auth->acl_get('u_workspace_edit')
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

			case 'upload':
				if (!$is_admin && !in_array($role, ['owner','collab'], true))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return (bool) $this->auth->acl_get('u_workspace_upload')
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

			case 'rename_move':
				if (!$is_admin && !in_array($role, ['owner','collab'], true))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return (bool) $this->auth->acl_get('u_workspace_rename_move')
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

			case 'delete':
				if (!$is_admin && !in_array($role, ['owner','collab'], true))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return (bool) $this->auth->acl_get('u_workspace_delete')
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

			case 'manage':
				if (!$is_admin && $role !== 'owner')
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return (bool) $this->auth->acl_get('u_workspace_manage_own')
					? ['ok' => true, 'error' => '']
					: ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];

			case 'replace':
				if (!$is_admin && !in_array($role, ['owner','collab'], true))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				if (!(bool) $this->auth->acl_get('u_workspace_replace') || !(bool) $this->auth->acl_get('u_workspace_edit'))
				{
					return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
				}
				return ['ok' => true, 'error' => ''];

			default:
				// Workspace implementation detail.
				return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
		}
	}

	/**
	 * Reject writes to a file locked by another collaborator.
	 * Project managers can deliberately override a collaborator lock.
	 * Call only after checking the caller's write permission.
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse|null
	 */
	protected function ensure_file_unlocked($file_id, $project_id)
	{
		$lock = $this->project_repo->get_file_lock((int) $file_id);
		if (!$lock || (int) $lock['user_id'] === (int) ($this->user->data['user_id'] ?? 0))
		{
			return null;
		}

		$manage_access = $this->assert_project_access((int) $project_id, 'manage');
		if (!empty($manage_access['ok']))
		{
			return null;
		}

		return $this->json_error('WSP_ERR_FILE_LOCKED_BY', [(string) ($lock['username'] ?? '')]);
	}

	protected function assert_file_access($file_id, $capability = 'view')
	{
		$file_id = (int) $file_id;
		$capability = (string) $capability;

		if (!$file_id)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA'), 'project_id' => null];
		}

		$sql = 'SELECT f.project_id
				FROM ' . $this->table_prefix . 'workspace_files f
				WHERE f.file_id = ' . (int) $file_id;

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_FILE_NOT_FOUND'), 'project_id' => null];
		}

		$project_id = (int) $row['project_id'];

		$proj_check = $this->assert_project_access($project_id, $capability);
		if (!$proj_check['ok'])
		{
			return ['ok' => false, 'error' => $proj_check['error'], 'project_id' => $project_id];
		}

		return ['ok' => true, 'error' => '', 'project_id' => $project_id];
	}

	/**
	 * Handles notify project members.
	 */
	protected function notify_project_members($project_id, $event, $object_type = '', $object_path = '', $message_key = '', array $metadata = [], $include_actor = false)
	{
		if (!isset($this->project_repo) || !method_exists($this->project_repo, 'notify_project_members'))
		{
			return 0;
		}

		return $this->project_repo->notify_project_members(
			(int) $project_id,
			(int) ($this->user->data['user_id'] ?? 0),
			(string) $event,
			(string) $object_type,
			(string) $object_path,
			(string) $message_key,
			$metadata,
			(bool) $include_actor
		);
	}

	/**
	 * Handles notify user.
	 */
	protected function notify_user($project_id, $user_id, $event, $object_type = '', $object_path = '', $message_key = '', array $metadata = [])
	{
		if (!isset($this->project_repo) || !method_exists($this->project_repo, 'notify_user'))
		{
			return 0;
		}

		return $this->project_repo->notify_user(
			(int) $project_id,
			(int) $user_id,
			(int) ($this->user->data['user_id'] ?? 0),
			(string) $event,
			(string) $object_type,
			(string) $object_path,
			(string) $message_key,
			$metadata
		);
	}

}