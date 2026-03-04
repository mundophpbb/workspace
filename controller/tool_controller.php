<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mundo phpBB Workspace - Tool Controller
 * Versão 4.4 (corrigida): SSOT + Permissões Granulares (replace/purge_cache) + Diff/Search/Changelog seguros
 */
class tool_controller extends base_controller
{
    /**
     * SSOT de acesso ao Workspace (AJAX/JSON).
     * @return JsonResponse|null
     */
    private function ensure_workspace_access()
    {
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
     * Gate SSOT por capability do projeto (view/edit/replace/...)
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
     * Normaliza line endings para evitar diffs falsos.
     */
    private function normalize_content($content)
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $content);
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
     * Procura um termo em todos os arquivos de um projeto (Busca Global)
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

        $like = '%' . $this->db->sql_escape($search_term) . '%';

        $sql = 'SELECT file_id, file_name
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . "
                  AND file_content LIKE '" . $like . "'";

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
     * Substitui termos em arquivos e registra auditoria no changelog.
     * - file_id = 0 => substitui no projeto inteiro
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

        // Se file_id foi informado, valida que pertence ao projeto
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
     * Adiciona um cabeçalho de consolidação/versão ao changelog.txt (i18n puro)
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
     * Limpa o conteúdo do changelog.txt (i18n puro)
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

        // garante que exista
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
     * Gera comparação Diff com normalização de finais de linha.
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

        $diff      = new \Diff(explode("\n", $v1), explode("\n", $v2));
        $renderer  = new \Diff_Renderer_Text_Unified();
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
     * Limpa cache do phpBB via IDE (GLOBAL) - exige admin do Workspace + ACL específica.
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
     * Prepend de log no changelog.txt do projeto (cria se não existir).
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

        // cria changelog se não existir
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
}