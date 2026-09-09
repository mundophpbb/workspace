<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Workspace component.
 */
class tool_controller extends base_controller
{
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
	 * Ensures project capability.
	 *
	 * @return JsonResponse|null
	 */
	private function ensure_project_capability($project_id, $capability)
	{
		$access = $this->assert_project_access((int) $project_id, (string) $capability);
		if (!$access['ok'])
		{
			return new JsonResponse(['success' => false, 'error' => $access['error']]);
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
	 * Finds TODO/FIXME markers only inside comments.
	 *
	 * This avoids false positives such as string values, slugs, CSS classes or
	 * task statuses like "todo" in normal code.
	 *
	 * @param string $content
	 * @return array<int, array<string, mixed>>
	 */
	private function validation_first_line_excerpt($content)
	{
		$content = (string) $content;
		$line = preg_split('/\r\n|\r|\n/', $content, 2)[0];
		$line = str_replace(["\t", "\0"], ['\t', ''], (string) $line);
		if (trim($line) === '')
		{
			return '[espaço/quebra de linha antes de <?php]';
		}
		if (function_exists('mb_substr'))
		{
			return mb_substr($line, 0, 220, 'UTF-8');
		}
		return substr($line, 0, 220);
	}

	/**
	 * Returns content suitable for PHP opening tag validation.
	 *
	 * Some legacy/imported project rows may store the opening tag HTML-escaped
	 * as &lt;?php while the editor displays it decoded. For validation purposes,
	 * decode entities before checking the PHP opening tag so valid files are not
	 * reported as missing <?php.
	 */
	private function validation_php_tag_probe_content($content)
	{
		$content = (string) $content;
		$probe = $content;

		// Imported/legacy rows can contain one or more HTML-encoding layers.
		// Decode only as far as necessary to expose the PHP opening tag.
		for ($i = 0; $i < 3 && strpos($probe, '<?php') === false; $i++)
		{
			$decoded = html_entity_decode($probe, ENT_QUOTES, 'UTF-8');
			if ($decoded === $probe)
			{
				break;
			}
			$probe = $decoded;
		}

		if (strpos($probe, '<?php') !== false)
		{
			return $probe;
		}

		return $content;
	}

	/**
	 * Reports whether PHP source declares a named class, interface or trait.
	 *
	 * Token inspection avoids treating words such as "class" inside language
	 * strings, comments or documentation as declarations. Anonymous classes are
	 * intentionally ignored because they do not define an autoloadable class.
	 *
	 * @param string $content
	 * @return bool
	 */
	private function validation_php_declares_named_class_like($content)
	{
		$content = (string) $content;
		if ($content === '')
		{
			return false;
		}

		$tokens = token_get_all($content);
		$count = count($tokens);
		$declaration_tokens = [T_CLASS, T_INTERFACE, T_TRAIT];

		for ($i = 0; $i < $count; $i++)
		{
			$token = $tokens[$i];
			if (!is_array($token) || !in_array($token[0], $declaration_tokens, true))
			{
				continue;
			}

			for ($j = $i + 1; $j < $count; $j++)
			{
				$next = $tokens[$j];
				if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
				{
					continue;
				}

				return is_array($next) && $next[0] === T_STRING;
			}
		}

		return false;
	}

	/**
	 * Finds a real PHP function call outside comments and quoted strings.
	 *
	 * @param string $content
	 * @param string $function_name
	 * @return int|false Byte offset of the first call.
	 */
	private function validation_php_function_call_offset($content, $function_name)
	{
		$content = (string) $content;
		$function_name = strtolower((string) $function_name);
		if ($content === '' || $function_name === '')
		{
			return false;
		}

		$tokens = token_get_all($content);
		$offset = 0;
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++)
		{
			$token = $tokens[$i];
			$text = is_array($token) ? $token[1] : $token;

			if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === $function_name)
			{
				$previous = null;
				for ($j = $i - 1; $j >= 0; $j--)
				{
					$candidate = $tokens[$j];
					if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
					{
						continue;
					}
					$previous = $candidate;
					break;
				}

				$next = null;
				for ($j = $i + 1; $j < $count; $j++)
				{
					$candidate = $tokens[$j];
					if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
					{
						continue;
					}
					$next = $candidate;
					break;
				}

				$is_method = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
				if (!$is_method && $next === '(')
				{
					return $offset;
				}
			}

			$offset += strlen($text);
		}

		return false;
	}

	private function find_todo_fixme_comments($content)
	{
		$content = $this->normalize_content((string) $content);
		$lines = explode("\n", $content);
		$matches = [];
		$in_block_comment = false;
		$in_html_comment = false;

		foreach ($lines as $line_index => $line_text)
		{
			$line = (string) $line_text;
			$length = strlen($line);
			$i = 0;
			$comment_segments = [];

			while ($i < $length)
			{
				if ($in_block_comment)
				{
					$end = strpos($line, '*/', $i);
					if ($end === false)
					{
						$comment_segments[] = substr($line, $i);
						$i = $length;
						continue;
					}

					$comment_segments[] = substr($line, $i, $end + 2 - $i);
					$i = $end + 2;
					$in_block_comment = false;
					continue;
				}

				if ($in_html_comment)
				{
					$end = strpos($line, '-->', $i);
					if ($end === false)
					{
						$comment_segments[] = substr($line, $i);
						$i = $length;
						continue;
					}

					$comment_segments[] = substr($line, $i, $end + 3 - $i);
					$i = $end + 3;
					$in_html_comment = false;
					continue;
				}

				$char = $line[$i];

				// Workspace implementation detail.
				if ($char === "'" || $char === '"' || $char === '`')
				{
					$quote = $char;
					$i++;
					while ($i < $length)
					{
						if ($line[$i] === '\\')
						{
							$i += 2;
							continue;
						}
						if ($line[$i] === $quote)
						{
							$i++;
							break;
						}
						$i++;
					}
					continue;
				}

				$next2 = ($i + 1 < $length) ? substr($line, $i, 2) : '';
				$next4 = ($i + 3 < $length) ? substr($line, $i, 4) : '';

				if ($next4 === '<!--')
				{
					$end = strpos($line, '-->', $i + 4);
					if ($end === false)
					{
						$comment_segments[] = substr($line, $i);
						$in_html_comment = true;
						$i = $length;
					}
					else
					{
						$comment_segments[] = substr($line, $i, $end + 3 - $i);
						$i = $end + 3;
					}
					continue;
				}

				if ($next2 === '/*')
				{
					$end = strpos($line, '*/', $i + 2);
					if ($end === false)
					{
						$comment_segments[] = substr($line, $i);
						$in_block_comment = true;
						$i = $length;
					}
					else
					{
						$comment_segments[] = substr($line, $i, $end + 2 - $i);
						$i = $end + 2;
					}
					continue;
				}

				if ($next2 === '//')
				{
					$comment_segments[] = substr($line, $i);
					$i = $length;
					continue;
				}

				if ($char === '#')
				{
					$comment_segments[] = substr($line, $i);
					$i = $length;
					continue;
				}

				$i++;
			}

			foreach ($comment_segments as $segment)
			{
				// A release marker must start the comment text and be written as
				// the conventional uppercase token. This avoids Portuguese words
				// Workspace implementation detail.
				$marker_probe = preg_replace('/^\s*(?:\/\/+|#+|\/\*+|\*+|<!--)\s*/', '', (string) $segment);
				if (!preg_match('/^@?(TODO|FIXME)\b(?:\s*[:(\[\-]|\s+|$)/', (string) $marker_probe, $todo_match))
				{
					continue;
				}

				$excerpt = trim($line);
				if (function_exists('mb_substr'))
				{
					$excerpt = mb_substr($excerpt, 0, 220, 'UTF-8');
				}
				else
				{
					$excerpt = substr($excerpt, 0, 220);
				}

				$token = strtoupper(ltrim((string) $todo_match[1], '@'));
				$matches[] = [
					'line' => (int) $line_index + 1,
					'excerpt' => $excerpt,
					'token' => $token,
				];
			}
		}

		return $matches;
	}

	/**
	 * Sanitiza nome exibido no BBCode [diff=...]
	 */
	private function sanitize_diff_filename($filename)
	{
		$filename = basename(str_replace('\\', '/', (string) $filename));
		$filename = trim($filename);

		if ($filename === '')
		{
			$filename = 'arquivo.txt';
		}

		$filename = preg_replace('/[^a-zA-Z0-9_\.\-]/', '_', $filename);

		if ($filename === '' || $filename === '_')
		{
			$filename = 'arquivo.txt';
		}

		return $filename;
	}

	/**
	 * Handles search project.
	 */
	public function search_project()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id  = (int) $this->request->variable('project_id', 0);
		$search_term = $this->request->variable('search', '', true);

		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if ($search_term === '')
		{
			return $this->json_error('WSP_TOOLS_SEARCH_TERM_REQUIRED');
		}

		// SSOT: view
		if ($r = $this->ensure_project_capability($project_id, 'view')) { return $r; }

		$sql = 'SELECT file_id, file_name
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . "
				  AND file_content LIKE '%" . $this->db->sql_escape($search_term) . "%'";

		$result  = $this->db->sql_query($sql);
		$matches = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$name = (string) $row['file_name'];
			$base = strtolower(basename($name));

			// Ignora changelog e placeholders
			if ($base === 'changelog.txt' || $base === '.placeholder')
			{
				continue;
			}

