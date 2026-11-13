<?php
namespace mundophpbb\workspace\service;

/**
 * GitHub PUBLIC repository client. No user tokens and no arbitrary URLs.
 * Redirects are never followed automatically, protecting forum hosts from SSRF.
 */
class github_public_client
{
	const MAX_JSON_BYTES = 524288;
	const MAX_ZIP_BYTES = 8388608;

	private $gate;

	public function set_gate(callable $gate)
	{
		$this->gate = $gate;
	}

	public static function repository_parts($input)
	{
		$input = trim((string) $input);
		if (strlen($input) > 240) return null;
		if (preg_match('~^https://github\.com/([^/?#]+)/([^/?#]+?)(?:\.git)?/?$~iD', $input, $match))
		{
			$owner = $match[1];
			$repo = $match[2];
		}
		elseif (preg_match('#^([^/]+)/([^/]+)$#D', $input, $match))
		{
			$owner = $match[1];
			$repo = $match[2];
		}
		else return null;
		if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/D', $owner) ||
			!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $repo) || $repo === '.' || $repo === '..' ||
			substr($repo, -4) === '.git') return null;
		return ['owner' => $owner, 'repo' => $repo];
	}

	public static function valid_ref($ref)
	{
		$ref = (string) $ref;
		if ($ref === '' || strlen($ref) > 120 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.\/-]*\z/', $ref) ||
			strpos($ref, '//') !== false || strpos($ref, '..') !== false || substr($ref, -1) === '/' ||
			substr($ref, -1) === '.' || preg_match('/(?:^|\/)\.|\.lock(?:\/|$)/i', $ref))
		{
			return false;
		}
		return true;
	}

	public static function safe_archive_redirect($url, $owner, $repo)
	{
		$p = parse_url((string) $url);
		if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' ||
			strtolower((string) ($p['host'] ?? '')) !== 'codeload.github.com' ||
			isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])) return false;
		$path = (string) ($p['path'] ?? '');
		return preg_match('#^/' . preg_quote($owner, '#') . '/' . preg_quote($repo, '#') .
			'/(?:legacy\.zip|zip)/[0-9a-f]{40}$#iD', $path) === 1 && empty($p['query']);
	}

	/** Strict TLS, no redirects, hard response cap, 8 seconds total. */
	private function fetch($url, $size, $sink = null)
	{
		if (!function_exists('curl_init')) throw new \RuntimeException('unavailable');
		if ($this->gate && !call_user_func($this->gate)) throw new \RuntimeException('rate_limited');
		$ch = curl_init($url);
		if (!$ch) throw new \RuntimeException('unavailable');
		$body = '';
		$over = false;
		$headers = [];
		$length = 0;
		$protocols = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2;
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12,
			CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROTOCOLS => $protocols,
			CURLOPT_USERAGENT => 'phpBB-Workspace/1.29 (public-repository-reader)',
			CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
			CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
				if (stripos($line, 'Location:') === 0) $headers['location'] = trim(substr($line, 9));
				return strlen($line);
			},
			CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$length, &$over, $size, $sink) {
				$n = strlen($chunk);
				$length += $n;
				if ($length > $size) { $over = true; return 0; }
				if ($sink)
				{
					$written = fwrite($sink, $chunk);
					return $written === false ? 0 : $written;
				}
				$body .= $chunk;
				return $n;
			},
		]);
		$ok = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($over) throw new \RuntimeException('too_large');
		if ($ok === false) throw new \RuntimeException('unavailable');
		if ($code === 403 || $code === 429) throw new \RuntimeException('rate_limited');
		if ($code === 404) throw new \RuntimeException('not_found');
		if ($code !== 200 && $code !== 302) throw new \RuntimeException('unavailable');
		return ['status' => $code, 'body' => $body, 'headers' => $headers];
	}

	private function json($path)
	{
		$response = $this->fetch('https://api.github.com' . $path, self::MAX_JSON_BYTES);
		if ($response['status'] !== 200) throw new \RuntimeException('unavailable');
		$data = json_decode($response['body'], true);
		if (!is_array($data)) throw new \RuntimeException('unavailable');
		return $data;
	}

	public function public_repo($owner, $repo)
	{
		$r = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo));
		if (!isset($r['private']) || $r['private'] !== false)
			throw new \RuntimeException('not_public');
		$branch = (string) ($r['default_branch'] ?? '');
		if (!self::valid_ref($branch)) throw new \RuntimeException('invalid_ref');
		return ['default_branch' => $branch, 'html_url' => 'https://github.com/' . $owner . '/' . $repo];
	}

	public function head($owner, $repo, $ref)
	{
		if (!self::valid_ref($ref)) throw new \RuntimeException('invalid_ref');
		$r = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits/' . rawurlencode($ref));
		$sha = (string) ($r['sha'] ?? '');
		if (!preg_match('/^[a-f0-9]{40}$/iD', $sha)) throw new \RuntimeException('unavailable');
		return strtolower($sha);
	}

	/**
	 * Recent public Actions runs (manual request only). Never accepts an arbitrary URL.
	 * Remote values are restricted to a small, display-safe structure.
	 */
	public function workflow_runs($owner, $repo)
	{
		$identity = self::repository_parts($owner . '/' . $repo);
		if (!$identity || $identity['owner'] !== $owner || $identity['repo'] !== $repo)
			throw new \RuntimeException('invalid_ref');
		$result = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) .
			'/actions/runs?per_page=8');
		if (!isset($result['workflow_runs']) || !is_array($result['workflow_runs']))
			throw new \RuntimeException('unavailable');
		$items = [];
		foreach (array_slice($result['workflow_runs'], 0, 8) as $run)
		{
			if (!is_array($run)) continue;
			$safe = self::normalize_workflow_run($run, $owner, $repo);
			if ($safe !== null) $items[] = $safe;
		}
		return ['total_count' => max(0, (int) ($result['total_count'] ?? count($items))), 'runs' => $items];
	}

	public static function normalize_workflow_run(array $run, $owner, $repo)
	{
		// Do not trust html_url, which may point to a third-party site.
		$id = (string) ($run['id'] ?? '');
		if (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $id)) return null;
		$statuses = ['queued','in_progress','completed','waiting','pending','requested','action_required'];
		$conclusions = ['success','failure','cancelled','skipped','neutral','timed_out','action_required','stale','startup_failure'];
		$status = (string) ($run['status'] ?? '');
		$conclusion = (string) ($run['conclusion'] ?? '');
		if (!in_array($status, $statuses, true)) $status = 'unknown';
		if (!in_array($conclusion, $conclusions, true)) $conclusion = '';
		$name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) ($run['name'] ?? '')));
		$branch = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) ($run['head_branch'] ?? '')));
		$sha = (string) ($run['head_sha'] ?? '');
		if (!preg_match('/\A[a-f0-9]{40}\z/iD', $sha)) $sha = '';
		$when = (string) ($run['created_at'] ?? '');
		if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $when)) $when = '';
		// Limit Unicode characters, not raw bytes (avoids invalid UTF-8 JSON output).
		preg_match('/\A.{0,90}/us', $name, $safeName);
		preg_match('/\A.{0,120}/us', $branch, $safeBranch);
		return [
			'id' => $id,
			'name' => $safeName[0] ?? '',
			'branch' => $safeBranch[0] ?? '',
			'status' => $status,
			'conclusion' => $conclusion,
			'sha' => strtolower($sha),
			'created_at' => $when,
			'url' => 'https://github.com/' . $owner . '/' . $repo . '/actions/runs/' . $id,
		];
	}

	/**
	 * List up to 8 PUBLISHED releases from the linked public repository.
	 * Git tags without a GitHub Release are not included by this endpoint.
	 * No remote URLs or release HTML is passed to the browser.
	 */
	public function releases($owner, $repo)
	{
		$identity = self::repository_parts($owner . '/' . $repo);
		if (!$identity || $identity['owner'] !== $owner || $identity['repo'] !== $repo)
			throw new \RuntimeException('invalid_ref');
		$data = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases?per_page=10');
		if (!is_array($data)) throw new \RuntimeException('unavailable');
		$items = [];
		foreach (array_slice($data, 0, 10) as $release)
		{
			if (!is_array($release)) continue;
			$safe = self::normalize_release($release);
			if ($safe !== null) $items[] = $safe;
			if (count($items) >= 8) break;
		}
		return ['releases' => $items];
	}

	/** Normalized, bounded data only; never trust GitHub's html_url. */
	public static function normalize_release(array $release)
	{
		if (!empty($release['draft'])) return null;
		$id = (string) ($release['id'] ?? '');
		$tag = (string) ($release['tag_name'] ?? '');
		if (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) ||
			!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+\/-]{0,119}\z/D', $tag)) return null;
		$title = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) ($release['name'] ?? ''));
		$notes = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) ($release['body'] ?? ''));
		// Bound string length by Unicode characters, preserving valid UTF-8 JSON.
		if (!preg_match('/\A.{0,120}/us', $title, $safeTitle)) $safeTitle = [];
		if (!preg_match('/\A.{0,550}/us', $notes, $safeNotes)) $safeNotes = [];
		$date = (string) ($release['published_at'] ?? '');
		if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $date)) $date = '';
		$assets = is_array($release['assets'] ?? null) ? count($release['assets']) : 0;
		return [
			'id' => $id,
			'tag' => $tag,
			'title' => trim($safeTitle[0] ?? ''),
			'notes' => trim($safeNotes[0] ?? ''),
			'published_at' => $date,
			'prerelease' => !empty($release['prerelease']),
			'assets_count' => min(100, $assets),
			'compare_supported' => self::valid_ref($tag),
		];
	}

	/** List recent commits on the linked branch/ref, with strict output bounds. */
	public function commits($owner, $repo, $ref)
	{
		$identity = self::repository_parts($owner . '/' . $repo);
		if (!$identity || $identity['owner'] !== $owner || $identity['repo'] !== $repo ||
			!self::valid_ref($ref)) throw new \RuntimeException('invalid_ref');
		$data = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) .
			'/commits?sha=' . rawurlencode($ref) . '&per_page=8');
		if (!is_array($data)) throw new \RuntimeException('unavailable');
		$items = [];
		foreach (array_slice($data, 0, 8) as $commit)
		{
			if (!is_array($commit)) continue;
			$safe = self::normalize_commit($commit);
			if ($safe !== null) $items[] = $safe;
		}
		return ['commits' => $items];
	}

	public static function normalize_commit(array $commit)
	{
		$sha = strtolower((string) ($commit['sha'] ?? ''));
		if (!preg_match('/\A[a-f0-9]{40}\z/D', $sha)) return null;
		$message = (string) ($commit['commit']['message'] ?? '');
		$message = preg_replace('/[\x00-\x1f\x7f]/', ' ', $message);
		$author = (string) ($commit['commit']['author']['name'] ?? '');
		$author = preg_replace('/[\x00-\x1f\x7f]/', ' ', $author);
		preg_match('/\A.{0,180}/us', $message, $m);
		preg_match('/\A.{0,80}/us', $author, $a);
		$date = (string) ($commit['commit']['author']['date'] ?? '');
		if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $date)) $date = '';
		return ['sha' => $sha, 'message' => trim($m[0] ?? ''),
			'author' => trim($a[0] ?? ''), 'date' => $date];
	}

	/** Show filenames modified by one of the eight currently recent commits. */
	public function commit_files($owner, $repo, $sha)
	{
		if (!preg_match('/\A[a-f0-9]{40}\z/D', (string) $sha)) throw new \RuntimeException('invalid_ref');
		$data = $this->json('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits/' . $sha);
		if (strtolower((string) ($data['sha'] ?? '')) !== $sha || !is_array($data['files'] ?? null))
			throw new \RuntimeException('unavailable');
		$files = []; $truncated = count($data['files']) > 100;
		foreach (array_slice($data['files'], 0, 100) as $file)
		{
			if (!is_array($file)) continue;
			$name = (string) ($file['filename'] ?? '');
			if ($name === '' || strlen($name) > 255 || strpos($name, "\0") !== false) continue;
			$status = (string) ($file['status'] ?? '');
			if (!in_array($status, ['added', 'modified', 'removed', 'renamed', 'copied', 'changed', 'unchanged'], true))
				$status = 'changed';
			$files[] = ['path' => $name, 'status' => $status];
		}
		return ['files' => $files, 'truncated' => $truncated];
	}

	/** Return a temporary ZIP file path; caller MUST unlink even on failure. */
	public function archive($owner, $repo, $sha)
	{
		if (!preg_match('/^[a-f0-9]{40}$/iD', (string) $sha)) throw new \RuntimeException('invalid_ref');
		$path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zipball/' . $sha;
		$r = $this->fetch('https://api.github.com' . $path, 4096);
		if ($r['status'] !== 302 || !isset($r['headers']['location']) ||
			!self::safe_archive_redirect($r['headers']['location'], $owner, $repo))
			throw new \RuntimeException('unavailable');
		$temp = tempnam(sys_get_temp_dir(), 'wspgh_');
		if (!$temp) throw new \RuntimeException('unavailable');
		$file = fopen($temp, 'wb');
		if (!$file) { @unlink($temp); throw new \RuntimeException('unavailable'); }
		try
		{
			$download = $this->fetch($r['headers']['location'], self::MAX_ZIP_BYTES, $file);
			if ($download['status'] !== 200 || ftell($file) < 20) throw new \RuntimeException('unavailable');
		}
		catch (\Exception $e)
		{
			fclose($file);
			@unlink($temp);
			throw $e;
		}
		fclose($file);
		return $temp;
	}
}
