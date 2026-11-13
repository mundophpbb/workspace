<?php
namespace mundophpbb\workspace\service;

/**
 * Bounded, database-backed project checkpoints. Never runs stored code.
 * Transaction ownership belongs to the caller (controller).
 */
class checkpoint_manager
{
	const MAX_FILES = 350;
	const MAX_TOTAL_BYTES = 8388608;
	const MAX_FILE_BYTES = 2097152;
	const MAX_CHECKPOINTS = 16;
	const MAX_DIFF_ITEMS = 350;

	private $db;
	private $prefix;

	public function __construct(\phpbb\db\driver\driver_interface $db, $table_prefix)
	{
		$this->db = $db;
		$this->prefix = (string) $table_prefix;
	}

	private function table($name) { return $this->prefix . $name; }

	/** Reject ambiguous/unsafe old paths: a checkpoint must restore exact file names. */
	public static function validPath($path)
	{
		if (!is_string($path) || $path === '' || strlen($path) > 255 || strpos($path, '\\') !== false ||
			strpos($path, "\0") !== false || preg_match('//u', $path) !== 1 || strpos($path, '<') !== false || strpos($path, '>') !== false ||
			preg_match('/[\x00-\x1f\x7f]/', $path) || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) return false;
		foreach (explode('/', $path) as $segment)
			if ($segment === '' || $segment === '.' || $segment === '..') return false;
		return true;
	}

	private function checkedRows(array $rows, $live)
	{
		if (count($rows) > self::MAX_FILES) throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
		$files = [];
		$names = [];
		$bytes = 0;
		foreach ($rows as $row)
		{
			$name = (string) ($row['file_name'] ?? '');
			$content = (string) ($row['file_content'] ?? '');
			$type = (string) ($row['file_type'] ?? 'txt');
			$len = strlen($content);
			$low = strtolower($name);
			if (!self::validPath($name) || isset($names[$low]) || $len > self::MAX_FILE_BYTES || strlen($type) > 50)
				throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
			$bytes += $len;
			if ($bytes > self::MAX_TOTAL_BYTES) throw new \RuntimeException('WSP_CP_SIZE_LIMIT');
			$names[$low] = true;
			$files[$name] = [
				'content' => $content, 'type' => $type, 'hash' => hash('sha256', $content),
				'id' => $live ? (int) ($row['file_id'] ?? 0) : 0,
				'rev' => $live ? (int) ($row['file_rev'] ?? 0) : 0,
			];
			if ($live && $files[$name]['id'] <= 0) throw new \RuntimeException('WSP_CP_CONFLICT');
		}
		ksort($files, SORT_STRING);
		return ['files' => $files, 'count' => count($files), 'bytes' => $bytes,
			'fingerprint' => self::fingerprint($files, $live)];
	}

	public static function fingerprint(array $files, $live)
	{
		ksort($files, SORT_STRING);
		$records = [];
		foreach ($files as $path => $file)
			$records[] = [$path, (string) $file['type'], (string) $file['hash'],
				$live ? (int) $file['id'] : 0, $live ? (int) $file['rev'] : 0];
		return hash('sha256', json_encode($records));
	}

