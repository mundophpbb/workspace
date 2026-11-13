<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\editor_settings;
use mundophpbb\workspace\service\github_public_client;
use mundophpbb\workspace\service\zip_importer;
use mundophpbb\workspace\repository\github_link_repository;

/**
 * Public GitHub repositories, opt-in and per-project. Never pushes or runs code.
 * All operations use authenticated POST, form token and the Workspace project ACL.
 */
class github_controller extends base_controller
{
	const MAX_IMPORT_FILES = 1800;
	const MAX_IMPORT_BYTES = 25165824;
	const MAX_PREVIEW_ROWS = 180;
	const CHECK_TTL = 600;

	private $links;
	private $github;

	public function __construct(
		\phpbb\controller\helper $helper,
		\phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db,
		$table_prefix,
		\phpbb\request\request $request,
		\phpbb\user $user,
		\phpbb\auth\auth $auth,
		$phpbb_root_path,
		\mundophpbb\workspace\repository\project_repository $project_repo,
		\mundophpbb\workspace\service\permission_service $permission_service,
		github_link_repository $links,
		github_public_client $github
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user, $auth,
			$phpbb_root_path, $project_repo, $permission_service);
		$this->links = $links;
		$this->github = $github;
		$this->github->set_gate([$this->links, 'claim_request']);
	}

	/** @return array|\Symfony\Component\HttpFoundation\JsonResponse */
	private function guard($level)
	{
		if ($r = $this->validate_post_form_key()) return $r;
		$settings = editor_settings::from_db($this->db, $this->table_prefix);
		if (empty($settings['github_enabled'])) return $this->json_error('WSP_GH_DISABLED');
		if (($level === 'import') && empty($settings['github_import_enabled']))
			return $this->json_error('WSP_GH_IMPORT_DISABLED');
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS ||
			!$this->permission_service->can_access_workspace()) return $this->json_error('WSP_ERR_PERMISSION');
		$project = (int) $this->request->variable('project_id', 0);
		$permissions = $level === 'manage' ? ['manage'] :
			($level === 'import' ? ['edit', 'upload', 'rename_move'] : ['edit']);
		if ($project <= 0) return $this->json_error('WSP_ERR_INVALID_DATA');
		foreach ($permissions as $cap)
			if (empty($this->assert_project_access($project, $cap)['ok']))
				return $this->json_error('WSP_ERR_PERMISSION');
		return ['project_id' => $project, 'user_id' => (int) $this->user->data['user_id']];
	}

	private function error_for(\Exception $e)
	{
		$map = [
			'unavailable' => 'WSP_GH_UNAVAILABLE', 'not_found' => 'WSP_GH_NOT_FOUND',
			'not_public' => 'WSP_GH_NOT_PUBLIC', 'invalid_ref' => 'WSP_GH_INVALID',
			'too_large' => 'WSP_GH_TOO_LARGE', 'rate_limited' => 'WSP_GH_RATE_LIMIT',
			'bad_archive' => 'WSP_GH_BAD_ARCHIVE', 'package_mismatch' => 'WSP_GH_PACKAGE_MISMATCH',
			'too_many' => 'WSP_GH_TOO_LARGE', 'too_big' => 'WSP_GH_TOO_LARGE',
			'diff_unavailable' => 'WSP_GH_DIFF_UNAVAILABLE',
			'empty' => 'WSP_GH_BAD_ARCHIVE', 'not_zip' => 'WSP_GH_BAD_ARCHIVE',
		];
		$key = $map[$e->getMessage()] ?? 'WSP_GH_FAILED';
		return $this->json_error($key);
	}

	private function link_data(array $row)
	{
		$owner = (string) $row['repo_owner']; $repo = (string) $row['repo_name'];
		return [
			'owner' => $owner, 'repo' => $repo, 'ref' => (string) $row['repo_ref'],
			'url' => 'https://github.com/' . $owner . '/' . $repo,
			'head_sha' => (string) $row['head_sha'],
			'checked_at' => (int) $row['checked_at'],
			'imported_sha' => (string) $row['imported_sha'],
			'imported_at' => (int) $row['imported_at'],
			'updates_available' => $row['imported_sha'] !== '' && $row['head_sha'] !== $row['imported_sha'],
			'never_imported' => $row['imported_sha'] === '',
		];
	}

	public function status()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$row = $this->links->get($ctx['project_id']);
		if (!$row) return $this->json_success(['project_id' => $ctx['project_id'], 'linked' => false]);
		$refresh = (int) $this->request->variable('refresh', 0) === 1;
		$cached = true;
		if ($refresh && time() - (int) $row['checked_at'] >= self::CHECK_TTL)
		{
			try
			{
				$sha = $this->github->head($row['repo_owner'], $row['repo_name'], $row['repo_ref']);
				if (!$this->links->refresh($ctx['project_id'], $sha))
					return $this->json_error('WSP_GH_FAILED');
				$row = $this->links->get($ctx['project_id']);
				$cached = false;
			}
			catch (\Exception $e) { return $this->error_for($e); }
		}
		return $this->json_success(['project_id' => $ctx['project_id'], 'linked' => true,
			'cached' => $cached, 'link' => $this->link_data($row)]);
	}

	public function link()
	{
		$ctx = $this->guard('manage');
		if (!is_array($ctx)) return $ctx;
		$identity = github_public_client::repository_parts($this->raw_post('repository'));
		$ref = trim($this->raw_post('ref'));
		if (!$identity || ($ref !== '' && !github_public_client::valid_ref($ref)))
			return $this->json_error('WSP_GH_INVALID');
		try
		{
			$meta = $this->github->public_repo($identity['owner'], $identity['repo']);
			if ($ref === '') $ref = $meta['default_branch'];
			$sha = $this->github->head($identity['owner'], $identity['repo'], $ref);
		}
		catch (\Exception $e) { return $this->error_for($e); }
		if (!$this->links->link($ctx['project_id'], $identity['owner'], $identity['repo'], $ref, $sha, $ctx['user_id']))
			return $this->json_error('WSP_GH_FAILED');
		return $this->json_success(['project_id' => $ctx['project_id'], 'linked' => true,
			'link' => $this->link_data($this->links->get($ctx['project_id']))]);
	}

	public function unlink_repo()
	{
		$ctx = $this->guard('manage');
		if (!is_array($ctx)) return $ctx;
		if (!$this->links->unlink($ctx['project_id'])) return $this->json_error('WSP_GH_FAILED');
		return $this->json_success(['project_id' => $ctx['project_id'], 'linked' => false]);
	}

	/**
	 * Opt-in, manually refreshed health summary. Each public API is independent:
	 * failure or rate limiting never gets mislabeled as a healthy repository.
	 * A stored imported SHA is historical evidence, not an actual file diff.
	 */
	public function health()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		$owner = (string) $link['repo_owner'];
		$repo = (string) $link['repo_name'];
		$data = [
			'project_id' => $ctx['project_id'],
			'checked_at' => time(),
			'link' => $this->link_data($link),
			'commit' => ['available' => false, 'error' => '', 'latest' => null],
			'release' => ['available' => false, 'error' => '', 'latest' => null],
			'actions' => ['available' => false, 'error' => '', 'latest' => null, 'total_count' => 0],
		];
		try
		{
			$commits = $this->github->commits($owner, $repo, (string) $link['repo_ref']);
			$data['commit']['available'] = true;
			$data['commit']['latest'] = $commits['commits'][0] ?? null;
		}
		catch (\Exception $e) { $data['commit']['error'] = $this->health_error($e); }
		try
		{
			$releases = $this->github->releases($owner, $repo);
			$data['release']['available'] = true;
			// The public endpoint excludes drafts and orders releases by GitHub's API.
			$data['release']['latest'] = $releases['releases'][0] ?? null;
		}
		catch (\Exception $e) { $data['release']['error'] = $this->health_error($e); }
		try
		{
			$runs = $this->github->workflow_runs($owner, $repo);
			$data['actions']['available'] = true;
			$data['actions']['total_count'] = (int) $runs['total_count'];
			// The latest run might belong to another branch; expose its branch.
			$data['actions']['latest'] = $runs['runs'][0] ?? null;
		}
		catch (\Exception $e) { $data['actions']['error'] = $this->health_error($e); }
		return $this->json_success($data);
	}

	private function health_error(\Exception $e)
	{
		// Never expose HTTP internals or remote response content.
		return $e->getMessage() === 'rate_limited' ? 'rate_limited' : 'unavailable';
	}

	/** Fetch recent GitHub Actions runs for the project's linked PUBLIC repository. */
	public function actions()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		try
		{
			$data = $this->github->workflow_runs((string) $link['repo_owner'], (string) $link['repo_name']);
		}
		catch (\Exception $e) { return $this->error_for($e); }
		return $this->json_success(['project_id' => $ctx['project_id'],
			'runs' => $data['runs'], 'total_count' => $data['total_count']]);
	}

	/** Recent public Releases only; no credentials or write operations. */
	public function releases()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		try
		{
			$data = $this->github->releases((string) $link['repo_owner'], (string) $link['repo_name']);
		}
		catch (\Exception $e) { return $this->error_for($e); }
		return $this->json_success(['project_id' => $ctx['project_id'], 'releases' => $data['releases']]);
	}

	/**
	 * Read-only comparison of a published Release to SAVED project files.
	 * Resolve tag -> SHA on the server; do not accept client-provided refs or URLs.
	 * The existing ZIP restrictions and package identity checks also apply here.
	 */
	public function compare_release()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		$id = (string) $this->request->variable('release_id', '');
		if (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $id))
			return $this->json_error('WSP_GH_INVALID');
		try
		{
			$data = $this->github->releases((string) $link['repo_owner'], (string) $link['repo_name']);
			$selected = null;
			foreach ($data['releases'] as $release)
				if ($release['id'] === $id && $release['compare_supported']) { $selected = $release; break; }
			if (!$selected) return $this->json_error('WSP_GH_RELEASE_NOT_FOUND');
			$ref = $selected['tag'];
			$sha = $this->github->head((string) $link['repo_owner'], (string) $link['repo_name'], $ref);
			$snapshot = $link;
			$snapshot['repo_ref'] = $ref;
			$snapshot['head_sha'] = $sha;
			$remote = $this->remote_files($snapshot);
			$plan = $this->plan($ctx['project_id'], $snapshot, $remote);
			$files = [];
			$remotePaths = [];
			foreach ($plan['entries'] as $entry)
			{
				$remotePaths[$entry['path']] = true;
				if ($entry['action'] !== 'unchanged' && count($files) < self::MAX_PREVIEW_ROWS)
					$files[] = ['path' => $entry['path'], 'action' => $entry['action']];
			}
			$onlyLocal = 0;
			foreach ($this->project_repo->get_project_files($ctx['project_id']) as $existing)
			{
				$path = (string) ($existing['file_name'] ?? '');
				if ($path === '' || isset($remotePaths[$path])) continue;
				$onlyLocal++;
				if (count($files) < self::MAX_PREVIEW_ROWS)
					$files[] = ['path' => $path, 'action' => 'local_only'];
			}
			$counts = $plan['counts'];
			$counts['local_only'] = $onlyLocal;
			return $this->json_success([
				'project_id' => $ctx['project_id'], 'release_id' => $id,
				'tag' => $ref, 'sha' => $sha, 'package' => $plan['package'],
				'counts' => $counts, 'files' => $files,
				'truncated' => $counts['created'] + $counts['updated'] + $onlyLocal > count($files),
				'skipped' => $plan['skipped'],
			]);
		}
		catch (\Exception $e) { return $this->error_for($e); }
	}

	/** Read-only, bounded per-file line diff for a verified published Release. */
	public function release_file_diff()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		$id = (string) $this->request->variable('release_id', '');
		$expected = (string) $this->request->variable('sha', '');
		$path = $this->raw_post('path');
		if (!preg_match('/\A[1-9][0-9]{0,17}\z/D', $id) ||
			!preg_match('/\A[a-f0-9]{40}\z/D', $expected) ||
			!is_string($path) || $path === '' || strlen($path) > 255 ||
			$this->sanitize_rel_path($path) !== $path)
			return $this->json_error('WSP_GH_INVALID');
		try
		{
			$data = $this->github->releases((string) $link['repo_owner'], (string) $link['repo_name']);
			$selected = null;
			foreach ($data['releases'] as $release)
				if ($release['id'] === $id && $release['compare_supported']) { $selected = $release; break; }
			if (!$selected) return $this->json_error('WSP_GH_RELEASE_NOT_FOUND');
			$sha = $this->github->head((string) $link['repo_owner'], (string) $link['repo_name'], $selected['tag']);
			if (!hash_equals($expected, $sha)) return $this->json_error('WSP_GH_CONFLICT');
			$snapshot = $link;
			$snapshot['repo_ref'] = $selected['tag'];
			$snapshot['head_sha'] = $sha;
			$remote = $this->remote_files($snapshot);
			$plan = $this->plan($ctx['project_id'], $snapshot, $remote);
			$entry = null;
			foreach ($plan['entries'] as $candidate)
				if ($candidate['path'] === $path) { $entry = $candidate; break; }
			$action = $entry ? $entry['action'] : 'local_only';
			if ($action === 'unchanged') return $this->json_error('WSP_GH_INVALID');
			if (!$entry)
			{
				$old = $this->project_repo->find_file($ctx['project_id'], $path);
				if (!$old) return $this->json_error('WSP_GH_INVALID');
				$local = (string) $old['file_content'];
				$github = '';
			}
			else
			{
				$local = $entry['before'] === null ? '' : $entry['before'];
				$github = $entry['content'];
			}
			$rows = \mundophpbb\workspace\service\github_line_diff::compare($local, $github);
			return $this->json_success(['project_id'=>$ctx['project_id'], 'release_id'=>$id,
				'sha'=>$sha, 'path'=>$path, 'action'=>$action, 'rows'=>$rows]);
		}
		catch (\Exception $e) { return $this->error_for($e); }
	}

	/** Public commit history for the specific branch/ref bound to this project. */
	public function commits()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		try
		{
			$data = $this->github->commits($link['repo_owner'], $link['repo_name'], $link['repo_ref']);
		}
		catch (\Exception $e) { return $this->error_for($e); }
		return $this->json_success(['project_id' => $ctx['project_id'], 'commits' => $data['commits']]);
	}

	/** Never trust a client-provided SHA: it must belong to the recent list of the bound ref. */
	private function selected_commit(array $link)
	{
		$sha = (string) $this->request->variable('sha', '');
		if (!preg_match('/\A[a-f0-9]{40}\z/D', $sha))
			throw new \RuntimeException('invalid_ref');
		$result = $this->github->commits($link['repo_owner'], $link['repo_name'], $link['repo_ref']);
		foreach ($result['commits'] as $commit)
			if (hash_equals($commit['sha'], $sha)) return $sha;
		throw new \RuntimeException('not_found');
	}

	/** Files touched by a recent commit, never arbitrary refs or patch content. */
	public function commit_detail()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		try
		{
			$sha = $this->selected_commit($link);
			$data = $this->github->commit_files($link['repo_owner'], $link['repo_name'], $sha);
		}
		catch (\Exception $e) { return $this->error_for($e); }
		return $this->json_success(['project_id'=>$ctx['project_id'], 'sha'=>$sha,
			'files'=>$data['files'], 'truncated'=>$data['truncated']]);
	}

	/** Compare a pinned recent commit against the project's saved files; no writes. */
	public function compare_commit()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		try
		{
			$sha = $this->selected_commit($link);
			$snapshot = $link;
			$snapshot['head_sha'] = $sha;
			$remote = $this->remote_files($snapshot);
			$plan = $this->plan($ctx['project_id'], $snapshot, $remote);
			$files = []; $remotePaths = [];
			foreach ($plan['entries'] as $entry)
			{
				$remotePaths[$entry['path']] = true;
				if ($entry['action'] !== 'unchanged' && count($files) < self::MAX_PREVIEW_ROWS)
					$files[] = ['path'=>$entry['path'], 'action'=>$entry['action']];
			}
			$onlyLocal = 0;
			foreach ($this->project_repo->get_project_files($ctx['project_id']) as $existing)
			{
				$path = (string) ($existing['file_name'] ?? '');
				if ($path === '' || isset($remotePaths[$path])) continue;
				$onlyLocal++;
				if (count($files) < self::MAX_PREVIEW_ROWS)
					$files[] = ['path'=>$path, 'action'=>'local_only'];
			}
			$counts = $plan['counts']; $counts['local_only'] = $onlyLocal;
			return $this->json_success(['project_id'=>$ctx['project_id'], 'sha'=>$sha,
				'package'=>$plan['package'], 'counts'=>$counts, 'files'=>$files,
				'truncated'=>$counts['created'] + $counts['updated'] + $onlyLocal > count($files),
				'skipped'=>$plan['skipped']]);
		}
		catch (\Exception $e) { return $this->error_for($e); }
	}

	/** Bounded visual text diff for a pinned recent commit. */
	public function commit_file_diff()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		$link = $this->links->get($ctx['project_id']);
		if (!$link) return $this->json_error('WSP_GH_NOT_LINKED');
		$path = $this->raw_post('path');
		if (!is_string($path) || $path === '' || strlen($path) > 255 ||
			$this->sanitize_rel_path($path) !== $path) return $this->json_error('WSP_GH_INVALID');
		try
		{
			$sha = $this->selected_commit($link);
			$snapshot = $link; $snapshot['head_sha'] = $sha;
			$remote = $this->remote_files($snapshot);
			$plan = $this->plan($ctx['project_id'], $snapshot, $remote);
			$entry = null;
			foreach ($plan['entries'] as $candidate)
				if ($candidate['path'] === $path) { $entry = $candidate; break; }
			$action = $entry ? $entry['action'] : 'local_only';
			if ($action === 'unchanged') return $this->json_error('WSP_GH_INVALID');
			if (!$entry)
			{
				$old = $this->project_repo->find_file($ctx['project_id'], $path);
				if (!$old) return $this->json_error('WSP_GH_INVALID');
				$local = (string) $old['file_content']; $github = '';
			}
			else
			{
				$local = $entry['before'] === null ? '' : $entry['before'];
				$github = $entry['content'];
			}
			$rows = \mundophpbb\workspace\service\github_line_diff::compare($local, $github);
			return $this->json_success(['project_id'=>$ctx['project_id'], 'sha'=>$sha,
				'path'=>$path, 'action'=>$action, 'rows'=>$rows]);
		}
		catch (\Exception $e) { return $this->error_for($e); }
	}

	/**
	 * Prepare a phpBB test workflow without modifying either the forum or GitHub.
	 * The developer must review and commit the YAML manually.
	 */
	public function workflow()
	{
		$ctx = $this->guard('read');
		if (!is_array($ctx)) return $ctx;
		if (!$this->links->get($ctx['project_id'])) return $this->json_error('WSP_GH_NOT_LINKED');
		$mode = (string) $this->request->variable('mode', 'basic');
		$branch = (string) $this->request->variable('phpbb_branch', '3.3.x');
		if (!in_array($mode, ['basic', 'full'], true) || !in_array($branch, ['3.3.x', 'master'], true))
			return $this->json_error('WSP_GH_INVALID');

		$files = $this->project_repo->get_project_files($ctx['project_id']);
		if (!is_array($files) || count($files) > 6000)
			return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		$rootManifests = [];
		$nestedManifests = [];
		foreach ($files as $file)
		{
			$path = (string) ($file['file_name'] ?? '');
			if ($path === 'composer.json') $rootManifests[] = $file;
			elseif (substr($path, -14) === '/composer.json') $nestedManifests[] = $file;
		}
		// Prefer the extension manifest at the project root. Ignore third-party
		// nested composers; when no root exists only one nested package is safe.
		$manifests = $rootManifests ? $rootManifests : $nestedManifests;
		if (count($manifests) !== 1) return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		$path = (string) $manifests[0]['file_name'];
		$manifestRow = $this->project_repo->find_file($ctx['project_id'], $path);
		if (!$manifestRow) return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		$composer = json_decode((string) ($manifestRow['file_content'] ?? ''), true);
		if (!is_array($composer) || ($composer['type'] ?? '') !== 'phpbb-extension')
			return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		$package = (string) ($composer['name'] ?? '');
		if ($path !== 'composer.json' && $path !== $package . '/composer.json')
			return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		$yaml = \mundophpbb\workspace\service\github_actions_workflow::build($package, $mode, $branch);
		if ($yaml === null) return $this->json_error('WSP_GH_WORKFLOW_MANIFEST');
		return $this->json_success(['project_id' => $ctx['project_id'],
			'filename' => '.github/workflows/tests.yml', 'yaml' => $yaml,
			'package' => $package, 'mode' => $mode, 'phpbb_branch' => $branch]);
	}

	/** File type rules matching the standard Workspace uploader. */
	private function github_file_type($path)
	{
		$base = strtolower(basename(str_replace('\\', '/', (string) $path)));
		if ($base === '.placeholder') return 'txt';
		if (in_array($base, ['dockerfile', 'makefile', '.htaccess'], true))
			return ltrim($base, '.');
		return strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) ?: 'txt';
	}

	/** Must be public for zip_importer callback. */
	public function github_entry_allowed($name)
	{
		return $this->is_placeholder_path($name) || $this->is_extension_allowed($name);
	}

	/** Remote snapshot is pinned to a validated SHA, not a changing branch. */
	protected function remote_files(array $link)
	{
		if (!class_exists('ZipArchive')) throw new \RuntimeException('bad_archive');
		$temp = $this->github->archive($link['repo_owner'], $link['repo_name'], $link['head_sha']);
		try
		{
			$importer = new zip_importer();
			$result = $importer->read($temp, [$this, 'github_entry_allowed'], $this->max_file_bytes());
		}
		finally { @unlink($temp); }
		foreach ($result['skipped'] as $skipped)
			if (in_array($skipped[1], ['unsafe', 'too_large', 'unreadable', 'outside_root'], true))
				throw new \RuntimeException('bad_archive');
		if (!isset($result['files']['composer.json'])) throw new \RuntimeException('bad_archive');
		$composer = json_decode((string) $result['files']['composer.json'], true);
		if (!is_array($composer) || ($composer['type'] ?? '') !== 'phpbb-extension' ||
			!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#D', (string) ($composer['name'] ?? '')))
			throw new \RuntimeException('bad_archive');
		if (count($result['files']) > self::MAX_IMPORT_FILES)
			throw new \RuntimeException('too_many');
		$total = 0;
		foreach ($result['files'] as $content)
		{
			$total += strlen($content);
			if ($total > self::MAX_IMPORT_BYTES) throw new \RuntimeException('too_large');
		}
		return ['files' => $result['files'], 'package' => (string) $composer['name'],
			'skipped' => count($result['skipped'])];
	}

	/** Every candidate is matched by the exact stored path, with case-fold collisions rejected. */
	private function plan($projectId, array $link, array $remote)
	{
		$metadata = $this->project_repo->get_project_files($projectId);
		if (count($metadata) > 6000) throw new \RuntimeException('too_large');
		$existing = [];
		$composers = [];
		foreach ($metadata as $m)
		{
			$path = (string) $m['file_name'];
			$lower = strtolower($path);
			if (isset($existing[$lower])) throw new \RuntimeException('package_mismatch');
			$existing[$lower] = $path;
			if ($lower === 'composer.json' || substr($lower, -14) === '/composer.json') $composers[] = $path;
		}
		if (count($composers) > 1) throw new \RuntimeException('package_mismatch');
		$prefix = '';
		if ($composers)
		{
			$path = $composers[0];
			$prefix = $path === 'composer.json' ? '' : substr($path, 0, -13);
			if ($prefix !== '' && $prefix !== $remote['package'] . '/')
				throw new \RuntimeException('package_mismatch');
			$entry = $this->project_repo->find_file($projectId, $path);
			$local = $entry ? json_decode((string) $entry['file_content'], true) : null;
			if (!is_array($local) || ($local['name'] ?? '') !== $remote['package'])
				throw new \RuntimeException('package_mismatch');
		}
		$entries = [];
		$seen = [];
		$summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
		$tokenData = [$projectId, $link['repo_owner'], $link['repo_name'], $link['repo_ref'], $link['head_sha'], $remote['package']];
		$bytes = 0;
		foreach ($remote['files'] as $relative => $content)
		{
			$path = $prefix . $relative;
			if (strlen($path) > 255 || $path !== $this->sanitize_rel_path($path) ||
				(!$this->is_placeholder_path($path) && !$this->is_extension_allowed($path)))
				throw new \RuntimeException('bad_archive');
			$lower = strtolower($path);
			if (isset($seen[$lower])) throw new \RuntimeException('bad_archive');
			$seen[$lower] = true;
			$previousName = $existing[$lower] ?? null;
			if ($previousName !== null && $previousName !== $path)
				throw new \RuntimeException('package_mismatch');
			$old = $previousName === null ? null : $this->project_repo->find_file($projectId, $path);
			if ($previousName !== null && !$old) throw new \RuntimeException('package_mismatch');
			$bytes += $old ? strlen((string) $old['file_content']) : 0;
			if ($bytes > self::MAX_IMPORT_BYTES) throw new \RuntimeException('too_large');
			$action = !$old ? 'create' : (((string) $old['file_content'] === $content) ? 'unchanged' : 'update');
			$summary[$action === 'create' ? 'created' : ($action === 'update' ? 'updated' : 'unchanged')]++;
			$entries[] = ['path' => $path, 'action' => $action, 'content' => $content,
				'file_id' => $old ? (int) $old['file_id'] : 0, 'rev' => $old ? (int) $old['file_rev'] : -1,
				'before' => $old ? (string) $old['file_content'] : null];
			$tokenData[] = [$path, $action, $old ? (int) $old['file_rev'] : -1,
				hash('sha256', (string) ($old['file_content'] ?? '')), hash('sha256', $content)];
		}
		return ['entries' => $entries, 'counts' => $summary,
			'token' => hash('sha256', json_encode($tokenData)),
			'package' => $remote['package'], 'skipped' => $remote['skipped']];
	}

	private function load_import_plan($projectId)
	{
		$link = $this->links->get($projectId);
		if (!$link) throw new \RuntimeException('not_found');
		if (!preg_match('/^[a-f0-9]{40}$/D', (string) $link['head_sha']))
			throw new \RuntimeException('invalid_ref');
		$remote = $this->remote_files($link);
		return ['link' => $link, 'plan' => $this->plan($projectId, $link, $remote)];
	}

	public function preview()
	{
		$ctx = $this->guard('import');
		if (!is_array($ctx)) return $ctx;
		try { $data = $this->load_import_plan($ctx['project_id']); }
		catch (\Exception $e) { return $this->error_for($e); }
		$items = [];
		foreach ($data['plan']['entries'] as $e)
			if ($e['action'] !== 'unchanged' && count($items) < self::MAX_PREVIEW_ROWS)
				$items[] = ['path' => $e['path'], 'action' => $e['action']];
		return $this->json_success(['project_id' => $ctx['project_id'],
			'preview_token' => $data['plan']['token'], 'sha' => $data['link']['head_sha'],
			'package' => $data['plan']['package'], 'counts' => $data['plan']['counts'],
			'files' => $items, 'truncated' => $data['plan']['counts']['created'] + $data['plan']['counts']['updated'] > count($items),
			'skipped' => $data['plan']['skipped']]);
	}

	public function apply()
	{
		$ctx = $this->guard('import');
		if (!is_array($ctx)) return $ctx;
		$token = $this->raw_post('preview_token');
		$sha = $this->raw_post('sha');
		if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !preg_match('/^[a-f0-9]{40}$/D', $sha))
			return $this->json_error('WSP_GH_PREVIEW_REQUIRED');
		try { $data = $this->load_import_plan($ctx['project_id']); }
		catch (\Exception $e) { return $this->error_for($e); }
		if (!hash_equals($data['plan']['token'], $token) || !hash_equals((string) $data['link']['head_sha'], $sha))
			return $this->json_error('WSP_GH_CONFLICT');
		// An automated multi-file import never overrides another member's file lock,
		// even when the caller is a project manager.
		foreach ($data['plan']['entries'] as $e)
		{
			if ($e['action'] !== 'update') continue;
			$lock = $this->project_repo->get_file_lock($e['file_id']);
			if ($lock && (int) ($lock['user_id'] ?? 0) !== $ctx['user_id'])
				return $this->json_error('WSP_ERR_FILE_LOCKED_BY', [(string) ($lock['username'] ?? '')]);
		}
		$this->db->sql_transaction('begin');
		foreach ($data['plan']['entries'] as $e)
		{
			if ($e['action'] === 'unchanged') continue;
			if ($e['action'] === 'create')
				$ok = $this->project_repo->insert_file($ctx['project_id'], $e['path'], $e['content'],
					$this->github_file_type($e['path']));
			else
			{
				$ok = $this->project_repo->update_file_content($e['file_id'], $e['content'],
					$this->github_file_type($e['path']), $e['rev']);
				if ($ok)
					$ok = $this->project_repo->create_file_version($ctx['project_id'], $e['file_id'], $ctx['user_id'],
						$e['path'], $e['before'], 'upload', 'Before GitHub import');
			}
			if (!$ok)
			{
				$this->db->sql_transaction('rollback');
				return $this->json_error('WSP_GH_CONFLICT');
			}
		}
		if (!$this->links->imported($ctx['project_id'], $sha))
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_GH_FAILED');
		}
		$this->project_repo->log_activity($ctx['project_id'], $ctx['user_id'], 'github_import', 'project',
			$data['plan']['package'], $data['plan']['counts']);
		$this->db->sql_transaction('commit');
		return $this->json_success(['project_id' => $ctx['project_id'], 'sha' => $sha,
			'counts' => $data['plan']['counts']]);
	}
}
