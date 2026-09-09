<?php
namespace mundophpbb\workspace\service;

use mundophpbb\workspace\repository\project_repository;

/**
 * Workspace component.
 */
class permission_service
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\user */
	protected $user;

	/** @var project_repository */
	protected $project_repo;

	public function __construct(\phpbb\auth\auth $auth, \phpbb\user $user, project_repository $project_repo)
	{
		$this->auth = $auth;
		$this->user = $user;
		$this->project_repo = $project_repo;
	}

	/**
	 * Handles uid.
	 */
	protected function uid($user_id = 0)
	{
		$uid = (int) ($user_id ?: ($this->user->data['user_id'] ?? 0));
		return $uid > 0 ? $uid : 0;
	}

	/**
	 * Handles acl.
	 */
	protected function acl($key)
	{
		return (bool) $this->auth->acl_get($key);
	}

	/**
	 * Checks whether it can access workspace.
	 */
	public function can_access_workspace()
	{
		$uid = $this->uid();
		if ($uid <= 0)
		{
			return false;
		}

		return $this->acl('u_workspace_access');
	}

	/**
	 * Checks whether it can create project.
	 */
	public function can_create_project()
	{
		return $this->can_access_workspace() && $this->acl('u_workspace_create');
	}

	/**
	 * Checks whether it can manage all.
	 */
	public function can_manage_all()
	{
		return $this->can_access_workspace() && $this->acl('u_workspace_manage_all');
	}

	/**
	 * Gets role.
	 */
	public function get_role($project_id, $user_id = 0)
	{
		if (!$this->can_access_workspace())
		{
			return '';
		}

		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if ($project_id <= 0 || $uid <= 0)
		{
			return '';
		}

		if (!$this->project_repo->project_exists($project_id))
		{
			return '';
		}

		// Workspace implementation detail.
		if ($this->can_manage_all())
		{
			return 'owner';
		}

		// Workspace implementation detail.
		$role = (string) $this->project_repo->get_user_role($project_id, $uid);
		if ($role !== '')
		{
			return $role; // repo sanitiza owner/collab/viewer
		}

		// Workspace implementation detail.
		if ((int) $this->project_repo->get_project_owner_id($project_id) === $uid)
		{
			return 'owner';
		}

		return '';
	}

	/**
	 * Checks whether it is project locked.
	 */
	public function is_project_locked($project_id)
	{
		if (!$this->can_access_workspace())
		{
			return false;
		}

		return (bool) $this->project_repo->is_project_locked((int) $project_id);
	}

	/**
	 * Checks whether it can view project.
	 */
	public function can_view_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
		{
			return false;
		}

		if (!$this->project_repo->project_exists($project_id))
		{
			return false;
		}

		// Workspace implementation detail.
		if ($this->can_manage_all())
		{
			return true;
		}

		// Workspace implementation detail.
		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		// Workspace implementation detail.
		$role = $this->get_role($project_id, $uid);
		if ($role !== '')
		{
			return true;
		}

		// Workspace implementation detail.
		// Workspace implementation detail.
		$mode = method_exists($this->project_repo, 'get_collaboration_mode')
			? (string) $this->project_repo->get_collaboration_mode($project_id)
			: 'private';

		return ($mode === 'pm_request' && $this->acl('u_workspace_view'));
	}

	/**
	 * Checks whether it can edit project.
	 */
	public function can_edit_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		// Workspace implementation detail.
		if ($this->can_manage_all())
		{
			return true;
		}

		// Precisa da ACL granular
		if (!$this->acl('u_workspace_edit'))
		{
			return false;
		}

		// Workspace implementation detail.
		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		// Workspace implementation detail.
		$role = $this->get_role($project_id, $uid);
		return in_array($role, ['owner', 'collab'], true);
	}

	/**
	 * Checks whether it can upload project.
	 */
	public function can_upload_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if (!$this->acl('u_workspace_upload'))
		{
			return false;
		}

		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		$role = $this->get_role($project_id, $uid);
		return in_array($role, ['owner', 'collab'], true);
	}

	/**
	 * Checks whether it can rename move project.
	 */
	public function can_rename_move_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if (!$this->acl('u_workspace_rename_move'))
		{
			return false;
		}

		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		$role = $this->get_role($project_id, $uid);
		return in_array($role, ['owner', 'collab'], true);
	}

	/**
	 * Checks whether it can delete project items.
	 */
	public function can_delete_project_items($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if (!$this->acl('u_workspace_delete'))
		{
			return false;
		}

		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		$role = $this->get_role($project_id, $uid);
		return in_array($role, ['owner', 'collab'], true);
	}

	/**
	 * Checks whether it can replace project.
	 */
	public function can_replace_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if (!$this->acl('u_workspace_replace'))
		{
			return false;
		}

		// Workspace implementation detail.
		return $this->can_edit_project($project_id, $uid);
	}

	/**
	 * Checks whether it can write project.
	 */
	public function can_write_project($project_id, $user_id = 0)
	{
		return $this->can_edit_project($project_id, $user_id)
			|| $this->can_manage_project($project_id, $user_id);
	}

	/**
	 * Checks whether it can download project.
	 */
	public function can_download_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
		{
			return false;
		}

		// Workspace implementation detail.
		if ($this->can_manage_all())
		{
			return true;
		}

		// Workspace implementation detail.
		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		// Workspace implementation detail.
		if ($this->get_role($project_id, $uid) === '')
		{
			return false;
		}

		// precisa da ACL download
		if (!$this->acl('u_workspace_download'))
		{
			return false;
		}

		return true;
	}

	/**
	 * Checks whether it can manage project.
	 */
	public function can_manage_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		if (!$this->acl('u_workspace_manage_own'))
		{
			return false;
		}

		return ($this->get_role($project_id, $uid) === 'owner');
	}

	/**
	 * Checks whether it can lock project.
	 */
	public function can_lock_project($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_access_workspace() || $project_id <= 0 || $uid <= 0)
		{
			return false;
		}

		// Workspace implementation detail.
		if (!$this->can_manage_all())
		{
			return false;
		}

		return $this->acl('u_workspace_lock');
	}

	/**
	 * Checks whether it can purge cache.
	 */
	public function can_purge_cache()
	{
		return $this->can_manage_all() && $this->acl('u_workspace_purge_cache');
	}

	/**
	 * Checks whether it can manage members.
	 */
	public function can_manage_members($project_id, $user_id = 0)
	{
		$project_id = (int) $project_id;
		$uid = $this->uid($user_id);

		if (!$this->can_view_project($project_id, $uid))
		{
			return false;
		}

		if ($this->can_manage_all())
		{
			return true;
		}

		if ($this->is_project_locked($project_id))
		{
			return false;
		}

		if (!$this->acl('u_workspace_manage_own'))
		{
			return false;
		}

		return ($this->get_role($project_id, $uid) === 'owner');
	}
}