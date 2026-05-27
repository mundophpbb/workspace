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
        $decoded = html_entity_decode($content, ENT_QUOTES, 'UTF-8');

        // Use the decoded form only when it exposes a PHP opening tag. This
        // avoids altering unrelated files for the validation pass.
        if (strpos($decoded, '<?php') !== false)
        {
            return $decoded;
        }

        return $content;
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

                // Skip quoted strings so values like "todo" are not treated as release TODOs.
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
                if (!preg_match('/(?:^|[^A-Za-z0-9_])(@?TODO|FIXME)\b/i', $segment, $todo_match))
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

    /**
     * Validador phpBB interno + checklist de release do projeto ativo.
     * Faz checagens estáticas em arquivos armazenados no Workspace, sem executar código do projeto.
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

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'release_validated', 'project', 'phpBB release checklist', [
                'errors' => (int) $report['summary']['errors'],
                'warnings' => (int) $report['summary']['warnings'],
                'passed' => (int) $report['summary']['passed'],
                'scope' => (string) $report['summary']['scope'],
            ]);
        }

        return $this->json_success($report);
    }

    private function get_project_files_for_validation($project_id)
    {
        $sql = 'SELECT file_name, file_content, file_type
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . '
                ORDER BY file_name ASC';
        $result = $this->db->sql_query($sql);
        $files = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $name = str_replace('\\', '/', (string) $row['file_name']);
            if ($name === '' || strtolower(basename($name)) === '.placeholder')
            {
                continue;
            }
            $files[$name] = (string) $row['file_content'];
        }
        $this->db->sql_freeresult($result);
        return $files;
    }

    private function normalize_validation_scope($scope)
    {
        $scope = strtolower((string) $scope);
        return in_array($scope, ['normal', 'full', 'phpbb_ext_db'], true) ? $scope : 'normal';
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
            if (!$is_supported_validation_source($name) && !$is_supported_config_file($name))
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
            if (!$is_ignored_by_scope && ($is_full_scope || !$is_include_area) && preg_match('#\.php$#', $name) && strpos($content, 'namespace ') === false && basename($name) !== 'ext.php')
            {
                $class_like_content = preg_match('/\b(class|interface|trait)\s+[A-Za-z_][A-Za-z0-9_]*/', $content);

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

        // Verificações cruzadas para tornar o checklist acionável.
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

        if ($exists('config/services.yml'))
        {
            preg_match_all('/class:\s*([^\r\n]+)/', $files['config/services.yml'], $class_matches);
            foreach ($class_matches[1] as $declared_class)
            {
                $declared_class = trim(str_replace(['\"', "'"], '', $declared_class));
                if ($declared_class === '' || strpos($declared_class, '%') !== false) { continue; }
                $class_path = str_replace('\\', '/', $declared_class) . '.php';
                if ($expected_namespace !== '' && strpos($declared_class, $expected_namespace . '\\') === 0)
                {
                    $relative = substr($class_path, strlen(str_replace('\\', '/', $expected_namespace)) + 1);
                    if ($relative !== '' && !array_key_exists($relative, $files))
                    {
                        $add('error', 'services.yml', 'Serviço declara classe que não foi encontrada no projeto.', 'config/services.yml', 'Corrija o caminho da classe no services.yml ou crie o arquivo correspondente.', $declared_class . ' => ' . $relative, 'service-class-file');
                    }
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

        $errors = 0; $warnings = 0;
        foreach ($issues as $issue)
        {
            if ($issue['severity'] === 'error') { $errors++; }
            else if ($issue['severity'] === 'warning') { $warnings++; }
        }
        $fixable = 0;
        foreach ($issues as $issue)
        {
            if (!empty($issue['fixable'])) { $fixable++; }
        }

        $passed = 0;
        foreach ($checklist as $item)
        {
            if ($item['status'] === 'pass') { $passed++; }
        }

        return [
            'summary' => [
                'errors' => $errors,
                'warnings' => $warnings,
                'passed' => $passed,
                'checks' => count($checklist),
                'files' => count($files),
                'scope' => $scope,
                'scope_label' => $scope_label,
                'ready' => ($errors === 0),
                'actionable' => count($issues),
                'fixable' => $fixable,
            ],
            'checklist' => $checklist,
            'issues' => $issues,
        ];
    }


    /**
     * Aplica apenas correcoes automaticas seguras apontadas pelo validador.
     * Regras seguras nesta versao:
     * - php-closing-tag: remove o ?> final de arquivos PHP puros.
     * - composer-type: define type=phpbb-extension em composer.json valido.
     * - composer-display-name: cria extra.display-name quando ausente.
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

                if ($file_name === 'composer.json')
                {
                    $json = json_decode($new_content, true);
                    if (is_array($json))
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

                        if (!empty($rules_applied))
                        {
                            $new_content = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
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

}