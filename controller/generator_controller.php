<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\feature_generator;
use mundophpbb\workspace\service\phpunit_scaffolder;

/**
 * Two-step phpBB scaffold generator. Never executes generated code.
 * Preview and apply both enforce project authorization and CSRF.
 */
class generator_controller extends base_controller
{
	const MAX_PREVIEW_BYTES = 1048576;
	const MAX_FILES = 12;

	private $generator;

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
		feature_generator $generator
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->generator = $generator;
	}

	private function setup()
	{
		if ($r = $this->validate_post_form_key()) return ['error' => $r];
		if (!$this->permission_service->can_access_workspace() ||
			(int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS)
		{
			return ['error' => $this->json_error('WSP_ERR_PERMISSION')];
		}
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($projectId <= 0) return ['error' => $this->json_error('WSP_ERR_INVALID_DATA')];
		// New files + edits to config files require both permissions.
		foreach (['edit', 'rename_move'] as $capability)
		{
			$access = $this->assert_project_access($projectId, $capability);
			if (empty($access['ok'])) return ['error' => $this->json_error('WSP_ERR_PERMISSION')];
		}
		$type = $this->raw_post('kind');
		$feature = $this->raw_post('feature');
		$event = $this->raw_post('event');
		if (strlen($type) > 20 || strlen($feature) > 40 || strlen($event) > 80)
		{
			return ['error' => $this->json_error('WSP_GEN_INVALID')];
		}
		return ['project_id' => $projectId, 'kind' => $type, 'feature' => $feature, 'event' => $event];
	}

	/** Identify the extension from composer.json, using its original ZIP storage prefix. */
	private function extension_identity($projectId, array $storedNames)
	{
		$composers = [];
		foreach ($storedNames as $path)
		{
			if ($path === 'composer.json' || substr($path, -14) === '/composer.json')
			{
				$composers[] = $path;
			}
		}
		if (in_array('composer.json', $composers, true)) $composers = ['composer.json'];
		if (count($composers) !== 1) return null;
		$path = $composers[0];
		$row = $this->project_repo->find_file($projectId, $path);
		if (!$row || strlen((string) $row['file_content']) > 262144) return null;
		$json = json_decode((string) $row['file_content'], true);
		if (!is_array($json) || empty($json['name']) || !is_string($json['name'])) return null;
		$parts = explode('/', $json['name']);
		if (count($parts) !== 2) return null;
		$base = $path === 'composer.json' ? '' : substr($path, 0, -13);
		// Reject deeply nested or unrelated composer.json files.
		if ($base !== '' && $base !== $json['name'] . '/') return null;
		return ['vendor' => $parts[0], 'extension' => $parts[1], 'prefix' => $base];
	}

	/** Forbid appending indented YAML beneath an unrelated trailing top-level section. */
	private function is_last_yaml_section($source, $section)
	{
		if ($source === '') return true;
		if (!preg_match_all('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:/m', $source, $sections)) return false;
		return end($sections[1]) === $section;
	}

	/**
	 * Resolve an existing target without evaluating its PHP. The source
	 * revision/hash is included in the preview fingerprint, so changes to it
	 * also invalidate a previously reviewed generated test.
	 */
	private function testTarget($projectId, $kind, $feature, array $identity, array $stored)
	{
		$prefix = $identity['prefix'];
		$candidates = [];
		if ($kind === 'test_service')
			$candidates = ['service/' . $feature . '.php', 'service/' . $feature . '_service.php'];
		elseif ($kind === 'test_controller')
			$candidates = ['controller/' . $feature . '_controller.php', 'controller/' . $feature . '.php'];
		else
		{
			$candidates[] = 'config/permissions.yml';
			$count = 0;
			foreach ($stored as $name)
			{
				if (strpos($name, $prefix . 'migrations/') === 0 && substr($name, -4) === '.php')
				{
					if (++$count > 200) return ['error' => 'WSP_TEST_LIMIT'];
					$candidates[] = substr($name, strlen($prefix));
				}
			}
		}
		$scaffolder = new phpunit_scaffolder();
		$bytes = 0;
		foreach ($candidates as $relative)
		{
			$name = $prefix . $relative;
			$existing = $stored[strtolower($name)] ?? null;
			if ($existing === null) continue;
			if ($existing !== $name) return ['error' => 'WSP_GEN_COLLISION'];
			$row = $this->project_repo->find_file($projectId, $name);
			if (!$row) return ['error' => 'WSP_GEN_CONFLICT'];
			$src = (string) $row['file_content'];
			$bytes += strlen($src);
			if ($bytes > 3145728) return ['error' => 'WSP_TEST_LIMIT'];
			if (strlen($src) > phpunit_scaffolder::MAX_SOURCE_BYTES) continue;
			if ($kind === 'test_permission')
			{
				if (!$scaffolder->hasPermissionDeclaration($src, $relative, $feature)) continue;
			}
			else
			{
				$class = $scaffolder->inspectClass($src);
				if (!$class || $class['namespace'] !== $identity['vendor'] . '\\' . $identity['extension'] . '\\' . ($kind === 'test_service' ? 'service' : 'controller') ||
					$class['class'] !== pathinfo($relative, PATHINFO_FILENAME))
					continue;
			}
			return ['path' => $relative, 'content' => $src,
				'rev' => (int) $row['file_rev'], 'hash' => hash('sha256', $src)];
		}
		return ['error' => 'WSP_TEST_TARGET'];
	}

	private function staged($projectId, $kind, $feature, $event)
	{
		$metadata = $this->project_repo->get_project_files($projectId);
		if (count($metadata) > 6000) return ['error' => 'WSP_GEN_TOO_MANY'];
		$stored = [];
		foreach ($metadata as $row)
		{
			$name = (string) ($row['file_name'] ?? '');
			$lower = strtolower($name);
			if (isset($stored[$lower]) && $stored[$lower] !== $name)
				return ['error' => 'WSP_GEN_COLLISION'];
			$stored[$lower] = $name;
		}
		$identity = $this->extension_identity($projectId, array_values($stored));
		if (!$identity) return ['error' => 'WSP_GEN_COMPOSER'];
		$isTest = in_array($kind, phpunit_scaffolder::TYPES, true);
		$dependency = null;
		if ($isTest)
		{
			$scaffolder = new phpunit_scaffolder();
			if (!$scaffolder->validate($kind, $feature, $identity['vendor'], $identity['extension']))
				return ['error' => 'WSP_GEN_INVALID'];
			$dependency = $this->testTarget($projectId, $kind, $feature, $identity, $stored);
			if (isset($dependency['error'])) return ['error' => $dependency['error']];
			try
			{
				$spec = $scaffolder->build($kind, $feature, $identity['vendor'], $identity['extension'],
					$dependency['path'], $dependency['content']);
			}
			catch (\InvalidArgumentException $e) { return ['error' => 'WSP_TEST_TARGET']; }
			// Preserve a maintained PHPUnit config; create the sample only if absent.
			$config = $identity['prefix'] . 'phpunit.xml.dist';
			if (isset($stored[strtolower($config)])) unset($spec['create']['phpunit.xml.dist']);
		}
		else
		{
			$validation = $this->generator->validate($kind, $feature, $identity['vendor'], $identity['extension'], $event);
			if ($validation !== '') return ['error' => 'WSP_GEN_INVALID'];
			$spec = $this->generator->build($kind, $feature, $identity['vendor'], $identity['extension'], $event);
		}
		$changes = [];
		$total = 0;
		foreach (['create', 'append'] as $action)
		{
			foreach ($spec[$action] as $relative => $entry)
			{
				$name = $identity['prefix'] . $relative;
				if (strlen($name) > 255 || $name !== $this->sanitize_rel_path($name) ||
					!$this->is_extension_allowed($name)) return ['error' => 'WSP_GEN_INVALID'];
				$existingName = $stored[strtolower($name)] ?? null;
				// Avoid case-insensitive filename aliasing on MySQL installations.
				if ($existingName !== null && $existingName !== $name) return ['error' => 'WSP_GEN_COLLISION'];
				$row = $existingName !== null ? $this->project_repo->find_file($projectId, $name) : false;
				if ($existingName !== null && !$row) return ['error' => 'WSP_GEN_CONFLICT'];
				if ($action === 'create')
				{
					if ($row) return ['error' => 'WSP_GEN_EXISTS'];
					$next = (string) $entry;
				}
				else
				{
					$old = $row ? (string) $row['file_content'] : '';
					$format = $entry['format'];
					$marker = (string) $entry['marker'];
					if ($format === 'services')
					{
						if ($old !== '' && !preg_match('/^services\s*:/m', $old)) return ['error' => 'WSP_GEN_FORMAT'];
						// Append only while the intended top-level YAML section is last.
						// Otherwise a nested YAML key may accidentally attach to imports/parameters.
						if (!$this->is_last_yaml_section($old, 'services')) return ['error' => 'WSP_GEN_FORMAT'];
						$exists = preg_match('/^\s{4}' . preg_quote($marker, '/') . '\s*$/m', $old);
					}
					elseif ($format === 'permissions')
					{
						if ($old !== '' && !preg_match('/^permissions\s*:/m', $old)) return ['error' => 'WSP_GEN_FORMAT'];
						if (!$this->is_last_yaml_section($old, 'permissions')) return ['error' => 'WSP_GEN_FORMAT'];
						$exists = preg_match('/^\s{2}' . preg_quote($marker, '/') . '\s*$/m', $old);
					}
					elseif ($format === 'routing')
					{
						$exists = preg_match('/^' . preg_quote($marker, '/') . '\s*$/m', $old);
					}
					else
					{
						if ($old !== '' && (strpos(ltrim($old), '<?php') !== 0 || strpos($old, '?>') !== false || strpos($old, '$lang') === false))
							return ['error' => 'WSP_GEN_FORMAT'];
						$exists = strpos($old, $marker) !== false;
					}
					if ($exists) return ['error' => 'WSP_GEN_EXISTS'];
					$next = ($old === '' ? (string) $entry['header'] : rtrim($old, "\r\n") . "\n") . (string) $entry['snippet'] . "\n";
				}
				$total += strlen($next);
				if ($total > self::MAX_PREVIEW_BYTES || strlen($next) > $this->max_file_bytes())
					return ['error' => 'WSP_GEN_TOO_LARGE'];
				$changes[] = [
					'path' => $name,
					'action' => $row ? 'modify' : 'create',
					'file_id' => $row ? (int) $row['file_id'] : 0,
					'rev' => $row ? (int) $row['file_rev'] : -1,
					'before' => $row ? (string) $row['file_content'] : null,
					'content' => $next,
				];
			}
		}
		if (!$changes || count($changes) > self::MAX_FILES) return ['error' => 'WSP_GEN_INVALID'];
		// Every original revision AND byte hash is bound to the preview token.
		$checks = [$projectId, $kind, $feature, $event, $identity['vendor'], $identity['extension']];
		if ($dependency !== null)
			$checks[] = [$dependency['path'], $dependency['rev'], $dependency['hash']];
		foreach ($changes as $item)
		{
			$checks[] = [$item['path'], $item['action'], $item['rev'],
				hash('sha256', (string) $item['before']), hash('sha256', $item['content'])];
		}
		return ['changes' => $changes, 'token' => hash('sha256', json_encode($checks)), 'identity' => $identity];
	}

	public function preview()
	{
		$form = $this->setup();
		if (isset($form['error'])) return $form['error'];
		$plan = $this->staged($form['project_id'], $form['kind'], $form['feature'], $form['event']);
		if (isset($plan['error'])) return $this->json_error($plan['error']);
		$files = [];
		foreach ($plan['changes'] as $item)
		{
			$files[] = ['path' => $item['path'], 'action' => $item['action'],
				'content' => $item['content']];
		}
		return $this->json_success(['preview_token' => $plan['token'],
			'files' => $files, 'extension' => $plan['identity']['vendor'] . '/' . $plan['identity']['extension']]);
	}

	public function apply()
	{
		$form = $this->setup();
		if (isset($form['error'])) return $form['error'];
		$requestedToken = $this->raw_post('preview_token');
		if (!preg_match('/^[0-9a-f]{64}$/D', $requestedToken))
			return $this->json_error('WSP_GEN_PREVIEW_REQUIRED');
		$plan = $this->staged($form['project_id'], $form['kind'], $form['feature'], $form['event']);
		if (isset($plan['error'])) return $this->json_error($plan['error']);
		if (!hash_equals($plan['token'], $requestedToken)) return $this->json_error('WSP_GEN_CONFLICT');

		$projectId = $form['project_id'];
		// A collaborator's lock protects config/services.yml etc. too.
		foreach ($plan['changes'] as $item)
		{
			if ($item['file_id'] > 0)
			{
				if ($r = $this->ensure_file_unlocked($item['file_id'], $projectId)) return $r;
			}
		}
		$this->db->sql_transaction('begin');
		$userId = (int) $this->user->data['user_id'];
		$resultFiles = [];
		foreach ($plan['changes'] as $item)
		{
			if ($item['action'] === 'create')
			{
				$id = $this->project_repo->insert_file($projectId, $item['path'], $item['content'],
					strtolower(pathinfo($item['path'], PATHINFO_EXTENSION)));
				if (!$id)
				{
					$this->db->sql_transaction('rollback');
					return $this->json_error('WSP_GEN_CONFLICT');
				}
			}
			else
			{
				if (!$this->project_repo->update_file_content($item['file_id'], $item['content'], null, $item['rev']))
				{
					$this->db->sql_transaction('rollback');
					return $this->json_error('WSP_GEN_CONFLICT');
				}
				$this->project_repo->create_file_version($projectId, $item['file_id'], $userId,
					$item['path'], (string) $item['before'], 'generator', 'Before generated feature');
				$id = $item['file_id'];
			}
			$resultFiles[] = ['path' => $item['path'], 'file_id' => $id, 'action' => $item['action']];
		}
		$this->project_repo->log_activity($projectId, $userId, 'feature_generated', 'project',
			$form['kind'] . ':' . $form['feature']);
		$this->db->sql_transaction('commit');
		return $this->json_success(['files' => $resultFiles]);
	}
}
