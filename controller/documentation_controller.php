<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\documentation_builder;
use mundophpbb\workspace\service\release_notes_snapshot_reader;
use mundophpbb\workspace\service\checkpoint_manager;

/** Read-only documentation preview + guarded README generation. */
class documentation_controller extends base_controller
{
	private $builder;
	private $reader;

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
		documentation_builder $builder,
		release_notes_snapshot_reader $reader
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->builder = $builder;
		$this->reader = $reader;
	}

	private function args()
	{
		if ($r = $this->validate_post_form_key()) return ['response' => $r];
		$id = (int) $this->request->variable('project_id', 0);
		if ($id <= 0 || (int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS ||
			!$this->permission_service->can_access_workspace() ||
			empty($this->assert_project_access($id, 'edit')['ok']))
			return ['response' => $this->json_error('WSP_ERR_PERMISSION')];
		$locale = $this->raw_post('locale', 'pt_br');
		$target = $this->raw_post('target', 'generated');
		$replace = $this->raw_post('replace_existing', '0') === '1';
		if (!documentation_builder::validLocale($locale) ||
			!documentation_builder::validTarget($target))
			return ['response' => $this->json_error('WSP_DOC_INVALID')];
		return ['id' => $id, 'locale' => $locale, 'target' => $target, 'replace' => $replace];
	}

	/** Handles ZIP imports stored with vendor/extension wrappers. */
	private function targetPath(array $snapshot, $target)
	{
		$relative = $target === 'readme' ? 'README.md' : 'docs/README_GERADO.md';
		if (isset($snapshot['files'][$relative]))
			return (string) $snapshot['files'][$relative]['stored_path'];
		if (!isset($snapshot['files']['composer.json'])) throw new \RuntimeException('WSP_DOC_COMPOSER');
		$composer = (string) $snapshot['files']['composer.json']['stored_path'];
		$dir = str_replace('\\', '/', dirname($composer));
		$path = ($dir === '.' || $dir === '') ? $relative : $dir . '/' . $relative;
		if (!checkpoint_manager::validPath($path)) throw new \RuntimeException('WSP_DOC_INVALID');
		return $path;
	}

	private function failure(\RuntimeException $e)
	{
		$key = (string) $e->getMessage();
		if ($key === 'WSP_RN_SIZE_LIMIT') $key = 'WSP_DOC_TOO_LARGE';
		if ($key === 'WSP_RN_CONFLICT') $key = 'WSP_DOC_CHANGED';
		if (!in_array($key, ['WSP_DOC_INVALID', 'WSP_DOC_COMPOSER', 'WSP_DOC_TOO_LARGE',
			'WSP_DOC_CHANGED', 'WSP_DOC_LOCKED', 'WSP_DOC_OVERWRITE', 'WSP_DOC_CANNOT_CREATE',
			'WSP_DOC_FAILED'], true)) $key = 'WSP_DOC_FAILED';
		return $this->json_error($key);
	}

	public function preview()
	{
		$args = $this->args();
		if (isset($args['response'])) return $args['response'];
		try
		{
			$saved = $this->reader->capture($args['id']);
			$report = $this->builder->generate($saved, $args['locale']);
			$path = $this->targetPath($saved, $args['target']);
			$existing = $this->project_repo->find_file($args['id'], $path);
			$mayCreate = !empty($this->assert_project_access($args['id'], 'rename_move')['ok']);
			$locked = false;
			if ($existing)
			{
				$lock = $this->project_repo->get_file_lock((int) $existing['file_id']);
				$locked = $lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'];
			}
			$mayWrite = ($existing ? $args['replace'] : $mayCreate) && !$locked;
			return $this->json_success([
				'project_id' => $args['id'],
				'path' => $path,
				'exists' => (bool) $existing,
				'can_write' => (bool) $mayWrite,
				'locked' => (bool) $locked,
				'overwrite_required' => (bool) ($existing && !$args['replace']),
				'token' => documentation_builder::token($args['id'], $saved, $args['locale'],
					$args['target'], $args['replace']),
				'draft' => $report['draft'],
				'facts' => $report['facts'],
			]);
		}
		catch (\RuntimeException $e) { return $this->failure($e); }
		catch (\Exception $e) { return $this->json_error('WSP_DOC_FAILED'); }
	}

	public function apply()
	{
		$args = $this->args();
		if (isset($args['response'])) return $args['response'];
		$token = $this->raw_post('preview_token');
		$draft = $this->raw_post('draft');
		if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return $this->json_error('WSP_DOC_CHANGED');
		if (trim($draft) === '' || strlen($draft) > documentation_builder::MAX_DRAFT_BYTES)
			return $this->json_error('WSP_DOC_TOO_LARGE');
		if ($r = $this->check_file_size($draft)) return $r;

		$this->db->sql_transaction('begin');
		try
		{
			$snapshot = $this->reader->capture($args['id']);
			$valid = documentation_builder::token($args['id'], $snapshot, $args['locale'],
				$args['target'], $args['replace']);
			if (!hash_equals($valid, $token)) throw new \RuntimeException('WSP_DOC_CHANGED');
			// Recheck composer.json and package identity at write time.
			$this->builder->generate($snapshot, $args['locale']);
			$path = $this->targetPath($snapshot, $args['target']);
			$existing = $this->project_repo->find_file($args['id'], $path);
			if ($existing)
			{
				if (!$args['replace']) throw new \RuntimeException('WSP_DOC_OVERWRITE');
				$lock = $this->project_repo->get_file_lock((int) $existing['file_id']);
				if ($lock && (int) ($lock['user_id'] ?? 0) !== (int) $this->user->data['user_id'])
					throw new \RuntimeException('WSP_DOC_LOCKED');
				if (!$this->project_repo->create_file_version($args['id'], (int) $existing['file_id'],
					(int) $this->user->data['user_id'], $path, (string) $existing['file_content'],
					'save', 'Before automatic documentation'))
					throw new \RuntimeException('WSP_DOC_FAILED');
				if (!$this->project_repo->update_file_content((int) $existing['file_id'],
					$draft, 'md', (int) $existing['file_rev']))
					throw new \RuntimeException('WSP_DOC_CHANGED');
				$fileId = (int) $existing['file_id'];
			}
			else
			{
				if (empty($this->assert_project_access($args['id'], 'rename_move')['ok']))
					throw new \RuntimeException('WSP_DOC_CANNOT_CREATE');
				$fileId = (int) $this->project_repo->insert_file($args['id'], $path, $draft, 'md');
				if ($fileId <= 0) throw new \RuntimeException('WSP_DOC_CHANGED');
			}
			$this->db->sql_transaction('commit');
			$this->project_repo->log_activity($args['id'], (int) $this->user->data['user_id'],
				'documentation_saved', 'file', $path, ['target' => $args['target']]);
			return $this->json_success(['project_id' => $args['id'], 'file_id' => $fileId,
				'path' => $path]);
		}
		catch (\RuntimeException $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->failure($e);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			return $this->json_error('WSP_DOC_FAILED');
		}
	}
}
