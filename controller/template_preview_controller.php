<?php
namespace mundophpbb\workspace\controller;

use mundophpbb\workspace\service\template_preview;
use mundophpbb\workspace\service\release_file_map;

/** Read-only preview API. No Twig, PHP, or extension code is executed. */
class template_preview_controller extends base_controller
{
	const MAX_CATALOG_ROWS = 1200;
	/** @var template_preview */
	private $previewEngine;

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
		template_preview $previewEngine
	) {
		parent::__construct($helper, $template, $db, $table_prefix, $request, $user,
			$auth, $phpbb_root_path, $project_repo, $permission_service);
		$this->previewEngine = $previewEngine;
	}

	private function permit($projectId)
	{
		// Apply authorization/CSRF BEFORE any project metadata or content query.
		if ($r = $this->validate_post_form_key()) return $r;
		if ((int) ($this->user->data['user_id'] ?? 0) === ANONYMOUS) return $this->json_error('WSP_ERR_PERMISSION');
		if ((int) $projectId <= 0 || !$this->permission_service->can_access_workspace())
			return $this->json_error('WSP_ERR_PERMISSION');
		$permission = $this->assert_project_access((int) $projectId, 'edit');
		if (empty($permission['ok'])) return $this->json_error('WSP_ERR_PERMISSION');
		return null;
	}

	private function meta($projectId, $fileId)
	{
		if ($fileId <= 0) return null;
		$sql = 'SELECT file_id, file_name, LENGTH(file_content) AS source_bytes FROM ' .
			$this->table_prefix . 'workspace_files WHERE project_id = ' . (int) $projectId .
			' AND file_id = ' . (int) $fileId;
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row) return null;
		$row['path'] = release_file_map::normalizePath((string) $row['file_name']);
		return $row;
	}

	private function content($projectId, array $row, $limit)
	{
		if ((int) $row['source_bytes'] > $limit) return null;
		$sql = 'SELECT file_content FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' .
			(int) $projectId . ' AND file_id = ' . (int) $row['file_id'];
		$result = $this->db->sql_query_limit($sql, 1);
		$data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$data) return null;
		$content = (string) $data['file_content'];
		return strlen($content) <= $limit ? $content : null;
	}

	/** Catalog: lists only templates/styles of the already-authorized project. */
	public function catalog()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit($projectId)) return $r;
		$sql = 'SELECT file_id, file_name, LENGTH(file_content) AS source_bytes FROM ' .
			$this->table_prefix . 'workspace_files WHERE project_id = ' . $projectId . ' ORDER BY file_id ASC';
		$result = $this->db->sql_query_limit($sql, self::MAX_CATALOG_ROWS + 1);
		$templates = []; $styles = []; $seen = 0; $omitted = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$seen++;
			if ($seen > self::MAX_CATALOG_ROWS) break;
			$path = release_file_map::normalizePath((string) ($row['file_name'] ?? ''));
			if ($path === '' || !(int) ($row['file_id'] ?? 0)) continue;
			if (template_preview::isTemplate($path))
			{
				if (count($templates) >= 250) { $omitted++; continue; }
				$templates[] = ['id' => (int) $row['file_id'], 'path' => $path,
					'large' => (int) $row['source_bytes'] > template_preview::MAX_TEMPLATE_BYTES];
			}
			elseif (template_preview::isCss($path))
			{
				if (count($styles) >= 150) { $omitted++; continue; }
				$styles[] = ['id' => (int) $row['file_id'], 'path' => $path,
					'large' => (int) $row['source_bytes'] > template_preview::MAX_CSS_BYTES];
			}
		}
		$this->db->sql_freeresult($result);
		usort($templates, function ($a,$b) {return strcasecmp($a['path'],$b['path']);});
		usort($styles, function ($a,$b) {return strcasecmp($a['path'],$b['path']);});
		return $this->json_success(['project_id' => $projectId, 'templates' => $templates,
			'styles' => $styles, 'partial' => $seen > self::MAX_CATALOG_ROWS || $omitted > 0]);
	}

	/** Preview: sample-only template interpolation. No file writes. */
	public function renderPreview()
	{
		$projectId = (int) $this->request->variable('project_id', 0);
		if ($r = $this->permit($projectId)) return $r;
		$templateId = (int) $this->request->variable('template_id', 0);
		$cssId = (int) $this->request->variable('css_id', 0);
		$profile = (string) $this->request->variable('profile', 'member');
		if ($templateId <= 0 || $cssId < 0 || !in_array($profile, ['member','visitor'], true))
			return $this->json_error('WSP_TP_INVALID');
		$template = $this->meta($projectId, $templateId);
		if (!$template || !template_preview::isTemplate($template['path']))
			return $this->json_error('WSP_TP_INVALID');
		$css = null;
		if ($cssId > 0)
		{
			$css = $this->meta($projectId, $cssId);
			if (!$css || !template_preview::isCss($css['path']))
				return $this->json_error('WSP_TP_INVALID');
		}
		$draftFileId = (int) $this->request->variable('draft_file_id', 0);
		$draft = null;
		if ($draftFileId > 0)
		{
			// A draft may replace only the file selected by ID from this same project.
			if ($draftFileId !== $templateId && $draftFileId !== $cssId)
				return $this->json_error('WSP_TP_INVALID');
			$draft = $this->raw_post('draft_content', '');
			$max = $draftFileId === $templateId ? template_preview::MAX_TEMPLATE_BYTES : template_preview::MAX_CSS_BYTES;
			if (strlen($draft) > $max) return $this->json_error('WSP_TP_TOO_LARGE');
		}
		$markup = $draftFileId === $templateId ? $draft : $this->content($projectId, $template, template_preview::MAX_TEMPLATE_BYTES);
		$stylesheet = $css ? ($draftFileId === $cssId ? $draft : $this->content($projectId, $css, template_preview::MAX_CSS_BYTES)) : '';
		if ($markup === null || $stylesheet === null) return $this->json_error('WSP_TP_TOO_LARGE');
		try { $preview = $this->previewEngine->preview($markup, $stylesheet, $profile); }
		catch (\InvalidArgumentException $e) { return $this->json_error('WSP_TP_TOO_LARGE'); }
		return $this->json_success(['project_id' => $projectId, 'template_id' => $templateId,
			'css_id' => $cssId, 'profile' => $profile, 'draft_used' => $draftFileId > 0,
			'preview' => $preview]);
	}
}
