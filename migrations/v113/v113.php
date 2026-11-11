<?php
namespace mundophpbb\workspace\migrations\v113;

/**
 * Workspace v113 - Data integrity.
 *
 *  1. Adds workspace_files.file_rev, a revision counter used for optimistic
 *     locking (concurrent writers of the same file no longer overwrite each other).
 *  2. Merges duplicate (project_id, file_name) rows so v114 can add a UNIQUE index.
 *     The duplicate's content is kept as a version of the surviving file.
 *  3. Normalizes legacy content that the editor stored HTML-escaped.
 *
 * Before this version the editor saved file contents through
 * request->variable(..., true), which runs htmlspecialchars(): "<?php" was stored
 * as "&lt;?php" while uploads stored raw bytes. From now on content is always
 * stored raw, so previously escaped rows are converted once, here.
 *
 * Step 3 is a heuristic: a row is treated as "escaped by the editor" only when it
 * contains no raw < > " characters and every & is part of &amp; &lt; &gt; or &quot;.
 * A snapshot of each converted file is kept as a version so it can be restored.
 */
class v113 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return [
			'\\mundophpbb\\workspace\\migrations\\v112\\v112',
		];
	}

	public function effectively_installed()
	{
		$table = $this->table_prefix . 'workspace_files';

		return $this->db_tools->sql_table_exists($table)
			&& $this->db_tools->sql_column_exists($table, 'file_rev');
	}

	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'workspace_files' => [
					'file_rev' => ['UINT', 0],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'workspace_files' => [
					'file_rev',
				],
			],
		];
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'merge_duplicate_files']]],
			['custom', [[$this, 'decode_legacy_content']]],
		];
	}

	public function revert_data()
	{
		return [];
	}

	/**
	 * Keeps the most recently modified row of every duplicated (project_id, file_name).
	 */
	public function merge_duplicate_files()
	{
		$files = $this->table_prefix . 'workspace_files';

		if (!$this->db_tools->sql_table_exists($files))
		{
			return;
		}

		$sql = 'SELECT project_id, file_name
				FROM ' . $files . '
				GROUP BY project_id, file_name
				HAVING COUNT(file_id) > 1';
		$result = $this->db->sql_query($sql);
		$groups = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$groups[] = ['project_id' => (int) $row['project_id'], 'file_name' => (string) $row['file_name']];
		}
		$this->db->sql_freeresult($result);

		$versions = $this->table_prefix . 'workspace_file_versions';
		$locks = $this->table_prefix . 'workspace_file_locks';
		$repoint = [
			$this->table_prefix . 'workspace_comments',
			$this->table_prefix . 'workspace_file_reviews',
			$this->table_prefix . 'workspace_tasks',
			$versions,
		];

		foreach ($groups as $group)
		{
			$sql = 'SELECT file_id, file_content, file_time
					FROM ' . $files . '
					WHERE project_id = ' . (int) $group['project_id'] . "
					  AND file_name = '" . $this->db->sql_escape($group['file_name']) . "'
					ORDER BY file_time DESC, file_id DESC";
			$result = $this->db->sql_query($sql);
			$rows = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[] = $row;
			}
			$this->db->sql_freeresult($result);

			if (count($rows) < 2)
			{
				continue;
			}

			$keep = array_shift($rows);
			$keep_id = (int) $keep['file_id'];
			$keep_hash = hash('sha256', (string) $keep['file_content']);

			foreach ($rows as $dup)
			{
				$dup_id = (int) $dup['file_id'];
				$hash = hash('sha256', (string) $dup['file_content']);

				// Never lose content: keep the discarded row as a version of the survivor.
				if ($hash !== $keep_hash && $this->db_tools->sql_table_exists($versions))
				{
					$this->db->sql_query('INSERT INTO ' . $versions . ' ' . $this->db->sql_build_array('INSERT', [
						'project_id'   => $group['project_id'],
						'file_id'      => $keep_id,
						'user_id'      => 0,
						'file_name'    => substr($group['file_name'], 0, 255),
						'file_content' => (string) $dup['file_content'],
						'content_hash' => $hash,
						'source'       => 'manual',
						'change_note'  => 'Duplicate file row merged by migration v113 (original file_id ' . $dup_id . ').',
						'created_time' => ((int) $dup['file_time'] > 0) ? (int) $dup['file_time'] : time(),
					]));
				}

				foreach ($repoint as $table)
				{
					if ($this->db_tools->sql_table_exists($table))
					{
						$this->db->sql_query('UPDATE ' . $table . ' SET file_id = ' . (int) $keep_id . ' WHERE file_id = ' . (int) $dup_id);
					}
				}

				// file_id is UNIQUE in the locks table, so the duplicate's lock is dropped.
				if ($this->db_tools->sql_table_exists($locks))
				{
					$this->db->sql_query('DELETE FROM ' . $locks . ' WHERE file_id = ' . (int) $dup_id);
				}

				$this->db->sql_query('DELETE FROM ' . $files . ' WHERE file_id = ' . (int) $dup_id);
			}
		}
	}

	/**
	 * Converts rows that were stored through htmlspecialchars() back to raw text.
	 */
	public function decode_legacy_content()
	{
		$this->decode_table($this->table_prefix . 'workspace_files', 'file_id', true);
		$this->decode_table($this->table_prefix . 'workspace_file_versions', 'version_id', false);
	}

	/**
	 * @param string $table
	 * @param string $pk
	 * @param bool   $snapshot Keep the pre-conversion content as a version (files table only)
	 */
	protected function decode_table($table, $pk, $snapshot)
	{
		if (!$this->db_tools->sql_table_exists($table))
		{
			return;
		}

		$any = $this->db->get_any_char();
		$like = [];
		foreach (['&lt;', '&gt;', '&quot;', '&amp;'] as $entity)
		{
			$like[] = 'file_content ' . $this->db->sql_like_expression($any . $entity . $any);
		}

		$versions = $this->table_prefix . 'workspace_file_versions';
		$last = 0;

		while (true)
		{
			$sql = 'SELECT ' . $pk . ', file_content' . ($snapshot ? ', project_id, file_name' : '') . '
					FROM ' . $table . '
					WHERE ' . $pk . ' > ' . (int) $last . '
					  AND (' . implode(' OR ', $like) . ')
					ORDER BY ' . $pk . ' ASC';
			$result = $this->db->sql_query_limit($sql, 25);
			$rows = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[] = $row;
			}
			$this->db->sql_freeresult($result);

			if (empty($rows))
			{
				break;
			}

			foreach ($rows as $row)
			{
				$last = (int) $row[$pk];
				$content = (string) $row['file_content'];

				if (!self::looks_html_escaped($content))
				{
					continue;
				}

				// Reverses exactly what htmlspecialchars(ENT_COMPAT) produced: & < > "
				$decoded = htmlspecialchars_decode($content, ENT_COMPAT);

				if ($snapshot && $this->db_tools->sql_table_exists($versions))
				{
					$this->db->sql_query('INSERT INTO ' . $versions . ' ' . $this->db->sql_build_array('INSERT', [
						'project_id'   => (int) $row['project_id'],
						'file_id'      => (int) $row[$pk],
						'user_id'      => 0,
						'file_name'    => substr((string) $row['file_name'], 0, 255),
						'file_content' => $content,
						'content_hash' => hash('sha256', $content),
						'source'       => 'manual',
						'change_note'  => 'Snapshot before HTML-entity normalization (migration v113). Restore this version only if the file was wrongly converted.',
						'created_time' => time(),
					]));
				}

				$sql_ary = ['file_content' => $decoded];
				if ($pk === 'version_id')
				{
					$sql_ary['content_hash'] = hash('sha256', $decoded);
				}

				$this->db->sql_query('UPDATE ' . $table . '
					SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
					WHERE ' . $pk . ' = ' . (int) $row[$pk]);
			}
		}
	}

	/**
	 * True when $content looks like output of htmlspecialchars(): it has at least
	 * one entity, no raw < > " and no & that is not part of &amp; &lt; &gt; &quot;.
	 */
	protected static function looks_html_escaped($content)
	{
		if (strpbrk($content, '<>"') !== false)
		{
			return false;
		}

		if (!preg_match('/&(?:amp|lt|gt|quot);/', $content))
		{
			return false;
		}

		return !preg_match('/&(?!(?:amp|lt|gt|quot);)/', $content);
	}
}