	public function capture($projectId)
	{
		$sql = 'SELECT file_id, file_name, file_type, file_content, file_rev FROM ' .
			$this->table('workspace_files') . ' WHERE project_id = ' . (int) $projectId . ' ORDER BY file_id ASC';
		$result = $this->db->sql_query_limit($sql, self::MAX_FILES + 1);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result)) $rows[] = $row;
		$this->db->sql_freeresult($result);
		return $this->checkedRows($rows, true);
	}

	public function listCheckpoints($projectId)
	{
		$result = $this->db->sql_query_limit('SELECT checkpoint_id, project_id, user_id, label, kind, created_time, file_count, total_bytes
			FROM ' . $this->table('workspace_checkpoints') . ' WHERE project_id = ' . (int) $projectId .
			' ORDER BY checkpoint_id DESC', self::MAX_CHECKPOINTS + 21);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result)) $rows[] = $row;
		$this->db->sql_freeresult($result);
		return array_slice($rows, 0, self::MAX_CHECKPOINTS + 20);
	}

	public function fetchCheckpoint($projectId, $checkpointId)
	{
		$result = $this->db->sql_query_limit('SELECT checkpoint_id, project_id, user_id, label, kind, created_time, file_count, total_bytes, fingerprint
			FROM ' . $this->table('workspace_checkpoints') . ' WHERE project_id = ' . (int) $projectId .
			' AND checkpoint_id = ' . (int) $checkpointId, 1);
		$meta = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$meta) throw new \RuntimeException('WSP_CP_NOT_FOUND');
		$result = $this->db->sql_query_limit('SELECT file_name, file_type, file_content, content_hash FROM ' .
			$this->table('workspace_checkpoint_files') . ' WHERE checkpoint_id = ' . (int) $checkpointId .
			' ORDER BY checkpoint_file_id ASC', self::MAX_FILES + 1);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result)) {
			if (!hash_equals((string) ($row['content_hash'] ?? ''), hash('sha256', (string) $row['file_content'])))
				throw new \RuntimeException('WSP_CP_CORRUPTED');
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		try { $saved = $this->checkedRows($rows, false); }
		catch (\RuntimeException $e) { throw new \RuntimeException('WSP_CP_CORRUPTED'); }
		if ($saved['count'] !== (int) $meta['file_count'] || $saved['bytes'] !== (int) $meta['total_bytes'] ||
			!hash_equals((string) $meta['fingerprint'], $saved['fingerprint']))
			throw new \RuntimeException('WSP_CP_CORRUPTED');
		$saved['meta'] = $meta;
		return $saved;
	}

	/** Call only inside a database transaction. */
	public function insertCheckpoint($projectId, $userId, $label, $kind, array $capture)
	{
		if ($projectId <= 0 || $userId <= 1 || !in_array($kind, ['manual', 'pre_restore', 'pre_generate', 'pre_refactor', 'release'], true))
			throw new \RuntimeException('WSP_CP_CONFLICT');
		$label = trim((string) $label);
		if ($label === '' || (function_exists('utf8_strlen') ? utf8_strlen($label) : (function_exists('mb_strlen') ? mb_strlen($label, 'UTF-8') : strlen($label))) > 120 || preg_match('/[\x00-\x1f\x7f]/', $label))
			throw new \RuntimeException('WSP_CP_INVALID_NAME');
		$data = [
			'project_id' => (int) $projectId, 'user_id' => (int) $userId,
			'label' => $label, 'kind' => $kind, 'created_time' => time(),
			'file_count' => (int) $capture['count'], 'total_bytes' => (int) $capture['bytes'],
			'fingerprint' => (string) self::fingerprint($capture['files'], false),
		];
		$ok = $this->db->sql_query('INSERT INTO ' . $this->table('workspace_checkpoints') . ' ' . $this->db->sql_build_array('INSERT', $data));
		if ($ok === false) throw new \RuntimeException('WSP_CP_FAILED');
		$id = (int) $this->db->sql_nextid();
		if ($id <= 0) throw new \RuntimeException('WSP_CP_FAILED');
		foreach ($capture['files'] as $path => $file)
		{
			$data = [
				'checkpoint_id' => $id, 'file_name' => $path, 'file_type' => (string) $file['type'],
				'file_content' => (string) $file['content'], 'content_hash' => (string) $file['hash'],
			];
			if ($this->db->sql_query('INSERT INTO ' . $this->table('workspace_checkpoint_files') . ' ' .
				$this->db->sql_build_array('INSERT', $data)) === false)
				throw new \RuntimeException('WSP_CP_FAILED');
		}
		$this->prune($projectId);
		return $id;
	}

	public function prune($projectId)
	{
		$result = $this->db->sql_query('SELECT checkpoint_id, kind FROM ' . $this->table('workspace_checkpoints') .
			' WHERE project_id = ' . (int) $projectId . ' ORDER BY checkpoint_id DESC');
		$ids = [];
		while ($row = $this->db->sql_fetchrow($result)) if (($row['kind'] ?? '') !== 'release') $ids[] = (int) $row['checkpoint_id'];
		$this->db->sql_freeresult($result);
		foreach (array_slice($ids, self::MAX_CHECKPOINTS) as $oldId) $this->deleteCheckpoint($projectId, $oldId);
	}

	/** Call only inside transaction, after ACL checks. */
	public function deleteCheckpoint($projectId, $checkpointId)
	{
		if ($projectId <= 0 || $checkpointId <= 0) return false;
		$check = $this->db->sql_query('SELECT kind FROM ' . $this->table('workspace_checkpoints') .
			' WHERE project_id = ' . (int) $projectId . ' AND checkpoint_id = ' . (int) $checkpointId);
		$kindRow = $this->db->sql_fetchrow($check);
		$this->db->sql_freeresult($check);
		if ($kindRow && $kindRow['kind'] === 'release') return false;
		$sql = sprintf(
			'DELETE FROM %s WHERE checkpoint_id = %d AND checkpoint_id IN (SELECT checkpoint_id FROM %s WHERE project_id = %d)',
			$this->table('workspace_checkpoint_files'),
			(int) $checkpointId,
			$this->table('workspace_checkpoints'),
			(int) $projectId
		);
		// MySQL permits a subquery referring to a different table from the deleted table.
		if ($this->db->sql_query($sql) === false) throw new \RuntimeException('WSP_CP_FAILED');
		if ($this->db->sql_query('DELETE FROM ' . $this->table('workspace_checkpoints') .
			' WHERE project_id = ' . (int) $projectId . ' AND checkpoint_id = ' . (int) $checkpointId) === false)
			throw new \RuntimeException('WSP_CP_FAILED');
		return $this->db->sql_affectedrows() > 0;
	}

	public function deleteProjectCheckpoints($projectId)
	{
		$sql = 'DELETE FROM ' . $this->table('workspace_checkpoint_files') . ' WHERE checkpoint_id IN
			(SELECT checkpoint_id FROM ' . $this->table('workspace_checkpoints') . ' WHERE project_id = ' . (int) $projectId . ')';
		return $this->db->sql_query($sql) !== false &&
			$this->db->sql_query('DELETE FROM ' . $this->table('workspace_checkpoints') .
				' WHERE project_id = ' . (int) $projectId) !== false;
	}

	public static function diff(array $current, array $saved)
	{
		$changes = [];
		$keys = array_unique(array_merge(array_keys($current['files']), array_keys($saved['files'])));
		sort($keys, SORT_STRING);
		foreach ($keys as $path)
		{
			$before = $current['files'][$path] ?? null;
			$after = $saved['files'][$path] ?? null;
			if ($before && $after && $before['hash'] === $after['hash'] && $before['type'] === $after['type']) continue;
			$action = !$before ? 'restore' : (!$after ? 'remove' : 'change');
			$changes[] = [
				'path' => $path, 'action' => $action,
				'before_bytes' => $before ? strlen($before['content']) : 0,
				'after_bytes' => $after ? strlen($after['content']) : 0,
				'file_id' => $before ? (int) $before['id'] : 0,
				'rev' => $before ? (int) $before['rev'] : 0,
			];
		}
		return $changes;
	}

	public static function previewToken($projectId, $checkpointId, array $current, array $saved)
	{
		return hash('sha256', (int) $projectId . ':' . (int) $checkpointId . ':' .
			$current['fingerprint'] . ':' . $saved['fingerprint']);
	}
}
