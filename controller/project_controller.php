<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mundo phpBB Workspace - Project Controller
 * Versão 4.2: Gestão de Projetos + Lock/Unlock + Gates SSOT + ACL Granular (folders) + JSON consistente
 */
class project_controller extends base_controller
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
     * Detecta file_type de forma consistente (case-insensitive).
     */
    private function detect_file_type($filename)
    {
        $filename = (string) $filename;
        $base = basename(str_replace('\\', '/', $filename));
        $base_l = strtolower($base);

        if ($base_l === '.placeholder') { return 'txt'; }
        if ($base_l === 'dockerfile')   { return 'dockerfile'; }
        if ($base_l === 'makefile')     { return 'makefile'; }
        if ($base_l === '.htaccess')    { return 'htaccess'; }

        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';
    }

    /**
     * Cria um novo projeto e inicializa o changelog.txt.
     */
    public function add_project()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        // Gate de criação (SSOT)
        if (isset($this->permission_service) && method_exists($this->permission_service, 'can_create_project'))
        {
            if (!$this->permission_service->can_create_project())
            {
                return $this->json_error('WSP_ERR_PERMISSION');
            }
        }
        else
        {
            if (!(bool) $this->auth->acl_get('u_workspace_create'))
            {
                return $this->json_error('WSP_ERR_PERMISSION');
            }
        }

        $name = trim($this->request->variable('name', '', true));
        if ($name === '')
        {
            return $this->json_error('WSP_ERR_INVALID_NAME');
        }

        if (utf8_strlen($name) > 120)
        {
            $name = utf8_substr($name, 0, 120);
        }

        $this->db->sql_transaction('begin');

        $sql_ary = [
            'project_name'   => $name,
            'project_desc'   => $this->user->lang('WSP_DEFAULT_DESC'),
            'project_time'   => time(),
            'user_id'        => (int) $this->user->data['user_id'],
            'project_locked' => 0,
            'locked_by'      => 0,
            'locked_time'    => 0,
        ];

        $ok = $this->db->sql_query(
            'INSERT INTO ' . $this->table_prefix . 'workspace_projects ' .
            $this->db->sql_build_array('INSERT', $sql_ary)
        );

        if ($ok === false)
        {
            $this->db->sql_transaction('rollback');
            return $this->json_error('WSP_ERR_CREATE_FAILED');
        }

        $project_id = (int) $this->db->sql_nextid();

        // Inicializa changelog.txt
        if ($project_id && $this->is_extension_allowed('changelog.txt'))
        {
            $header  = str_repeat("=", 50) . "\n";
            $header .= $this->user->lang('WSP_LOG_PROJECT_CREATED', date('d/m/Y H:i')) . "\n";
            $header .= str_repeat("=", 50) . "\n\n";

            $file_ary = [
                'project_id'   => $project_id,
                'file_name'    => 'changelog.txt',
                'file_content' => $header,
                'file_type'    => 'txt',
                'file_time'    => time(),
            ];

            $ok2 = $this->db->sql_query(
                'INSERT INTO ' . $this->table_prefix . 'workspace_files ' .
                $this->db->sql_build_array('INSERT', $file_ary)
            );

            if ($ok2 === false)
            {
                $this->db->sql_transaction('rollback');
                return $this->json_error('WSP_ERR_CREATE_FAILED');
            }
        }

        $this->db->sql_transaction('commit');

        return $this->json_success(['project_id' => $project_id]);
    }

    /**
     * Renomeia o projeto (metadados).
     */
    public function rename_project()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $new_name   = trim($this->request->variable('new_name', '', true));

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($new_name === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (utf8_strlen($new_name) > 120)
        {
            $new_name = utf8_substr($new_name, 0, 120);
        }

        $sql_ary = [
            'project_name' => $new_name,
            'project_time' => time(),
        ];

        $ok = $this->db->sql_query(
            'UPDATE ' . $this->table_prefix . 'workspace_projects
             SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
             WHERE project_id = ' . (int) $project_id
        );

        if ($ok === false)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_PROJECT_RENAMED', $new_name));

        return $this->json_success();
    }

    /**
     * Remove o projeto e todos os arquivos vinculados.
     */
    public function delete_project()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $this->db->sql_transaction('begin');

        $ok1 = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_files
             WHERE project_id = ' . (int) $project_id
        );

        $ok2 = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_projects_users
             WHERE project_id = ' . (int) $project_id
        );

        $ok3 = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_projects
             WHERE project_id = ' . (int) $project_id
        );

        if ($ok1 !== false && $ok2 !== false && $ok3 !== false)
        {
            $this->db->sql_transaction('commit');
            return $this->json_success();
        }

        $this->db->sql_transaction('rollback');
        return $this->json_error('WSP_ERR_DELETE_FAILED');
    }

    /**
     * Exclui uma pasta virtual e todo o seu conteúdo.
     * ✅ capability granular: delete
     */
    public function delete_folder()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $path       = $this->sanitize_rel_path($this->request->variable('path', '', true));

        $access = $this->assert_project_access($project_id, 'delete');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($path === '')
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $prefix = (substr($path, -1) !== '/') ? ($path . '/') : $path;
        $like_prefix = $this->db->sql_escape($prefix) . '%';
        $path_esc    = $this->db->sql_escape($path);

        $this->db->sql_transaction('begin');

        $sql = 'DELETE FROM ' . $this->table_prefix . "workspace_files
                WHERE project_id = " . (int) $project_id . "
                  AND (file_name LIKE '" . $like_prefix . "'
                       OR file_name = '" . $path_esc . "')";

        $ok = $this->db->sql_query($sql);

        if ($ok === false)
        {
            $this->db->sql_transaction('rollback');
            return $this->json_error('WSP_ERR_DELETE_FAILED');
        }

        $this->db->sql_transaction('commit');

        $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_FOLDER_DELETED', $path));

        return $this->json_success();
    }

    /**
     * Renomeia uma pasta virtual e atualiza o caminho de todos os arquivos filhos.
     * ✅ capability granular: rename_move
     */
    public function rename_folder()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);

        $old_path_raw = $this->request->variable('old_path', '', true);
        $new_path_raw = $this->request->variable('new_path', '', true);

        $old_path = trim($this->sanitize_rel_path($old_path_raw), '/');
        $new_path = trim($this->sanitize_rel_path($new_path_raw), '/');

        $access = $this->assert_project_access($project_id, 'rename_move');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($old_path === '' || $new_path === '' || $old_path === $new_path)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $old_prefix = $old_path . '/';
        $new_prefix = $new_path . '/';

        // Impede renomear para dentro dela mesma
        if (strpos($new_prefix, $old_prefix) === 0)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        // Proteção contra colisão: já existe algo no destino que não seja parte da pasta antiga?
        $new_like      = $this->db->sql_escape($new_prefix) . '%';
        $old_like      = $this->db->sql_escape($old_prefix) . '%';
        $new_path_esc  = $this->db->sql_escape($new_path);
        $old_path_esc  = $this->db->sql_escape($old_path);

        $sql_conflict = 'SELECT file_id
                         FROM ' . $this->table_prefix . "workspace_files
                         WHERE project_id = " . (int) $project_id . "
                           AND (file_name LIKE '" . $new_like . "'
                                OR file_name = '" . $new_path_esc . "')
                           AND NOT (file_name LIKE '" . $old_like . "'
                                    OR file_name = '" . $old_path_esc . "')";

        $res_c = $this->db->sql_query_limit($sql_conflict, 1);
        $conflict = $this->db->sql_fetchrow($res_c);
        $this->db->sql_freeresult($res_c);

        if ($conflict)
        {
            return $this->json_error('WSP_ERR_FILE_EXISTS');
        }

        // Busca arquivos a mover
        $sql = 'SELECT file_id, file_name
                FROM ' . $this->table_prefix . "workspace_files
                WHERE project_id = " . (int) $project_id . "
                  AND (file_name LIKE '" . $old_like . "'
                       OR file_name = '" . $old_path_esc . "')";

        $result = $this->db->sql_query($sql);
        $files = $this->db->sql_fetchrowset($result);
        $this->db->sql_freeresult($result);

        if (empty($files))
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $this->db->sql_transaction('begin');

        try
        {
            foreach ($files as $f)
            {
                $current_name = (string) $f['file_name'];

                if ($current_name === $old_path)
                {
                    $target_name = $new_path;
                }
                else
                {
                    $relative_part = substr($current_name, strlen($old_prefix));
                    $target_name = $new_prefix . $relative_part;
                }

                $target_name = $this->sanitize_rel_path($target_name);
                if ($target_name === '')
                {
                    throw new \RuntimeException('Invalid target path');
                }

                $new_type = $this->detect_file_type($target_name);

                $sql_ary = [
                    'file_name' => $target_name,
                    'file_type' => $new_type,
                    'file_time' => time(),
                ];

                $ok = $this->db->sql_query(
                    'UPDATE ' . $this->table_prefix . 'workspace_files
                     SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                     WHERE file_id = ' . (int) $f['file_id']
                );

                if ($ok === false)
                {
                    throw new \RuntimeException('Update failed');
                }
            }

            $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_FOLDER_MOVE', $old_path, $new_path));

            $this->db->sql_transaction('commit');
        }
        catch (\Exception $e)
        {
            $this->db->sql_transaction('rollback');
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        return $this->json_success();
    }

    /**
     * Tranca (lock) o projeto.
     */
    public function lock_project()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);

        $access = $this->assert_project_access($project_id, 'lock');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $lock_info = (array) $this->project_repo->get_project_lock_info($project_id) + [
            'project_locked' => 0,
            'locked_by'      => 0,
            'locked_time'    => 0,
        ];

        if (!empty($lock_info['project_locked']))
        {
            return $this->json_success([
                'is_locked'   => 1,
                'locked_by'   => (int) $lock_info['locked_by'],
                'locked_time' => (int) $lock_info['locked_time'],
            ]);
        }

        $ok = $this->project_repo->set_project_lock($project_id, true, (int) $this->user->data['user_id']);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_PROJECT_LOCKED'));

        $lock_info = (array) $this->project_repo->get_project_lock_info($project_id);

        return $this->json_success([
            'is_locked'   => 1,
            'locked_by'   => (int) ($lock_info['locked_by'] ?? 0),
            'locked_time' => (int) ($lock_info['locked_time'] ?? 0),
        ]);
    }

    /**
     * Destranca (unlock) o projeto.
     */
    public function unlock_project()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);

        $access = $this->assert_project_access($project_id, 'lock');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $lock_info = (array) $this->project_repo->get_project_lock_info($project_id) + [
            'project_locked' => 0,
            'locked_by'      => 0,
            'locked_time'    => 0,
        ];

        if (empty($lock_info['project_locked']))
        {
            return $this->json_success([
                'is_locked'   => 0,
                'locked_by'   => 0,
                'locked_time' => 0,
            ]);
        }

        // Regra extra (mantida): não-admin não destranca lock de outro
        $is_admin = isset($this->permission_service) && method_exists($this->permission_service, 'can_manage_all')
            ? (bool) $this->permission_service->can_manage_all()
            : (bool) $this->auth->acl_get('u_workspace_manage_all');

        if (!$is_admin && (int) $lock_info['locked_by'] !== (int) $this->user->data['user_id'])
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $ok = $this->project_repo->set_project_lock($project_id, false, 0);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_PROJECT_UNLOCKED'));

        return $this->json_success([
            'is_locked'   => 0,
            'locked_by'   => 0,
            'locked_time' => 0,
        ]);
    }

    /**
     * Download ZIP do projeto.
     */
    public function download_project($project_id = 0)
    {
        if ($r = $this->ensure_workspace_access()) { trigger_error($this->user->lang('WSP_ERR_PERMISSION')); }

        $p_id = (int) $project_id;
        if ($p_id <= 0) { $p_id = (int) $this->request->variable('project_id', 0); }
        if ($p_id <= 0) { $p_id = (int) $this->request->variable('p', 0); }
        if ($p_id <= 0) { trigger_error($this->user->lang('WSP_ERR_ZIP_CREATE_FAILED')); }

        $access = $this->assert_project_access($p_id, 'download');
        if (!$access['ok'])
        {
            trigger_error($access['error']);
        }

        $sql = 'SELECT project_name
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . (int) $p_id;

        $result = $this->db->sql_query($sql);
        $project = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$project) { trigger_error($this->user->lang('WSP_ERR_INVALID_ID')); }
        if (!class_exists('ZipArchive')) { trigger_error($this->user->lang('WSP_ERR_ZIP_NOT_AVAILABLE')); }

        $zip = new \ZipArchive();
        $temp_file = tempnam(sys_get_temp_dir(), 'wsp');

        if ($zip->open($temp_file, \ZipArchive::CREATE) !== true)
        {
            trigger_error($this->user->lang('WSP_ERR_INVALID_DATA'));
        }

        $sql = 'SELECT file_name, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $p_id;

        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result))
        {
            if (strtolower(basename((string) $row['file_name'])) === '.placeholder') { continue; }
            $zip->addFromString((string) $row['file_name'], (string) $row['file_content']);
        }
        $this->db->sql_freeresult($result);

        $zip->close();

        $download_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $project['project_name']) . '.zip';

        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    /**
     * Prepend de uma entrada no changelog.txt do projeto.
     */
    private function append_changelog_entry($project_id, $action)
    {
        $project_id = (int) $project_id;
        $action = (string) $action;

        if ($project_id <= 0 || $action === '') { return; }

        $date = date('d/m/Y H:i');
        $log_entry = "[$date] " . $action . "\n";
        $separator = str_repeat("-", 40) . "\n";

        $sql = 'SELECT file_id, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $project_id . "
                  AND file_name = 'changelog.txt'";

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row)
        {
            $existing = str_replace(["\r\n", "\r"], "\n", (string) $row['file_content']);
            $new_content = $log_entry . $separator . $existing;

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
                'file_name'    => 'changelog.txt',
                'file_content' => $log_entry . $separator,
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