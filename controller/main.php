<?php
namespace mundophpbb\workspace\controller;

/**
 * Workspace component.
 */
class main extends base_controller
{
	/**
	 * Normalizes route url.
	 */
	protected $load_files_when_no_active_project = false;

	/**
	 * Normalizes route url.
	 */
	protected function normalize_route_url($url)
	{
		$url = (string) $url;
		if ($url === '')
		{
			return '';
		}

		$url = str_replace('&amp;', '&', $url);

		// Workspace implementation detail.
		if (preg_match('#^https?://#i', $url))
		{
			return $url;
		}

		// remove "./"
		$url = preg_replace('#^\./#', '/', $url);

		// Workspace implementation detail.
		if ($url !== '' && $url[0] !== '/')
		{
			$url = '/' . $url;
		}

		// Workspace implementation detail.
		$url = preg_replace('#/app\.php/app\.php/#', '/app.php/', $url);

		return $url;
	}

	/**
	 * Ponto de entrada principal da IDE.
	 */
	public function handle()
	{
		// Workspace implementation detail.
		if (isset($this->permission_service) && method_exists($this->permission_service, 'can_access_workspace'))
		{
			if (!$this->permission_service->can_access_workspace())
			{
				trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
			}
		}
		else
		{
			if (!$this->auth->acl_get('u_workspace_access'))
			{
				trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
			}
		}

		// Workspace implementation detail.
		$this->user->add_lang_ext('mundophpbb/workspace', 'workspace');

		// Workspace implementation detail.
		$active_p_id = (int) $this->request->variable('p', 0);

		// Workspace implementation detail.
		$active_project_lock = [
			'project_locked' => false,
			'locked_by'      => 0,
			'locked_time'    => 0,
		];

		if ($active_p_id > 0)
		{
			// Workspace implementation detail.
			$access = $this->assert_project_access($active_p_id, 'view');
			$access_ok = !empty($access['ok']);

			if (!$access_ok)
			{
				// Workspace implementation detail.
				$active_p_id = 0;
			}
			else
			{
				if (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_lock_info'))
				{
					$active_project_lock = (array) $this->project_repo->get_project_lock_info($active_p_id);
				}

				// Download via proxy (?download=1)
				if ($this->request->variable('download', 0))
				{
					// Workspace implementation detail.
					$d = $this->assert_project_access($active_p_id, 'download');
					if (empty($d['ok']))
					{
						trigger_error(!empty($d['error']) ? $d['error'] : $this->user->lang('WSP_ERR_PERMISSION'));
					}

					return $this->download_project_proxy($active_p_id);
				}
			}
		}

		// Workspace implementation detail.
		$this->template->destroy_block_vars('projects');

		// 6) Assets + rotas + SSOT wspVars
		$this->assign_assets_and_routes($active_p_id, $active_project_lock);

		// Workspace implementation detail.
		$current_user_id = (int) ($this->user->data['user_id'] ?? 0);
		$projects = $this->fetch_user_projects($current_user_id);
		$can_manage_all_for_listing = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
			? (bool) $this->permission_service->can_manage_all()
			: (bool) $this->auth->acl_get('u_workspace_manage_all');

		$active_project_summary = [
			'ACTIVE_PROJECT_NAME'         => '',
			'ACTIVE_PROJECT_ROLE'         => '',
			'ACTIVE_PROJECT_MEMBER_COUNT' => 0,
			'ACTIVE_PROJECT_CAN_MANAGE'    => 0,
			'ACTIVE_PROJECT_COLLAB_MODE'    => 'private',
			'ACTIVE_PROJECT_IS_MEMBER'       => 0,
		];

		foreach ($projects as $row)
		{
			$project_id = (int) $row['project_id'];
			$is_active  = ($active_p_id > 0 && $project_id === (int) $active_p_id);

			// Workspace implementation detail.
			$can_open = false;
			$can_download = false;

			if (isset($this->permission_service))
			{
				if (method_exists($this->permission_service, 'can_view_project'))
				{
					$can_open = (bool) $this->permission_service->can_view_project($project_id);
				}
				else
				{
					$tmp = $this->assert_project_access($project_id, 'view');
					$can_open = !empty($tmp['ok']);
				}

				if (method_exists($this->permission_service, 'can_download_project'))
				{
					$can_download = (bool) $this->permission_service->can_download_project($project_id);
				}
				else
				{
					$tmp = $this->assert_project_access($project_id, 'download');
					$can_download = !empty($tmp['ok']);
				}
			}
			else
			{
				$tmp = $this->assert_project_access($project_id, 'view');
				$can_open = !empty($tmp['ok']);

				$tmp = $this->assert_project_access($project_id, 'download');
				$can_download = !empty($tmp['ok']);
			}

			$project_role = $this->resolve_project_role($row, $project_id, $current_user_id, $can_manage_all_for_listing);
			$member_count = max(1, (int) ($row['member_count'] ?? 1));

			if ($is_active)
			{
				$active_project_summary = [
					'ACTIVE_PROJECT_NAME'         => (string) $row['project_name'],
					'ACTIVE_PROJECT_ROLE'         => (string) $project_role,
					'ACTIVE_PROJECT_MEMBER_COUNT' => (int) $member_count,
					'ACTIVE_PROJECT_CAN_MANAGE'    => !empty($this->assert_project_access($project_id, 'manage')['ok']) ? 1 : 0,
					'ACTIVE_PROJECT_COLLAB_MODE'    => (string) ($row['collaboration_mode'] ?? 'private'),
					'ACTIVE_PROJECT_IS_MEMBER'       => (((int) ($row['user_id'] ?? 0) === $current_user_id) || ((isset($this->project_repo) && method_exists($this->project_repo, 'get_user_role')) ? ((string) $this->project_repo->get_user_role($project_id, $current_user_id) !== '') : false) || $can_manage_all_for_listing) ? 1 : 0,
				];
			}

			$u_download = $can_download
				? $this->normalize_route_url($this->helper->route('mundophpbb_workspace_download', ['project_id' => $project_id]))
				: '';

			$this->template->assign_block_vars('projects', [
				'ID'          => $project_id,
				'NAME'        => $row['project_name'],
				'IS_ACTIVE'   => $is_active,

				'IS_LOCKED'   => !empty($row['project_locked']),
				'LOCKED_BY'   => (int) ($row['locked_by'] ?? 0),
				'LOCKED_TIME' => (int) ($row['locked_time'] ?? 0),

				// ✅ novos flags
				'CAN_OPEN'     => $can_open ? 1 : 0,
				'CAN_DOWNLOAD' => $can_download ? 1 : 0,
				'ROLE'         => $project_role,
				'MEMBER_COUNT' => $member_count,
				'COLLAB_MODE'  => (string) ($row['collaboration_mode'] ?? 'private'),

				'U_DOWNLOAD'  => $u_download,
			]);

			// Workspace implementation detail.
			if ($this->should_load_files_for_project($active_p_id, $is_active))
			{
				// Workspace implementation detail.
				if (!$can_open)
				{
					continue;
				}

				$files = isset($this->project_repo)
					? (array) $this->project_repo->get_project_files($project_id)
					: (array) $this->fetch_project_files($project_id);

				foreach ($files as $f_row)
				{
					$this->template->assign_block_vars('projects.files', [
						'F_ID'   => (int) $f_row['file_id'],
						'F_NAME' => basename($f_row['file_name']),
						'F_PATH' => $f_row['file_name'],
						'F_TYPE' => strtolower($f_row['file_type']),
					]);
				}
			}
		}

		$this->template->assign_vars($active_project_summary);

		// Workspace implementation detail.
		$this->template->destroy_block_vars('project_members');
		$this->template->destroy_block_vars('project_activity');
		$this->template->destroy_block_vars('project_tasks');

		if ($active_p_id > 0 && isset($this->project_repo))
		{
			if (method_exists($this->project_repo, 'get_project_members'))
			{
				foreach ((array) $this->project_repo->get_project_members($active_p_id) as $member)
				{
					$role = (string) ($member['role'] ?? 'viewer');
					$this->template->assign_block_vars('project_members', [
						'USER_ID'     => (int) ($member['user_id'] ?? 0),
						'USERNAME'    => (string) ($member['username'] ?? ''),
						'USER_COLOUR' => (string) ($member['user_colour'] ?? ''),
						'ROLE'        => $role,
						'IS_OWNER'    => !empty($member['is_owner']) ? 1 : 0,
					]);
				}
			}

			if (method_exists($this->project_repo, 'get_recent_activity'))
			{
				foreach ((array) $this->project_repo->get_recent_activity($active_p_id, 10) as $activity)
				{
					$this->template->assign_block_vars('project_activity', [
						'ID'          => (int) ($activity['activity_id'] ?? 0),
						'ACTION'      => (string) ($activity['action'] ?? ''),
						'OBJECT_TYPE' => (string) ($activity['object_type'] ?? ''),
						'OBJECT_PATH' => (string) ($activity['object_path'] ?? ''),
						'USERNAME'    => (string) ($activity['username'] ?? ''),
						'USER_COLOUR' => (string) ($activity['user_colour'] ?? ''),
						'TIME'        => (int) ($activity['created_time'] ?? 0),
					]);
				}
			}

			if (method_exists($this->project_repo, 'get_project_tasks'))
			{
				foreach ((array) $this->project_repo->get_project_tasks($active_p_id, 30) as $task)
				{
					$this->template->assign_block_vars('project_tasks', [
						'TASK_ID' => (int) ($task['task_id'] ?? 0),
						'TITLE' => (string) ($task['title'] ?? ''),
						'DESCRIPTION' => (string) ($task['description'] ?? ''),
						'STATUS' => (string) ($task['status'] ?? 'todo'),
						'PRIORITY' => (string) ($task['priority'] ?? 'normal'),
						'ASSIGNED_TO' => (int) ($task['assigned_to'] ?? 0),
						'ASSIGNED_USERNAME' => (string) ($task['assigned_username'] ?? ''),
						'FILE_ID' => (int) ($task['file_id'] ?? 0),
						'FILE_NAME' => (string) ($task['file_name'] ?? ''),
					]);
				}
			}
		}

		// 8) Render
		$response = $this->helper->render('workspace_main.html', $this->user->lang('WSP_TITLE'));

		// 9) No-cache (IDE)
		$response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
		$response->headers->set('Pragma', 'no-cache');
		$response->headers->set('Expires', '0');

		return $response;
	}

	/**
	 * Downloads project proxy.
	 */
	protected function download_project_proxy($project_id)
	{
		$id = (int) $project_id;
		if ($id <= 0)
		{
			trigger_error($this->user->lang('WSP_ERR_INVALID_DATA'));
		}

		return redirect($this->normalize_route_url($this->helper->route('mundophpbb_workspace_download', ['project_id' => $id])));
	}

	/**
	 * Assigns assets and routes.
	 */
	protected function assign_assets_and_routes($active_p_id, array $active_project_lock = [])
	{
		$board_url = function_exists('generate_board_url') ? (generate_board_url() . '/') : '';
		$wsp_url_path = $board_url . 'ext/mundophpbb/workspace/styles/all';
		$ace_path     = $wsp_url_path . '/template/ace';

		// Cache-busting por mtime
		$js_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/js/wsp_core.js';
		$wsp_js_version = (file_exists($js_file)) ? (int) filemtime($js_file) : 0;

		$css_file = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/theme/workspace.css';
		$wsp_css_version = (file_exists($css_file)) ? (int) filemtime($css_file) : 0;

		// Workspace implementation detail.
		$wsp_lang_dictionary = [];
		foreach ((array) $this->user->lang as $key => $value)
		{
			if (strpos((string) $key, 'WSP_') === 0 && (is_scalar($value) || $value === null))
			{
				$wsp_lang_dictionary[$key] = $value;
			}
		}

		// Workspace implementation detail.
		$active_locked = !empty($active_project_lock['project_locked']);

		// Admin global
		$can_manage_all = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
			? (bool) $this->permission_service->can_manage_all()
			: (bool) $this->auth->acl_get('u_workspace_manage_all');

		// Grupo global de acesso ao Workspace (configurado pelo administrador)
		$wsp_access_group_id = 0;
		$wsp_access_group_name = '';
		$sql = 'SELECT config_name, config_value
				FROM ' . $this->table_prefix . "config
				WHERE config_name IN ('mundophpbb_workspace_access_group_id', 'mundophpbb_workspace_access_group_name')";
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['config_name'] === 'mundophpbb_workspace_access_group_id')
			{
				$wsp_access_group_id = (int) $row['config_value'];
			}
			else if ($row['config_name'] === 'mundophpbb_workspace_access_group_name')
			{
				$wsp_access_group_name = (string) $row['config_value'];
			}
		}
		$this->db->sql_freeresult($result);
		$wsp_access_group_configured = ($wsp_access_group_id > 0);

