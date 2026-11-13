<?php
namespace mundophpbb\workspace\controller;

/** Read-only, permission-filtered summary of projects and recorded releases. */
class portfolio_controller extends base_controller
{
	const MAX_PROJECTS = 200;

	public function summary()
	{
		if ($r = $this->validate_post_form_key()) return $r;
		$uid = (int) ($this->user->data['user_id'] ?? 0);
		if ($uid <= 0 || (defined('ANONYMOUS') && $uid === ANONYMOUS) ||
			!$this->permission_service->can_access_workspace())
			return $this->json_error('WSP_ERR_PERMISSION');

		$projects = $this->table_prefix . 'workspace_projects';
		$members = $this->table_prefix . 'workspace_projects_users';
		$all = $this->permission_service->can_manage_all();
		if ($all)
		{
			$sql = 'SELECT p.project_id, p.project_name, p.project_locked FROM ' . $projects . ' p ORDER BY p.project_id DESC';
		}
		else
		{
			// Candidate set is owner/member only; always re-check ACL per project.
			$sql = 'SELECT DISTINCT p.project_id, p.project_name, p.project_locked FROM ' . $projects . ' p'
				. ' LEFT JOIN ' . $members . ' m ON m.project_id = p.project_id AND m.user_id = ' . $uid
				. ' WHERE p.user_id = ' . $uid . ' OR m.user_id = ' . $uid . ' ORDER BY p.project_id DESC';
		}
		$result = $this->db->sql_query_limit($sql, self::MAX_PROJECTS + 1);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result)) $rows[] = $row;
		$this->db->sql_freeresult($result);
		$truncated = count($rows) > self::MAX_PROJECTS;
		$rows = array_slice($rows, 0, self::MAX_PROJECTS);
		$visible = [];
		foreach ($rows as $row)
		{
			$id = (int) $row['project_id'];
			if (!$this->permission_service->can_view_project($id)) continue;
			$visible[$id] = [
				'project_id' => $id,
				'project_name' => (string) $row['project_name'],
				'project_locked' => !empty($row['project_locked']),
				'can_edit' => (bool) $this->permission_service->can_edit_project($id),
				'last_release' => null,
				'release_count' => 0,
				'file_count' => 0,
				'tasks_todo' => 0,
				'tasks_doing' => 0,
				'tasks_done' => 0,
			];
		}
		// Six complete UTC calendar buckets including the current month.
		$months = [];
		for ($i = 5; $i >= 0; $i--)
		{
			$monthTime = gmmktime(0, 0, 0, (int) gmdate('n') - $i, 1, (int) gmdate('Y'));
			$months[gmdate('Y-m', $monthTime)] = ['activity' => 0, 'releases' => 0];
		}
		$activityTruncated = false;
		$actionCounts = [];
		if ($visible)
		{
			$ids = array_keys($visible);
			$idset = $this->db->sql_in_set('project_id', $ids);
			$res = $this->db->sql_query('SELECT project_id, COUNT(*) AS file_count FROM ' .
				$this->table_prefix . 'workspace_files WHERE ' . $idset . ' GROUP BY project_id');
			while ($row = $this->db->sql_fetchrow($res))
				$visible[(int) $row['project_id']]['file_count'] = (int) $row['file_count'];
			$this->db->sql_freeresult($res);
			// Maintenance evidence: use only persisted task states, never inferred test results.
			$res = $this->db->sql_query('SELECT project_id, status, COUNT(*) AS task_count FROM ' .
				$this->table_prefix . 'workspace_tasks WHERE ' . $idset .
				' GROUP BY project_id, status');
			while ($row = $this->db->sql_fetchrow($res))
			{
				$id = (int) $row['project_id'];
				$status = (string) $row['status'];
				if (isset($visible[$id]) && in_array($status, ['todo', 'doing', 'done'], true))
					$visible[$id]['tasks_' . $status] = (int) $row['task_count'];
			}
			$this->db->sql_freeresult($res);
			// Latest release selected in PHP for portability across supported DB engines.
			$res = $this->db->sql_query('SELECT project_id, version, package_name, created_time, release_id FROM ' .
				$this->table_prefix . 'workspace_release_records WHERE ' . $idset .
				' ORDER BY project_id ASC, release_id DESC');
			while ($row = $this->db->sql_fetchrow($res))
			{
				$id = (int) $row['project_id'];
				$visible[$id]['release_count']++;
				$month = gmdate('Y-m', (int) $row['created_time']);
				if (isset($months[$month])) $months[$month]['releases']++;
				if ($visible[$id]['last_release'] === null)
					$visible[$id]['last_release'] = [
						'version' => (string) $row['version'],
						'package_name' => (string) $row['package_name'],
						'created_time' => (int) $row['created_time'],
					];
			}
			$this->db->sql_freeresult($res);
			// Bounded activity sampling: counts are explicitly marked partial when capped.
			$start = gmmktime(0, 0, 0, (int) gmdate('n') - 5, 1, (int) gmdate('Y'));
			$res = $this->db->sql_query_limit('SELECT created_time, action FROM ' .
				$this->table_prefix . 'workspace_activity WHERE ' . $idset .
				' AND created_time >= ' . (int) $start . ' ORDER BY created_time DESC', 5001);
			$seen = 0;
			while ($row = $this->db->sql_fetchrow($res))
			{
				if (++$seen > 5000) { $activityTruncated = true; break; }
				$month = gmdate('Y-m', (int) $row['created_time']);
				if (isset($months[$month]))
				{
					$months[$month]['activity']++;
					$action = trim((string) $row['action']);
					if ($action === '') $action = 'unspecified';
					// Restrict action labels to the persisted event name, never user-supplied paths.
					$action = substr($action, 0, 80);
					if (!isset($actionCounts[$action])) $actionCounts[$action] = 0;
					$actionCounts[$action]++;
				}
			}
			$this->db->sql_freeresult($res);
		}
		// A maintenance flag is an actionable hint, not an EPV/CI verdict.
		foreach ($visible as &$project)
		{
			$project['attention_count'] = (int) $project['tasks_todo'] + (int) $project['tasks_doing'];
			$project['needs_attention'] = $project['attention_count'] > 0 || (bool) $project['project_locked'];
			$project['validation_state'] = 'not_checked_here';
		}
		unset($project);
		$out = array_values($visible);
		$totals = ['projects' => count($out), 'files' => 0, 'releases' => 0,
			'tasks_todo' => 0, 'tasks_doing' => 0, 'tasks_done' => 0];
		foreach ($out as $project)
		{
			$totals['files'] += $project['file_count'];
			$totals['releases'] += $project['release_count'];
			foreach (['todo', 'doing', 'done'] as $state)
				$totals['tasks_' . $state] += $project['tasks_' . $state];
		}
		$trend = [];
		foreach ($months as $month => $counts)
			$trend[] = ['month' => $month, 'activities' => $counts['activity'], 'releases' => $counts['releases']];
		arsort($actionCounts, SORT_NUMERIC);
		$distribution = [];
		$other = 0;
		foreach ($actionCounts as $action => $amount)
		{
			if (count($distribution) < 8) $distribution[] = ['action' => $action, 'count' => (int) $amount];
			else $other += (int) $amount;
		}
		if ($other) $distribution[] = ['action' => '__other__', 'count' => $other];
		// Two consecutive three-calendar-month periods. The current month is incomplete.
		$previous = ['activities' => 0, 'releases' => 0];
		$recent = ['activities' => 0, 'releases' => 0];
		foreach ($trend as $index => $monthRow)
		{
			$part = $index < 3 ? 'previous' : 'recent';
			if ($part === 'previous')
			{
				$previous['activities'] += $monthRow['activities'];
				$previous['releases'] += $monthRow['releases'];
			}
			else
			{
				$recent['activities'] += $monthRow['activities'];
				$recent['releases'] += $monthRow['releases'];
			}
		}
		return $this->json_success([
			'statistics' => ['totals' => $totals, 'monthly' => $trend,
				'activity_truncated' => $activityTruncated,
				'distribution' => $distribution, 'comparison' => [
					'previous' => $previous, 'recent' => $recent,
					'previous_months' => array_slice(array_keys($months), 0, 3),
					'recent_months' => array_slice(array_keys($months), 3, 3),
					'current_month_incomplete' => true]],
			'projects' => $out,
			'count' => count($out),
			'without_recorded_release' => count(array_filter($out, function ($v) { return $v['last_release'] === null; })),
			'attention_projects' => count(array_filter($out, function ($v) { return $v['needs_attention']; })),
			'truncated' => $truncated,
		]);
	}
}
