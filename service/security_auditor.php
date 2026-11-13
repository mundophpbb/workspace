<?php
namespace mundophpbb\workspace\service;

/**
 * Bounded, read-only heuristic audit of phpBB extension source code.
 * Does not execute submitted PHP/JS, read the filesystem or disclose source snippets.
 * Findings are review prompts, not proof of a vulnerability.
 */
class security_auditor
{
	const MAX_FILES = 450;
	const MAX_TOTAL_BYTES = 6291456;
	const MAX_FILE_BYTES = 262144;
	const MAX_FINDINGS = 180;
	const MAX_FILE_FINDINGS = 30;

	private $findings = [];
	private $counts = [];
	private $seen = [];
	private $limitReached = false;

	/** @param array<string,string> $files @param array<string,int> $ids */
	public function analyze(array $files, array $ids = [])
	{
		$this->findings = [];
		$this->counts = [];
		$this->seen = [];
		$this->limitReached = false;
		$inspected = 0;
		$skipped = 0;
		$ignored = 0;
		$bytes = 0;
		foreach ($files as $path => $contents)
		{
			$path = str_replace('\\', '/', (string) $path);
			if (!is_string($contents) || !$this->isSource($path)) { $ignored++; continue; }
			$size = strlen($contents);
			if ($inspected >= self::MAX_FILES || $size > self::MAX_FILE_BYTES || $bytes + $size > self::MAX_TOTAL_BYTES)
			{
				$skipped++;
				continue;
			}
			$inspected++;
			$bytes += $size;
			$this->counts[$path] = 0;
			$fileId = (int) ($ids[$path] ?? 0);
			if (preg_match('/\.php$/i', $path)) $this->scanPhp($path, $contents, $fileId);
			else if (preg_match('/\.(?:twig|html)$/i', $path)) $this->scanTemplate($path, $contents, $fileId);
			else if (preg_match('/\.js$/i', $path)) $this->scanJs($path, $contents, $fileId);
			else $this->scanConfiguration($path, $contents, $fileId);
		}
		$order = ['high' => 0, 'medium' => 1, 'low' => 2];
		usort($this->findings, static function ($a, $b) use ($order) {
			return ($order[$a['severity']] <=> $order[$b['severity']]) ?: strcmp($a['file'], $b['file']) ?: ($a['line'] <=> $b['line']) ?: strcmp($a['code'], $b['code']);
		});
		$summary = ['high' => 0, 'medium' => 0, 'low' => 0];
		$categories = [];
		foreach ($this->findings as $finding)
		{
			$summary[$finding['severity']]++;
			$categories[$finding['category']] = ($categories[$finding['category']] ?? 0) + 1;
		}
		ksort($categories);
		return [
			'static_only' => true,
			'findings' => $this->findings,
			'summary' => [
				'total' => count($this->findings), 'by_severity' => $summary,
				'by_category' => $categories, 'analyzed_files' => $inspected,
			],
			'coverage' => [
				'complete' => !$skipped && !$this->limitReached,
				'skipped' => $skipped, 'ignored_non_source' => $ignored,
				'capped_findings' => $this->limitReached, 'analyzed_bytes' => $bytes,
			],
		];
	}

	private function isSource($path)
	{
		if (preg_match('#(^|/)(?:ace|vendor|node_modules|third_party|tests?|fixtures|coverage|dist)(/|$)#i', $path)) return false;
		return (bool) preg_match('/\.(?:php|js|twig|html|yml|yaml|json|ini|env)$/i', $path);
	}

	private function add($code, $category, $severity, $confidence, $path, $line, $fileId)
	{
		$line = max(1, (int) $line);
		$key = $path . ':' . $line . ':' . $code;
		if (isset($this->seen[$key])) return;
		$globalFull = count($this->findings) >= self::MAX_FINDINGS;
		$fileFull = ($this->counts[$path] ?? 0) >= self::MAX_FILE_FINDINGS;
		if ($globalFull || $fileFull)
		{
			// Keep bounded memory while retaining later high-severity matches.
			// A high finding can evict a low finding, but never the reverse.
			$priority = ['high' => 0, 'medium' => 1, 'low' => 2];
			$victim = -1;
			$victimRank = $priority[$severity];
			foreach ($this->findings as $index => $existing)
			{
				if ($fileFull && $existing['file'] !== $path) continue;
				$rank = $priority[$existing['severity']];
				if ($rank > $victimRank) { $victim = $index; $victimRank = $rank; }
			}
			$this->limitReached = true;
			if ($victim < 0) return;
			$old = $this->findings[$victim];
			unset($this->seen[$old['file'] . ':' . $old['line'] . ':' . $old['code']]);
			$this->counts[$old['file']]--;
			array_splice($this->findings, $victim, 1);
		}
		$this->seen[$key] = true;
		$this->counts[$path] = ($this->counts[$path] ?? 0) + 1;
		$this->findings[] = [
			'code' => $code, 'category' => $category, 'severity' => $severity,
			'confidence' => $confidence, 'file' => $path, 'line' => $line,
			'file_id' => $fileId,
		];
	}