		// ==========================
		// Workspace implementation detail.
		// ==========================
		$active_can_view        = 0;
		$active_can_edit        = 0;
		$active_can_upload      = 0;
		$active_can_rename_move = 0;
		$active_can_delete      = 0;
		$active_can_manage      = 0;
		$active_can_replace     = 0;
		$active_can_lock        = 0;
		$active_can_download    = 0;

		$uid = (int) ($this->user->data['user_id'] ?? 0);

		if ((int) $active_p_id > 0)
		{
			if (isset($this->permission_service))
			{
				$active_can_view        = (int) $this->permission_service->can_view_project($active_p_id);
				$active_can_edit        = (int) $this->permission_service->can_edit_project($active_p_id);
				$active_can_upload      = (int) $this->permission_service->can_upload_project($active_p_id);
				$active_can_rename_move = (int) $this->permission_service->can_rename_move_project($active_p_id);
				$active_can_delete      = (int) $this->permission_service->can_delete_project_items($active_p_id);
				$active_can_manage      = (int) $this->permission_service->can_manage_project($active_p_id);
				$active_can_replace     = (int) $this->permission_service->can_replace_project($active_p_id);
				$active_can_lock        = (int) $this->permission_service->can_lock_project($active_p_id);
				$active_can_download    = (int) $this->permission_service->can_download_project($active_p_id);
			}
			else
			{
				// Workspace implementation detail.
				$is_owner = (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_owner_id'))
					? ((int) $this->project_repo->get_project_owner_id($active_p_id) === $uid)
					: false;

				if ($can_manage_all)
				{
					$active_can_view        = 1;
					$active_can_edit        = 1;
					$active_can_upload      = 1;
					$active_can_rename_move = 1;
					$active_can_delete      = 1;
					$active_can_manage      = 1;
					$active_can_replace     = 1;
					$active_can_lock        = (int) $this->auth->acl_get('u_workspace_lock');
					$active_can_download    = 1;
				}
				else if ($is_owner && !$active_locked)
				{
					$active_can_view        = (int) $this->auth->acl_get('u_workspace_view');
					$active_can_edit        = (int) $this->auth->acl_get('u_workspace_edit');
					$active_can_upload      = (int) $this->auth->acl_get('u_workspace_upload');
					$active_can_rename_move = (int) $this->auth->acl_get('u_workspace_rename_move');
					$active_can_delete      = (int) $this->auth->acl_get('u_workspace_delete');
					$active_can_manage      = (int) $this->auth->acl_get('u_workspace_manage_own');
					$active_can_replace     = ((int) $this->auth->acl_get('u_workspace_replace') && (int) $this->auth->acl_get('u_workspace_edit')) ? 1 : 0;
					$active_can_lock        = 0;
					$active_can_download    = (int) $this->auth->acl_get('u_workspace_download');
				}
			}
		}

		// ==========================
		// Workspace implementation detail.
		// ==========================
		$can_purge_cache = 0;
		if (isset($this->permission_service) && method_exists($this->permission_service, 'can_purge_cache'))
		{
			$can_purge_cache = (int) $this->permission_service->can_purge_cache();
		}
		else
		{
			$can_purge_cache = ((int) $this->auth->acl_get('u_workspace_manage_all') && (int) $this->auth->acl_get('u_workspace_purge_cache')) ? 1 : 0;
		}

		// Workspace implementation detail.
		$route = function ($name, array $params = []) {
			$u = (string) $this->helper->route((string) $name, $params);
			return $this->normalize_route_url($u);
		};

		// Native phpBB CSRF token used by every Workspace POST request.
		// add_form_key() assigns S_FORM_TOKEN to the template.
		if (function_exists('add_form_key'))
		{
			add_form_key('mundophpbb_workspace');
		}

		// Workspace implementation detail.
		$wsp_vars = [
			// Core
			'basePath'        => $ace_path,
			'allowedExt'      => implode(',', $this->allowed_extensions),
			'activeProjectId' => (int) $active_p_id,
			'lang'            => $wsp_lang_dictionary,

			// Lock/perms
			'canManageAll'     => $can_manage_all ? 1 : 0,
			'activeLocked'     => $active_locked ? 1 : 0,
			'activeLockedBy'   => (int) ($active_project_lock['locked_by'] ?? 0),
			'activeLockedTime' => (int) ($active_project_lock['locked_time'] ?? 0),

			// Workspace implementation detail.
			'activeCanView'        => (int) $active_can_view,
			'activeCanEdit'        => (int) $active_can_edit,
			'activeCanUpload'      => (int) $active_can_upload,
			'activeCanRenameMove'  => (int) $active_can_rename_move,
			'activeCanDelete'      => (int) $active_can_delete,
			'activeCanManage'      => (int) $active_can_manage,
			'activeCanReplace'     => (int) $active_can_replace,
			'activeCanLock'        => (int) $active_can_lock,
			'activeCanDownload'    => (int) $active_can_download,

			// global
			'canPurgeCache'        => (int) $can_purge_cache,
			'accessGroupConfigured' => $wsp_access_group_configured ? 1 : 0,
			'accessGroupName'       => $wsp_access_group_name,

			// URLs
			'mainUrl'          => $route('mundophpbb_workspace_main'),
			'loadUrl'          => $route('mundophpbb_workspace_load', []),
			'saveUrl'          => $route('mundophpbb_workspace_save', []),
			'addUrl'           => $route('mundophpbb_workspace_add_project', []),
			'addFileUrl'       => $route('mundophpbb_workspace_add_file', []),
			'uploadUrl'        => $route('mundophpbb_workspace_upload', []),
			'renameUrl'        => $route('mundophpbb_workspace_rename_file', []),
			'moveFileUrl'      => $route('mundophpbb_workspace_move_file', []),
			'renameProjectUrl' => $route('mundophpbb_workspace_rename_project', []),
			'renameFolderUrl'  => $route('mundophpbb_workspace_rename_folder', []),
			'deleteFileUrl'    => $route('mundophpbb_workspace_delete_file', []),
			'deleteUrl'        => $route('mundophpbb_workspace_delete_project', []),
			'deleteFolderUrl'  => $route('mundophpbb_workspace_delete_folder', []),
			'changelogUrl'     => $route('mundophpbb_workspace_changelog', []),
			'clearChangelogUrl'=> $route('mundophpbb_workspace_clear_changelog', []),
			'diffUrl'          => $route('mundophpbb_workspace_diff', []),
			'searchUrl'        => $route('mundophpbb_workspace_search', []),
			'replaceUrl'       => $route('mundophpbb_workspace_replace', []),
			'refreshCacheUrl'  => $route('mundophpbb_workspace_refresh_cache', []),
			'validateReleaseUrl' => $route('mundophpbb_workspace_validate_release', []),
			'applyValidationFixesUrl' => $route('mundophpbb_workspace_apply_validation_fixes', []),
			'epvStatusUrl' => $route('mundophpbb_workspace_epv_status', []),
			'epvInstallUrl' => $route('mundophpbb_workspace_epv_install', []),
			'downloadUrl'      => $route('mundophpbb_workspace_download', ['project_id' => 0]),
			'downloadSubmissionUrl' => $route('mundophpbb_workspace_download_submission', ['project_id' => 0]),
			'exportCollabUrl'  => $route('mundophpbb_workspace_export_collab', ['project_id' => 0]),
			'lockProjectUrl'   => $route('mundophpbb_workspace_lock_project', []),
			'unlockProjectUrl' => $route('mundophpbb_workspace_unlock_project', []),
			'membersUrl'       => $route('mundophpbb_workspace_members', []),
			'addMemberUrl'     => $route('mundophpbb_workspace_add_member', []),
			'updateMemberUrl'  => $route('mundophpbb_workspace_update_member', []),
			'removeMemberUrl'  => $route('mundophpbb_workspace_remove_member', []),
			'collaborationModeUrl' => $route('mundophpbb_workspace_collaboration_mode', []),
			'requestCollaborationUrl' => $route('mundophpbb_workspace_request_collaboration', []),
			'accessGroupConfigUrl' => $route('mundophpbb_workspace_access_group_config', []),
			'activeCollaborationMode' => 'private',
			'activityUrl'      => $route('mundophpbb_workspace_activity', []),
			'commentsUrl'      => $route('mundophpbb_workspace_comments', []),
			'addCommentUrl'    => $route('mundophpbb_workspace_add_comment', []),
			'resolveCommentUrl'=> $route('mundophpbb_workspace_resolve_comment', []),
			'deleteCommentUrl' => $route('mundophpbb_workspace_delete_comment', []),
			'fileReviewUrl'    => $route('mundophpbb_workspace_file_review', []),
			'requestReviewUrl' => $route('mundophpbb_workspace_request_file_review', []),
			'setReviewUrl'     => $route('mundophpbb_workspace_set_file_review', []),
			'fileVersionsUrl' => $route('mundophpbb_workspace_file_versions', []),
			'fileVersionViewUrl' => $route('mundophpbb_workspace_file_version_view', []),
			'fileVersionRestoreUrl' => $route('mundophpbb_workspace_file_version_restore', []),
			'fileLockUrl' => $route('mundophpbb_workspace_file_lock', []),
			'lockFileUrl' => $route('mundophpbb_workspace_lock_file', []),
			'unlockFileUrl' => $route('mundophpbb_workspace_unlock_file', []),
			'notificationsUrl' => $route('mundophpbb_workspace_notifications', []),
			'notificationsReadUrl' => $route('mundophpbb_workspace_notifications_read', []),
			'tasksUrl' => $route('mundophpbb_workspace_tasks', []),
			'addTaskUrl' => $route('mundophpbb_workspace_task_add', []),
			'updateTaskUrl' => $route('mundophpbb_workspace_task_update', []),
			'deleteTaskUrl' => $route('mundophpbb_workspace_task_delete', []),

			// Compat legado
			'WSP_CAN_MANAGE_ALL'     => $can_manage_all ? 1 : 0,
			'WSP_ACTIVE_LOCKED'      => $active_locked ? 1 : 0,
			'WSP_ACTIVE_LOCKED_BY'   => (int) ($active_project_lock['locked_by'] ?? 0),
			'WSP_ACTIVE_LOCKED_TIME' => (int) ($active_project_lock['locked_time'] ?? 0),
		];

		$wsp_unread_notifications = (isset($this->project_repo) && method_exists($this->project_repo, 'get_unread_notification_count'))
			? (int) $this->project_repo->get_unread_notification_count((int) ($this->user->data['user_id'] ?? 0))
			: 0;

		$wsp_vars['unreadNotifications'] = $wsp_unread_notifications;

		// JSON seguro pra embutir no <script>
		$json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		$this->template->assign_vars([
			// Assets
			'T_WSP_ASSETS'    => $wsp_url_path,
			'T_WSP_ACE_PATH'  => $ace_path,
			'WSP_JS_VERSION'  => $wsp_js_version,
			'WSP_CSS_VERSION' => $wsp_css_version,

			// Compat
			'ACTIVE_PROJECT_ID'       => (int) $active_p_id,
			'WSP_ALLOWED_EXT'         => implode(',', $this->allowed_extensions),
			'WSP_ROOT_LABEL'          => $this->user->lang('WSP_ROOT'),
			'WSP_LANG_JSON'           => json_encode($wsp_lang_dictionary, $json_flags),

			'WSP_ACTIVE_LOCKED'       => $active_locked ? 1 : 0,
			'WSP_ACTIVE_LOCKED_BY'    => (int) ($active_project_lock['locked_by'] ?? 0),
			'WSP_ACTIVE_LOCKED_TIME'  => (int) ($active_project_lock['locked_time'] ?? 0),
			'WSP_CAN_MANAGE_ALL'      => $can_manage_all ? 1 : 0,
			'WSP_CAN_PURGE_CACHE'  => (int) $can_purge_cache,
			'WSP_ACCESS_GROUP_CONFIGURED' => $wsp_access_group_configured ? 1 : 0,
			'WSP_ACCESS_GROUP_NAME' => $wsp_access_group_name,
			'WSP_CAN_LOCK'         => (int) $active_can_lock,
			'ACTIVE_PROJECT_NAME'     => '',
			'ACTIVE_PROJECT_ROLE'     => '',
			'ACTIVE_PROJECT_MEMBER_COUNT' => 0,
			'ACTIVE_PROJECT_CAN_MANAGE' => 0,
			'ACTIVE_PROJECT_COLLAB_MODE' => 'private',
			'ACTIVE_PROJECT_IS_MEMBER' => 0,
			'ACTIVE_CAN_MANAGE'         => (int) $active_can_manage,
			'ACTIVE_CAN_EDIT'           => (int) $active_can_edit,
			'WSP_UNREAD_NOTIFICATIONS'  => (int) $wsp_unread_notifications,

			// Workspace implementation detail.
			'WSP_VARS_JSON' => json_encode($wsp_vars, $json_flags),
		]);
	}