			$matches[] = [
				'id'   => (int) $row['file_id'],
				'name' => $name,
			];
		}
		$this->db->sql_freeresult($result);

		return $this->json_success(['matches' => $matches]);
	}

	/**
	 * Handles replace project.
	 */
	public function replace_project()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id   = (int) $this->request->variable('project_id', 0);
		$file_id      = (int) $this->request->variable('file_id', 0);
		$search_term  = $this->request->variable('search', '', true);
		$replace_term = $this->request->variable('replace', '', true);

		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if ($search_term === '')
		{
			return $this->json_error('WSP_TOOLS_SEARCH_TERM_REQUIRED');
		}

		// ✅ SSOT: replace (inclui lock + ACL u_workspace_replace + u_workspace_edit)
		if ($r = $this->ensure_project_capability($project_id, 'replace')) { return $r; }

		// Workspace implementation detail.
		if ($file_id > 0)
		{
			$sql_check = 'SELECT file_id
						  FROM ' . $this->table_prefix . 'workspace_files
						  WHERE project_id = ' . (int) $project_id . '
							AND file_id = ' . (int) $file_id;

			$res = $this->db->sql_query($sql_check);
			$ok  = $this->db->sql_fetchrow($res);
			$this->db->sql_freeresult($res);

			if (!$ok)
			{
				return $this->json_error('WSP_ERR_INVALID_DATA');
			}
		}

		$sql_where = 'project_id = ' . (int) $project_id;
		if ($file_id > 0)
		{
			$sql_where .= ' AND file_id = ' . (int) $file_id;
		}

		$like = '%' . $this->db->sql_escape($search_term) . '%';

		$sql = 'SELECT file_id, file_content, file_name
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE ' . $sql_where . "
				  AND file_content LIKE '" . $like . "'";

		$result        = $this->db->sql_query($sql);
		$updated_count = 0;

		$this->db->sql_transaction('begin');

		try
		{
			while ($row = $this->db->sql_fetchrow($result))
			{
				$name = (string) $row['file_name'];
				$base = strtolower(basename($name));

				if ($base === 'changelog.txt' || $base === '.placeholder')
				{
					continue;
				}

				$old_content = (string) $row['file_content'];
				$new_content = str_replace($search_term, $replace_term, $old_content);

				if ($new_content !== $old_content)
				{
					$sql_ary = [
						'file_content' => $new_content,
						'file_time'    => time(),
					];

					$update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
								   SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
								   WHERE file_id = ' . (int) $row['file_id'];

					$ok = $this->db->sql_query($update_sql);
					if ($ok === false)
					{
						throw new \RuntimeException('Update failed');
					}

					$log_msg = sprintf(
						$this->user->lang('WSP_LOG_REPLACE_ACTION'),
						$search_term,
						$replace_term,
						$name
					);

					$this->log_to_changelog_internal($project_id, $log_msg);
					$updated_count++;
				}
			}

			$this->db->sql_freeresult($result);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_freeresult($result);
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		return $this->json_success(['updated' => $updated_count]);
	}

	/**
	 * Handles generate changelog.
	 */
	public function generate_changelog()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		// ✅ SSOT: edit
		if ($r = $this->ensure_project_capability($project_id, 'edit')) { return $r; }

		$sql = 'SELECT file_id, file_content
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . " AND file_name = 'changelog.txt'";

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$date_str = date('d/m/Y H:i');

		$header  = "\n" . str_repeat("#", 60) . "\n";
		$header .= "# " . $this->user->lang('WSP_GENERATE_CHANGELOG_AT', $date_str) . "\n";
		$header .= str_repeat("#", 60) . "\n\n";

		$existing = $this->normalize_content((string) $row['file_content']);
		$final_content = $header . $existing;

		$sql_ary = [
			'file_content' => $final_content,
			'file_time'    => time(),
		];

		$update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
					   SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
					   WHERE file_id = ' . (int) $row['file_id'];

		$this->db->sql_query($update_sql);

		return $this->json_success();
	}

	/**
	 * Handles clear changelog.
	 */
	public function clear_changelog()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		// ✅ SSOT: edit
		if ($r = $this->ensure_project_capability($project_id, 'edit')) { return $r; }

		// Workspace implementation detail.
		$sql_check = 'SELECT file_id
					  FROM ' . $this->table_prefix . 'workspace_files
					  WHERE project_id = ' . (int) $project_id . " AND file_name = 'changelog.txt'";

		$res = $this->db->sql_query($sql_check);
		$row = $this->db->sql_fetchrow($res);
		$this->db->sql_freeresult($res);

		if (!$row)
		{
			return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
		}

		$date_str = date('d/m/Y H:i');

		$header  = str_repeat("=", 60) . "\n";
		$header .= "  " . $this->user->lang('WSP_HISTORY_CLEANED_AT', $date_str) . "\n";
		$header .= str_repeat("=", 60) . "\n\n";

		$sql_ary = [
			'file_content' => $header,
			'file_time'    => time(),
		];

		$update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
					   SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
					   WHERE project_id = ' . (int) $project_id . " AND file_name = 'changelog.txt'";

		$this->db->sql_query($update_sql);

		return $this->json_success();
	}

	/**
	 * Handles generate diff.
	 */
	public function generate_diff()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$v1_id    = (int) $this->request->variable('original_id', 0);
		$v2_id    = (int) $this->request->variable('modified_id', 0);
		$filename = $this->sanitize_diff_filename($this->request->variable('filename', 'arquivo.txt', true));

		if (!$v1_id || !$v2_id)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if ($v1_id === $v2_id)
		{
			return $this->json_error('WSP_TOOLS_DIFF_SAME_FILES');
		}

		$a1 = $this->assert_file_access($v1_id, 'view');
		$a2 = $this->assert_file_access($v2_id, 'view');

		if (!$a1['ok']) return new JsonResponse(['success' => false, 'error' => $a1['error']]);
		if (!$a2['ok']) return new JsonResponse(['success' => false, 'error' => $a2['error']]);

		if ((int) $a1['project_id'] !== (int) $a2['project_id'])
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		$v1 = $this->normalize_content($this->get_file_content($v1_id));
		$v2 = $this->normalize_content($this->get_file_content($v2_id));

		$lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';
		if (!file_exists($lib_path . 'Diff.php'))
		{
			return $this->json_error('WSP_ERR_DIFF_LIB_MISSING');
		}

		require_once($lib_path . 'Diff.php');
		require_once($lib_path . 'Diff/Renderer/Abstract.php');
		require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

		$diff      = new \mundophpbb\workspace\lib\Diff(explode("\n", $v1), explode("\n", $v2));
		$renderer  = new \mundophpbb\workspace\lib\Diff_Renderer_Text_Unified();
		$diff_text = $diff->render($renderer);

		if (empty(trim((string) $diff_text)))
		{
			$no_changes = '--- ' . $this->user->lang('WSP_DIFF_NO_CHANGES') . ' ---';
			return $this->json_success([
				'bbcode'   => "[diff={$filename}]\n" . $no_changes . "\n[/diff]",
				'filename' => $filename,
			]);
		}

		return $this->json_success([
			'bbcode'   => "[diff={$filename}]\n" . $diff_text . "\n[/diff]",
			'filename' => $filename,
		]);
	}

	/**
	 * Handles refresh cache.
	 */
	public function refresh_cache()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		// ✅ SSOT: purge_cache (global, project_id=0)
		if ($r = $this->ensure_project_capability(0, 'purge_cache')) { return $r; }

		global $phpbb_container;

		try
		{
			$phpbb_container->get('cache')->purge();
			return $this->json_success();
		}
		catch (\Exception $e)
		{
			return $this->json_error('WSP_ERR_CACHE_PURGE_FAILED');
		}
	}

	private function get_file_content($file_id)
	{
		$sql = 'SELECT file_content
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE file_id = ' . (int) $file_id;

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (string) $row['file_content'] : '';
	}

	/**
	 * Handles log to changelog internal.
	 */
	private function log_to_changelog_internal($project_id, $message)
	{
		$project_id = (int) $project_id;
		$date      = date('d/m/Y H:i');
		$message   = (string) $message;
		$log_entry = "[$date] " . $message . "\n";

		$sql = 'SELECT file_id, file_content
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . " AND file_name = 'changelog.txt'";

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			$existing_content = $this->normalize_content((string) $row['file_content']);
			$new_content      = $log_entry . $existing_content;

			$sql_ary = [
				'file_content' => $new_content,
				'file_time'    => time(),
			];

			$update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
						   SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
						   WHERE file_id = ' . (int) $row['file_id'];

			$this->db->sql_query($update_sql);
			return;
		}

		// Workspace implementation detail.
		$file_ary = [
			'project_id'   => (int) $project_id,
			'file_name'    => 'changelog.txt',
			'file_content' => $log_entry,
			'file_type'    => 'txt',
			'file_time'    => time(),
		];

		$this->db->sql_query(
			'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
			$this->db->sql_build_array('INSERT', $file_ary)
		);
	}

	/**
	 * Validates release.
	 */
	public function validate_release()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if ($r = $this->ensure_project_capability($project_id, 'view')) { return $r; }

		$scope = $this->normalize_validation_scope($this->request->variable('scope', 'normal'));
		$files = $this->get_project_files_for_validation($project_id);
		$report = $this->build_phpbb_release_validation($files, $scope);

		$official_epv = $this->run_official_epv($files, $scope);
		$report = $this->merge_official_epv_report($report, $official_epv);

		if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
		{
			$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'release_validated', 'project', 'phpBB release checklist', [
				'errors' => (int) $report['summary']['errors'],
				'warnings' => (int) $report['summary']['warnings'],
				'passed' => (int) $report['summary']['passed'],
				'scope' => (string) $report['summary']['scope'],
				'epv_status' => (string) ($report['summary']['epv_status'] ?? 'not-run'),
			]);
		}

		return $this->json_success($report);
	}

	private function get_project_files_for_validation($project_id)
	{
		// file_name stores the virtual path. Older/imported projects may contain
		// harmless encoding/whitespace differences or duplicate rows from a prior
		// upload. Canonicalise paths and let the newest row win deterministically.
		$sql = 'SELECT file_id, file_name, file_content, file_type, file_time
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . '
				ORDER BY file_time ASC, file_id ASC';
		$result = $this->db->sql_query($sql);
		$files = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$name = $this->validation_normalize_project_path((string) $row['file_name']);
			if ($name === '' || strtolower(basename($name)) === '.placeholder')
			{
				continue;
			}
			$files[$name] = (string) $row['file_content'];
		}
		$this->db->sql_freeresult($result);

		// Every validation layer must see the same extension-relative file map.
		// Online/imported projects can contain a harmless wrapper such as
		// vendor/extension/ or project-name/ around only part of the tree.
		// Canonicalising once here prevents language, DI and CSRF checks from
		// disagreeing with the filesystem view materialised for the official EPV.
		$files = $this->validation_canonicalize_project_files($files);
		uksort($files, 'strnatcasecmp');
		return $files;
	}

	/**
	 * Converts a mixed virtual tree into extension-relative paths.
	 *
	 * The canonical root is inferred from composer.json and its package name.
	 * A final conservative fallback removes only leading wrapper directories
	 * that precede a known phpBB extension root (language/, controller/, etc.).
	 */
	private function validation_canonicalize_project_files(array $files)
	{
		$normalized = [];
		foreach ($files as $name => $content)
		{
			$name = $this->validation_normalize_project_path((string) $name);
			if ($name === '')
			{
				continue;
			}
			$normalized[$name] = (string) $content;
		}
		if (!$normalized)
		{
			return [];
		}

		$composer_path = isset($normalized['composer.json']) ? 'composer.json' : '';
		if ($composer_path === '')
		{
			$candidates = [];
			foreach (array_keys($normalized) as $name)
			{
				if (preg_match('#(?:^|/)composer\.json$#i', $name))
				{
					$candidates[] = $name;
				}
			}
			usort($candidates, function ($a, $b) {
				$depth_a = substr_count((string) $a, '/');
				$depth_b = substr_count((string) $b, '/');
				if ($depth_a !== $depth_b)
				{
					return $depth_a <=> $depth_b;
				}
				return strlen((string) $a) <=> strlen((string) $b);
			});
			if ($candidates)
			{
				$composer_path = (string) $candidates[0];
			}
		}

		$root_prefix = '';
		$package_prefix = '';
		$extension_dir = '';
		if ($composer_path !== '')
		{
			$dir = str_replace('\\', '/', dirname($composer_path));
			if ($dir !== '.' && $dir !== '/')
			{
				$root_prefix = trim($dir, '/');
			}
			$composer_data = json_decode((string) $normalized[$composer_path], true);
			if (is_array($composer_data) && !empty($composer_data['name']))
			{
				$package_name = strtolower(trim(str_replace('\\', '/', (string) $composer_data['name']), '/'));
				if (preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $package_name))
				{
					$package_prefix = $package_name;
					$extension_dir = basename($package_name);
				}
			}
		}

		$known_roots = [
			'acp', 'adm', 'assets', 'auth', 'config', 'controller', 'cron', 'event',
			'language', 'lib', 'migrations', 'notification', 'repository', 'service',
			'styles', 'tests', 'docs', 'vendor', 'node_modules',
		];
		$root_files = ['composer.json', 'ext.php', 'license.txt', 'license', 'readme.md', 'readme.txt'];
		$canonical = [];
		$scores = [];

		foreach ($normalized as $original_name => $content)
		{
			$relative = $original_name;
			$changed = false;

			if ($root_prefix !== '' && (strcasecmp($relative, $root_prefix) === 0 || stripos($relative, $root_prefix . '/') === 0))
			{
				$relative = ltrim(substr($relative, strlen($root_prefix)), '/');
				$changed = true;
			}

			if ($package_prefix !== '')
			{
				$lower = strtolower($relative);
				if (strpos($lower, $package_prefix . '/') === 0)
				{
					$relative = substr($relative, strlen($package_prefix) + 1);
					$changed = true;
				}
				else
				{
					$needle = '/' . $package_prefix . '/';
					$pos = strpos('/' . $lower, $needle);
					if ($pos !== false)
					{
						$relative = substr($relative, $pos + strlen($needle) - 1);
						$relative = ltrim($relative, '/');
						$changed = true;
					}
				}
			}

			$parts = explode('/', $relative);
			$first = strtolower((string) ($parts[0] ?? ''));
			if ($first !== '' && !in_array($first, $known_roots, true) && !in_array(strtolower(basename($relative)), $root_files, true))
			{
				$max = min(3, count($parts) - 1);
				for ($i = 1; $i <= $max; $i++)
				{
					if (in_array(strtolower((string) $parts[$i]), $known_roots, true))
					{
						$relative = implode('/', array_slice($parts, $i));
						$changed = true;
						break;
					}
				}
			}

			if ($extension_dir !== '' && stripos($relative, $extension_dir . '/') === 0)
			{
				$rest = substr($relative, strlen($extension_dir) + 1);
				$rest_first = strtolower((string) strtok($rest, '/'));
				if (in_array($rest_first, $known_roots, true) || in_array(strtolower($rest), $root_files, true))
				{
					$relative = $rest;
					$changed = true;
				}
			}

			$relative = $this->validation_normalize_project_path($relative);
			if ($relative === '')
			{
				continue;
			}

			// Prefer an already extension-relative row over a wrapped duplicate.
			$score = $changed ? 10 : 100;
			if (trim((string) $content) !== '')
			{
				$score += 5;
			}
			if (strpos($this->validation_php_tag_probe_content((string) $content), '<?php') !== false)
			{
				$score += 2;
			}

			if (!isset($canonical[$relative]) || $score > (int) $scores[$relative])
			{
				$canonical[$relative] = (string) $content;
				$scores[$relative] = $score;
			}
		}

		return $canonical;
	}

	/**
	 * Canonicalises a virtual project path for validation/materialisation.
	 *
	 * This is deliberately path-only: file contents are never transformed here.
	 */
	private function validation_normalize_project_path($name)
	{
		$name = trim((string) $name, " \t\n\r\0\x0B");
		if ($name === '')
		{
			return '';
		}

		for ($i = 0; $i < 2; $i++)
		{
			$decoded = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
			if ($decoded === $name)
			{
				break;
			}
			$name = $decoded;
		}

		// Decode encoded separators only; leave other percent sequences untouched.
		$name = preg_replace_callback('/%(?:2f|5c)/i', function ($m) {
			return strtolower($m[0]) === '%2f' ? '/' : '\\';
		}, $name);
		$name = str_replace('\\', '/', $name);
		$name = preg_replace('#/+#', '/', $name);
		$name = preg_replace('#^(?:\./)+#', '', $name);
		$name = ltrim($name, '/');
		$name = trim($name);

		$parts = [];
		foreach (explode('/', $name) as $part)
		{
			$part = trim($part);
			if ($part === '' || $part === '.')
			{
				continue;
			}
			if ($part === '..')
			{
				return '';
			}
			$parts[] = $part;
		}

		return implode('/', $parts);
	}

	private function normalize_validation_scope($scope)
	{
		$scope = strtolower((string) $scope);
		return in_array($scope, ['normal', 'full', 'phpbb_ext_db'], true) ? $scope : 'normal';
	}

	private function merge_official_epv_report(array $report, array $epv)
	{
		$report['official_epv'] = $epv;
		if (empty($epv['required']))
		{
			$report['summary']['epv_required'] = false;
			$report['summary']['epv_status'] = 'not-run';
			$report['summary']['epv_passed'] = false;
			return $report;
		}

		$status = (string) ($epv['status'] ?? 'unavailable');
		$check_status = ($status === 'pass') ? 'pass' : (($status === 'unavailable') ? 'warning' : 'error');
		$report['checklist'][] = [
			'key' => 'official_epv',
			'label' => 'Official phpBB EPV',
			'status' => $check_status,
			'detail' => (string) ($epv['summary'] ?? ''),
		];

		$report['summary']['checks'] = count($report['checklist']);
		if ($check_status === 'pass')
		{
			$report['summary']['passed'] = (int) $report['summary']['passed'] + 1;
		}
		$report['summary']['epv_required'] = true;
		$report['summary']['epv_status'] = $status;
		$report['summary']['epv_passed'] = ($status === 'pass');
		$report['summary']['workspace_ready'] = !empty($report['summary']['ready']);
		$report['summary']['ready'] = (!empty($report['summary']['ready']) && $status === 'pass');

		if ($status === 'unavailable')
		{
			$report['summary']['score'] = min((int) $report['summary']['score'], 90);
		}
		else if ($status !== 'pass')
		{
			$report['summary']['score'] = min((int) $report['summary']['score'], 70);
		}

		return $report;
	}

	/**
	 * Restricts server-wide EPV management to extension administrators.
	 *
	 * @return JsonResponse|null
	 */
	private function ensure_epv_admin_access()
	{
		if ($r = $this->ensure_workspace_access())
		{
			return $r;
		}

		if (!$this->auth->acl_get('a_extensions'))
		{
			return $this->json_error('WSP_EPV_INSTALL_PERMISSION');
		}

		return null;
	}

	/**
	 * Returns the local environment capabilities used by the one-click EPV setup.
	 */
	private function get_epv_setup_status()
	{
		$root = realpath($this->phpbb_root_path);
		if ($root === false)
		{
			$root = rtrim((string) $this->phpbb_root_path, '/\\');
		}
		$root = rtrim((string) $root, '/\\');
		$tools_dir = $root . DIRECTORY_SEPARATOR . 'tools';
		$target_dir = $tools_dir . DIRECTORY_SEPARATOR . 'epv';
		$epv = $this->find_official_epv();
		$php_cli = $this->find_php_cli_binary();
		$proc_open = function_exists('proc_open');
		$zip = class_exists('ZipArchive');
		$download = function_exists('curl_init') || (bool) ini_get('allow_url_fopen');
		$writable = is_dir($tools_dir) ? is_writable($tools_dir) : is_writable($root);
		$admin = (bool) $this->auth->acl_get('a_extensions');
		$managed = false;
		if ($epv && !empty($epv['script']))
		{
			$script = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $epv['script']);
			$managed_prefix = rtrim($target_dir, '/\\') . DIRECTORY_SEPARATOR;
			$managed = (strpos($script, $managed_prefix) === 0);
		}

		$checks = [
			'admin' => $admin,
			'proc_open' => $proc_open,
			'php_cli' => ($php_cli !== ''),
			'zip' => $zip,
			'download' => $download,
			'writable' => $writable,
		];

		$can_install = true;
		foreach ($checks as $value)
		{
			if (!$value)
			{
				$can_install = false;
				break;
			}
		}

		$auto_ready = !empty($checks['proc_open']) && !empty($checks['php_cli']) && !empty($checks['zip']) && !empty($checks['download']) && !empty($checks['writable']);

		return [
			'installed' => (bool) $epv,
			'managed' => $managed,
			'can_install' => $can_install,
			'auto_ready' => $auto_ready,
			'target' => 'phpBB/tools/epv',
			'php_cli_path' => $php_cli,
			'checks' => $checks,
			'source' => 'https://github.com/phpbb/epv',
		];
	}

	/**
	 * Reports whether the official EPV can be installed or managed automatically.
	 */
	public function epv_status()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		return $this->json_success(['epv_setup' => $this->get_epv_setup_status()]);
	}

	/**
	 * Installs or refreshes the official phpBB EPV under phpBB/tools/epv.
	 *
	 * The package is downloaded only from the official phpbb/epv GitHub
	 * repository, staged separately, verified, prepared with its bundled
	 * composer.phar and promoted only after all setup steps succeed.
	 */
	public function epv_install()
	{
		if ($r = $this->ensure_epv_admin_access()) { return $r; }

		$installed = $this->install_official_epv_package();
		if (empty($installed['ok']))
		{
			return $this->json_error((string) ($installed['lang'] ?? 'WSP_EPV_INSTALL_EXCEPTION'), $installed['lang_params'] ?? []);
		}

		return $this->json_success([
			'message' => $this->user->lang('WSP_EPV_INSTALL_SUCCESS'),
			'epv_setup' => $this->get_epv_setup_status(),
		]);
	}

	/**
	 * Downloads and prepares the official EPV without any user-facing CLI step.
	 *
	 * @return array{ok:bool,lang?:string,lang_params?:array,error?:string}
	 */
	private function install_official_epv_package()
	{
		$status = $this->get_epv_setup_status();
		if (empty($status['auto_ready']))
		{
			return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_REQUIREMENTS'];
		}

		@set_time_limit(360);
		$root = realpath($this->phpbb_root_path);
		if ($root === false)
		{
			$root = rtrim((string) $this->phpbb_root_path, '/\\');
		}
		$root = rtrim((string) $root, '/\\');
		$tools_dir = $root . DIRECTORY_SEPARATOR . 'tools';
		$target_dir = $tools_dir . DIRECTORY_SEPARATOR . 'epv';
		if (!is_dir($tools_dir) && !@mkdir($tools_dir, 0755, true) && !is_dir($tools_dir))
		{
			return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_TOOLS_DIR_FAILED'];
		}

		try
		{
			$suffix = bin2hex(random_bytes(6));
		}
		catch (\Throwable $e)
		{
			$suffix = str_replace('.', '', uniqid('', true));
		}
		$stage = $tools_dir . DIRECTORY_SEPARATOR . '.workspace-epv-' . $suffix;
		$archive = $stage . DIRECTORY_SEPARATOR . 'epv.zip';
		$extract = $stage . DIRECTORY_SEPARATOR . 'extract';
		$backup = $tools_dir . DIRECTORY_SEPARATOR . '.workspace-epv-backup-' . $suffix;
		if (!@mkdir($extract, 0700, true) && !is_dir($extract))
		{
			return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_STAGE_FAILED'];
		}

		$composer_output = '';
		try
		{
			$download = $this->download_official_epv_archive($archive);
			if (empty($download['ok']))
			{
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_DOWNLOAD_FAILED', 'lang_params' => [(string) $download['error']]];
			}

			$source_dir = $this->extract_official_epv_archive($archive, $extract);
			if ($source_dir === '')
			{
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_ARCHIVE_INVALID'];
			}

			$composer_file = $source_dir . DIRECTORY_SEPARATOR . 'composer.json';
			$composer_phar = $source_dir . DIRECTORY_SEPARATOR . 'composer.phar';
			$epv_script = $source_dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'EPV.php';
			$composer = json_decode((string) @file_get_contents($composer_file), true);
			if (!is_array($composer) || (string) ($composer['name'] ?? '') !== 'phpbb/epv' || !is_file($composer_phar) || !is_file($epv_script))
			{
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_ARCHIVE_INVALID'];
			}

			$php_cli = (string) $status['php_cli_path'];
			if ($php_cli === '')
			{
				$php_cli = $this->find_php_cli_binary();
			}
			$composer_home = $stage . DIRECTORY_SEPARATOR . 'composer-home';
			$composer_cache = $composer_home . DIRECTORY_SEPARATOR . 'cache';
			if ((!is_dir($composer_home) && !@mkdir($composer_home, 0700, true) && !is_dir($composer_home))
				|| (!is_dir($composer_cache) && !@mkdir($composer_cache, 0700, true) && !is_dir($composer_cache)))
			{
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_STAGE_FAILED'];
			}

			$execution = $this->execute_validation_process([
				$php_cli,
				$composer_phar,
				'install',
				'--no-dev',
				'--prefer-dist',
				'--no-interaction',
				'--no-progress',
				'--optimize-autoloader',
			], $source_dir, 300, [
				'HOME' => $composer_home,
				'COMPOSER_HOME' => $composer_home,
				'COMPOSER_CACHE_DIR' => $composer_cache,
				'COMPOSER_NO_INTERACTION' => '1',
				'COMPOSER_FUND' => '0',
			]);
			$composer_output = trim((string) $execution['stdout'] . ((string) $execution['stderr'] !== '' ? "\n" . (string) $execution['stderr'] : ''));
			if (empty($execution['started']) || !empty($execution['timed_out']) || (int) $execution['exit_code'] !== 0 || !is_file($source_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'))
			{
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_COMPOSER_FAILED', 'lang_params' => [$this->truncate_epv_setup_output($composer_output)]];
			}

			if (is_dir($target_dir))
			{
				if (!@rename($target_dir, $backup))
				{
					return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_REPLACE_FAILED'];
				}
			}

			if (!@rename($source_dir, $target_dir))
			{
				if (is_dir($backup) && !is_dir($target_dir))
				{
					@rename($backup, $target_dir);
				}
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_REPLACE_FAILED'];
			}

			$marker = [
				'source' => 'https://github.com/phpbb/epv',
				'installed_at' => gmdate('c'),
				'installed_by' => (int) $this->user->data['user_id'],
				'auto' => true,
			];
			@file_put_contents($target_dir . DIRECTORY_SEPARATOR . '.workspace-install.json', json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			$installed = $this->find_official_epv();
			if (!$installed || !is_file($target_dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'EPV.php'))
			{
				$this->remove_validation_temp_directory($target_dir);
				if (is_dir($backup))
				{
					@rename($backup, $target_dir);
				}
				return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_VERIFY_FAILED'];
			}

			if (is_dir($backup))
			{
				$this->remove_validation_temp_directory($backup);
			}

			return ['ok' => true];
		}
		catch (\Throwable $e)
		{
			return ['ok' => false, 'lang' => 'WSP_EPV_INSTALL_EXCEPTION', 'lang_params' => [$e->getMessage()]];
		}
		finally
		{
			if (is_dir($stage))
			{
				$this->remove_validation_temp_directory($stage);
			}
		}
	}

	private function truncate_epv_setup_output($text)
	{
		$text = trim((string) $text);
		if ($text === '')
		{
			return $this->user->lang('WSP_EPV_INSTALL_NO_OUTPUT');
		}
		if (function_exists('mb_substr'))
		{
			$length = mb_strlen($text, 'UTF-8');
			return mb_substr($text, max(0, $length - 1600), 1600, 'UTF-8');
		}
		return substr($text, -1600);
	}

	private function download_official_epv_archive($destination)
	{
		$url = 'https://github.com/phpbb/epv/archive/refs/heads/master.zip';
		$error = '';
		if (function_exists('curl_init'))
		{
			$handle = @fopen($destination, 'wb');
			if (!$handle)
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_INSTALL_WRITE_FAILED')];
			}
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_FILE => $handle,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CONNECTTIMEOUT => 20,
				CURLOPT_TIMEOUT => 120,
				CURLOPT_USERAGENT => 'phpBB Workspace EPV Installer',
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			]);
			$ok = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = (string) curl_error($ch);
			curl_close($ch);
			fclose($handle);
			if (!$ok || $code < 200 || $code >= 300)
			{
				@unlink($destination);
				return ['ok' => false, 'error' => $error !== '' ? $error : $this->user->lang('WSP_EPV_INSTALL_HTTP_ERROR', $code)];
			}
		}
		else
		{
			$context = stream_context_create(['http' => [
				'timeout' => 120,
				'follow_location' => 1,
				'max_redirects' => 5,
				'user_agent' => 'phpBB Workspace EPV Installer',
			], 'ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
			]]);
			$data = @file_get_contents($url, false, $context);
			if ($data === false || @file_put_contents($destination, $data) === false)
			{
				@unlink($destination);
				return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_INSTALL_NETWORK_FAILED')];
			}
		}

		$size = is_file($destination) ? (int) filesize($destination) : 0;
		if ($size < 1000 || $size > 25 * 1024 * 1024)
		{
			@unlink($destination);
			return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_INSTALL_ARCHIVE_SIZE')];
		}

		return ['ok' => true, 'error' => ''];
	}

	private function extract_official_epv_archive($archive, $destination)
	{
		$zip = new \ZipArchive();
		if ($zip->open($archive) !== true)
		{
			return '';
		}
		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			$name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
			if ($name === '' || strpos($name, "\0") !== false || strpos($name, '/') === 0 || preg_match('#^[A-Za-z]:#', $name) || preg_match('#(^|/)\.\.(/|$)#', $name))
			{
				$zip->close();
				return '';
			}
		}
		if (!$zip->extractTo($destination))
		{
			$zip->close();
			return '';
		}
		$zip->close();

		$items = @scandir($destination);
		if (!is_array($items))
		{
			return '';
		}
		foreach ($items as $item)
		{
			if ($item === '.' || $item === '..') { continue; }
			$dir = $destination . DIRECTORY_SEPARATOR . $item;
			if (is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'composer.json') && is_file($dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'EPV.php'))
			{
				return $dir;
			}
		}
		return '';
	}

	/**
	 * Runs the official phpBB Extension Pre Validator against a temporary copy of
	 * the virtual Workspace project. EPV is discovered locally and can be
	 * installed from the official repository by an extension administrator.
	 */
	private function run_official_epv(array $files, $scope)
	{
		$required = in_array((string) $scope, ['phpbb_ext_db', 'full'], true);
		$setup = $this->get_epv_setup_status();
		$result = [
			'required' => $required,
			'available' => false,
			'can_install' => (bool) $setup['can_install'],
			'managed' => (bool) $setup['managed'],
			'setup' => $setup,
			'ran' => false,
			'status' => $required ? 'unavailable' : 'not-run',
			'summary' => $required ? $this->user->lang('WSP_EPV_SUMMARY_NOT_VERIFIED') : $this->user->lang('WSP_EPV_SUMMARY_NOT_REQUIRED'),
			'counts' => ['fatal' => 0, 'errors' => 0, 'warnings' => 0, 'notices' => 0],
			'messages' => [],
			'raw_output' => '',
			'version' => '',
			'install_command' => 'composer require phpbb/epv:dev-master --dev --no-interaction --ignore-platform-reqs',
			'install_location' => 'phpBB/tools/epv',
		];

		if (!$required)
		{
			return $result;
		}

		$epv = $this->find_official_epv();
		if (!$epv && !empty($setup['auto_ready']))
		{
			$auto = $this->install_official_epv_package();
			$setup = $this->get_epv_setup_status();
			$result['setup'] = $setup;
			$result['can_install'] = (bool) $setup['can_install'];
			$result['managed'] = (bool) $setup['managed'];
			if (!empty($auto['ok']))
			{
				$epv = $this->find_official_epv();
			}
		}
		if (!$epv)
		{
			$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_NOT_INSTALLED');
			return $result;
		}
		$result['available'] = true;
		$result['version'] = (string) ($epv['version'] ?? '');

		if (!function_exists('proc_open'))
		{
			$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_PROC_OPEN_DISABLED');
			return $result;
		}

		$php_binary = $this->find_php_cli_binary();
		if ($php_binary === '')
		{
			$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_PHP_CLI_MISSING');
			return $result;
		}

		$tmp_dir = $this->create_validation_temp_directory();
		if ($tmp_dir === '')
		{
			$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_TEMP_FAILED');
			return $result;
		}

		try
		{
			// Titania/EPV require composer.json at <dir>/<vendor>/<package>/composer.json
			// where vendor/package matches composer.json "name". --dir must be the parent.
			$materialize_dir = $tmp_dir;
			$composer_data = isset($files['composer.json']) ? json_decode((string) $files['composer.json'], true) : null;
			if (is_array($composer_data) && !empty($composer_data['name']) && preg_match('#^([a-z0-9_-]+)/([a-z0-9_-]+)$#', strtolower((string) $composer_data['name']), $package_match))
			{
				$materialize_dir = $tmp_dir . DIRECTORY_SEPARATOR . $package_match[1] . DIRECTORY_SEPARATOR . $package_match[2];
				if (!is_dir($materialize_dir) && !@mkdir($materialize_dir, 0700, true) && !is_dir($materialize_dir))
				{
					$result['status'] = 'fail';
					$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_TEMP_FAILED');
					return $result;
				}
			}

			$materialized = $this->materialize_validation_files($files, $materialize_dir);
			if (!$materialized['ok'])
			{
				$result['status'] = 'fail';
				$result['summary'] = (string) $materialized['error'];
				return $result;
			}

			$execution = $this->execute_validation_process([
				$php_binary,
				(string) $epv['script'],
				'--no-ansi',
				'--no-interaction',
				'run',
				'--dir=' . $tmp_dir,
			], $tmp_dir, 180);

			if (empty($execution['started']))
			{
				$result['status'] = 'fail';
				$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_PROCESS_START_FAILED', (string) $execution['error']);
				return $result;
			}

			$result['ran'] = true;
			$stdout = (string) $execution['stdout'];
			$stderr = (string) $execution['stderr'];
			$raw = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
			$parsed = $this->parse_official_epv_output($raw);
			$result['counts'] = $parsed['counts'];
			$result['messages'] = $parsed['messages'];
			$result['raw_output'] = function_exists('mb_substr') ? mb_substr($parsed['raw_output'], 0, 40000) : substr($parsed['raw_output'], 0, 40000);

			$blocking = (int) $parsed['counts']['fatal'] + (int) $parsed['counts']['errors'];
			$exit_code = (int) $execution['exit_code'];
			if (!empty($execution['timed_out']))
			{
				$result['status'] = 'fail';
				$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_TIMEOUT', 180);
			}
			else if (!empty($parsed['engine_error']))
			{
				$result['status'] = 'fail';
				$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_INTERNAL_ERROR');
			}
			else if (!empty($parsed['summary_found']) && $blocking === 0 && $exit_code === 0)
			{
				$result['status'] = 'pass';
				$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_PASS');
			}
			else
			{
				$result['status'] = 'fail';
				$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_COUNTS', (int) $parsed['counts']['fatal'], (int) $parsed['counts']['errors'], (int) $parsed['counts']['warnings'], (int) $parsed['counts']['notices']);
				if (empty($parsed['summary_found']))
				{
					$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_MISSING');
				}
				else if ($blocking === 0 && $exit_code !== 0)
				{
					$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_EXIT_CODE', $exit_code);
				}
			}
		}
		catch (\Throwable $e)
		{
			$result['ran'] = true;
			$result['status'] = 'fail';
			$result['summary'] = $this->user->lang('WSP_EPV_SUMMARY_EXCEPTION', $e->getMessage());
		}
		finally
		{
			$this->remove_validation_temp_directory($tmp_dir);
		}

		return $result;
	}


	/**
	 * Locates a PHP CLI executable without relying on optional Symfony Process.
	 */
	private function find_php_cli_binary()
	{
		$candidates = [];
		if (defined('PHPBB_PHP_CLI') && trim((string) constant('PHPBB_PHP_CLI')) !== '')
		{
			$candidates[] = trim((string) constant('PHPBB_PHP_CLI'));
		}

		$binary_name = (DIRECTORY_SEPARATOR === '\\') ? 'php.exe' : 'php';
		if (defined('PHP_BINDIR') && PHP_BINDIR !== '')
		{
			$candidates[] = rtrim((string) PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . $binary_name;
		}
		if (defined('PHP_BINARY') && PHP_BINARY !== '')
		{
			$php_binary = (string) PHP_BINARY;
			$base_name = strtolower(basename($php_binary));
			if ($base_name === 'php' || $base_name === 'php.exe')
			{
				$candidates[] = $php_binary;
			}
			$candidates[] = dirname($php_binary) . DIRECTORY_SEPARATOR . $binary_name;
		}

		$candidates = array_merge($candidates, [
			'/usr/bin/php',
			'/usr/local/bin/php',
			'/bin/php',
		]);

		foreach (array_unique($candidates) as $candidate)
		{
			if (is_file($candidate) && is_executable($candidate))
			{
				return $candidate;
			}
			if (is_file($candidate))
			{
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Executes EPV without a shell. The command-array form of proc_open() is
	 * available on PHP 7.4+, which is also the minimum supported by Workspace.
	 */

	/**
	 * Reads an original process environment value without getenv(), which the
	 * official EPV rejects for extension code.
	 */
	private function read_process_environment_value($name)
	{
		if (!function_exists('filter_input') || !defined('INPUT_ENV'))
		{
			return null;
		}

		$value = filter_input(INPUT_ENV, (string) $name, FILTER_UNSAFE_RAW);
		return is_string($value) ? $value : null;
	}

	private function execute_validation_process(array $command, $cwd, $timeout, array $environment = [])
	{
		$result = [
			'started' => false,
			'timed_out' => false,
			'exit_code' => -1,
			'stdout' => '',
			'stderr' => '',
			'error' => '',
		];

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$options = (DIRECTORY_SEPARATOR === '\\') ? ['bypass_shell' => true] : [];

		// Keep the web server's complete environment and temporarily add only
		// the variables required by the child process. This is important on
		// shared hosting/FPM where HOME is often intentionally unset. Passing a
		// partial environment array to proc_open() would also discard PATH, proxy
		// and CA certificate variables, so inheritance is preserved instead.
		$previous_environment = [];
		foreach ($environment as $name => $value)
		{
			$name = (string) $name;
			if ($name === '' || preg_match('/[^A-Za-z0-9_]/', $name))
			{
				continue;
			}
			$previous_environment[$name] = $this->read_process_environment_value($name);
			@putenv($name . '=' . (string) $value);
		}

		$process = @proc_open($command, $descriptors, $pipes, (string) $cwd, null, $options);

		foreach ($previous_environment as $name => $value)
		{
			if ($value === null)
			{
				@putenv($name);
			}
			else
			{
				@putenv($name . '=' . (string) $value);
			}
		}

		if (!is_resource($process))
		{
			$result['error'] = 'proc_open';
			return $result;
		}

		$result['started'] = true;
		@fclose($pipes[0]);
		@stream_set_blocking($pipes[1], false);
		@stream_set_blocking($pipes[2], false);
		$started_at = microtime(true);
		$last_status = null;

		while (true)
		{
			$result['stdout'] .= (string) @stream_get_contents($pipes[1]);
			$result['stderr'] .= (string) @stream_get_contents($pipes[2]);
			$status = @proc_get_status($process);
			if (!is_array($status))
			{
				break;
			}
			$last_status = $status;
			if (empty($status['running']))
			{
				break;
			}
			if ((microtime(true) - $started_at) >= (float) $timeout)
			{
				$result['timed_out'] = true;
				@proc_terminate($process);
				break;
			}
			usleep(50000);
		}

		$result['stdout'] .= (string) @stream_get_contents($pipes[1]);
		$result['stderr'] .= (string) @stream_get_contents($pipes[2]);
		@fclose($pipes[1]);
		@fclose($pipes[2]);
		$close_code = @proc_close($process);
		if (is_array($last_status) && isset($last_status['exitcode']) && (int) $last_status['exitcode'] >= 0)
		{
			$result['exit_code'] = (int) $last_status['exitcode'];
		}
		else
		{
			$result['exit_code'] = (int) $close_code;
		}

		return $result;
	}

	private function find_official_epv()
	{
		$root = realpath($this->phpbb_root_path);
		if ($root === false)
		{
			$root = rtrim((string) $this->phpbb_root_path, '/\\');
		}
		$root = rtrim((string) $root, '/\\');
		$candidates = [];
		if (defined('PHPBB_EPV_PATH') && trim((string) constant('PHPBB_EPV_PATH')) !== '')
		{
			$candidates[] = trim((string) constant('PHPBB_EPV_PATH'));
		}
		$candidates = array_merge($candidates, [
			$root . '/tools/epv/vendor/bin/EPV.php',
			$root . '/tools/epv/vendor/phpbb/epv/src/EPV.php',
			$root . '/vendor/bin/EPV.php',
			$root . '/vendor/phpbb/epv/src/EPV.php',
			$root . '/tools/epv/src/EPV.php',
			$root . '/epv/src/EPV.php',
		]);

		foreach ($candidates as $candidate)
		{
			$candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $candidate);
			if (!is_file($candidate))
			{
				continue;
			}

			$version = '';
			$composer_candidates = [
				$root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpbb' . DIRECTORY_SEPARATOR . 'epv' . DIRECTORY_SEPARATOR . 'composer.json',
				$root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'epv' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpbb' . DIRECTORY_SEPARATOR . 'epv' . DIRECTORY_SEPARATOR . 'composer.json',
				dirname($candidate, 2) . DIRECTORY_SEPARATOR . 'composer.json',
			];
			foreach ($composer_candidates as $composer_file)
			{
				if (!is_file($composer_file)) { continue; }
				$json = json_decode((string) @file_get_contents($composer_file), true);
				if (is_array($json))
				{
					$version = !empty($json['version']) ? (string) $json['version'] : (!empty($json['name']) ? 'local' : '');
				}
				break;
			}
			return ['script' => $candidate, 'version' => $version];
		}

		return null;
	}

	private function create_validation_temp_directory()
	{
		$base = rtrim(sys_get_temp_dir(), '/\\');
		try
		{
			$suffix = bin2hex(random_bytes(8));
		}
		catch (\Throwable $e)
		{
			$suffix = str_replace('.', '', uniqid('', true));
		}
		$dir = $base . DIRECTORY_SEPARATOR . 'phpbb_workspace_epv_' . $suffix;
		return @mkdir($dir, 0700, true) ? $dir : '';
	}

	private function materialize_validation_files(array $files, $directory)
	{
		$base = rtrim((string) $directory, '/\\');
		foreach ($files as $name => $content)
		{
			$name = str_replace('\\', '/', (string) $name);
			$name = ltrim($name, '/');
			if ($name === '' || strpos($name, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[A-Za-z]:#', $name))
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_TEMP_UNSAFE_PATH', $name)];
			}
			$target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
			$parent = dirname($target);
			if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent))
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_TEMP_DIR_FAILED')];
			}
			if (@file_put_contents($target, (string) $content) === false)
			{
				return ['ok' => false, 'error' => $this->user->lang('WSP_EPV_TEMP_FILE_FAILED', $name)];
			}
		}
		return ['ok' => true, 'error' => ''];
	}

	private function remove_validation_temp_directory($directory)
	{
		$directory = (string) $directory;
		if ($directory === '' || !is_dir($directory)) { return; }
		$items = @scandir($directory);
		if (!is_array($items)) { return; }
		foreach ($items as $item)
		{
			if ($item === '.' || $item === '..') { continue; }
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path) && !is_link($path)) { $this->remove_validation_temp_directory($path); }
			else { @unlink($path); }
		}
		@rmdir($directory);
	}

	private function parse_official_epv_output($raw)
	{
		$raw = preg_replace('/\x1B(?:[@-Z\-_]|\[[0-?]*[ -\/]*[@-~])/', '', (string) $raw);
		$counts = ['fatal' => 0, 'errors' => 0, 'warnings' => 0, 'notices' => 0];
		$summary_found = false;
		$engine_error = false;
		if (preg_match('/Fatal:\s*(\d+)\s*,\s*Error:\s*(\d+)\s*,\s*Warning:\s*(\d+)\s*,\s*Notice:\s*(\d+)/i', $raw, $m))
		{
			$counts = ['fatal' => (int) $m[1], 'errors' => (int) $m[2], 'warnings' => (int) $m[3], 'notices' => (int) $m[4]];
			$summary_found = true;
		}

		$messages = [];
		foreach (preg_split('/\r\n|\r|\n/', $raw) as $line)
		{
			$line = trim(strip_tags((string) $line));
			if ($line === '') { continue; }

			if (preg_match('/^(?:PHP\s+)?(?:Fatal error|Uncaught Error|Uncaught Exception):\s*(.+)$/i', $line, $m))
			{
				$engine_error = true;
				$messages[] = ['severity' => 'error', 'message' => (string) $m[1]];
				continue;
			}

			if (preg_match('/^(Error|Warning|Notice):\s*(.+)$/i', $line, $m))
			{
				$kind = strtolower((string) $m[1]);
				$severity = ($kind === 'error') ? 'error' : (($kind === 'warning') ? 'warning' : 'info');
				$messages[] = ['severity' => $severity, 'message' => (string) $m[2]];
			}
		}
		return ['counts' => $counts, 'messages' => $messages, 'raw_output' => trim($raw), 'summary_found' => $summary_found, 'engine_error' => $engine_error];
	}

	/**
	 * Parse the subset of services.yml needed by the preflight validator.
	 *
	 * The parser is intentionally conservative: phpBB extension service files use a
	 * predictable indentation layout, and avoiding a YAML runtime dependency keeps
	 * the validator compatible with the phpBB 3.3 container.
	 */
	private function validation_parse_services($content)
	{
		$services = [];
		$current = '';
		$in_arguments = false;
		$lines = preg_split('/\r\n|\r|\n/', (string) $content);

		foreach ($lines as $line)
		{
			if (preg_match('/^ {4}([A-Za-z0-9_.-]+):\s*$/', $line, $m))
			{
				$current = (string) $m[1];
				$in_arguments = false;
				if ($current === '' || $current[0] === '_')
				{
					$current = '';
					continue;
				}
				if (!isset($services[$current]))
				{
					$services[$current] = [
						'class' => '',
						'arguments' => [],
						'argument_count' => 0,
					];
				}
				continue;
			}

			if ($current === '')
			{
				continue;
			}

			if (preg_match('/^ {8}class:\s*(.+?)\s*$/', $line, $m))
			{
				$services[$current]['class'] = trim((string) $m[1], " \t\n\r\0\x0B\"'");
				$in_arguments = false;
				continue;
			}

			if (preg_match('/^ {8}arguments:\s*(.*)$/', $line, $m))
			{
				$in_arguments = true;
				$inline = trim((string) $m[1]);
				if ($inline !== '' && $inline !== '[]')
				{
					if ($inline[0] === '[' && substr($inline, -1) === ']')
					{
						$inside = trim(substr($inline, 1, -1));
						if ($inside !== '')
						{
							$parts = preg_split('/\s*,\s*/', $inside);
							$services[$current]['argument_count'] += count($parts);
						}
					}
					preg_match_all('/@\??([A-Za-z0-9_.-]+)/', $inline, $refs);
					foreach ($refs[1] as $ref)
					{
						$services[$current]['arguments'][] = (string) $ref;
					}
				}
				continue;
			}

			if ($in_arguments && preg_match('/^ {8}([A-Za-z0-9_.-]+):/', $line, $m) && strtolower((string) $m[1]) !== 'arguments')
			{
				$in_arguments = false;
			}

			if ($in_arguments && preg_match('/^ {12}-\s*(.+?)\s*$/', $line, $m))
			{
				$services[$current]['argument_count']++;
				preg_match_all('/@\??([A-Za-z0-9_.-]+)/', (string) $m[1], $refs);
				foreach ($refs[1] as $ref)
				{
					$services[$current]['arguments'][] = (string) $ref;
				}
				continue;
			}

			if (preg_match('/^ {0,4}\S/', $line))
			{
				$current = '';
				$in_arguments = false;
			}
		}

		return $services;
	}

	/**
	 * Checks whether a service ID is declared by the installed phpBB core YAML.
	 *
	 * Compiled Symfony containers may hide/in-line private services, so
	 * ContainerInterface::has() alone is not a reliable validation signal.
	 *
	 * @param string $service_id
	 * @return bool|null True/false when the core config could be inspected, null otherwise.
	 */
	private function validation_core_service_declared($service_id)
	{
		$service_id = trim((string) $service_id);
		if ($service_id === '' || $this->phpbb_root_path === '')
		{
			return null;
		}

		static $cache = [];
		$root = rtrim((string) $this->phpbb_root_path, '/\\') . DIRECTORY_SEPARATOR . 'config';
		if (array_key_exists($root, $cache))
		{
			return isset($cache[$root][$service_id]);
		}

		$cache[$root] = [];
		if (!is_dir($root) || !is_readable($root))
		{
			unset($cache[$root]);
			return null;
		}

		try
		{
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file)
			{
				if (!$file->isFile() || !preg_match('/\.ya?ml$/i', $file->getFilename()))
				{
					continue;
				}
				$content = @file_get_contents($file->getPathname());
				if ($content === false)
				{
					continue;
				}
				if (preg_match_all('/^[ \t]*[\'"]?([A-Za-z0-9_.-]+)[\'"]?\s*:/m', $content, $matches))
				{
					foreach ($matches[1] as $id)
					{
						$cache[$root][(string) $id] = true;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			unset($cache[$root]);
			return null;
		}

		return isset($cache[$root][$service_id]);
	}

	/**
	 * Detects a real global PHP function call using the tokenizer.
	 * Comments and string literals are intentionally ignored.
	 */
	private function validation_php_has_function_call($content, $function_name)
	{
		$function_name = strtolower((string) $function_name);
		if ($function_name === '')
		{
			return false;
		}

		try
		{
			$content = $this->validation_php_tag_probe_content((string) $content);
			if (strpos($content, '<?php') === false)
			{
				$content = "<?php\n" . $content;
			}
			$tokens = token_get_all($content);
		}
		catch (\Throwable $e)
		{
			return false;
		}

		$count = count($tokens);
		for ($i = 0; $i < $count; $i++)
		{
			$token = $tokens[$i];
			if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== $function_name)
			{
				continue;
			}

			$prev = null;
			for ($j = $i - 1; $j >= 0; $j--)
			{
				if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
				{
					continue;
				}
				$prev = $tokens[$j];
				break;
			}
			if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true))
			{
				continue;
			}

			for ($j = $i + 1; $j < $count; $j++)
			{
				if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
				{
					continue;
				}
				return $tokens[$j] === '(';
			}
		}

		return false;
	}

	private function validation_runtime_has_service($service_id)
	{
		global $phpbb_container;

		if (!is_object($phpbb_container) || !method_exists($phpbb_container, 'has'))
		{
			return null;
		}

		try
		{
			return (bool) $phpbb_container->has((string) $service_id);
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}

	/**
	 * Finds the project file that actually declares the requested class,
	 * interface or trait. This is a fallback for virtual projects whose stored
	 * path metadata may differ slightly from the expected PSR-4 path.
	 */
	private function validation_find_declared_class_file(array $files, $class_name)
	{
		$class_name = trim((string) $class_name, " \t\n\r\0\x0B\\");
		if ($class_name === '' || strpos($class_name, '\\') === false)
		{
			return '';
		}

		$last_separator = strrpos($class_name, '\\');
		$expected_namespace = substr($class_name, 0, $last_separator);
		$expected_short_name = substr($class_name, $last_separator + 1);
		foreach ($files as $name => $content)
		{
			$normalized_name = $this->validation_normalize_project_path((string) $name);
			$php_content = $this->validation_php_tag_probe_content((string) $content);
			$is_php_path = (bool) preg_match('#\.php$#i', $normalized_name);
			$is_php_content = (strpos($php_content, '<?php') !== false);
			if (!$is_php_path && !$is_php_content)
			{
				continue;
			}

			try
			{
				if (!$is_php_content)
				{
					$php_content = "<?php\n" . $php_content;
				}
				$tokens = token_get_all($php_content);
			}
			catch (\Throwable $e)
			{
				continue;
			}

			$namespace = '';
			$count = count($tokens);
			for ($i = 0; $i < $count; $i++)
			{
				$token = $tokens[$i];
				if (is_array($token) && $token[0] === T_NAMESPACE)
				{
					$namespace = '';
					for ($j = $i + 1; $j < $count; $j++)
					{
						$part = $tokens[$j];
						if ($part === ';' || $part === '{')
						{
							break;
						}
						if (is_array($part) && in_array($part[0], [T_STRING, T_NS_SEPARATOR], true))
						{
							$namespace .= $part[1];
						}
						else if (defined('T_NAME_QUALIFIED') && is_array($part) && $part[0] === T_NAME_QUALIFIED)
						{
							$namespace .= $part[1];
						}
					}
					continue;
				}

				if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true))
				{
					continue;
				}

				for ($j = $i + 1; $j < $count; $j++)
				{
					$next = $tokens[$j];
					if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
					{
						continue;
					}
					if (is_array($next) && $next[0] === T_STRING
						&& strcasecmp($namespace, $expected_namespace) === 0
						&& strcasecmp($next[1], $expected_short_name) === 0)
					{
						return (string) $name;
					}
					break;
				}
			}
		}

		return '';
	}


	/**
	 * Resolves a project file path defensively.
	 *
	 * Older imports can keep the package prefix (vendor/extension/) or a
	 * leading slash in file_name. Validation should still recognise the same
	 * physical extension-relative file without weakening class checks.
	 */
	private function validation_resolve_project_file_path(array $files, $relative_path)
	{
		$target = strtolower($this->validation_normalize_project_path((string) $relative_path));
		if ($target === '')
		{
			return '';
		}

		$matches = [];
		foreach ($files as $name => $unused_content)
		{
			$normalized = strtolower($this->validation_normalize_project_path((string) $name));
			if ($normalized === '')
			{
				continue;
			}
			if ($normalized === $target)
			{
				return (string) $name;
			}
			if (strlen($normalized) > strlen($target) && substr($normalized, -strlen('/' . $target)) === '/' . $target)
			{
				$matches[] = (string) $name;
			}
		}

		return count($matches) === 1 ? $matches[0] : '';
	}

	private function validation_class_relative_path($class_name, $expected_namespace)
	{
		$class_name = trim((string) $class_name, " \t\n\r\0\x0B\\");
		$expected_namespace = trim((string) $expected_namespace, " \t\n\r\0\x0B\\");
		if ($class_name === '' || $expected_namespace === '')
		{
			return '';
		}

		$prefix = $expected_namespace . '\\';
		if (strpos($class_name, $prefix) !== 0)
		{
			return '';
		}

		return str_replace('\\', '/', substr($class_name, strlen($prefix))) . '.php';
	}

	private function validation_constructor_parameter_stats($content)
	{
		$content = (string) $content;
		if (!preg_match('/\bfunction\s+__construct\s*\(/', $content, $m, PREG_OFFSET_CAPTURE))
		{
			return null;
		}

		$start = (int) $m[0][1] + strlen((string) $m[0][0]) - 1;
		$length = strlen($content);
		$depth = 0;
		$quote = '';
		$escaped = false;
		$buffer = '';

		for ($i = $start; $i < $length; $i++)
		{
			$char = $content[$i];
			if ($quote !== '')
			{
				if ($escaped) { $escaped = false; }
				else if ($char === '\\') { $escaped = true; }
				else if ($char === $quote) { $quote = ''; }
				if ($depth > 0) { $buffer .= $char; }
				continue;
			}
			if ($char === "'" || $char === '"')
			{
				$quote = $char;
				if ($depth > 0) { $buffer .= $char; }
				continue;
			}
			if ($char === '(')
			{
				$depth++;
				if ($depth > 1) { $buffer .= $char; }
				continue;
			}
			if ($char === ')')
			{
				$depth--;
				if ($depth === 0) { break; }
				$buffer .= $char;
				continue;
			}
			if ($depth > 0) { $buffer .= $char; }
		}

		$buffer = trim($buffer);
		if ($buffer === '')
		{
			return ['required' => 0, 'total' => 0];
		}

		$parts = [];
		$current = '';
		$round = 0; $square = 0; $curly = 0; $quote = ''; $escaped = false;
		$buffer_length = strlen($buffer);
		for ($i = 0; $i < $buffer_length; $i++)
		{
			$char = $buffer[$i];
			if ($quote !== '')
			{
				$current .= $char;
				if ($escaped) { $escaped = false; }
				else if ($char === '\\') { $escaped = true; }
				else if ($char === $quote) { $quote = ''; }
				continue;
			}
			if ($char === "'" || $char === '"') { $quote = $char; $current .= $char; continue; }
			if ($char === '(') { $round++; $current .= $char; continue; }
			if ($char === ')') { $round--; $current .= $char; continue; }
			if ($char === '[') { $square++; $current .= $char; continue; }
			if ($char === ']') { $square--; $current .= $char; continue; }
			if ($char === '{') { $curly++; $current .= $char; continue; }
			if ($char === '}') { $curly--; $current .= $char; continue; }
			if ($char === ',' && $round === 0 && $square === 0 && $curly === 0)
			{
				$parts[] = trim($current);
				$current = '';
				continue;
			}
			$current .= $char;
		}
		if (trim($current) !== '') { $parts[] = trim($current); }

		$required = 0;
		foreach ($parts as $part)
		{
			if (strpos($part, '=') === false && strpos($part, '...$') === false)
			{
				$required++;
			}
		}
		return ['required' => $required, 'total' => count($parts)];
	}

	private function validation_constructor_parameter_count($content)
	{
		$stats = $this->validation_constructor_parameter_stats($content);
		return $stats === null ? null : (int) $stats['total'];
	}

	private function validation_resolve_constructor_parameter_stats($relative_class, array $files)
	{
		$relative_class = (string) $relative_class;
		if ($relative_class === '' || !isset($files[$relative_class]))
		{
			return null;
		}
		$stats = $this->validation_constructor_parameter_stats($files[$relative_class]);
		if ($stats !== null)
		{
			return $stats;
		}

		if (preg_match('/\bextends\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $files[$relative_class], $m))
		{
			$dir = dirname($relative_class);
			$parent = ($dir === '.' ? '' : $dir . '/') . (string) $m[1] . '.php';
			if (isset($files[$parent]))
			{
				return $this->validation_constructor_parameter_stats($files[$parent]);
			}
		}
		return null;
	}

	private function validation_resolve_constructor_parameter_count($relative_class, array $files)
	{
		$stats = $this->validation_resolve_constructor_parameter_stats($relative_class, $files);
		return $stats === null ? null : (int) $stats['total'];
	}

	private function validation_class_has_method($relative_class, $method_name, array $files, $depth = 0)
	{
		if ($depth > 4 || $relative_class === '' || !isset($files[$relative_class]))
		{
			return false;
		}
		$content = (string) $files[$relative_class];
		if (preg_match('/\bfunction\s+' . preg_quote((string) $method_name, '/') . '\s*\(/', $content))
		{
			return true;
		}
		if (preg_match('/\bextends\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $content, $m))
		{
			$dir = dirname($relative_class);
			$parent = ($dir === '.' ? '' : $dir . '/') . (string) $m[1] . '.php';
			return $this->validation_class_has_method($parent, $method_name, $files, $depth + 1);
		}
		return false;
	}

	private function validation_parse_routes($content)
	{
		$routes = [];
		$current = '';
		$lines = preg_split('/\r\n|\r|\n/', (string) $content);

		foreach ($lines as $line)
		{
			if (preg_match('/^([A-Za-z0-9_.-]+):\s*$/', $line, $m))
			{
				$current = (string) $m[1];
				$routes[$current] = [
					'path' => '',
					'controller' => '',
					'methods' => [],
				];
				continue;
			}
			if ($current === '')
			{
				continue;
			}
			if (preg_match('/^ {4}path:\s*(.+?)\s*$/', $line, $m))
			{
				$routes[$current]['path'] = trim((string) $m[1], " \t\n\r\0\x0B\"'");
			}
			if (strpos($line, '_controller:') !== false && preg_match('/_controller:\s*([^}\r\n]+)/', $line, $m))
			{
				$routes[$current]['controller'] = trim((string) $m[1], " \t\n\r\0\x0B\"'");
			}
			if (preg_match('/^ {4}methods:\s*\[([^\]]*)\]/', $line, $m))
			{
				$methods = preg_split('/\s*,\s*/', trim((string) $m[1]));
				$routes[$current]['methods'] = array_values(array_filter(array_map(function ($method) {
					return strtoupper(trim((string) $method, " \t\n\r\0\x0B\"'"));
				}, $methods)));
			}
		}

		return $routes;
	}

	private function validation_language_keys($content)
	{
		$keys = [];
		preg_match_all('/(?:[\'\"]([A-Z][A-Z0-9_]{2,})[\'\"]\s*=>|\$lang\s*\[\s*[\'\"]([A-Z][A-Z0-9_]{2,})[\'\"]\s*\]\s*=)/', (string) $content, $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			$key = !empty($match[1]) ? (string) $match[1] : (!empty($match[2]) ? (string) $match[2] : '');
			if ($key !== '')
			{
				$keys[] = $key;
			}
		}
		return $keys;
	}

	private function validation_language_prefixes(array $keys)
	{
		$counts = [];
		foreach ($keys as $key)
		{
			if (preg_match('/^([A-Z][A-Z0-9]*_)/', (string) $key, $m))
			{
				$prefix = (string) $m[1];
				$counts[$prefix] = isset($counts[$prefix]) ? $counts[$prefix] + 1 : 1;
			}
		}
		arsort($counts);
		$prefixes = [];
		foreach ($counts as $prefix => $count)
		{
			if ($count >= 3)
			{
				$prefixes[] = $prefix;
			}
		}
		return $prefixes;
	}

	private function validation_line_for_offset($content, $offset)
	{
		if ((int) $offset <= 0)
		{
			return 1;
		}
		return substr_count(substr((string) $content, 0, (int) $offset), "\n") + 1;
	}

	private function validation_space_indent_count($content)
	{
		$count = 0;
		foreach (preg_split('/\r\n|\r|\n/', (string) $content) as $line)
		{
			if (preg_match('/^ {4,}\S/', (string) $line))
			{
				$count++;
			}
		}
		return $count;
	}

	private function validation_normalize_php_indentation($content)
	{
		$tokens = token_get_all((string) $content);
		$output = '';
		$at_line_start = true;

		foreach ($tokens as $token)
		{
			$text = is_array($token) ? (string) $token[1] : (string) $token;

			if (is_array($token) && $token[0] === T_WHITESPACE)
			{
				$normalized = '';
				$length = strlen($text);
				for ($i = 0; $i < $length; $i++)
				{
					$char = $text[$i];
					if ($at_line_start && $char === ' ')
					{
						$start = $i;
						while ($i < $length && $text[$i] === ' ')
						{
							$i++;
						}
						$spaces = $i - $start;
						$normalized .= str_repeat("\t", intdiv($spaces, 4)) . str_repeat(' ', $spaces % 4);
						$i--;
						continue;
					}

					$normalized .= $char;
					if ($char === "\n" || $char === "\r")
					{
						$at_line_start = true;
					}
					else if ($char !== "\t" && $char !== ' ')
					{
						$at_line_start = false;
					}
				}
				$text = $normalized;
			}

			$output .= $text;

			if (!(is_array($token) && $token[0] === T_WHITESPACE) && $text !== '')
			{
				$last_lf = strrpos($text, "\n");
				$last_cr = strrpos($text, "\r");
				$last_break = max($last_lf === false ? -1 : $last_lf, $last_cr === false ? -1 : $last_cr);
				if ($last_break >= 0)
				{
					$tail = substr($text, $last_break + 1);
					$at_line_start = ($tail === '' || preg_match('/^[ \t]*$/', $tail));
				}
				else
				{
					$at_line_start = false;
				}
			}
		}

		return $output;
	}

	private function validation_encode_json_with_tabs(array $json)
	{
		$encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false)
		{
			return '';
		}
		$encoded = preg_replace_callback('/^( +)/m', function ($match) {
			$spaces = strlen($match[1]);
			return str_repeat("\t", intdiv($spaces, 4)) . str_repeat(' ', $spaces % 4);
		}, $encoded);
		return rtrim((string) $encoded) . "\n";
	}

	private function validation_normalize_composer_indentation($content)
	{
		$json = json_decode((string) $content, true);
		if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE)
		{
			return (string) $content;
		}
		$encoded = $this->validation_encode_json_with_tabs($json);
		return $encoded !== '' ? $encoded : (string) $content;
	}

	private function build_phpbb_release_validation(array $files, $scope = 'normal')
	{
		$scope = $this->normalize_validation_scope($scope);
		$is_full_scope = ($scope === 'full');
		$is_db_scope = ($scope === 'phpbb_ext_db');
		$issues = [];
		$checklist = [];

		$add = function ($severity, $category, $message, $file = '', $action = '', $example = '', $rule = '', $line = 0, $excerpt = '') use (&$issues) {
			$fixable_rules = ['php-closing-tag', 'php-open-tag-leading-whitespace', 'composer-type', 'composer-display-name', 'utf8-bom', 'line-ending-crlf', 'line-ending-cr'];
			$issues[] = [
				'severity' => (string) $severity,
				'category' => (string) $category,
				'message' => (string) $message,
				'file' => (string) $file,
				'action' => (string) $action,
				'example' => (string) $example,
				'rule' => (string) $rule,
				'line' => (int) $line,
				'excerpt' => (string) $excerpt,
				'fixable' => in_array((string) $rule, $fixable_rules, true),
			];
		};
		$check = function ($key, $label, $status, $detail = '') use (&$checklist) {
			$checklist[] = [
				'key' => (string) $key,
				'label' => (string) $label,
				'status' => (string) $status,
				'detail' => (string) $detail,
			];
		};
		$exists = function ($name) use ($files) {
			return array_key_exists((string) $name, $files);
		};
		$contains_file = function ($pattern) use ($files) {
			foreach ($files as $name => $content)
			{
				if (preg_match($pattern, $name)) { return true; }
			}
			return false;
		};
		$is_validation_vendor_area = function ($name) {
			$name = strtolower(str_replace('\\', '/', (string) $name));
			return (bool) preg_match('#^(vendor/|node_modules/|lib/diff(?:/|\.php$)|styles/[^/]+/template/ace/|styles/[^/]+/theme/vendor/|assets/vendor/)#', $name);
		};
		$is_scope_ignored_area = function ($name) use ($is_validation_vendor_area, $is_full_scope) {
			return (!$is_full_scope && $is_validation_vendor_area($name));
		};
		$scope_label = ($scope === 'full') ? 'Validação completa' : (($scope === 'phpbb_ext_db') ? 'phpBB Extension DB' : 'Validação normal');
		$check('validation_scope', 'Escopo da validação', 'pass', $scope_label);
		$check('validation_languages', 'Linguagens validadas', 'pass', 'PHP, JavaScript, HTML/Twig e CSS. Arquivos de configuração phpBB essenciais continuam com verificações estruturais.');
		$is_phpbb_include_area = function ($name) {
			$name = strtolower(str_replace('\\', '/', (string) $name));
			return (bool) preg_match('#^(language/|config/|adm/style/|styles/|docs?/|tests?/fixtures/)#', $name);
		};
		$is_extension_class_area = function ($name) {
			$name = strtolower(str_replace('\\', '/', (string) $name));
			return (bool) preg_match('#^(controller/|event/|service/|repository/|migrations/|acp/|cron/|notification/|auth/)#', $name);
		};
		$is_binary_asset = function ($name, $content) {
			$name = strtolower(str_replace('\\', '/', (string) $name));
			if (preg_match('#\.(png|jpe?g|gif|webp|ico|bmp|zip|tar|gz|bz2|7z|rar|pdf|woff2?|ttf|eot|otf|mp3|mp4|mov|avi|webm|ogg)$#', $name))
			{
				return true;
			}
			return (strpos((string) $content, "\0") !== false);
		};
		$is_supported_validation_source = function ($name) {
			$name = strtolower(str_replace('\\', '/', (string) $name));

			// Official validator scope: PHP, JavaScript, HTML/Twig templates and CSS.
			// Other Ace-supported languages remain editable, but are intentionally not validated.
			return (bool) preg_match('#\.(php|js|css|html|htm|twig)$#', $name);
		};
		$is_supported_config_file = function ($name) {
			$name = strtolower(str_replace('\\', '/', (string) $name));

			// Keep structural phpBB release checks for required configuration files,
			// without treating every possible editor language as a validation target.
			return ($name === 'composer.json' || preg_match('#^config/.+\.ya?ml$#', $name));
		};
		$should_validate_encoding = function ($name, $content) use ($is_binary_asset, $is_scope_ignored_area, $is_full_scope, $is_db_scope, $is_supported_validation_source, $is_supported_config_file) {
			if ($is_binary_asset($name, $content) || $is_scope_ignored_area($name))
			{
				return false;
			}
			if ($is_supported_validation_source($name) === false && $is_supported_config_file($name) === false)
			{
				return false;
			}
			// UTF-8 and UNIX line ending checks are most important for Extension DB/full audits.
			return ($is_db_scope || $is_full_scope);
		};

		$expected_vendor = '';
		$expected_extension = '';
		$expected_namespace = '';
		$composer = $exists('composer.json') ? $files['composer.json'] : '';
		if ($composer !== '')
		{
			$json = json_decode($composer, true);
			if (is_array($json))
			{
				$check('composer_json', 'composer.json válido', 'pass', 'JSON parseado com sucesso.');
				if (empty($json['name']) || strpos((string) $json['name'], '/') === false)
				{
					$add('error', 'composer.json', 'O campo "name" deve existir no formato vendor/extension.', 'composer.json', 'Defina um nome Composer canônico para a extensão.', '"name": "mundophpbb/workspace"', 'composer-name');
				}
				else
				{
					$name_parts = explode('/', strtolower((string) $json['name']), 2);
					$expected_vendor = preg_replace('/[^a-z0-9_]/', '_', $name_parts[0]);
					$expected_extension = preg_replace('/[^a-z0-9_]/', '_', $name_parts[1]);
					$expected_namespace = $expected_vendor . '\\' . $expected_extension;
					$check('composer_namespace', 'Namespace base esperado', 'pass', $expected_namespace);
					if (($is_db_scope || $is_full_scope) && !preg_match('#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#', strtolower((string) $json['name'])))
					{
						$add('error', 'composer.json', 'O name do pacote não segue o formato de diretório aceito para extensão phpBB.', 'composer.json', 'Use somente letras minúsculas, números e underscore em vendor/extension.', '"name": "vendor/extension_name"', 'composer-package-name');
					}
				}
				if (empty($json['type']) || (string) $json['type'] !== 'phpbb-extension')
				{
					$add('warning', 'composer.json', 'Recomendado usar "type": "phpbb-extension".', 'composer.json', 'Ajuste o campo type para instalação correta como extensão phpBB.', '"type": "phpbb-extension"', 'composer-type');
				}
				if (empty($json['license']))
				{
					$add($is_db_scope ? 'error' : 'warning', 'composer.json', 'Informe uma licença para distribuição.', 'composer.json', 'Inclua uma licença compatível com a distribuição pretendida.', '"license": "GPL-2.0-only"', 'composer-license');
				}
				if (empty($json['authors']))
				{
					$add($is_db_scope ? 'error' : 'warning', 'composer.json', 'Informe authors para facilitar publicação/revisão.', 'composer.json', 'Inclua ao menos um autor com nome.', '"authors": [{"name": "Mundo phpBB"}]', 'composer-authors');
				}
				if (empty($json['extra']['display-name']))
				{
					$add('warning', 'composer.json', 'Considere definir extra.display-name para exibição clara no ACP.', 'composer.json', 'Adicione um nome amigável em extra.display-name.', '"extra": {"display-name": "Workspace"}', 'composer-display-name');
				}
				if (($is_db_scope || $is_full_scope) && empty($json['version']))
				{
					$add('warning', 'composer.json', 'O campo version não está definido.', 'composer.json', 'Defina a versão do release para facilitar instalação, atualização e revisão.', '"version": "1.0.0"', 'composer-version');
				}
				if (($is_db_scope || $is_full_scope) && empty($json['extra']['soft-require']['phpbb/phpbb']))
				{
					$add('warning', 'composer.json', 'Compatibilidade com phpBB não está declarada em extra.soft-require.', 'composer.json', 'Declare a faixa de versões do phpBB testada pela extensão.', '"soft-require": {"phpbb/phpbb": ">=3.3.0"}', 'composer-phpbb-compat');
				}
			}
			else
			{
				$check('composer_json', 'composer.json válido', 'error', 'JSON inválido.');
				$add('error', 'composer.json', 'composer.json não pôde ser interpretado como JSON válido.', 'composer.json', 'Corrija a sintaxe JSON antes de tentar instalar/publicar.', 'Use aspas duplas, vírgulas válidas e remova comentários.', 'composer-json');
			}
		}
		else
		{
			$check('composer_json', 'composer.json presente', 'error', 'Arquivo obrigatório ausente.');
			$add('error', 'Estrutura', 'composer.json não encontrado.', 'composer.json', 'Crie o composer.json na raiz da extensão.', '{
  "name": "vendor/extension",
  "type": "phpbb-extension"
}', 'required-file');
		}

		if ($exists('ext.php'))
		{
			$check('ext_php', 'ext.php presente', 'pass', 'Classe principal da extensão encontrada.');
			if (strpos($files['ext.php'], 'class ext') === false)
			{
				$add('warning', 'ext.php', 'ext.php foi encontrado, mas não contém claramente a classe ext.', 'ext.php', 'Confirme se o arquivo declara a classe principal ext.', 'class ext extends \phpbb\extension\base {}', 'ext-class');
			}
			if ($expected_namespace !== '' && strpos($files['ext.php'], 'namespace ' . $expected_namespace . ';') === false)
			{
				$add('error', 'Namespace', 'O namespace de ext.php não bate com o name do composer.json.', 'ext.php', 'Ajuste o namespace ou o composer.json para usarem o mesmo vendor/extension.', 'namespace ' . $expected_namespace . ';', 'namespace-match');
			}
		}
		else
		{
			$check('ext_php', 'ext.php presente', 'error', 'Arquivo obrigatório ausente.');
			$add('error', 'Estrutura', 'ext.php não encontrado.', 'ext.php', 'Crie a classe principal da extensão na raiz.', '<?php
namespace vendor\extension;
class ext extends \phpbb\extension\base {}', 'required-file');
		}

		foreach (['config/services.yml', 'config/routing.yml'] as $cfg)
		{
			if ($exists($cfg))
			{
				$check(str_replace(['/', '.'], '_', $cfg), $cfg . ' presente', 'pass', 'Arquivo encontrado.');
				if (trim($files[$cfg]) === '')
				{
					$add('warning', 'Configuração', $cfg . ' está vazio.', $cfg, 'Remova o arquivo vazio ou preencha com configuração válida.', '', 'empty-config');
				}
			}
			else
			{
				$check(str_replace(['/', '.'], '_', $cfg), $cfg . ' presente', 'warning', 'Ausente; pode ser aceitável em extensões simples.');
			}
		}

		$has_lang_en = $contains_file('#^language/en/.+\.php$#');
		$has_lang_pt = $contains_file('#^language/pt_br/.+\.php$#');
		$check('language_en', 'Idioma inglês', $has_lang_en ? 'pass' : 'warning', $has_lang_en ? 'Arquivos language/en encontrados.' : 'language/en não encontrado.');
		$check('language_pt_br', 'Idioma pt_br', $has_lang_pt ? 'pass' : 'warning', $has_lang_pt ? 'Arquivos language/pt_br encontrados.' : 'language/pt_br não encontrado.');
		if (!$has_lang_en) { $add($is_db_scope ? 'error' : 'warning', 'Idioma', 'Inclua language/en para publicação internacional.', 'language/en', 'Crie ao menos um arquivo de idioma em inglês.', 'language/en/common.php ou language/en/<nome>.php', 'language-en'); }
		if (!$has_lang_pt) { $add('warning', 'Idioma', 'Inclua language/pt_br para a base local.', 'language/pt_br', 'Crie ao menos um arquivo de idioma em pt_br.', 'language/pt_br/common.php ou language/pt_br/<nome>.php', 'language-pt-br'); }

		$has_migration = $contains_file('#^migrations/.+\.php$#');
		$check('migrations', 'Migrations', $has_migration ? 'pass' : 'warning', $has_migration ? 'Migrations encontradas.' : 'Nenhuma migration encontrada.');
		foreach ($files as $name => $content)
		{
			if (preg_match('#^migrations/.+\.php$#', $name))
			{
				if (strpos($content, 'extends') === false || strpos($content, 'migration') === false)
				{
					$add('warning', 'Migration', 'Migration não parece extender a classe base de migration do phpBB.', $name, 'Confirme se a classe herda de \phpbb\db\migration\migration.', 'class v100 extends \phpbb\db\migration\migration', 'migration-base');
				}
			}
			$is_vendor_area = $is_validation_vendor_area($name);
			$is_ignored_by_scope = $is_scope_ignored_area($name);
			$is_include_area = $is_phpbb_include_area($name);
			$is_extension_area = $is_extension_class_area($name);

			if ($should_validate_encoding($name, $content))
			{
				$encoding_severity = $is_db_scope ? 'error' : 'warning';
				if (substr($content, 0, 3) === "\xEF\xBB\xBF")
				{
					$add($encoding_severity, 'Codificação', 'Arquivo com BOM UTF-8; phpBB Extension DB exige UTF-8 sem BOM.', $name, 'Remova o BOM do início do arquivo e mantenha o conteúdo em UTF-8.', 'UTF-8 sem BOM', 'utf8-bom');
				}
				if (@preg_match('//u', $content) !== 1)
				{
					$add($encoding_severity, 'Codificação', 'Arquivo não parece estar em UTF-8 válido.', $name, 'Converta o arquivo para UTF-8 sem BOM antes de publicar.', 'UTF-8 sem BOM', 'utf8-invalid');
				}
				if (strpos($content, "\r\n") !== false)
				{
					$add($encoding_severity, 'Quebra de linha', 'Arquivo usa quebra de linha Windows/CRLF; phpBB Extension DB exige LF/UNIX.', $name, 'Converta as quebras de linha para LF/UNIX.', 'LF/UNIX (\n)', 'line-ending-crlf');
				}
				else if (preg_match('/\r(?!\n)/', $content))
				{
					$add($encoding_severity, 'Quebra de linha', 'Arquivo usa quebra de linha CR; phpBB Extension DB exige LF/UNIX.', $name, 'Converta as quebras de linha para LF/UNIX.', 'LF/UNIX (\n)', 'line-ending-cr');
				}
			}

			if (!$is_ignored_by_scope && preg_match('#\.php$#', $name))
			{
				$php_open_content = $this->validation_php_tag_probe_content($content);
				if (substr($php_open_content, 0, 3) === "\xEF\xBB\xBF")
				{
					$php_open_content = substr($php_open_content, 3);
				}

				if (strpos($php_open_content, '<?php') !== 0)
				{
					$open_pos = strpos($php_open_content, '<?php');
					if ($open_pos === false)
					{
						$add(
							'error',
							'PHP',
							'Arquivo PHP não contém a abertura <?php.',
							$name,
							'Adicione <?php no início do arquivo PHP ou confirme se a extensão do arquivo está correta.',
							'<?php',
							'php-open-tag-missing',
							1,
							$this->validation_first_line_excerpt($php_open_content)
						);
					}
					else
					{
						$prefix = substr($php_open_content, 0, $open_pos);
						if ($prefix !== '' && preg_match('/^\s+$/', $prefix))
						{
							$add(
								'error',
								'PHP',
								'Arquivo PHP possui espaço ou quebra de linha antes de <?php.',
								$name,
								'Remova qualquer espaço, tabulação ou quebra de linha antes de <?php. Arquivos PHP da extensão devem iniciar diretamente com <?php.',
								'<?php',
								'php-open-tag-leading-whitespace',
								1,
								$this->validation_first_line_excerpt($php_open_content)
							);
						}
						else
						{
							$add(
								'error',
								'PHP',
								'Arquivo PHP possui texto antes de <?php.',
								$name,
								'Remova qualquer texto antes de <?php. Isso pode gerar saída antes dos headers, quebrar AJAX/JSON e causar falhas no phpBB.',
								'<?php',
								'php-open-tag-before-text',
								1,
								$this->validation_first_line_excerpt($php_open_content)
							);
						}
					}
				}
			}

			if (!$is_ignored_by_scope && preg_match('#\.php$#', $name) && preg_match('/\?>\s*$/', $content))
			{
				$add('warning', 'PHP', 'Evite fechar arquivos PHP puros com ?> para reduzir risco de saída acidental.', $name, 'Remova o fechamento final ?> do arquivo PHP puro.', 'Remova apenas o ?> final, mantendo o conteúdo PHP.', 'php-closing-tag');
			}
			if (($is_db_scope || $is_full_scope) && preg_match('#\.php$#', $name) && !$is_include_area && basename($name) !== 'ext.php' && strpos($content, 'namespace ') === false && $this->validation_php_declares_named_class_like($content))
			{
				$add(
					'error',
					'PHP / EPV oficial',
					'Arquivo com class/interface/trait sem namespace; o EPV oficial reprova este padrão mesmo em bibliotecas empacotadas.',
					$name,
					'Adicione um namespace explícito à classe ou remova o arquivo do pacote de submissão.',
					$expected_namespace !== '' ? 'namespace ' . $expected_namespace . '\\...;' : 'namespace vendor\extension\...;',
					'epv-class-namespace'
				);
			}

			if (!$is_ignored_by_scope && !$is_vendor_area && !$is_include_area && preg_match('#\.php$#', $name) && strpos($content, 'namespace ') === false && basename($name) !== 'ext.php')
			{
				$class_like_content = $this->validation_php_declares_named_class_like($content);

				if ($is_extension_area || $class_like_content)
				{
					$add('warning', 'PHP', 'Classe PHP própria sem namespace explícito; confirme se é intencional.', $name, 'Adicione namespace em classes da extensão. Arquivos de idioma/configuração e bibliotecas de terceiros empacotadas não precisam seguir o namespace da extensão.', $expected_namespace !== '' ? 'namespace ' . $expected_namespace . '\\...;' : 'namespace vendor\extension\...;', 'php-namespace');
				}
			}
			if (!$is_ignored_by_scope && $is_supported_validation_source($name))
			{
				foreach ($this->find_todo_fixme_comments($content) as $todo_match)
				{
					$line_number = (int) $todo_match['line'];
					$excerpt = (string) $todo_match['excerpt'];
					$token = (string) $todo_match['token'];

					$add(
						'warning',
						'Qualidade',
						'Encontrado ' . $token . ' em comentário antes do release.',
						$name,
						'Revise o comentário indicado. Se a pendência ainda existir, resolva antes do release ou transforme em tarefa do Workspace; se for apenas nota técnica, remova o marcador ' . $token . '.',
						'Linha ' . $line_number . ': ' . $excerpt,
						'todo-fixme',
						$line_number,
						$excerpt
					);
				}
			}
		}

		if ($exists('config/permissions.yml'))
		{
			$check('permissions_yml', 'permissions.yml', 'pass', 'Arquivo de permissões encontrado.');
		}
		else
		{
			$check('permissions_yml', 'permissions.yml', 'warning', 'Ausente; aceitável se a extensão não define ACLs.');
		}

		$has_controller = $contains_file('#^controller/.+\.php$#');
		$has_event = $contains_file('#^event/.+\.php$#');
		$check('entry_points', 'Controllers/listeners', ($has_controller || $has_event) ? 'pass' : 'warning', ($has_controller || $has_event) ? 'Pontos de entrada encontrados.' : 'Nenhum controller/listener encontrado.');

		$has_template = $contains_file('#^styles/.+/template/.+\.(html|twig)$#');
		$has_css = $contains_file('#^styles/.+/theme/.+\.(css|scss|less)$#');
		$check('style_assets', 'Templates/estilos', ($has_template || $has_css) ? 'pass' : 'warning', ($has_template || $has_css) ? 'Assets de estilo encontrados.' : 'Nenhum asset visual encontrado.');

		// Workspace implementation detail.
		if ($expected_namespace !== '')
		{
			foreach ($files as $name => $content)
			{
				if (preg_match('#^(controller|event|service|repository|migrations)/.+\.php$#', $name) && strpos($content, 'namespace ' . $expected_namespace . '\\') === false)
				{
					$add('warning', 'Namespace', 'Arquivo PHP fora do namespace base esperado pelo composer.json.', $name, 'Padronize o namespace da classe para evitar falha de autoload.', 'namespace ' . $expected_namespace . '\\' . dirname($name) . ';', 'namespace-file');
				}
			}
		}


		if ($exists('config/routing.yml'))
		{
			preg_match_all('/_controller:\s*([^\r\n]+)/', $files['config/routing.yml'], $route_matches);
			foreach ($route_matches[1] as $controller_ref)
			{
				$controller_ref = trim(str_replace(['\"', "'"], '', $controller_ref));
				if ($controller_ref !== '' && strpos($controller_ref, '::') === false && strpos($controller_ref, ':') === false)
				{
					$add('warning', 'routing.yml', 'Referência de controller em formato incomum.', 'config/routing.yml', 'Use service_id:method ou Classe::metodo conforme o padrão usado no projeto.', '_controller: mundophpbb.workspace.controller.main:handle', 'route-controller-format');
				}
			}
		}

		foreach ($files as $name => $content)
		{
			if (preg_match('#^language/.+\.php$#', $name) && strpos($content, '$lang') === false)
			{
				$add('warning', 'Idioma', 'Arquivo de idioma não contém $lang.', $name, 'Confirme se o arquivo segue o padrão de idioma do phpBB.', '$lang = array_merge($lang, [...]);', 'language-lang-array');
			}
		}

		// ---------------------------------------------------------
		// phpBB Extension DB preflight: cross-file/runtime checks
		// ---------------------------------------------------------
		$preflight_severity = ($is_db_scope || $is_full_scope) ? 'error' : 'warning';

		// 1) Dependency Injection: declared services, class files and runtime core services.
		$service_problem_count = 0;
		$service_map = [];
		if ($exists('config/services.yml'))
		{
			$service_map = $this->validation_parse_services($files['config/services.yml']);
			foreach ($service_map as $service_id => $service_info)
			{
				$class_name = isset($service_info['class']) ? (string) $service_info['class'] : '';
				$relative_class = $this->validation_class_relative_path($class_name, $expected_namespace);
				$resolved_class_file = $relative_class !== '' ? $this->validation_resolve_project_file_path($files, $relative_class) : '';
				if ($relative_class !== '' && $resolved_class_file === '')
				{
					$resolved_class_file = $this->validation_find_declared_class_file($files, $class_name);
				}
				if ($relative_class !== '' && $resolved_class_file === '')
				{
					$service_problem_count++;
					$add('error', 'DI / services.yml', 'O serviço ' . $service_id . ' aponta para uma classe que não existe no projeto.', 'config/services.yml', 'Corrija class: ou adicione o arquivo da classe antes de habilitar a extensão.', $class_name . ' => ' . $relative_class, 'di-class-missing');
				}
				else if ($resolved_class_file !== '')
				{
					$constructor_stats = $this->validation_resolve_constructor_parameter_stats($resolved_class_file, $files);
					$configured_count = isset($service_info['argument_count']) ? (int) $service_info['argument_count'] : 0;
					if ($constructor_stats !== null && ($configured_count < (int) $constructor_stats['required'] || $configured_count > (int) $constructor_stats['total']))
					{
						$service_problem_count++;
						$add('error', 'DI / services.yml', 'Quantidade de argumentos do serviço é incompatível com o construtor: ' . $service_id . '.', 'config/services.yml', 'Ajuste arguments: para fornecer todos os argumentos obrigatórios sem exceder o total aceito pelo __construct() (incluindo o controller base herdado).', 'services.yml: ' . $configured_count . '; construtor aceita ' . (int) $constructor_stats['required'] . ' obrigatório(s) até ' . (int) $constructor_stats['total'] . ' total.', 'di-constructor-arguments');
					}
				}

				foreach (array_unique((array) $service_info['arguments']) as $dependency)
				{
					if (isset($service_map[$dependency]))
					{
						continue;
					}
					$runtime_has = $this->validation_runtime_has_service($dependency);
					$core_declared = $this->validation_core_service_declared($dependency);
					if ($runtime_has === false && $core_declared === false)
					{
						$service_problem_count++;
						$add(
							$preflight_severity,
							'DI / services.yml',
							'Dependência de serviço inexistente no container do phpBB: @' . $dependency . ' (usada por ' . $service_id . ').',
							'config/services.yml',
							'Use um service ID realmente registrado no phpBB alvo, declare o serviço na extensão ou remova a dependência. Este check evita o fatal ServiceNotFoundException durante a compilação do container.',
							"arguments:\n    - '@" . $dependency . "'",
							'di-service-missing'
						);
					}
				}
			}
		}
		$check('di_dependencies', 'Dependências do container Symfony', $service_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $service_problem_count ? ($service_problem_count . ' problema(s) de serviço encontrados.') : 'Nenhuma dependência de serviço ausente foi detectada no container/YAML do phpBB atual.');

		// 2) Routing: service/method references and POST requirement for state-changing actions.
		$route_problem_count = 0;
		if ($exists('config/routing.yml'))
		{
			$routes = $this->validation_parse_routes($files['config/routing.yml']);
			foreach ($routes as $route_name => $route)
			{
				$controller_ref = isset($route['controller']) ? (string) $route['controller'] : '';
				$method_name = '';
				$service_id = '';

				if ($controller_ref !== '' && strpos($controller_ref, '::') === false && preg_match('/^([A-Za-z0-9_.-]+):([A-Za-z_][A-Za-z0-9_]*)$/', $controller_ref, $m))
				{
					$service_id = (string) $m[1];
					$method_name = (string) $m[2];
					if (!isset($service_map[$service_id]))
					{
						$runtime_has = $this->validation_runtime_has_service($service_id);
						if ($runtime_has === false)
						{
							$route_problem_count++;
							$add('error', 'routing.yml', 'A rota ' . $route_name . ' referencia um serviço de controller inexistente: ' . $service_id . '.', 'config/routing.yml', 'Declare o controller em services.yml ou corrija _controller.', '_controller: ' . $service_id . ':' . $method_name, 'route-service-missing');
						}
					}
					else
					{
						$class_name = (string) $service_map[$service_id]['class'];
						$relative_class = $this->validation_class_relative_path($class_name, $expected_namespace);
						if ($relative_class !== '' && $exists($relative_class) && !$this->validation_class_has_method($relative_class, $method_name, $files))
						{
							$route_problem_count++;
							$add('error', 'routing.yml', 'A rota ' . $route_name . ' aponta para método não encontrado: ' . $method_name . '().', $relative_class, 'Crie o método público no controller ou corrija o nome em routing.yml.', $controller_ref, 'route-method-missing');
						}
					}
				}

				$action_probe = strtolower($route_name . ' ' . $method_name . ' ' . (isset($route['path']) ? $route['path'] : ''));
				$is_mutating = (bool) preg_match('/(?:^|[_\/.-])(add|create|save|update|edit|delete|remove|rename|move|upload|replace|lock|unlock|purge|clear|set|approve|reject|fix|apply|resolve|request)(?:$|[_\/.-])/', $action_probe);
				if ($is_mutating && !in_array('POST', (array) $route['methods'], true))
				{
					$route_problem_count++;
					$add($preflight_severity, 'routing.yml', 'Rota que altera estado não está restrita a POST: ' . $route_name . '.', 'config/routing.yml', 'Defina methods: [POST] e mantenha validação CSRF no endpoint.', 'methods: [POST]', 'route-mutation-post');
				}
			}
		}
		$check('routing_contract', 'Rotas e controllers', $route_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $route_problem_count ? ($route_problem_count . ' inconsistência(s) encontrada(s).') : 'Referências de controller e métodos HTTP passaram no preflight.');

		// 3) ACL consistency: permissions used in PHP must exist in permissions.yml.
		$acl_problem_count = 0;
		$defined_permissions = [];
		$permission_prefixes = [];
		if ($exists('config/permissions.yml'))
		{
			preg_match_all('/^ {4}([au]_[a-z0-9_]+):\s*$/mi', $files['config/permissions.yml'], $permission_matches);
			$defined_permissions = array_values(array_unique(array_map('strtolower', $permission_matches[1])));
			foreach ($defined_permissions as $permission)
			{
				if (preg_match('/^([au]_[a-z0-9]+_)/', $permission, $m))
				{
					$permission_prefixes[(string) $m[1]] = true;
				}
			}
		}
		if ($defined_permissions)
		{
			foreach ($files as $name => $content)
			{
				if (!preg_match('#\.php$#', $name) || $is_validation_vendor_area($name) || preg_match('#^language/#', $name))
				{
					continue;
				}
				preg_match_all('/[\'\"]([au]_[a-z0-9_]+)[\'\"]/i', $content, $used_matches, PREG_OFFSET_CAPTURE);
				foreach ($used_matches[1] as $permission_hit)
				{
					$permission = strtolower((string) $permission_hit[0]);
					$is_extension_permission = false;
					foreach ($permission_prefixes as $prefix => $unused)
					{
						if (strpos($permission, $prefix) === 0)
						{
							$is_extension_permission = true;
							break;
						}
					}
					if ($is_extension_permission && !in_array($permission, $defined_permissions, true))
					{
						$acl_problem_count++;
						$line_number = $this->validation_line_for_offset($content, (int) $permission_hit[1]);
						$add('error', 'Permissões', 'Permissão usada no código, mas não declarada em config/permissions.yml: ' . $permission . '.', $name, 'Declare a ACL correta ou substitua a referência por uma permissão existente.', $permission, 'acl-undefined', $line_number);
					}
				}
			}
		}
		$check('acl_consistency', 'Permissões usadas x declaradas', $acl_problem_count ? 'error' : 'pass', $acl_problem_count ? ($acl_problem_count . ' referência(s) de ACL indefinida(s).') : 'Nenhuma ACL própria indefinida foi encontrada.');

		// 4) Language-key consistency, focused on prefixes owned by this extension.
		$language_problem_count = 0;
		$english_keys = [];
		$language_keys_by_dir = [];
		foreach ($files as $name => $content)
		{
			if (preg_match('#^language/([^/]+)/.+\.php$#', $name, $m))
			{
				$lang_dir = strtolower((string) $m[1]);
				if (!isset($language_keys_by_dir[$lang_dir]))
				{
					$language_keys_by_dir[$lang_dir] = [];
				}
				$file_language_keys = $this->validation_language_keys($content);
				$file_counts = array_count_values($file_language_keys);
				foreach ($file_counts as $language_key => $language_count)
				{
					if ($language_count > 1)
					{
						$language_problem_count++;
						$add('warning', 'Idioma', 'Chave de idioma definida mais de uma vez no mesmo arquivo: ' . $language_key . '.', $name, 'Mantenha apenas uma definição da chave dentro deste arquivo para evitar sobrescrita silenciosa.', $language_key, 'language-key-duplicate');
					}
				}
				$language_keys_by_dir[$lang_dir] = array_merge($language_keys_by_dir[$lang_dir], array_values(array_unique($file_language_keys)));
			}
		}
		foreach ($language_keys_by_dir as $lang_dir => $lang_keys)
		{
			// The same key may legitimately appear in different language files that
			// are loaded in different contexts (for example info_acp_* and acp.php).
			// Only duplicates within the same file are reported above.
			$language_keys_by_dir[$lang_dir] = array_values(array_unique($lang_keys));
		}
		if (isset($language_keys_by_dir['en']))
		{
			$english_keys = $language_keys_by_dir['en'];
			$owned_prefixes = $this->validation_language_prefixes($english_keys);
			if ($owned_prefixes)
			{
				$prefix_pattern = '(?:' . implode('|', array_map(function ($prefix) {
					return preg_quote($prefix, '/');
				}, $owned_prefixes)) . ')[A-Z0-9_]+';

				foreach ($files as $name => $content)
				{
					if ($is_validation_vendor_area($name) || preg_match('#^language/#', $name) || !preg_match('#\.(php|js|html|twig)$#', $name))
					{
						continue;
					}
					$usage_matches = [];
					if (preg_match('#\.php$#', $name))
					{
						preg_match_all('/(?:(?:->lang|json_error)\s*\(\s*|->lang\s*\[\s*|trigger_error\s*\(\s*)[\'\"](' . $prefix_pattern . ')[\'\"]/', $content, $php_usage, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
						$usage_matches = array_merge($usage_matches, $php_usage);
					}
					else if (preg_match('#\.js$#', $name))
					{
						preg_match_all('/\bWSP\.lang\s*\(\s*[\'\"](' . $prefix_pattern . ')[\'\"]/', $content, $js_usage, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
						$usage_matches = array_merge($usage_matches, $js_usage);
					}
					else if (preg_match('#\.(?:html|twig)$#', $name))
					{
						preg_match_all('/\{L_(' . $prefix_pattern . ')\}/', $content, $tpl_usage, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
						$usage_matches = array_merge($usage_matches, $tpl_usage);
					}

					foreach ($usage_matches as $usage)
					{
						$key = (string) $usage[1][0];
						$offset = (int) $usage[1][1];
						if (substr($key, -1) === '_')
						{
							continue;
						}
						if (!in_array($key, $english_keys, true))
						{
							$language_problem_count++;
							$add($preflight_severity, 'Idioma', 'Chave de idioma usada, mas ausente em language/en: ' . $key . '.', $name, 'Adicione a chave no pacote de idioma inglês e replique nos demais idiomas mantidos.', "'" . $key . "' => '...',", 'language-key-missing', $this->validation_line_for_offset($content, $offset));
						}
					}
				}
			}

			foreach ($language_keys_by_dir as $lang_dir => $lang_keys)
			{
				if ($lang_dir === 'en')
				{
					continue;
				}
				$missing = array_values(array_diff($english_keys, $lang_keys));
				if ($missing)
				{
					$language_problem_count += count($missing);
					$preview = implode(', ', array_slice($missing, 0, 8));
					if (count($missing) > 8) { $preview .= ', ...'; }
					$add('warning', 'Idioma', 'O idioma ' . $lang_dir . ' não contém ' . count($missing) . ' chave(s) presentes no inglês.', 'language/' . $lang_dir, 'Sincronize as chaves antes do release para evitar textos ausentes na interface.', $preview, 'language-pack-mismatch');
				}
			}
		}
		$check('language_consistency', 'Chaves de idioma', $language_problem_count ? 'warning' : 'pass', $language_problem_count ? ($language_problem_count . ' aviso(s) de idioma/tradução detectado(s).') : 'Uso e definição das chaves próprias estão consistentes.');

		// 4b) Hard-coded user-facing text. phpBB validators expect display strings
		// to live in language files. This specifically catches service/controller
		// result arrays such as ['message' => 'Readable sentence'], direct
		// trigger_error()/Response strings and similar patterns that previously
		// slipped through the key-consistency check above.
		$hardcoded_language_problem_count = 0;
		$hardcoded_seen = [];
		$is_human_language_literal = function ($literal) {
			$literal = trim((string) $literal);
			if ($literal === '' || strlen($literal) < 4)
			{
				return false;
			}
			if (!preg_match('/[[:alpha:]]/u', $literal) || !preg_match('/\s/u', $literal))
			{
				return false;
			}
			// Language keys and machine/status identifiers are not display text.
			if (preg_match('/^[A-Z][A-Z0-9_]+$/', $literal) || preg_match('/^[a-z0-9_.:\/-]+$/', $literal))
			{
				return false;
			}
			if (preg_match('#^(?:https?://|mailto:|[A-Za-z0-9_.-]+\\[A-Za-z0-9_\\.-]+)$#', $literal))
			{
				return false;
			}
			return true;
		};
		$report_hardcoded_literal = function ($name, $content, $literal, $offset, $context) use (&$hardcoded_language_problem_count, &$hardcoded_seen, $is_human_language_literal, $add, $preflight_severity) {
			$literal = trim((string) $literal);
			if ($is_human_language_literal($literal) === false)
			{
				return;
			}
			$line_number = $this->validation_line_for_offset($content, (int) $offset);
			$fingerprint = $name . ':' . $line_number . ':' . $literal;
			if (isset($hardcoded_seen[$fingerprint]))
			{
				return;
			}
			$hardcoded_seen[$fingerprint] = true;
			$hardcoded_language_problem_count++;
			$excerpt = $literal;
			if (strlen($excerpt) > 180)
			{
				$excerpt = substr($excerpt, 0, 177) . '...';
			}
			$add(
				$preflight_severity,
				'Idioma / texto hard-coded',
				'Texto potencialmente exibido ao usuário está escrito diretamente no PHP' . ($context !== '' ? ' (' . $context . ')' : '') . '.',
				$name,
				'Mova a mensagem para language/en e use $user->lang(), $language->lang() ou retorne uma chave de idioma para a camada que exibe a resposta.',
				"'EXTENSION_MESSAGE_KEY' => '" . str_replace("'", "\\'", $excerpt) . "',",
				'language-hardcoded-ui-text',
				$line_number,
				$excerpt
			);
		};

		foreach ($files as $name => $content)
		{
			if ($is_validation_vendor_area($name) || preg_match('#^(?:language|tests?)/#i', $name) || !preg_match('#\.php$#i', $name))
			{
				continue;
			}

			$lines = preg_split('/\r\n|\r|\n/', (string) $content, -1, PREG_SPLIT_OFFSET_CAPTURE);
			foreach ($lines as $line_data)
			{
				$line_text = (string) $line_data[0];
				$line_offset = (int) $line_data[1];
				$trimmed_line = ltrim($line_text);

				// Do not interpret documentation/examples inside comments as UI output.
				if ($trimmed_line === '' || preg_match('#^(?://|/\*|\*|\*/|\#)#', $trimmed_line))
				{
					continue;
				}

				// Strong signal: an associative result field intended to carry a message.
				if (preg_match('/[\'\"](?:message|error|warning|notice)[\'\"]\s*=>/i', $line_text, $field_match, PREG_OFFSET_CAPTURE))
				{
					$arrow_pos = strpos($line_text, '=>', (int) $field_match[0][1]);
					$tail = ($arrow_pos === false) ? '' : substr($line_text, $arrow_pos + 2);
					if ($tail !== '' && preg_match_all('/(?<![\'\"])([\'\"])([^\'\"\r\n]{4,})\\1/', $tail, $literals, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
					{
						foreach ($literals as $literal_match)
						{
							$report_hardcoded_literal($name, $content, (string) $literal_match[2][0], $line_offset + $arrow_pos + 2 + (int) $literal_match[2][1], 'campo message/error/warning/notice');
						}
					}
				}

				// Common direct-output APIs used by phpBB/Symfony controllers.
				if (preg_match_all('/(?:trigger_error|json_error_msg|json_error|new\s+(?:\\\\)?(?:Symfony\\\\Component\\\\HttpFoundation\\\\)?Response)\s*\(\s*([\'\"])([^\'\"\r\n]{4,})\\1/i', $line_text, $direct_matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
				{
					foreach ($direct_matches as $direct_match)
					{
						$report_hardcoded_literal($name, $content, (string) $direct_match[2][0], $line_offset + (int) $direct_match[2][1], 'resposta direta');
					}
				}
			}
		}
		$check(
			'language_hardcoded_ui_text',
			'Textos de interface fora de language/',
			$hardcoded_language_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass',
			$hardcoded_language_problem_count ? ($hardcoded_language_problem_count . ' texto(s) potencialmente exibido(s) ao usuário estão hard-coded no PHP.') : 'Nenhum texto de interface hard-coded foi encontrado nos padrões de alto sinal.'
		);

		// 5) CSRF baseline for state-changing routes.
		$has_mutating_route = false;
		if ($exists('config/routing.yml'))
		{
			foreach ($this->validation_parse_routes($files['config/routing.yml']) as $route_name => $route)
			{
				$probe = strtolower($route_name . ' ' . (string) $route['controller'] . ' ' . (string) $route['path']);
				if (preg_match('/(?:^|[_\/.-])(add|create|save|update|edit|delete|remove|rename|move|upload|replace|lock|unlock|purge|clear|set|approve|reject|fix|apply|resolve|request)(?:$|[_\/.-])/', $probe))
				{
					$has_mutating_route = true;
					break;
				}
			}
		}
		$has_check_form_key = false;
		$has_add_form_key = false;
		foreach ($files as $name => $content)
		{
			$normalized_name = $this->validation_normalize_project_path((string) $name);
			$php_probe = $this->validation_php_tag_probe_content((string) $content);
			if ($is_validation_vendor_area($normalized_name)
				|| (!preg_match('#\.php$#i', $normalized_name) && strpos($php_probe, '<?php') === false))
			{
				continue;
			}
			if (!$has_check_form_key && $this->validation_php_has_function_call($content, 'check_form_key'))
			{
				$has_check_form_key = true;
			}
			if (!$has_add_form_key && $this->validation_php_has_function_call($content, 'add_form_key'))
			{
				$has_add_form_key = true;
			}
		}
		$csrf_problem = false;
		if ($has_mutating_route && !$has_check_form_key)
		{
			$csrf_problem = true;
			$add($preflight_severity, 'Segurança / CSRF', 'Existem rotas que alteram estado, mas não foi encontrada validação check_form_key().', 'controller/', 'Adicione add_form_key() ao fluxo de formulário e valide check_form_key() antes de processar POSTs.', "add_form_key('vendor_extension');\ncheck_form_key('vendor_extension');", 'csrf-check-missing');
		}
		if ($has_mutating_route && !$has_add_form_key)
		{
			$csrf_problem = true;
			$add($preflight_severity, 'Segurança / CSRF', 'Existem operações mutáveis, mas não foi encontrada geração de form key com add_form_key().', 'controller/', 'Gere o token do phpBB e envie creation_time/form_token nos POSTs.', "add_form_key('vendor_extension');", 'csrf-token-missing');
		}
		$check('csrf_baseline', 'Proteção CSRF', $csrf_problem ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $csrf_problem ? 'Proteção CSRF não pôde ser confirmada.' : 'Chamadas reais add_form_key()/check_form_key() foram encontradas no projeto.');

		// 6) Extension DB asset loading and direct superglobal access.
		$asset_problem_count = 0;
		$superglobal_problem_count = 0;
		foreach ($files as $name => $content)
		{
			if ($is_validation_vendor_area($name))
			{
				continue;
			}
			if (preg_match('#^styles/.+/template/.+\.(?:html|twig)$#', $name))
			{
				if (preg_match('/<script\b[^>]*\bsrc\s*=/i', $content, $m, PREG_OFFSET_CAPTURE))
				{
					$asset_problem_count++;
					$add($preflight_severity, 'Assets', 'Template carrega JavaScript com <script src> diretamente.', $name, 'Use a função de template INCLUDEJS para assets da extensão.', '{% INCLUDEJS \'@vendor_extension/js/file.js\' %}', 'asset-raw-script', $this->validation_line_for_offset($content, (int) $m[0][1]));
				}
				if (preg_match('/<link\b[^>]*\brel\s*=\s*[\'\"]?stylesheet[\'\"]?[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE))
				{
					$asset_problem_count++;
					$add($preflight_severity, 'Assets', 'Template carrega stylesheet com <link> diretamente.', $name, 'Use a função de template INCLUDECSS para CSS da extensão.', '{% INCLUDECSS \'@vendor_extension/file.css\' %}', 'asset-raw-css', $this->validation_line_for_offset($content, (int) $m[0][1]));
				}
			}
			if (preg_match('#\.php$#', $name) && preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER|ENV|SESSION)\b/', $content, $m, PREG_OFFSET_CAPTURE))
			{
				$superglobal_problem_count++;
				$add($preflight_severity, 'Request / Segurança', 'Acesso direto a superglobal detectado: ' . $m[0][0] . '.', $name, 'Use o serviço request do phpBB e APIs apropriadas em vez de acessar superglobais diretamente.', '$this->request->variable(...)', 'direct-superglobal', $this->validation_line_for_offset($content, (int) $m[0][1]));
			}
		}
		$check('asset_loading', 'INCLUDEJS / INCLUDECSS', $asset_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $asset_problem_count ? ($asset_problem_count . ' inclusão(ões) direta(s) encontrada(s).') : 'Nenhuma inclusão direta de JS/CSS foi detectada nos templates.');
		$check('request_api', 'Request API / superglobais', $superglobal_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $superglobal_problem_count ? ($superglobal_problem_count . ' acesso(s) direto(s) encontrado(s).') : 'Nenhum acesso direto a superglobais foi detectado.');

		// 7) Mirror the official EPV potential-SQL-injection heuristic.
		$epv_sql_problem_count = 0;
		$epv_sql_allowed_keywords = [
			'sql_in_set',
			'sql_escape',
			'sql_bit_and',
			'get_visibility_sql',
			'get_sql_where',
			'get_forums_visibility_sql',
			'ORDER BY',
			'ORDER_BY',
		];
		foreach ($files as $name => $content)
		{
			if (!preg_match('#\.php$#i', $name))
			{
				continue;
			}
			$code_lines = explode("\n", (string) $content);
			if (preg_match_all('/WHERE[^;\$]+[=<>]+[^;]+("|\') \. \$/mU', (string) $content, $sql_matches, PREG_OFFSET_CAPTURE))
			{
				foreach ($sql_matches[0] as $sql_match)
				{
					$prelines = substr_count((string) $content, "\n", 0, (int) $sql_match[1]);
					$inlines = substr_count((string) $sql_match[0], "\n");
					$line_index = $prelines + $inlines;
					$line_text = isset($code_lines[$line_index]) ? (string) $code_lines[$line_index] : '';
					$allowed = false;
					foreach ($epv_sql_allowed_keywords as $keyword)
					{
						if (strpos($line_text, $keyword) !== false)
						{
							$allowed = true;
							break;
						}
					}
					if ($allowed)
					{
						continue;
					}
					$epv_sql_problem_count++;
					$add(
						'warning',
						'SQL / EPV oficial',
						'O padrão corresponde à regra "Found potential SQL injection" do EPV oficial.',
						$name,
						'Faça o cast diretamente na concatenação para inteiros ou mantenha sql_escape()/sql_in_set() na própria linha analisada pelo EPV.',
						trim($line_text),
						'epv-potential-sql-injection',
						$line_index + 1,
						trim($line_text)
					);
				}
			}
		}
		$check(
			'epv_sql_injection',
			'SQL injection potencial (regra EPV)',
			$epv_sql_problem_count ? 'warning' : 'pass',
			$epv_sql_problem_count ? ($epv_sql_problem_count . ' ocorrência(s) compatível(is) com a heurística oficial do EPV.') : 'Nenhuma ocorrência encontrada pela heurística oficial do EPV.'
		);

		// 8) SQL portability, debugging leftovers and package hygiene.
		$portability_problem_count = 0;
		$debug_problem_count = 0;
		$hygiene_problem_count = 0;
		foreach ($files as $name => $content)
		{
			if ($is_validation_vendor_area($name))
			{
				continue;
			}
			if (preg_match('#\.php$#', $name))
			{
				if (preg_match('/\b(?:ALTER\s+TABLE|CREATE\s+TABLE)\b[\s\S]{0,600}?\b(?:ENGINE\s*=|CHARACTER\s+SET|COLLATE\s*=|LONGTEXT\b|AUTO_INCREMENT\b)|\b(?:ALTER\s+TABLE|CREATE\s+TABLE)\b[\s\S]{0,600}?`[A-Za-z0-9_]+`/i', $content, $m, PREG_OFFSET_CAPTURE))
				{
					$portability_problem_count++;
					$excerpt = preg_replace('/\s+/', ' ', trim((string) $m[0][0]));
					if (strlen($excerpt) > 180) { $excerpt = substr($excerpt, 0, 180) . '...'; }
					$add($preflight_severity, 'DBAL / Portabilidade', 'SQL DDL específico de MySQL/MariaDB detectado.', $name, 'Use schema_changes/migration APIs e DBAL portável do phpBB. Evite tipos, collations e sintaxe específicos de um único banco.', $excerpt, 'sql-db-specific', $this->validation_line_for_offset($content, (int) $m[0][1]));
				}
				if (preg_match('/\b(?:SELECT|UPDATE|DELETE)\b[\s\S]{0,600}?\bLIMIT\s+[0-9]+\b/i', $content, $m, PREG_OFFSET_CAPTURE))
				{
					$portability_problem_count++;
					$add($preflight_severity, 'DBAL / Portabilidade', 'LIMIT escrito diretamente em SQL.', $name, 'Use $db->sql_query_limit() para manter compatibilidade entre bancos.', '$this->db->sql_query_limit($sql, $limit);', 'sql-direct-limit', $this->validation_line_for_offset($content, (int) $m[0][1]));
				}
				if (preg_match('/\b(?:var_dump|print_r)\s*\(|\bdebug_zval_dump\s*\(/', $content, $m, PREG_OFFSET_CAPTURE))
				{
					$debug_problem_count++;
					$add('warning', 'Release / Debug', 'Código de debug encontrado: ' . trim((string) $m[0][0]) . '.', $name, 'Remova saídas de debug antes de gerar o pacote final.', '', 'debug-leftover', $this->validation_line_for_offset($content, (int) $m[0][1]));
				}
			}

			if (preg_match('#(?:^|/)(?:\.git|\.github/workflows|\.idea|\.vscode)(?:/|$)|(?:^|/)(?:\.DS_Store|Thumbs\.db)$|\.(?:bak|old|orig|rej|log)$#i', $name))
			{
				$hygiene_problem_count++;
				$add('warning', 'Pacote', 'Arquivo de desenvolvimento/backup não deve ir no ZIP de submissão.', $name, 'Remova este item do pacote final.', '', 'package-junk-file');
			}
		}
		$check('db_portability', 'DBAL / portabilidade SQL', $portability_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $portability_problem_count ? ($portability_problem_count . ' padrão(ões) dependente(s) de banco encontrado(s).') : 'Nenhum padrão SQL obviamente específico de MySQL foi encontrado.');
		$check('release_debug', 'Resíduos de debug', $debug_problem_count ? 'warning' : 'pass', $debug_problem_count ? ($debug_problem_count . ' ocorrência(s) de debug encontrada(s).') : 'Nenhum var_dump/print_r foi encontrado no código próprio.');
		$check('package_hygiene', 'Higiene do pacote', $hygiene_problem_count ? 'warning' : 'pass', $hygiene_problem_count ? ($hygiene_problem_count . ' arquivo(s) desnecessário(s) detectado(s).') : 'Nenhum arquivo típico de IDE, backup ou log foi encontrado.');

		// 7b) phpBB-specific validation regressions seen in real Extension DB reviews.
		// The official EPV rejects getenv() in extension code. Detect it locally
		// so the user sees the problem before the official process is started.
		$epv_function_problem_count = 0;
		foreach ($files as $name => $content)
		{
			if ($is_validation_vendor_area($name) || !preg_match('#\.php$#i', $name))
			{
				continue;
			}
			if ($this->validation_php_has_function_call($content, 'getenv'))
			{
				$epv_function_problem_count++;
				$add($preflight_severity, 'PHP / EPV oficial', 'Uso de getenv() detectado; o EPV oficial classifica esta função como erro.', $name, 'Use configuração/constante injetada pelo phpBB ou outra API apropriada, evitando getenv() no código da extensão.', 'getenv(...)', 'epv-disallowed-getenv');
			}
		}
		$check('epv_disallowed_getenv', 'Funções rejeitadas pelo EPV', $epv_function_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $epv_function_problem_count ? ($epv_function_problem_count . ' arquivo(s) usam getenv().') : 'Nenhum uso de getenv() foi encontrado no código próprio.');

		$phpbb_api_problem_count = 0;
		$sql_package_problem_count = 0;
		$unique_index_guard_problem_count = 0;
		foreach ($files as $name => $content)
		{
			if ($is_validation_vendor_area($name))
			{
				continue;
			}

			$htmlspecialchars_offset = preg_match('#\.php$#i', $name) ? $this->validation_php_function_call_offset($content, 'htmlspecialchars') : false;
			if ($htmlspecialchars_offset !== false)
			{
				$phpbb_api_problem_count++;
				$line_number = $this->validation_line_for_offset($content, (int) $htmlspecialchars_offset);
				$add(
					$preflight_severity,
					'phpBB API / escaping',
					'Uso de htmlspecialchars() nativo detectado; a validação phpBB pode exigir utf8_htmlspecialchars() ou escaping no template.',
					$name,
					'Use utf8_htmlspecialchars() quando o escape precisar ocorrer no PHP. Se estiver montando HTML no PHP, prefira passar dados ao template e deixar o Twig escapar a saída.',
					'utf8_htmlspecialchars($value)',
					'phpbb-native-htmlspecialchars',
					$line_number,
					'htmlspecialchars('
				);
			}

			if (preg_match('#\.sql$#i', $name))
			{
				$sql_package_problem_count++;
				$add(
					'warning',
					'Pacote / tipo de arquivo',
					'Arquivo .sql encontrado no pacote: o relatório de validação pode marcá-lo como tipo de arquivo não reconhecido e alterações de banco devem usar migrations.',
					$name,
					'Se for apenas documentação/remoção manual, distribua em contrib/ como .txt. Se altera o banco durante instalação/atualização, converta para migration phpBB.',
					'contrib/example.sql.txt ou migrations/v_x_y_z.php',
					'package-raw-sql-file'
				);
			}


			if (preg_match('#(?:^|/)migrations/.*\.php$#i', $name))
			{
				foreach ($this->validation_php_function_blocks((string) $content) as $function_block)
				{
					$function_source = (string) $function_block['content'];
					if (!preg_match('/\bsql_list_index\s*\(/i', $function_source, $list_index_match, PREG_OFFSET_CAPTURE))
					{
						continue;
					}
					if (!preg_match('/\bsql_create_unique_index\s*\(/i', $function_source))
					{
						continue;
					}
					if (preg_match('/\bsql_unique_index_exists\s*\(/i', $function_source))
					{
						continue;
					}

					$unique_index_guard_problem_count++;
					$absolute_offset = (int) $function_block['offset'] + (int) $list_index_match[0][1];
					$line_number = $this->validation_line_for_offset($content, $absolute_offset);
					$function_name = !empty($function_block['name']) ? (string) $function_block['name'] : '(anonymous)';
					$add(
						($is_db_scope || $is_full_scope) ? 'error' : 'warning',
						'Migration / índice único',
						'sql_list_index() está sendo usado no mesmo helper que cria índice UNIQUE. No phpBB, sql_list_index() não lista índices UNIQUE nem PRIMARY KEY, então a guarda pode tentar recriar um índice já existente e causar erro Duplicate key name.',
						$name,
						'Use $this->db_tools->sql_unique_index_exists($table, $index_name) antes de sql_create_unique_index(). Não use sql_list_index() para testar a existência de índice UNIQUE.',
						"protected function {$function_name}(...) { if (!\$this->db_tools->sql_unique_index_exists(\$table, \$index_name)) { \$this->db_tools->sql_create_unique_index(\$table, \$index_name, \$columns); } }",
						'migration-unique-index-guard',
						$line_number,
						'sql_list_index() + sql_create_unique_index()'
					);
				}
			}
		}
		$check('phpbb_escaping_api', 'Escaping compatível com phpBB', $phpbb_api_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $phpbb_api_problem_count ? ($phpbb_api_problem_count . ' uso(s) de htmlspecialchars() nativo encontrado(s).') : 'Nenhum htmlspecialchars() nativo foi encontrado no código próprio.');
		$check('package_sql_files', 'Arquivos .sql no pacote', $sql_package_problem_count ? 'warning' : 'pass', $sql_package_problem_count ? ($sql_package_problem_count . ' arquivo(s) .sql encontrado(s).') : 'Nenhum arquivo .sql solto foi encontrado no pacote.');
		$check('migration_unique_index_guard', 'Migrations / índices UNIQUE', $unique_index_guard_problem_count ? (($is_db_scope || $is_full_scope) ? 'error' : 'warning') : 'pass', $unique_index_guard_problem_count ? ($unique_index_guard_problem_count . ' helper(s) usam sql_list_index() como guarda para criação de índice UNIQUE.') : 'Nenhum uso incompatível de sql_list_index() para criação de índice UNIQUE foi encontrado.');

		// 8) Coding-style policy checks and non-blocking architecture notices.
		$coding_style_problem_count = 0;
		$comment_language_problem_count = 0;
		$direct_output_problem_count = 0;
		$safe_style_fix_candidates = 0;
		$coding_style_files = [];
		$comment_language_files = [];
		$direct_output_files = [];
		if ($is_db_scope || $is_full_scope)
		{
			foreach ($files as $name => $content)
			{
				if ($is_validation_vendor_area($name) || preg_match('#^language/#i', $name) || !preg_match('#\.(?:php|js|json|css)$#', $name))
				{
					continue;
				}
				$lines = preg_split('/\r\n|\r|\n/', (string) $content);
				$space_indent_count = $this->validation_space_indent_count($content);
				$first_space_line = 0;
				foreach ($lines as $line_index => $source_line)
				{
					if (preg_match('/^ {4,}\S/', (string) $source_line))
					{
						$first_space_line = $line_index + 1;
						break;
					}
				}
				if ($space_indent_count >= 5)
				{
					$coding_style_problem_count++;
					$coding_style_files[] = $name . ' (' . $space_indent_count . ' linhas; primeira: ' . $first_space_line . ')';
					$lname = strtolower(str_replace('\\', '/', (string) $name));
					if (preg_match('#\.php$#', $lname) && $this->validation_normalize_php_indentation($content) !== $content)
					{
						$safe_style_fix_candidates++;
					}
					else if ($lname === 'composer.json' && $this->validation_normalize_composer_indentation($content) !== $content)
					{
						$safe_style_fix_candidates++;
					}
				}

				if (preg_match('#\.(?:php|js)$#', $name))
				{
					$comment_text = '';
					if (preg_match('#\.php$#', $name))
					{
						foreach (token_get_all((string) $content) as $token)
						{
							if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT))
							{
								$comment_text .= "\n" . $token[1];
							}
						}
					}
					else if (preg_match_all('#(?://|/\*|\*)\s*([^\r\n]+)#', (string) $content, $comments))
					{
						$comment_text = implode("\n", $comments[1]);
					}
					if ($comment_text !== '' && preg_match('/\b(?:arquivo|arquivos|projeto|projetos|usuário|usuarios|permissão|permissoes|validação|validacao|corrigido|versão|versao|carrega|salva|bloqueia|retorna|cria|atualiza|exclui|busca|conteúdo|conteudo|revisão|revisao|colaboração|colaboracao|não|nao|também|tambem)\b/iu', $comment_text))
					{
						$comment_language_problem_count++;
						$comment_language_files[] = $name;
					}
				}

				if (preg_match('#\.php$#', $name))
				{
					$tokens = token_get_all((string) $content);
					$token_line = 1;
					foreach ($tokens as $token_index => $token)
					{
						if (is_array($token))
						{
							$token_line = (int) $token[2];
							if ($token[0] !== T_STRING || !in_array(strtolower($token[1]), ['header', 'readfile'], true))
							{
								continue;
							}

							$next_index = $token_index + 1;
							while (isset($tokens[$next_index]) && is_array($tokens[$next_index]) && $tokens[$next_index][0] === T_WHITESPACE)
							{
								$next_index++;
							}
							if (isset($tokens[$next_index]) && $tokens[$next_index] === '(')
							{
								$direct_output_problem_count++;
								$direct_output_files[] = $name . ' (linha ' . $token_line . ')';
								break;
							}
						}
					}
				}
			}
		}
		if ($coding_style_files)
		{
			$preview = implode('; ', array_slice($coding_style_files, 0, 12));
			if (count($coding_style_files) > 12) { $preview .= '; ...'; }
			$indent_action = 'A política de validação do phpBB exige tabs para indentação em PHP, JavaScript, JSON e CSS. Revise estes arquivos antes da submissão.';
			if ($safe_style_fix_candidates > 0)
			{
				$indent_action .= ' O botão de correções seguras pode normalizar automaticamente ' . $safe_style_fix_candidates . ' arquivo(s) PHP/composer.json sem alterar strings ou heredocs.';
			}
			$add('warning', 'Coding style / política phpBB', 'Indentação por espaços detectada em ' . count($coding_style_files) . ' arquivo(s).', '', $indent_action, $preview, 'coding-indent-spaces');
		}
		if ($comment_language_files)
		{
			$preview = implode(', ', array_slice($comment_language_files, 0, 12));
			if (count($comment_language_files) > 12) { $preview .= ', ...'; }
			$add('warning', 'Coding style / política phpBB', 'Comentários possivelmente fora do inglês foram detectados em ' . count($comment_language_files) . ' arquivo(s).', '', 'A política do phpBB exige inglês em comentários distribuídos. Revise os comentários/docblocks indicados antes da submissão.', $preview, 'comments-non-english');
		}
		if ($direct_output_files)
		{
			$preview = implode(', ', array_slice($direct_output_files, 0, 10));
			if (count($direct_output_files) > 10) { $preview .= ', ...'; }
			$add('info', 'Arquitetura / revisão', 'header()/readfile() direto detectado em ' . count($direct_output_files) . ' arquivo(s).', '', 'Isto não é uma proibição explícita da política de validação. Revise apenas se quiser padronizar a resposta HTTP com Response/StreamedResponse do Symfony.', $preview, 'direct-http-output');
		}

		$check('coding_indent', 'Coding style / indentação', $coding_style_problem_count ? 'warning' : 'pass', $coding_style_problem_count ? ($coding_style_problem_count . ' arquivo(s) usam espaços onde a política phpBB espera tabs.') : 'Nenhuma indentação recorrente por quatro espaços foi detectada.');
		$check('comments_language', 'Idioma dos comentários', $comment_language_problem_count ? 'warning' : 'pass', $comment_language_problem_count ? ($comment_language_problem_count . ' arquivo(s) contêm comentários possivelmente fora do inglês.') : 'Nenhum comentário claramente fora do inglês foi detectado pelo heurístico.');
		$check('http_response_style', 'Respostas HTTP diretas', $direct_output_problem_count ? 'info' : 'pass', $direct_output_problem_count ? ($direct_output_problem_count . ' arquivo(s) usam header/readfile/exit; revisão opcional, sem bloqueio de submissão.') : 'Nenhuma saída HTTP direta foi detectada.');

		$errors = 0; $warnings = 0; $notices = 0;
		foreach ($issues as $issue)
		{
			if ($issue['severity'] === 'error') { $errors++; }
			else if ($issue['severity'] === 'warning') { $warnings++; }
			else if ($issue['severity'] === 'info') { $notices++; }
		}
		$fixable = 0;
		foreach ($issues as $issue)
		{
			if (!empty($issue['fixable'])) { $fixable++; }
		}
		$fixable += (int) $safe_style_fix_candidates;

		$passed = 0;
		foreach ($checklist as $item)
		{
			if ($item['status'] === 'pass') { $passed++; }
		}

		return [
			'summary' => [
				'errors' => $errors,
				'warnings' => $warnings,
				'notices' => $notices,
				'passed' => $passed,
				'checks' => count($checklist),
				'files' => count($files),
				'scope' => $scope,
				'scope_label' => $scope_label,
				'ready' => ($errors === 0),
				'score' => max(0, 100 - ($errors * 12) - ($warnings * 3)),
				'actionable' => count($issues),
				'fixable' => $fixable,
			],
			'checklist' => $checklist,
			'issues' => $issues,
		];
	}


	/**
	 * Handles apply validation fixes.
	 */
	public function apply_validation_fixes()
	{
		if ($r = $this->ensure_workspace_access()) { return $r; }

		$project_id = (int) $this->request->variable('project_id', 0);
		if ($project_id <= 0)
		{
			return $this->json_error('WSP_ERR_INVALID_DATA');
		}

		if ($r = $this->ensure_project_capability($project_id, 'edit')) { return $r; }

		$scope = $this->normalize_validation_scope($this->request->variable('scope', 'normal'));
		$rows = $this->get_project_file_rows_for_validation($project_id);
		$files = [];
		foreach ($rows as $row)
		{
			$files[(string) $row['file_name']] = (string) $row['file_content'];
		}

		$before_report = $this->build_phpbb_release_validation($files, $scope);
		$targets = [];
		foreach ((array) $before_report['issues'] as $issue)
		{
			if (empty($issue['fixable']))
			{
				continue;
			}
			$rule = (string) ($issue['rule'] ?? '');
			$file = (string) ($issue['file'] ?? '');
			if ($rule !== '' && $file !== '')
			{
				$targets[$file][$rule] = true;
			}
		}

		// Defensive pass: line ending fixes must not depend only on the JSON
		// report payload. If the scope is Extension DB/full, scan the raw stored
		// content again and add CRLF/CR targets for supported phpBB text files.
		// This prevents cases where another safe fix is applied but Windows line
		// endings remain because the issue was filtered out or not grouped.
		if ($scope === 'phpbb_ext_db' || $scope === 'full')
		{
			foreach ($rows as $row)
			{
				$scan_name = str_replace('\\', '/', (string) $row['file_name']);
				$scan_lname = strtolower($scan_name);
				$scan_content = (string) $row['file_content'];

				$is_vendor_scan = (bool) preg_match('#^(vendor/|node_modules/|lib/diff(?:/|\.php$)|styles/[^/]+/template/ace/|styles/[^/]+/theme/vendor/|assets/vendor/)#', $scan_lname);
				$is_supported_scan = (bool) preg_match('#\.(php|js|css|html|htm|twig)$#', $scan_lname) || $scan_lname === 'composer.json' || (bool) preg_match('#^config/.+\.ya?ml$#', $scan_lname);
				$is_binary_scan = (strpos($scan_content, "\0") !== false) || (bool) preg_match('#\.(png|jpe?g|gif|webp|ico|bmp|zip|tar|gz|bz2|7z|rar|pdf|woff2?|ttf|eot|otf|mp3|mp4|mov|avi|webm|ogg)$#', $scan_lname);

				if ($is_vendor_scan || !$is_supported_scan || $is_binary_scan)
				{
					continue;
				}

				if (strpos($scan_content, "\r\n") !== false)
				{
					$targets[$scan_name]['line-ending-crlf'] = true;
				}
				if (preg_match('/\r(?!\n)/', $scan_content))
				{
					$targets[$scan_name]['line-ending-cr'] = true;
				}

				if ($this->validation_space_indent_count($scan_content) >= 5)
				{
					if (preg_match('#\.php$#', $scan_lname) && $this->validation_normalize_php_indentation($scan_content) !== $scan_content)
					{
						$targets[$scan_name]['coding-indent-tabs-php'] = true;
					}
					else if ($scan_lname === 'composer.json' && $this->validation_normalize_composer_indentation($scan_content) !== $scan_content)
					{
						$targets[$scan_name]['coding-indent-tabs-json'] = true;
					}
				}
			}
		}

		if (empty($targets))
		{
			return $this->json_success([
				'applied' => 0,
				'files' => [],
				'report' => $before_report,
			]);
		}

		$changed_files = [];
		$applied = 0;
		$this->db->sql_transaction('begin');

		try
		{
			foreach ($rows as $row)
			{
				$file_name = (string) $row['file_name'];
				if (empty($targets[$file_name]))
				{
					continue;
				}

				$old_content = (string) $row['file_content'];
				$new_content = $old_content;
				$rules_applied = [];

				if (!empty($targets[$file_name]['utf8-bom']) && substr($new_content, 0, 3) === "\xEF\xBB\xBF")
				{
					$new_content = substr($new_content, 3);
					$rules_applied[] = 'utf8-bom';
				}

				if (
					(!empty($targets[$file_name]['line-ending-crlf']) && strpos($new_content, "\r\n") !== false) ||
					(!empty($targets[$file_name]['line-ending-cr']) && preg_match('/\r(?!\n)/', $new_content))
				)
				{
					$had_crlf = (strpos($new_content, "\r\n") !== false);
					$had_cr = (bool) preg_match('/\r(?!\n)/', $new_content);
					$new_content = str_replace(["\r\n", "\r"], "\n", $new_content);
					if ($had_crlf)
					{
						$rules_applied[] = 'line-ending-crlf';
					}
					if ($had_cr)
					{
						$rules_applied[] = 'line-ending-cr';
					}
				}

				if (!empty($targets[$file_name]['php-open-tag-leading-whitespace']) && preg_match('#\.php$#', $file_name))
				{
					$bom = '';
					$candidate = $new_content;
					if (substr($candidate, 0, 3) === "\xEF\xBB\xBF")
					{
						$bom = "\xEF\xBB\xBF";
						$candidate = substr($candidate, 3);
					}
					$open_pos = strpos($candidate, '<?php');
					if ($open_pos !== false)
					{
						$prefix = substr($candidate, 0, $open_pos);
						if ($prefix !== '' && preg_match('/^\s+$/', $prefix))
						{
							$new_content = $bom . substr($candidate, $open_pos);
							$rules_applied[] = 'php-open-tag-leading-whitespace';
						}
					}
				}

				if (!empty($targets[$file_name]['php-closing-tag']) && preg_match('#\.php$#', $file_name))
				{
					$fixed = preg_replace('/\?>\s*$/', '', $new_content);
					if ($fixed !== $new_content)
					{
						$new_content = rtrim($fixed) . "\n";
						$rules_applied[] = 'php-closing-tag';
					}
				}

				if (!empty($targets[$file_name]['coding-indent-tabs-php']) && preg_match('#\.php$#', $file_name))
				{
					$fixed = $this->validation_normalize_php_indentation($new_content);
					if ($fixed !== $new_content)
					{
						$new_content = $fixed;
						$rules_applied[] = 'coding-indent-tabs-php';
					}
				}

				if ($file_name === 'composer.json')
				{
					$json = json_decode($new_content, true);
					if (is_array($json) && json_last_error() === JSON_ERROR_NONE)
					{
						if (!empty($targets[$file_name]['composer-type']) && (empty($json['type']) || (string) $json['type'] !== 'phpbb-extension'))
						{
							$json['type'] = 'phpbb-extension';
							$rules_applied[] = 'composer-type';
						}

						if (!empty($targets[$file_name]['composer-display-name']) && empty($json['extra']['display-name']))
						{
							if (empty($json['extra']) || !is_array($json['extra']))
							{
								$json['extra'] = [];
							}
							$display = 'phpBB Extension';
							if (!empty($json['name']) && strpos((string) $json['name'], '/') !== false)
							{
								$parts = explode('/', (string) $json['name'], 2);
								$display = ucwords(str_replace(['-', '_'], ' ', $parts[1]));
							}
							$json['extra']['display-name'] = $display;
							$rules_applied[] = 'composer-display-name';
						}

						$rewrite_json = !empty($rules_applied) || !empty($targets[$file_name]['coding-indent-tabs-json']);
						if ($rewrite_json)
						{
							$encoded = $this->validation_encode_json_with_tabs($json);
							if ($encoded !== '' && $encoded !== $new_content)
							{
								$new_content = $encoded;
								if (!empty($targets[$file_name]['coding-indent-tabs-json']))
								{
									$rules_applied[] = 'coding-indent-tabs-json';
								}
							}
						}
					}
				}

				if ($new_content === $old_content || empty($rules_applied))
				{
					continue;
				}

				if (isset($this->project_repo) && method_exists($this->project_repo, 'create_file_version'))
				{
					$this->project_repo->create_file_version(
						$project_id,
						(int) $row['file_id'],
						(int) $this->user->data['user_id'],
						$file_name,
						$old_content,
						'manual',
						'Snapshot antes de autocorrecao segura do validador phpBB.'
					);
				}

				$sql_ary = [
					'file_content' => $new_content,
					'file_time' => time(),
				];
				$this->db->sql_query(
					'UPDATE ' . $this->table_prefix . 'workspace_files
					 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
					 WHERE file_id = ' . (int) $row['file_id'] . '
					   AND project_id = ' . (int) $project_id
				);

				$applied += count($rules_applied);
				$changed_files[] = [
					'file_id' => (int) $row['file_id'],
					'file' => $file_name,
					'rules' => $rules_applied,
				];

				$this->log_to_changelog_internal($project_id, 'Validador phpBB: correcoes seguras aplicadas em ' . $file_name . ' (' . implode(', ', $rules_applied) . ').');
			}

			if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
			{
				$this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'release_fixes_applied', 'project', 'phpBB release autofix', [
					'applied' => $applied,
					'files' => count($changed_files),
				]);
			}

			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_ERR_UPDATE_FAILED');
		}

		$after_files = $this->get_project_files_for_validation($project_id);
		$after_report = $this->build_phpbb_release_validation($after_files, $scope);
		$after_epv = $this->run_official_epv($after_files, $scope);
		$after_report = $this->merge_official_epv_report($after_report, $after_epv);

		return $this->json_success([
			'applied' => $applied,
			'files' => $changed_files,
			'report' => $after_report,
		]);
	}

	private function get_project_file_rows_for_validation($project_id)
	{
		$sql = 'SELECT file_id, file_name, file_content, file_type
				FROM ' . $this->table_prefix . 'workspace_files
				WHERE project_id = ' . (int) $project_id . '
				ORDER BY file_name ASC';
		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$name = str_replace('\\', '/', (string) $row['file_name']);
			if ($name === '' || strtolower(basename($name)) === '.placeholder')
			{
				continue;
			}
			$row['file_name'] = $name;
			$rows[] = $row;
		}
		$this->db->sql_freeresult($result);
		return $rows;
	}


	private function validation_php_function_blocks($content)
	{
		$tokens = token_get_all((string) $content);
		$normalized = [];
		$offset = 0;
		foreach ($tokens as $token)
		{
			$text = is_array($token) ? (string) $token[1] : (string) $token;
			$normalized[] = [
				'id' => is_array($token) ? (int) $token[0] : null,
				'text' => $text,
				'offset' => $offset,
			];
			$offset += strlen($text);
		}

		$blocks = [];
		$count = count($normalized);
		for ($i = 0; $i < $count; $i++)
		{
			if ($normalized[$i]['id'] !== T_FUNCTION)
			{
				continue;
			}

			$function_name = '';
			$open_brace = -1;
			for ($j = $i + 1; $j < $count; $j++)
			{
				if ($normalized[$j]['id'] === T_STRING && $function_name === '')
				{
					$function_name = (string) $normalized[$j]['text'];
				}
				if ($normalized[$j]['text'] === '(' && $function_name === '')
				{
					break;
				}
				if ($normalized[$j]['text'] === '{')
				{
					$open_brace = $j;
					break;
				}
				if ($normalized[$j]['text'] === ';')
				{
					break;
				}
			}

			if ($open_brace < 0 || $function_name === '')
			{
				continue;
			}

			$depth = 0;
			$close_brace = -1;
			for ($j = $open_brace; $j < $count; $j++)
			{
				if ($normalized[$j]['text'] === '{')
				{
					$depth++;
				}
				else if ($normalized[$j]['text'] === '}')
				{
					$depth--;
					if ($depth === 0)
					{
						$close_brace = $j;
						break;
					}
				}
			}

			if ($close_brace < 0)
			{
				continue;
			}

			$start_offset = (int) $normalized[$i]['offset'];
			$end_offset = (int) $normalized[$close_brace]['offset'] + strlen((string) $normalized[$close_brace]['text']);
			$blocks[] = [
				'name' => $function_name,
				'offset' => $start_offset,
				'content' => substr((string) $content, $start_offset, $end_offset - $start_offset),
			];
			$i = $close_brace;
		}

		return $blocks;
	}

}