	/** Remove PHP comments while preserving line breaks. No code is evaluated. */
	private function withoutPhpComments($source, $maskStrings = false)
	{
		if (strpos($source, '<?php') === false && strpos($source, '&lt;?php') !== false)
			$source = str_replace('&lt;?php', '<?php', $source);
		$tokens = token_get_all($source);
		$text = '';
		foreach ($tokens as $token)
		{
			if (!is_array($token)) { $text .= $token; continue; }
			$part = $token[1];
			if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT ||
				($maskStrings && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE)))
				$text .= preg_replace('/[^\r\n]/', ' ', $part);
			else $text .= $part;
		}
		return $text;
	}

	private function lineAt($text, $offset)
	{
		return 1 + substr_count(substr($text, 0, (int) $offset), "\n");
	}

	private function matchRule($text, $pattern, $code, $category, $severity, $confidence, $path, $fileId)
	{
		$matches = [];
		if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) return;
		foreach ($matches as $match)
		{
			$this->add($code, $category, $severity, $confidence, $path, $this->lineAt($text, $match[0][1]), $fileId);
			if ($this->limitReached) return;
		}
	}

	private function scanPhp($path, $source, $fileId)
	{
		$configText = $this->withoutPhpComments($source);
		$text = $this->withoutPhpComments($source, true);
		$this->matchRule($text, '/\b(?:echo|print)\s*(?:\(\s*)?\$_(?:GET|POST|REQUEST)\s*\b/i', 'XSS_DIRECT_OUTPUT', 'xss', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\b(?:echo|print)\s*(?:\(\s*)?\$[\w]+->(?:raw_variable)\s*\(/i', 'XSS_RAW_OUTPUT', 'xss', 'medium', 'medium', $path, $fileId);
		$this->matchRule($text, '/\b(?:sql_query|sql_query_limit|mysqli_query|mysql_query)\s*\([^;\n]{0,330}\$_(?:GET|POST|REQUEST)\b/i', 'SQL_INPUT', 'sql', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\b(?:sql_query|sql_query_limit)\s*\([^;\n]{0,330}(?:->(?:raw_variable|variable)\s*\()/i', 'SQL_REQUEST', 'sql', 'medium', 'medium', $path, $fileId);
		$this->matchRule($text, '/\b(?:mysql_query|mysqli_query|mysqli_real_query)\s*\(/i', 'SQL_DBAL', 'sql', 'medium', 'high', $path, $fileId);
		$this->matchRule($text, '/\beval\s*\(/i', 'DYNAMIC_EVAL', 'execution', 'high', 'medium', $path, $fileId);
		$this->matchRule($text, '/\b(?:include|include_once|require|require_once)\s*(?:\(\s*)?\$_(?:GET|POST|REQUEST)\b/i', 'DYNAMIC_INCLUDE', 'execution', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\bunserialize\s*\(\s*\$_(?:GET|POST|REQUEST)\b/i', 'INPUT_UNSERIALIZE', 'execution', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\b(?:shell_exec|exec|system|passthru|proc_open)\s*\(\s*(?:\$_(?:GET|POST|REQUEST)\b|\$[\w]+->raw_variable\s*\()/i', 'INPUT_COMMAND', 'execution', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\bCURLOPT_SSL_VERIFYPEER\s*,\s*(?:false|0)\b/i', 'TLS_VERIFICATION', 'transport', 'high', 'high', $path, $fileId);
		$this->matchRule($text, '/\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b/i', 'SUPERGLOBAL_INPUT', 'input', 'low', 'high', $path, $fileId);
		if (preg_match('/\bmove_uploaded_file\s*\(/i', $text) && !preg_match('/\b(?:allowed_extensions|allowed_types|validate_extension|check_extension|is_allowed|pathinfo|mime_content_type|finfo_file|upload_validator|validate_upload)\b/i', $text))
			$this->matchRule($text, '/\bmove_uploaded_file\s*\(/i', 'UPLOAD_REVIEW', 'upload', 'medium', 'low', $path, $fileId);
		$this->scanPhpMethods($path, $text, $fileId);
		$this->scanConfiguration($path, $configText, $fileId);
		// Add lower-priority style findings last so they cannot hide a stronger finding.
		$this->matchRule($text, '/\$_(?:GET|POST|REQUEST|COOKIE|FILES)\b/i', 'SUPERGLOBAL_INPUT', 'input', 'low', 'high', $path, $fileId);
	}

	/** Checks only obvious inline mutating POST handlers; never certifies missing guards. */
	private function scanPhpMethods($path, $text, $fileId)
	{
		if (!preg_match('#(?:^|/)(?:controller|acp)/#i', $path)) return;
		if (!preg_match_all('/\bpublic\s+function\s+([A-Za-z_]\w*)\s*\([^)]{0,450}\)\s*(?::\s*[\\?\w]+\s*)?\{/i', $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return;
		foreach ($found as $method)
		{
			$name = strtolower($method[1][0]);
			if (!preg_match('/^(?:save|store|create|add|delete|remove|update|set|submit|upload|apply|restore|replace|import|handle)/', $name)) continue;
			$start = $method[0][1] + strlen($method[0][0]);
			$depth = 1;
			$end = $start;
			$limit = min(strlen($text), $start + 40000);
			for (; $end < $limit && $depth > 0; $end++)
			{
				if ($text[$end] === '{') $depth++;
				if ($text[$end] === '}') $depth--;
			}
			if ($depth !== 0) continue;
			$body = substr($text, $start, $end - $start);
			if (!preg_match('/\bis_set_post\s*\(|\$_POST\b|request_interface::POST\b/i', $body)) continue;
			if (!preg_match('/\bsql_query\s*\(|->(?:set|insert|update|delete|save|execute)\s*\(/i', $body)) continue;
			$line = $this->lineAt($text, $method[0][1]);
			if (!preg_match('/\b(?:check_form_key|validate_post_form_key|validate_csrf|check_csrf|verify_csrf)\s*\(/i', $body))
				$this->add('CSRF_REVIEW', 'csrf', 'medium', 'low', $path, $line, $fileId);
			if (!preg_match('/\b(?:assert_project_access|acl_get|acl_gets|can_edit|can_manage|can_access|require_permission|authorize|permit)\s*\(|\bpermission_service\b/i', $body))
				$this->add('AUTH_REVIEW', 'authorization', 'medium', 'low', $path, $line, $fileId);
		}
	}

	private function scanTemplate($path, $source, $fileId)
	{
		if (preg_match('/\.(?:twig|html)$/i', $path))
		{
			$this->matchRule($source, '/\{\{[^}\n]{1,220}\|\s*raw\b[^}\n]*\}\}/i', 'TWIG_RAW', 'xss', 'medium', 'medium', $path, $fileId);
			$this->matchRule($source, '/\{[^}\n]{1,220}\|\s*raw\b[^}\n]*\}/i', 'TWIG_RAW', 'xss', 'medium', 'medium', $path, $fileId);
		}
	}

	private function scanJs($path, $source, $fileId)
	{
		$this->matchRule($source, '/^[^\r\n\/]*\.innerHTML\s*=\s*(?!["\x27`]\s*["\x27`])[^;\r\n]{1,200}/mi', 'JS_HTML_SINK', 'xss', 'medium', 'medium', $path, $fileId);
		$this->matchRule($source, '/\.html\s*\(\s*(?!["\x27`])(?:[a-z_$][\w$]*\b|\$\(|window\.)/i', 'JQUERY_HTML_SINK', 'xss', 'low', 'low', $path, $fileId);
	}

	private function scanConfiguration($path, $source, $fileId)
	{
		// No secret value or code snippet is sent to the browser (including in reports).
		$this->matchRule($source,
			'/(?:["\x27]?\b(?:api[_-]?key|secret[_-]?key|access[_-]?token|private[_-]?token|password|passwd)["\x27]?)\s*(?:=>|[:=])\s*["\x27](?!example|dummy|changeme|placeholder|your[_-]|test[_-])[^"\x27\r\n]{12,}["\x27]/i',
			'EMBEDDED_SECRET', 'secrets', 'medium', 'low', $path, $fileId);
	}
}
