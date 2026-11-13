<?php
namespace mundophpbb\workspace\repository;

/** Per-project public GitHub settings. No credentials stored. */
class github_link_repository
{
	private $db;
	private $prefix;

	public function __construct(\phpbb\db\driver\driver_interface $db, $prefix)
	{
		$this->db = $db;
		$this->prefix = (string) $prefix;
	}

	public function get($projectId)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->prefix .
			'workspace_github_links WHERE project_id = ' . (int) $projectId);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	public function link($projectId, $owner, $repo, $branch, $sha, $userId)
	{
		$this->db->sql_transaction('begin');
		$row = $this->get($projectId);
		$fields = [
			'repo_owner' => $owner, 'repo_name' => $repo, 'repo_ref' => $branch,
			'head_sha' => $sha, 'checked_at' => time(), 'imported_sha' => '',
			'imported_at' => 0, 'linked_by' => (int) $userId, 'linked_at' => time(),
		];
		if ($row && strcasecmp($row['repo_owner'], $owner) === 0 &&
			strcasecmp($row['repo_name'], $repo) === 0 && $row['repo_ref'] === $branch)
		{
			$fields['imported_sha'] = (string) $row['imported_sha'];
			$fields['imported_at'] = (int) $row['imported_at'];
		}
		$table = $this->prefix . 'workspace_github_links';
		if ($row)
		{
			$query = sprintf('UPDATE %s SET %s WHERE project_id = %d',
				$table,
				$this->db->sql_build_array('UPDATE', $fields),
				(int) $projectId);
		}
		else
		{
			$query = sprintf('INSERT INTO %s %s',
				$table,
				$this->db->sql_build_array('INSERT', ['project_id' => (int) $projectId] + $fields));
		}
		$ok = $this->db->sql_query($query);
		$this->db->sql_transaction($ok !== false ? 'commit' : 'rollback');
		return $ok !== false;
	}

	public function refresh($projectId, $sha)
	{
		return $this->db->sql_query('UPDATE ' . $this->prefix . 'workspace_github_links SET ' .
			$this->db->sql_build_array('UPDATE', ['head_sha' => $sha, 'checked_at' => time()]) .
			' WHERE project_id = ' . (int) $projectId) !== false;
	}

	public function imported($projectId, $sha)
	{
		return $this->db->sql_query('UPDATE ' . $this->prefix . 'workspace_github_links SET ' .
			$this->db->sql_build_array('UPDATE', ['imported_sha' => $sha, 'imported_at' => time()]) .
			' WHERE project_id = ' . (int) $projectId) !== false;
	}

	public function unlink($projectId)
	{
		return $this->db->sql_query('DELETE FROM ' . $this->prefix .
			'workspace_github_links WHERE project_id = ' . (int) $projectId) !== false;
	}

	/** Global budget: reserves at most 40 API requests per rolling hour for ALL board users. */
	public function claim_request()
	{
		$table = $this->prefix . 'workspace_github_budget';
		$cutoff = time() - 3600;
		$this->db->sql_query('UPDATE ' . $table . ' SET window_start = ' . time() .
			', used_count = 0 WHERE budget_id = 1 AND window_start < ' . (int) $cutoff);
		$ok = $this->db->sql_query('UPDATE ' . $table .
			' SET used_count = used_count + 1 WHERE budget_id = 1 AND used_count < 40');
		return $ok !== false && (int) $this->db->sql_affectedrows() === 1;
	}
}
