<?php
namespace mundophpbb\workspace\service;

/**
 * Literal, bounded project-wide source search and replacement planner.
 * It never interprets a project file as executable code, never executes a regex
 * provided by the user and never writes to a database.
 */
class refactor_engine
{
	const MAX_SEARCH_BYTES = 160;
	const MAX_REPLACE_BYTES = 320;
	const MAX_MATCHES = 1200;
	const MAX_CHANGED_FILES = 100;
	const MAX_FILE_BYTES = 524288;
	const MAX_SCAN_BYTES = 8388608;
	const MAX_PROJECT_FILES = 1000;

	public static function validate($search, $replacement, $filter)
	{
		if (!is_string($search) || strlen($search) < 2 || strlen($search) > self::MAX_SEARCH_BYTES ||
			!is_string($replacement) || strlen($replacement) > self::MAX_REPLACE_BYTES ||
			!is_string($filter) || strlen($filter) > 100 ||
			strpos($search, "\0") !== false || strpos($replacement, "\0") !== false ||
			preg_match('/[\r\n]/', $search) || preg_match('/[\x00-\x1f\x7f]/', $filter) ||
			preg_match('//u', $search) !== 1 || preg_match('//u', $replacement) !== 1 ||
			preg_match('//u', $filter) !== 1)
		{
			throw new \RuntimeException('WSP_RF_INVALID');
		}
	}

	public static function pattern($search, $caseSensitive, $wholeWord)
	{
		$term = preg_quote($search, '~');
		if ($wholeWord) $term = '(?<![\\p{L}\\p{N}_])' . $term . '(?![\\p{L}\\p{N}_])';
		return '~' . $term . '~u' . ($caseSensitive ? '' : 'i');
	}

	public static function candidatePath($path, $filter)
	{
		if (!is_string($path) || $path === '' || strlen($path) > 255 ||
			strpos($path, "\0") !== false || strpos($path, '\\') !== false || $path[0] === '/' ||
			preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $path) ||
			substr($path, -12) === '/.placeholder') return false;
		foreach (explode('/', $path) as $part)
			if ($part === '' || $part === '..' || $part === '.') return false;
		if ($filter !== '' && stripos($path, $filter) === false) return false;
		$base = strtolower(basename($path));
		if ($base === '.placeholder' || $base === 'changelog.txt') return false;
		$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
		return in_array($ext, [
			'php', 'js', 'ts', 'css', 'scss', 'sass', 'less', 'html', 'htm', 'twig',
			'json', 'xml', 'yml', 'yaml', 'sql', 'md', 'txt', 'csv', 'ini', 'conf',
			'sh', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'svg'
		], true) || in_array($base, ['composer.json', 'dockerfile', 'makefile', '.htaccess'], true);
	}

	/** Compute source line and a short sample without exposing full source over AJAX. */
	private static function sample($content, $offset)
	{
		$before = substr($content, 0, $offset);
		$line = substr_count($before, "\n") + 1;
		$start = strrpos($before, "\n");
		$start = $start === false ? 0 : $start + 1;
		$end = strpos($content, "\n", $offset);
		$end = $end === false ? strlen($content) : $end;
		$snippet = substr($content, $start, min(220, $end - $start));
		return ['line' => $line, 'text' => str_replace("\r", '', $snippet)];
	}

	/**
	 * @param array $rows Each row has file_id, file_name, file_rev and file_content.
	 * @return array Public preview + private updates, both bounded in size.
	 */
	public function plan(array $rows, $search, $replacement, $filter = '', $caseSensitive = true, $wholeWord = false)
	{
		self::validate($search, $replacement, $filter);
		$pattern = self::pattern($search, $caseSensitive, $wholeWord);
		$files = [];
		$updates = [];
		$totalMatches = 0;
		$scanned = 0;
		$scannedBytes = 0;
		$skipped = 0;
		$complete = true;
		$records = [];
		foreach ($rows as $row)
		{
			$path = (string) ($row['file_name'] ?? '');
			if (!self::candidatePath($path, $filter)) continue;
			$id = (int) ($row['file_id'] ?? 0);
			$rev = (int) ($row['file_rev'] ?? -1);
			$source = (string) ($row['file_content'] ?? '');
			$bytes = strlen($source);
			if ($id <= 0 || $rev < 0 || $bytes > self::MAX_FILE_BYTES ||
				$scannedBytes + $bytes > self::MAX_SCAN_BYTES ||
				strpos($source, "\0") !== false || preg_match('//u', $source) !== 1)
			{
				$skipped++;
				$complete = false;
				continue;
			}
			$scanned++;
			$scannedBytes += $bytes;
			// Limit PCRE match collection so a two-character pattern in a large file
			// cannot allocate an unbounded array of offset pairs.
			$samples = [];
			$count = 0;
			$offset = 0;
			while (true)
			{
				$match = [];
				$hit = preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset);
				if ($hit === false) throw new \RuntimeException('WSP_RF_INVALID');
				if ($hit === 0) break;
				$count++;
				if ($count <= 5) $samples[] = self::sample($source, (int) $match[0][1]);
				if ($totalMatches + $count > self::MAX_MATCHES) break;
				$length = strlen((string) $match[0][0]);
				if ($length === 0) throw new \RuntimeException('WSP_RF_INVALID');
				$offset = (int) $match[0][1] + $length;
				if ($offset >= $bytes) break;
			}
			if ($count === 0) continue;
			if ($totalMatches + $count > self::MAX_MATCHES || count($files) >= self::MAX_CHANGED_FILES)
			{
				$complete = false;
				$skipped++;
				continue;
			}
			$totalMatches += $count;
			$next = preg_replace_callback($pattern, static function () use ($replacement) {
				return $replacement;
			}, $source);
			if (!is_string($next)) throw new \RuntimeException('WSP_RF_INVALID');
			$files[] = ['file_id' => $id, 'path' => $path, 'rev' => $rev,
				'matches' => $count, 'samples' => $samples, 'changed' => $source !== $next];
			$updates[$id] = ['id' => $id, 'path' => $path, 'rev' => $rev,
				'old' => $source, 'new' => $next, 'count' => $count];
			$records[] = [$id, $path, $rev, hash('sha256', $source)];
		}
		usort($files, static function ($a, $b) { return strcmp($a['path'], $b['path']); });
		return [
			'files' => $files, 'updates' => $updates,
			'summary' => ['matches' => $totalMatches, 'matching_files' => count($files),
				'scanned' => $scanned, 'skipped' => $skipped, 'complete' => $complete],
			'fingerprint' => hash('sha256', json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
		];
	}

	public static function previewToken($projectId, $search, $replacement, $filter, $case, $whole, array $plan)
	{
		return hash('sha256', json_encode([
			(int) $projectId, (string) $search, (string) $replacement, (string) $filter,
			(bool) $case, (bool) $whole, $plan['fingerprint'], $plan['summary'],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