	/**
	 * Handles fetch user projects.
	 */
	protected function fetch_user_projects($user_id)
	{
		$user_id = (int) $user_id;

		$can_manage_all = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
			? (bool) $this->permission_service->can_manage_all()
			: (bool) $this->auth->acl_get('u_workspace_manage_all');

		$projects_table = $this->table_prefix . 'workspace_projects';
		$members_table = $this->table_prefix . 'workspace_projects_users';

		if ($can_manage_all)
		{
			$sql = sprintf(
				'SELECT p.project_id, p.project_name, p.project_locked, p.locked_by, p.locked_time, p.user_id, p.collaboration_mode,
				 (1 + (SELECT COUNT(*) FROM %s pu2 WHERE pu2.project_id = p.project_id AND pu2.user_id <> p.user_id)) AS member_count
				 FROM %s p ORDER BY p.project_name ASC',
				$members_table,
				$projects_table
			);
		}
		else
		{
			$can_view_public = (bool) $this->auth->acl_get('u_workspace_view');
			if ($can_view_public)
			{
				$sql = sprintf(
					"SELECT DISTINCT p.project_id, p.project_name, p.project_locked, p.locked_by, p.locked_time, p.user_id, p.collaboration_mode,
					 (1 + (SELECT COUNT(*) FROM %s pu2 WHERE pu2.project_id = p.project_id AND pu2.user_id <> p.user_id)) AS member_count
					 FROM %s p LEFT JOIN %s pu ON pu.project_id = p.project_id AND pu.user_id = %d
					 WHERE p.user_id = %d OR pu.user_id = %d OR (p.project_locked = 0 AND p.collaboration_mode = 'pm_request')
					 ORDER BY p.project_name ASC",
					$members_table,
					$projects_table,
					$members_table,
					(int) $user_id,
					(int) $user_id,
					(int) $user_id
				);
			}
			else
			{
				$sql = sprintf(
					'SELECT DISTINCT p.project_id, p.project_name, p.project_locked, p.locked_by, p.locked_time, p.user_id, p.collaboration_mode,
					 (1 + (SELECT COUNT(*) FROM %s pu2 WHERE pu2.project_id = p.project_id AND pu2.user_id <> p.user_id)) AS member_count
					 FROM %s p LEFT JOIN %s pu ON pu.project_id = p.project_id AND pu.user_id = %d
					 WHERE p.user_id = %d OR pu.user_id = %d ORDER BY p.project_name ASC',
					$members_table,
					$projects_table,
					$members_table,
					(int) $user_id,
					(int) $user_id,
					(int) $user_id
				);
			}
		}

		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Resolves project role.
	 */
	protected function resolve_project_role(array $row, $project_id, $user_id, $can_manage_all = false)
	{
		if ($can_manage_all)
		{
			return 'owner';
		}

		$project_id = (int) $project_id;
		$user_id = (int) $user_id;

		if ($project_id <= 0 || $user_id <= 0)
		{
			return 'viewer';
		}

		if ((int) ($row['user_id'] ?? 0) === $user_id)
		{
			return 'owner';
		}

		$role = '';
		if (isset($this->permission_service) && method_exists($this->permission_service, 'get_role'))
		{
			$role = (string) $this->permission_service->get_role($project_id, $user_id);
		}
		else if (isset($this->project_repo) && method_exists($this->project_repo, 'get_user_role'))
		{
			$role = (string) $this->project_repo->get_user_role($project_id, $user_id);
		}

		return $role !== '' ? $role : 'viewer';
	}

	/**
	 * Handles fetch project files.
	 */
	protected function fetch_project_files($project_id)
	{
		$sql = 'SELECT file_id, file_name, file_type
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . '
				ORDER BY file_name ASC';

		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Handles should load files for project.
	 */
	protected function should_load_files_for_project($active_p_id, $is_active)
	{
		if ((int) $active_p_id > 0)
		{
			return (bool) $is_active;
		}

		return (bool) $this->load_files_when_no_active_project;
	}
}