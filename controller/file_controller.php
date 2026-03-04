<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mundo phpBB Workspace - File Controller
 * Versão 4.2: SSOT + ACL Granular (upload/rename_move/delete/edit) + I/O + Changelog diff + i18n total
 */
class file_controller extends base_controller
{
    /**
     * Erro JSON com mensagem direta (para usar $access['error'] sem perder detalhe).
     */
    private function json_error_msg($msg)
    {
        return new JsonResponse([
            'success' => false,
            'error'   => (string) $msg,
        ]);
    }

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
     * Normaliza line endings para evitar diff "arquivo inteiro".
     */
    private function normalize_content($content)
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $content);
    }

    /**
     * Detecta file_type de forma consistente com o JS (case-insensitive).
     */
    private function detect_file_type($path)
    {
        $base   = basename(str_replace('\\', '/', (string) $path));
        $base_l = strtolower($base);

        if ($base_l === '.placeholder') { return 'txt'; }
        if ($base_l === 'dockerfile')   { return 'dockerfile'; }
        if ($base_l === 'makefile')     { return 'makefile'; }
        if ($base_l === '.htaccess')    { return 'htaccess'; }

        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        return $ext ?: 'txt';
    }

    /**
     * Upload de ficheiros (full_path) + Base64 (firewall safe)
     */
    public function upload_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);

        // ACL granular: upload
        $access = $this->assert_project_access($project_id, 'upload');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $filename = $this->request->variable('full_path', '', true);
        $file     = $this->request->file('file');

        $encoded_content = $this->request->variable('file_content', '', true);
        $is_encoded      = (int) $this->request->variable('is_encoded', 0);

        if (empty($filename) && isset($file['name']))
        {
            $filename = $file['name'];
        }

        $filename = $this->sanitize_rel_path($filename);
        if ($filename === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
        {
            return $this->json_error('WSP_ERR_INVALID_EXT');
        }

        // Conteúdo
        $content = false;

        if ($is_encoded && !empty($encoded_content))
        {
            if (preg_match('/^data:.*?;base64,/', $encoded_content))
            {
                $encoded_content = preg_replace('/^data:.*?;base64,/', '', $encoded_content);
            }

            $content = base64_decode($encoded_content, true);
        }
        else if (isset($file['tmp_name']) && !empty($file['tmp_name']))
        {
            $content = @file_get_contents($file['tmp_name']);
        }
        else
        {
            return $this->json_error('WSP_ERR_NO_CONTENT');
        }

        if ($content === false)
        {
            return $this->json_error('WSP_ERR_CONTENT_PROCESS');
        }

        $content = $this->normalize_content($content);
        $ext     = $this->detect_file_type($filename);

        $sql = 'SELECT file_id, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . "
                  AND file_name = '" . $this->db->sql_escape($filename) . "'";

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $this->db->sql_transaction('begin');

        if ($row)
        {
            $old = $this->normalize_content($row['file_content']);

            if ($old !== $content)
            {
                $this->log_to_changelog(
                    $project_id,
                    $this->user->lang('WSP_LOG_UPLOAD_UPDATE', $filename),
                    $old,
                    $content
                );
            }

            $sql_ary = [
                'file_content' => $content,
                'file_time'    => time(),
                'file_type'    => $ext,
            ];

            $sql_update = 'UPDATE ' . $this->table_prefix . 'workspace_files
                           SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                           WHERE file_id = ' . (int) $row['file_id'];

            $this->db->sql_query($sql_update);
        }
        else
        {
            $this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_UPLOAD_NEW', $filename));

            $file_ary = [
                'project_id'   => (int) $project_id,
                'file_name'    => $filename,
                'file_content' => $content,
                'file_type'    => $ext,
                'file_time'    => time(),
            ];

            $this->db->sql_query(
                'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
                $this->db->sql_build_array('INSERT', $file_ary)
            );
        }

        $this->db->sql_transaction('commit');
        return $this->json_success();
    }

    /**
     * Adiciona um novo ficheiro / cria pasta via .placeholder
     * ✅ Agora é operação de árvore => capability rename_move
     */
    public function add_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $filename   = trim($this->request->variable('name', '', true));

        // ✅ criação de arquivo/pasta = rename/move tree op
        $access = $this->assert_project_access($project_id, 'rename_move');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $filename = $this->sanitize_rel_path($filename);
        if (!$project_id || $filename === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
        {
            return $this->json_error('WSP_ERR_INVALID_EXT');
        }

        $sql = 'SELECT file_id
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . "
                  AND file_name = '" . $this->db->sql_escape($filename) . "'";

        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($exists)
        {
            return $this->json_error('WSP_ERR_FILE_EXISTS');
        }

        $ext = $this->detect_file_type($filename);

        // Conteúdo inicial só para PHP (mantém seu padrão)
        $initial_content = ($ext === 'php') ? "<?php\n\n" : "";

        $this->db->sql_transaction('begin');
        $this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_FILE_CREATED', $filename));

        $file_ary = [
            'project_id'   => (int) $project_id,
            'file_name'    => $filename,
            'file_content' => $initial_content,
            'file_type'    => $ext,
            'file_time'    => time(),
        ];

        $this->db->sql_query(
            'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
            $this->db->sql_build_array('INSERT', $file_ary)
        );

        $file_id = (int) $this->db->sql_nextid();
        $this->db->sql_transaction('commit');

        return $this->json_success(['file_id' => $file_id]);
    }

    public function load_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $file_id = (int) $this->request->variable('file_id', 0);
        $access  = $this->assert_file_access($file_id, 'view');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $sql = 'SELECT file_content, file_name, file_type
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE file_id = ' . (int) $file_id;

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $content = $this->normalize_content((string) $row['file_content']);

        return $this->json_success([
            'content' => (string) html_entity_decode($content, ENT_QUOTES, 'UTF-8'),
            'name'    => (string) $row['file_name'],
            'type'    => strtolower((string) $row['file_type']),
        ]);
    }

    public function save_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $file_id = (int) $this->request->variable('file_id', 0);
        $content = $this->request->variable('content', '', true);

        // ✅ salvar conteúdo = edit
        $access = $this->assert_file_access($file_id, 'edit');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $content = $this->normalize_content($content);

        $sql_old = 'SELECT project_id, file_name, file_content
                    FROM ' . $this->table_prefix . 'workspace_files
                    WHERE file_id = ' . (int) $file_id;

        $result_old = $this->db->sql_query($sql_old);
        $row_old    = $this->db->sql_fetchrow($result_old);
        $this->db->sql_freeresult($result_old);

        $this->db->sql_transaction('begin');

        if ($row_old)
        {
            $old = $this->normalize_content((string) $row_old['file_content']);

            if ($old !== $content)
            {
                $this->log_to_changelog(
                    (int) $row_old['project_id'],
                    $this->user->lang('WSP_LOG_FILE_CHANGED', (string) $row_old['file_name']),
                    $old,
                    $content
                );
            }
        }

        $sql_ary = [
            'file_content' => $content,
            'file_time'    => time(),
        ];

        $sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE file_id = ' . (int) $file_id;

        $this->db->sql_query($sql);
        $this->db->sql_transaction('commit');

        return $this->json_success();
    }

    public function rename_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $file_id  = (int) $this->request->variable('file_id', 0);
        $new_name = $this->sanitize_rel_path($this->request->variable('new_name', '', true));

        if (!$file_id || $new_name === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (!$this->is_placeholder_path($new_name) && !$this->is_extension_allowed($new_name))
        {
            return $this->json_error('WSP_ERR_INVALID_EXT');
        }

        // ✅ rename/move = rename_move
        $access = $this->assert_file_access($file_id, 'rename_move');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        // ✅ Evita Notice: pega project_id e nome antigo do banco
        $sql_info = 'SELECT project_id, file_name
                     FROM ' . $this->table_prefix . 'workspace_files
                     WHERE file_id = ' . (int) $file_id;

        $res_info = $this->db->sql_query($sql_info);
        $info     = $this->db->sql_fetchrow($res_info);
        $this->db->sql_freeresult($res_info);

        if (!$info)
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $project_id = (int) $info['project_id'];
        $old_name   = (string) $info['file_name'];

        // conflito
        $sql_check = 'SELECT file_id
                      FROM ' . $this->table_prefix . 'workspace_files
                      WHERE project_id = ' . (int) $project_id . "
                        AND file_name = '" . $this->db->sql_escape($new_name) . "'
                        AND file_id <> " . (int) $file_id;

        $result_check = $this->db->sql_query($sql_check);
        $exists       = $this->db->sql_fetchrow($result_check);
        $this->db->sql_freeresult($result_check);

        if ($exists)
        {
            return $this->json_error('WSP_ERR_FILE_EXISTS');
        }

        $this->db->sql_transaction('begin');

        $this->log_to_changelog(
            $project_id,
            $this->user->lang('WSP_LOG_RENAME_ACTION', $old_name, $new_name)
        );

        $new_ext = $this->detect_file_type($new_name);

        $sql_ary = [
            'file_name' => $new_name,
            'file_type' => $new_ext,
            'file_time' => time(),
        ];

        $this->db->sql_query(
            'UPDATE ' . $this->table_prefix . 'workspace_files
             SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
             WHERE file_id = ' . (int) $file_id
        );

        $this->db->sql_transaction('commit');
        return $this->json_success();
    }

    public function move_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $file_id  = (int) $this->request->variable('file_id', 0);
        $new_path = $this->sanitize_rel_path($this->request->variable('new_path', '', true));

        if (!$file_id || $new_path === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (!$this->is_placeholder_path($new_path) && !$this->is_extension_allowed($new_path))
        {
            return $this->json_error('WSP_ERR_INVALID_EXT');
        }

        // ✅ rename/move = rename_move
        $access = $this->assert_file_access($file_id, 'rename_move');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        // ✅ Evita Notice: pega project_id e nome antigo do banco
        $sql_info = 'SELECT project_id, file_name
                     FROM ' . $this->table_prefix . 'workspace_files
                     WHERE file_id = ' . (int) $file_id;

        $res_info = $this->db->sql_query($sql_info);
        $info     = $this->db->sql_fetchrow($res_info);
        $this->db->sql_freeresult($res_info);

        if (!$info)
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $project_id = (int) $info['project_id'];
        $old_name   = (string) $info['file_name'];

        // conflito
        $sql_check = 'SELECT file_id
                      FROM ' . $this->table_prefix . 'workspace_files
                      WHERE project_id = ' . (int) $project_id . "
                        AND file_name = '" . $this->db->sql_escape($new_path) . "'
                        AND file_id <> " . (int) $file_id;

        $result_check = $this->db->sql_query($sql_check);
        $exists       = $this->db->sql_fetchrow($result_check);
        $this->db->sql_freeresult($result_check);

        if ($exists)
        {
            return $this->json_error('WSP_ERR_FILE_EXISTS');
        }

        $this->db->sql_transaction('begin');

        $this->log_to_changelog(
            $project_id,
            $this->user->lang('WSP_LOG_FILE_MOVE_ACTION', $old_name, $new_path)
        );

        $new_ext = $this->detect_file_type($new_path);

        $sql_ary = [
            'file_name' => $new_path,
            'file_type' => $new_ext,
            'file_time' => time(),
        ];

        $this->db->sql_query(
            'UPDATE ' . $this->table_prefix . 'workspace_files
             SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
             WHERE file_id = ' . (int) $file_id
        );

        $this->db->sql_transaction('commit');
        return $this->json_success();
    }

    public function delete_file()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $file_id = (int) $this->request->variable('file_id', 0);
        if (!$file_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        // ✅ delete = delete
        $access = $this->assert_file_access($file_id, 'delete');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        // ✅ Evita Notice: pega project_id e file_name do banco (não depende de $access['project_id'])
        $sql_info = 'SELECT project_id, file_name
                     FROM ' . $this->table_prefix . 'workspace_files
                     WHERE file_id = ' . (int) $file_id;

        $res_info = $this->db->sql_query($sql_info);
        $info     = $this->db->sql_fetchrow($res_info);
        $this->db->sql_freeresult($res_info);

        if (!$info)
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $project_id = (int) $info['project_id'];
        $file_name  = (string) $info['file_name'];

        $this->db->sql_transaction('begin');

        $this->log_to_changelog(
            $project_id,
            $this->user->lang('WSP_LOG_DELETE_ACTION', $file_name)
        );

        $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_files
             WHERE file_id = ' . (int) $file_id
        );

        $this->db->sql_transaction('commit');
        return $this->json_success();
    }

    private function log_to_changelog($project_id, $action, $old_content = null, $new_content = null)
    {
        $date   = date('d/m/Y H:i');
        $action = (string) $action;

        if ($old_content !== null) $old_content = $this->normalize_content($old_content);
        if ($new_content !== null) $new_content = $this->normalize_content($new_content);

        $log_entry = "[$date] $action\n";

        if ($old_content !== null && $new_content !== null && $old_content !== $new_content)
        {
            $lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';

            if (file_exists($lib_path . 'Diff.php'))
            {
                require_once($lib_path . 'Diff.php');
                require_once($lib_path . 'Diff/Renderer/Abstract.php');
                require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

                $diff      = new \Diff(explode("\n", $old_content), explode("\n", $new_content));
                $renderer  = new \Diff_Renderer_Text_Unified();
                $diff_text = $diff->render($renderer);

                if (!empty(trim((string) $diff_text)))
                {
                    $log_entry .= $this->user->lang('WSP_LOG_DIFF_LABEL') . ":\n" . $diff_text . "\n";
                }
            }
            else
            {
                $log_entry .= $this->user->lang('WSP_LOG_CONTENT_MODIFIED_FALLBACK') . "\n";
            }
        }

        $changelog_name = 'changelog.txt';

        $sql = 'SELECT file_id, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . "
                  AND file_name = '" . $this->db->sql_escape($changelog_name) . "'";

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row)
        {
            $existing     = $this->normalize_content((string) $row['file_content']);
            $new_content  = $log_entry . str_repeat("-", 40) . "\n" . $existing;

            $sql_ary = [
                'file_content' => $new_content,
                'file_time'    => time(),
            ];

            $this->db->sql_query(
                'UPDATE ' . $this->table_prefix . 'workspace_files
                 SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                 WHERE file_id = ' . (int) $row['file_id']
            );
        }
        else
        {
            $file_ary = [
                'project_id'   => (int) $project_id,
                'file_name'    => $changelog_name,
                'file_content' => $log_entry . str_repeat("-", 40) . "\n",
                'file_type'    => 'txt',
                'file_time'    => time(),
            ];

            $this->db->sql_query(
                'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
                $this->db->sql_build_array('INSERT', $file_ary)
            );
        }
    }
}