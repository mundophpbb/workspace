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
            'collaboration_mode' => 'private',
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

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'project_created', 'project', $name);
        }

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

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'project_renamed', 'project', $new_name);
        }

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

        $ok_comments = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_comments
             WHERE project_id = ' . (int) $project_id
        );

        $ok_reviews = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_file_reviews
             WHERE project_id = ' . (int) $project_id
        );

        $ok_versions = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_file_versions
             WHERE project_id = ' . (int) $project_id
        );
        $ok_locks = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_file_locks
             WHERE project_id = ' . (int) $project_id
        );

        $ok_activity = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_activity
             WHERE project_id = ' . (int) $project_id
        );

        $ok_tasks = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_tasks
             WHERE project_id = ' . (int) $project_id
        );

        $ok3 = $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_projects
             WHERE project_id = ' . (int) $project_id
        );

        if ($ok1 !== false && $ok2 !== false && $ok_comments !== false && $ok_reviews !== false && $ok_versions !== false && $ok_locks !== false && $ok_activity !== false && $ok_tasks !== false && $ok3 !== false)
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

        $sql_comments = 'DELETE FROM ' . $this->table_prefix . "workspace_comments
                WHERE project_id = " . (int) $project_id . "
                  AND file_id IN (
                    SELECT file_id FROM " . $this->table_prefix . "workspace_files
                    WHERE project_id = " . (int) $project_id . "
                      AND (file_name LIKE '" . $like_prefix . "'
                           OR file_name = '" . $path_esc . "')
                  )";
        $ok_comments = $this->db->sql_query($sql_comments);

        $sql_reviews = 'DELETE FROM ' . $this->table_prefix . "workspace_file_reviews
                WHERE project_id = " . (int) $project_id . "
                  AND file_id IN (
                    SELECT file_id FROM " . $this->table_prefix . "workspace_files
                    WHERE project_id = " . (int) $project_id . "
                      AND (file_name LIKE '" . $like_prefix . "'
                           OR file_name = '" . $path_esc . "')
                  )";
        $ok_reviews = $this->db->sql_query($sql_reviews);

        $sql_versions = 'DELETE FROM ' . $this->table_prefix . "workspace_file_versions
                WHERE project_id = " . (int) $project_id . "
                  AND file_id IN (
                    SELECT file_id FROM " . $this->table_prefix . "workspace_files
                    WHERE project_id = " . (int) $project_id . "
                      AND (file_name LIKE '" . $like_prefix . "'
                           OR file_name = '" . $path_esc . "')
                  )";
        $ok_versions = $this->db->sql_query($sql_versions);
        $sql_locks = 'DELETE FROM ' . $this->table_prefix . "workspace_file_locks
                WHERE project_id = " . (int) $project_id . "
                  AND file_id IN (
                    SELECT file_id FROM " . $this->table_prefix . "workspace_files
                    WHERE project_id = " . (int) $project_id . "
                      AND (file_name LIKE '" . $like_prefix . "'
                           OR file_name = '" . $path_esc . "')
                  )";
        $ok_locks = $this->db->sql_query($sql_locks);

        $sql_tasks = 'DELETE FROM ' . $this->table_prefix . "workspace_tasks
                WHERE project_id = " . (int) $project_id . "
                  AND file_id IN (
                    SELECT file_id FROM " . $this->table_prefix . "workspace_files
                    WHERE project_id = " . (int) $project_id . "
                      AND (file_name LIKE '" . $like_prefix . "'
                           OR file_name = '" . $path_esc . "')
                  )";
        $ok_tasks = $this->db->sql_query($sql_tasks);

        $sql = 'DELETE FROM ' . $this->table_prefix . "workspace_files
                WHERE project_id = " . (int) $project_id . "
                  AND (file_name LIKE '" . $like_prefix . "'
                       OR file_name = '" . $path_esc . "')";

        $ok = $this->db->sql_query($sql);

        if ($ok === false || $ok_comments === false || $ok_reviews === false || $ok_versions === false || $ok_locks === false || $ok_tasks === false)
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
            'collaboration_mode' => 'private',
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

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'project_locked', 'project', '');
        }

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
            'collaboration_mode' => 'private',
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

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'project_unlocked', 'project', '');
        }

        return $this->json_success([
            'is_locked'   => 0,
            'locked_by'   => 0,
            'locked_time' => 0,
        ]);
    }


    /**
     * Lista membros do projeto ativo.
     */
    public function list_members()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $access = $this->assert_project_access($project_id, 'view');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $members = (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_members'))
            ? (array) $this->project_repo->get_project_members($project_id)
            : [];

        return $this->json_success(['members' => $members]);
    }

    /**
     * Adiciona/convida membro por nome de usuario do phpBB.
     */
    public function add_member()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $username = trim($this->request->variable('username', '', true));
        $role = $this->request->variable('role', 'viewer', true);

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($username === '' || !isset($this->project_repo) || !method_exists($this->project_repo, 'find_user_by_name'))
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $target = $this->project_repo->find_user_by_name($username);
        if (!$target)
        {
            return $this->json_error('WSP_ERR_USER_NOT_FOUND');
        }

        $target_id = (int) $target['user_id'];
        if ($target_id <= 0)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if ((int) $this->project_repo->get_project_owner_id($project_id) === $target_id)
        {
            $role = 'owner';
        }
        else
        {
            $role = $this->project_repo->sanitize_role($role);
            if ($role === 'owner')
            {
                $role = 'collab';
            }
            $ok = $this->project_repo->upsert_member($project_id, $target_id, $role, (int) $this->user->data['user_id']);
            if (!$ok)
            {
                return $this->json_error('WSP_ERR_UPDATE_FAILED');
            }
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'member_added', 'member', (string) $target['username'], ['role' => $role]);
        }

        $this->append_changelog_entry((int) $project_id, $this->user->lang('WSP_LOG_MEMBER_ADDED', (string) $target['username'], $role));

        $this->notify_user($project_id, $target_id, 'member_added', 'project', (string) $target['username'], 'WSP_NOTIFY_MEMBER_ADDED', ['role' => $role]);
        $this->notify_project_members($project_id, 'team_changed', 'member', (string) $target['username'], 'WSP_NOTIFY_TEAM_CHANGED', ['role' => $role]);

        return $this->json_success([
            'user_id' => $target_id,
            'username' => (string) $target['username'],
            'role' => $role,
        ]);
    }

    /**
     * Atualiza papel de membro.
     */
    public function update_member()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $target_id = (int) $this->request->variable('user_id', 0);
        $role = $this->request->variable('role', 'viewer', true);

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($target_id <= 0 || (int) $this->project_repo->get_project_owner_id($project_id) === $target_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $role = $this->project_repo->sanitize_role($role);
        if ($role === 'owner')
        {
            $role = 'collab';
        }

        $ok = $this->project_repo->upsert_member($project_id, $target_id, $role, (int) $this->user->data['user_id']);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'member_role_changed', 'member', (string) $target_id, ['role' => $role]);
        }

        $this->notify_user($project_id, $target_id, 'member_role_changed', 'project', (string) $target_id, 'WSP_NOTIFY_ROLE_CHANGED', ['role' => $role]);
        $this->notify_project_members($project_id, 'team_changed', 'member', (string) $target_id, 'WSP_NOTIFY_TEAM_CHANGED', ['role' => $role]);

        return $this->json_success(['role' => $role]);
    }

    /**
     * Remove membro do projeto.
     */
    public function remove_member()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $target_id = (int) $this->request->variable('user_id', 0);

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($target_id <= 0)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $ok = $this->project_repo->remove_member($project_id, $target_id);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'member_removed', 'member', (string) $target_id);
        }

        $this->notify_user($project_id, $target_id, 'member_removed', 'project', (string) $target_id, 'WSP_NOTIFY_MEMBER_REMOVED');
        $this->notify_project_members($project_id, 'team_changed', 'member', (string) $target_id, 'WSP_NOTIFY_TEAM_CHANGED');

        return $this->json_success();
    }

    /**
     * Lista atividade recente do projeto.
     */
    public function list_activity()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $access = $this->assert_project_access($project_id, 'view');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $activity = (isset($this->project_repo) && method_exists($this->project_repo, 'get_recent_activity'))
            ? (array) $this->project_repo->get_recent_activity($project_id, 20)
            : [];

        return $this->json_success(['activity' => $activity]);
    }


    /**
     * Atualiza o modo de colaboração do projeto.
     */
    public function set_collaboration_mode()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $mode = $this->request->variable('mode', 'private', true);

        $access = $this->assert_project_access($project_id, 'manage');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $mode = (isset($this->project_repo) && method_exists($this->project_repo, 'sanitize_collaboration_mode'))
            ? $this->project_repo->sanitize_collaboration_mode($mode)
            : (in_array($mode, ['private', 'pm_request'], true) ? $mode : 'private');

        if (!isset($this->project_repo) || !method_exists($this->project_repo, 'set_collaboration_mode'))
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        if (!$this->project_repo->set_collaboration_mode($project_id, $mode))
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'collaboration_mode_changed', 'project', $mode);
        }

        $this->append_changelog_entry($project_id, $this->user->lang('WSP_LOG_COLLAB_MODE_CHANGED', $this->user->lang($mode === 'pm_request' ? 'WSP_COLLAB_MODE_PM_REQUEST' : 'WSP_COLLAB_MODE_PRIVATE')));

        return $this->json_success(['mode' => $mode]);
    }

    /**
     * Solicita colaboração por MP ao responsável do projeto.
     */
    public function request_collaboration()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $role = $this->request->variable('role', 'collab', true);
        $message = trim($this->request->variable('message', '', true));

        if ($project_id <= 0 || !isset($this->project_repo) || !$this->project_repo->project_exists($project_id))
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $uid = (int) ($this->user->data['user_id'] ?? 0);
        $owner_id = (int) $this->project_repo->get_project_owner_id($project_id);
        if ($uid <= 0 || $owner_id <= 0 || $uid === $owner_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if ((string) $this->project_repo->get_user_role($project_id, $uid) !== '')
        {
            return $this->json_error('WSP_ERR_ALREADY_MEMBER');
        }

        $mode = method_exists($this->project_repo, 'get_collaboration_mode') ? $this->project_repo->get_collaboration_mode($project_id) : 'private';
        if ($mode !== 'pm_request')
        {
            return $this->json_error('WSP_ERR_COLLAB_REQUESTS_DISABLED');
        }

        $role = ($role === 'viewer') ? 'viewer' : 'collab';
        if (utf8_strlen($message) > 1000)
        {
            $message = utf8_substr($message, 0, 1000);
        }

        $project = $this->get_project_row($project_id);
        $project_name = $project ? (string) $project['project_name'] : ('#' . $project_id);

        $subject = $this->user->lang('WSP_PM_COLLAB_SUBJECT', $project_name);
        $body = $this->user->lang('WSP_PM_COLLAB_BODY',
            (string) ($this->user->data['username'] ?? ''),
            $project_name,
            $this->user->lang($role === 'viewer' ? 'WSP_ROLE_VIEWER' : 'WSP_ROLE_COLLAB'),
            ($message !== '' ? $message : $this->user->lang('WSP_PM_COLLAB_NO_MESSAGE'))
        );

        $pm_sent = $this->send_private_message($owner_id, $subject, $body);

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, $uid, 'collaboration_requested', 'project', $project_name, ['role' => $role, 'pm_sent' => $pm_sent ? 1 : 0]);
        }

        $this->notify_user($project_id, $owner_id, 'collaboration_requested', 'project', $project_name, 'WSP_NOTIFY_COLLAB_REQUESTED', ['role' => $role, 'pm_sent' => $pm_sent ? 1 : 0]);

        return $this->json_success([
            'pm_sent' => $pm_sent ? 1 : 0,
            'message' => $pm_sent ? $this->user->lang('WSP_COLLAB_REQUEST_SENT') : $this->user->lang('WSP_COLLAB_REQUEST_SAVED_NO_PM'),
        ]);
    }

    /**
     * Busca linha básica do projeto.
     */
    private function get_project_row($project_id)
    {
        $sql = 'SELECT project_id, project_name, user_id, collaboration_mode
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . (int) $project_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ?: null;
    }

    /**
     * Envia MP usando a API padrão do phpBB quando disponível.
     */
    private function send_private_message($to_user_id, $subject, $message)
    {
        global $phpEx;

        $to_user_id = (int) $to_user_id;
        if ($to_user_id <= 0)
        {
            return false;
        }

        $phpEx = $phpEx ?: 'php';
        $pm_file = $this->phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx;
        $content_file = $this->phpbb_root_path . 'includes/functions_content.' . $phpEx;

        if (!function_exists('submit_pm') && file_exists($pm_file))
        {
            include_once $pm_file;
        }
        if (!function_exists('generate_text_for_storage') && file_exists($content_file))
        {
            include_once $content_file;
        }

        if (!function_exists('submit_pm') || !function_exists('generate_text_for_storage'))
        {
            return false;
        }

        $uid = $bitfield = '';
        $flags = OPTION_FLAG_BBCODE + OPTION_FLAG_SMILIES + OPTION_FLAG_LINKS;
        generate_text_for_storage($message, $uid, $bitfield, $flags, true, true, true);

        $data = [
            'address_list'       => ['u' => [$to_user_id => 'to']],
            'from_user_id'       => (int) ($this->user->data['user_id'] ?? 0),
            'from_username'      => (string) ($this->user->data['username'] ?? ''),
            'icon_id'            => 0,
            'from_user_ip'       => (string) ($this->user->ip ?? ''),
            'enable_sig'         => false,
            'enable_bbcode'      => true,
            'enable_smilies'     => true,
            'enable_urls'        => true,
            'message'            => $message,
            'bbcode_bitfield'    => $bitfield,
            'bbcode_uid'         => $uid,
            'message_attachment' => 0,
            'filename_data'      => [],
            'attachment_data'    => [],
        ];

        try
        {
            submit_pm('post', (string) $subject, $data, false);
            return true;
        }
        catch (\Throwable $e)
        {
            return false;
        }
        catch (\Exception $e)
        {
            return false;
        }
    }


    /**
     * Lista notificacoes colaborativas do usuario atual.
     */
    public function list_notifications()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $user_id = (int) ($this->user->data['user_id'] ?? 0);
        $notifications = (isset($this->project_repo) && method_exists($this->project_repo, 'get_user_notifications'))
            ? (array) $this->project_repo->get_user_notifications($user_id, 30)
            : [];
        $unread = (isset($this->project_repo) && method_exists($this->project_repo, 'get_unread_notification_count'))
            ? (int) $this->project_repo->get_unread_notification_count($user_id)
            : 0;

        return $this->json_success([
            'notifications' => $notifications,
            'unread' => $unread,
        ]);
    }

    /**
     * Marca notificacoes como lidas. notification_id=0 marca todas.
     */
    public function mark_notifications_read()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $notification_id = (int) $this->request->variable('notification_id', 0);
        $user_id = (int) ($this->user->data['user_id'] ?? 0);

        if (isset($this->project_repo) && method_exists($this->project_repo, 'mark_notifications_read'))
        {
            $this->project_repo->mark_notifications_read($user_id, $notification_id);
        }

        $unread = (isset($this->project_repo) && method_exists($this->project_repo, 'get_unread_notification_count'))
            ? (int) $this->project_repo->get_unread_notification_count($user_id)
            : 0;

        return $this->json_success(['unread' => $unread]);
    }



    /**
     * Lista tarefas colaborativas do projeto.
     */
    public function list_tasks()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $access = $this->assert_project_access($project_id, 'view');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $tasks = (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_tasks'))
            ? (array) $this->project_repo->get_project_tasks($project_id, 100)
            : [];

        return $this->json_success(['tasks' => $tasks]);
    }

    /**
     * Cria tarefa colaborativa.
     */
    public function add_task()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $title = trim($this->request->variable('title', '', true));
        $description = trim($this->request->variable('description', '', true));
        $priority = $this->request->variable('priority', 'normal', true);
        $assigned_to = (int) $this->request->variable('assigned_to', 0);
        $file_id = (int) $this->request->variable('file_id', 0);

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($title === '' || !isset($this->project_repo) || !method_exists($this->project_repo, 'add_task'))
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if (utf8_strlen($title) > 180)
        {
            $title = utf8_substr($title, 0, 180);
        }

        if ($file_id > 0)
        {
            $file = $this->project_repo->get_file_info($file_id);
            if (!$file || (int) $file['project_id'] !== (int) $project_id)
            {
                $file_id = 0;
            }
        }

        if ($assigned_to > 0)
        {
            $owner_id = (int) $this->project_repo->get_project_owner_id($project_id);
            $role = $this->project_repo->get_user_role($project_id, $assigned_to);
            if ($assigned_to !== $owner_id && $role === '')
            {
                return $this->json_error('WSP_ERR_INVALID_DATA');
            }
        }

        $task_id = (int) $this->project_repo->add_task($project_id, $file_id, $title, $description, $priority, $assigned_to, (int) $this->user->data['user_id']);
        if ($task_id <= 0)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'task_created', 'task', $title, ['task_id' => $task_id]);
        }

        if ($assigned_to > 0)
        {
            $this->notify_user($project_id, $assigned_to, 'task_assigned', 'task', $title, 'WSP_NOTIFY_TASK_ASSIGNED', ['task_id' => $task_id]);
        }

        return $this->json_success(['task' => $this->project_repo->get_task($task_id)]);
    }

    /**
     * Atualiza tarefa colaborativa.
     */
    public function update_task()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $task_id = (int) $this->request->variable('task_id', 0);

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        if ($task_id <= 0 || !isset($this->project_repo) || !method_exists($this->project_repo, 'get_task'))
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $task = $this->project_repo->get_task($task_id);
        if (!$task || (int) $task['project_id'] !== (int) $project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $fields = [];
        $mode = $this->request->variable('mode', '', true);
        if ($mode === 'status')
        {
            $fields['status'] = $this->request->variable('status', 'todo', true);
        }
        else
        {
            $title = trim($this->request->variable('title', (string) $task['title'], true));
            if ($title !== '')
            {
                if (utf8_strlen($title) > 180)
                {
                    $title = utf8_substr($title, 0, 180);
                }
                $fields['title'] = $title;
            }
            $fields['description'] = trim($this->request->variable('description', (string) $task['description'], true));
            $fields['priority'] = $this->request->variable('priority', (string) $task['priority'], true);
            $fields['assigned_to'] = (int) $this->request->variable('assigned_to', (int) $task['assigned_to']);
            $fields['file_id'] = (int) $this->request->variable('file_id', (int) $task['file_id']);
        }

        if (isset($fields['assigned_to']) && (int) $fields['assigned_to'] > 0)
        {
            $owner_id = (int) $this->project_repo->get_project_owner_id($project_id);
            $role = $this->project_repo->get_user_role($project_id, (int) $fields['assigned_to']);
            if ((int) $fields['assigned_to'] !== $owner_id && $role === '')
            {
                return $this->json_error('WSP_ERR_INVALID_DATA');
            }
        }

        if (isset($fields['file_id']) && (int) $fields['file_id'] > 0)
        {
            $file = $this->project_repo->get_file_info((int) $fields['file_id']);
            if (!$file || (int) $file['project_id'] !== (int) $project_id)
            {
                $fields['file_id'] = 0;
            }
        }

        $ok = $this->project_repo->update_task($task_id, $fields, (int) $this->user->data['user_id']);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_UPDATE_FAILED');
        }

        $updated = $this->project_repo->get_task($task_id);
        $action = (isset($fields['status']) && $fields['status'] === 'done') ? 'task_completed' : 'task_updated';
        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], $action, 'task', (string) $updated['title'], ['task_id' => $task_id]);
        }

        if (!empty($updated['assigned_to']) && (int) $updated['assigned_to'] !== (int) $this->user->data['user_id'])
        {
            $this->notify_user($project_id, (int) $updated['assigned_to'], 'task_updated', 'task', (string) $updated['title'], 'WSP_NOTIFY_TASK_UPDATED', ['task_id' => $task_id]);
        }

        return $this->json_success(['task' => $updated]);
    }

    /**
     * Exclui tarefa colaborativa.
     */
    public function delete_task()
    {
        if ($r = $this->ensure_workspace_access()) { return $r; }

        $project_id = (int) $this->request->variable('project_id', 0);
        $task_id = (int) $this->request->variable('task_id', 0);

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return $this->json_error_msg($access['error']);
        }

        $task = (isset($this->project_repo) && method_exists($this->project_repo, 'get_task')) ? $this->project_repo->get_task($task_id) : null;
        if (!$task || (int) $task['project_id'] !== (int) $project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $ok = $this->project_repo->delete_task($task_id);
        if (!$ok)
        {
            return $this->json_error('WSP_ERR_DELETE_FAILED');
        }

        if (method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($project_id, (int) $this->user->data['user_id'], 'task_deleted', 'task', (string) $task['title'], ['task_id' => $task_id]);
        }

        return $this->json_success();
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
     * Exporta ZIP colaborativo: arquivos do projeto + relatorios de equipe, revisao, comentarios,
     * historico de versoes e atividade recente. Nao altera arquivos do projeto.
     */
    public function export_collaborative_package($project_id = 0)
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

        $project = $this->fetch_project_export_row($p_id);
        if (!$project) { trigger_error($this->user->lang('WSP_ERR_INVALID_ID')); }
        if (!class_exists('ZipArchive')) { trigger_error($this->user->lang('WSP_ERR_ZIP_NOT_AVAILABLE')); }

        $zip = new \ZipArchive();
        $temp_file = tempnam(sys_get_temp_dir(), 'wsp_collab');

        if ($zip->open($temp_file, \ZipArchive::CREATE) !== true)
        {
            trigger_error($this->user->lang('WSP_ERR_INVALID_DATA'));
        }

        $sql = 'SELECT file_id, file_name, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . (int) $p_id . '
                ORDER BY file_name ASC';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            if (strtolower(basename((string) $row['file_name'])) === '.placeholder') { continue; }
            $zip->addFromString((string) $row['file_name'], (string) $row['file_content']);
        }
        $this->db->sql_freeresult($result);

        $base_dir = '_workspace_review/';
        $zip->addFromString($base_dir . 'README.md', $this->build_export_readme($project));
        $zip->addFromString($base_dir . 'project_report.md', $this->build_project_report_markdown($p_id, $project));
        $zip->addFromString($base_dir . 'members.csv', $this->build_members_csv($p_id));
        $zip->addFromString($base_dir . 'activity.csv', $this->build_activity_csv($p_id));
        $zip->addFromString($base_dir . 'review_status.csv', $this->build_review_status_csv($p_id));
        $zip->addFromString($base_dir . 'comments.csv', $this->build_comments_csv($p_id));
        $zip->addFromString($base_dir . 'versions.csv', $this->build_versions_csv($p_id));
        $zip->addFromString($base_dir . 'tasks.csv', $this->build_tasks_csv($p_id));
        $zip->addFromString($base_dir . 'release_checklist.md', $this->build_release_checklist_markdown($p_id));
        $zip->addFromString($base_dir . 'validation_report.csv', $this->build_validation_report_csv($p_id));

        $zip->close();

        if (isset($this->project_repo) && method_exists($this->project_repo, 'log_activity'))
        {
            $this->project_repo->log_activity($p_id, (int) $this->user->data['user_id'], 'collab_exported', 'project', (string) $project['project_name']);
        }

        $download_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $project['project_name']) . '_collab_export.zip';

        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    private function fetch_project_export_row($project_id)
    {
        $sql = 'SELECT p.*, u.username AS owner_username
                FROM ' . $this->table_prefix . 'workspace_projects p
                LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.user_id
                WHERE p.project_id = ' . (int) $project_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ?: false;
    }

    private function export_csv(array $headers, array $rows)
    {
        $out = [];
        $out[] = $this->csv_line($headers);
        foreach ($rows as $row)
        {
            $line = [];
            foreach ($headers as $key)
            {
                $line[] = isset($row[$key]) ? $row[$key] : '';
            }
            $out[] = $this->csv_line($line);
        }
        return implode("\n", $out) . "\n";
    }

    private function csv_line(array $values)
    {
        $escaped = [];
        foreach ($values as $value)
        {
            $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
            $value = str_replace('"', '""', $value);
            $escaped[] = '"' . $value . '"';
        }
        return implode(',', $escaped);
    }

    private function fmt_time($time)
    {
        $time = (int) $time;
        return $time > 0 ? date('Y-m-d H:i:s', $time) : '';
    }

    private function build_export_readme(array $project)
    {
        $name = (string) ($project['project_name'] ?? 'Workspace');
        return '# ' . $name . " - Workspace collaborative export\n\n"
            . "This ZIP contains the current project files and a review package generated by Mundo phpBB Workspace.\n\n"
            . "## Review files\n\n"
            . "- `project_report.md`: human-readable project summary.\n"
            . "- `members.csv`: project team and roles.\n"
            . "- `activity.csv`: recent collaborative activity.\n"
            . "- `review_status.csv`: current file review status.\n"
            . "- `comments.csv`: file review comments.\n"
            . "- `versions.csv`: available file snapshots.\n\n"
            . "Generated at: " . date('Y-m-d H:i:s') . "\n";
    }

    private function build_project_report_markdown($project_id, array $project)
    {
        $files_count = $this->count_rows('workspace_files', 'project_id = ' . (int) $project_id . " AND LOWER(file_name) <> '.placeholder'");
        $comments_open = $this->count_rows('workspace_comments', 'project_id = ' . (int) $project_id . ' AND resolved = 0');
        $comments_total = $this->count_rows('workspace_comments', 'project_id = ' . (int) $project_id);
        $reviews_pending = $this->count_rows('workspace_file_reviews', 'project_id = ' . (int) $project_id . " AND status = 'pending'");
        $reviews_approved = $this->count_rows('workspace_file_reviews', 'project_id = ' . (int) $project_id . " AND status = 'approved'");
        $reviews_changes = $this->count_rows('workspace_file_reviews', 'project_id = ' . (int) $project_id . " AND status = 'changes_requested'");
        $versions_total = $this->count_rows('workspace_file_versions', 'project_id = ' . (int) $project_id);
        $tasks_todo = $this->count_rows('workspace_tasks', 'project_id = ' . (int) $project_id . " AND status = 'todo'");
        $tasks_doing = $this->count_rows('workspace_tasks', 'project_id = ' . (int) $project_id . " AND status = 'doing'");
        $tasks_done = $this->count_rows('workspace_tasks', 'project_id = ' . (int) $project_id . " AND status = 'done'");
        $members = isset($this->project_repo) && method_exists($this->project_repo, 'get_project_members') ? (array) $this->project_repo->get_project_members($project_id) : [];

        $md = '# Collaborative review report: ' . (string) $project['project_name'] . "\n\n";
        $md .= '- Project ID: ' . (int) $project_id . "\n";
        $md .= '- Owner: ' . (string) ($project['owner_username'] ?? '') . "\n";
        $md .= '- Generated at: ' . date('Y-m-d H:i:s') . "\n";
        $md .= '- Project locked: ' . (!empty($project['project_locked']) ? 'yes' : 'no') . "\n\n";
        $md .= "## Summary\n\n";
        $md .= '- Files: ' . $files_count . "\n";
        $md .= '- Members: ' . count($members) . "\n";
        $md .= '- Comments: ' . $comments_total . ' total, ' . $comments_open . " open\n";
        $md .= '- Reviews: ' . $reviews_pending . ' pending, ' . $reviews_approved . ' approved, ' . $reviews_changes . " with requested changes\n";
        $md .= '- Snapshots: ' . $versions_total . "\n";
        $md .= '- Tasks: ' . $tasks_todo . ' to do, ' . $tasks_doing . ' in progress, ' . $tasks_done . " done\n\n";
        $md .= "## Team\n\n";
        foreach ($members as $m)
        {
            $md .= '- ' . (string) ($m['username'] ?? '') . ' — ' . (string) ($m['role'] ?? '') . "\n";
        }
        return $md;
    }

    private function count_rows($table, $where)
    {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $this->table_prefix . $table . ' WHERE ' . $where;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ? (int) $row['total'] : 0;
    }

    private function build_members_csv($project_id)
    {
        $rows = [];
        $members = isset($this->project_repo) && method_exists($this->project_repo, 'get_project_members') ? (array) $this->project_repo->get_project_members($project_id) : [];
        foreach ($members as $m)
        {
            $rows[] = [
                'user_id' => (int) ($m['user_id'] ?? 0),
                'username' => (string) ($m['username'] ?? ''),
                'role' => (string) ($m['role'] ?? ''),
                'added_by' => (int) ($m['added_by'] ?? 0),
                'added_time' => $this->fmt_time($m['added_time'] ?? 0),
                'is_owner' => !empty($m['is_owner']) ? 1 : 0,
            ];
        }
        return $this->export_csv(['user_id', 'username', 'role', 'added_by', 'added_time', 'is_owner'], $rows);
    }

    private function build_activity_csv($project_id)
    {
        $rows = [];
        $activity = isset($this->project_repo) && method_exists($this->project_repo, 'get_recent_activity') ? (array) $this->project_repo->get_recent_activity($project_id, 50) : [];
        foreach ($activity as $a)
        {
            $rows[] = [
                'activity_id' => (int) ($a['activity_id'] ?? 0),
                'created_time' => $this->fmt_time($a['created_time'] ?? 0),
                'username' => (string) ($a['username'] ?? ''),
                'action' => (string) ($a['action'] ?? ''),
                'object_type' => (string) ($a['object_type'] ?? ''),
                'object_path' => (string) ($a['object_path'] ?? ''),
                'metadata' => (string) ($a['metadata'] ?? ''),
            ];
        }
        return $this->export_csv(['activity_id', 'created_time', 'username', 'action', 'object_type', 'object_path', 'metadata'], $rows);
    }

    private function build_review_status_csv($project_id)
    {
        $sql = 'SELECT f.file_name, r.status, r.review_note, r.created_time, r.updated_time, r.reviewed_time,
                       req.username AS requested_by, rev.username AS reviewed_by
                FROM ' . $this->table_prefix . 'workspace_file_reviews r
                LEFT JOIN ' . $this->table_prefix . 'workspace_files f ON f.file_id = r.file_id
                LEFT JOIN ' . USERS_TABLE . ' req ON req.user_id = r.requested_by
                LEFT JOIN ' . USERS_TABLE . ' rev ON rev.user_id = r.reviewed_by
                WHERE r.project_id = ' . (int) $project_id . '
                ORDER BY f.file_name ASC, r.updated_time DESC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($r = $this->db->sql_fetchrow($result))
        {
            $rows[] = [
                'file_name' => (string) ($r['file_name'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'requested_by' => (string) ($r['requested_by'] ?? ''),
                'reviewed_by' => (string) ($r['reviewed_by'] ?? ''),
                'created_time' => $this->fmt_time($r['created_time'] ?? 0),
                'updated_time' => $this->fmt_time($r['updated_time'] ?? 0),
                'reviewed_time' => $this->fmt_time($r['reviewed_time'] ?? 0),
                'review_note' => (string) ($r['review_note'] ?? ''),
            ];
        }
        $this->db->sql_freeresult($result);
        return $this->export_csv(['file_name', 'status', 'requested_by', 'reviewed_by', 'created_time', 'updated_time', 'reviewed_time', 'review_note'], $rows);
    }

    private function build_comments_csv($project_id)
    {
        $sql = 'SELECT f.file_name, c.comment_id, c.line_number, c.message, c.resolved, c.created_time, c.resolved_time,
                       u.username, ru.username AS resolved_by
                FROM ' . $this->table_prefix . 'workspace_comments c
                LEFT JOIN ' . $this->table_prefix . 'workspace_files f ON f.file_id = c.file_id
                LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = c.user_id
                LEFT JOIN ' . USERS_TABLE . ' ru ON ru.user_id = c.resolved_by
                WHERE c.project_id = ' . (int) $project_id . '
                ORDER BY c.resolved ASC, f.file_name ASC, c.line_number ASC, c.created_time ASC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($r = $this->db->sql_fetchrow($result))
        {
            $rows[] = [
                'comment_id' => (int) ($r['comment_id'] ?? 0),
                'file_name' => (string) ($r['file_name'] ?? ''),
                'line_number' => (int) ($r['line_number'] ?? 0),
                'username' => (string) ($r['username'] ?? ''),
                'created_time' => $this->fmt_time($r['created_time'] ?? 0),
                'resolved' => !empty($r['resolved']) ? 1 : 0,
                'resolved_by' => (string) ($r['resolved_by'] ?? ''),
                'resolved_time' => $this->fmt_time($r['resolved_time'] ?? 0),
                'message' => (string) ($r['message'] ?? ''),
            ];
        }
        $this->db->sql_freeresult($result);
        return $this->export_csv(['comment_id', 'file_name', 'line_number', 'username', 'created_time', 'resolved', 'resolved_by', 'resolved_time', 'message'], $rows);
    }

    private function build_versions_csv($project_id)
    {
        $sql = 'SELECT v.version_id, v.file_id, v.file_name, v.content_hash, v.source, v.change_note, v.created_time, u.username
                FROM ' . $this->table_prefix . 'workspace_file_versions v
                LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = v.user_id
                WHERE v.project_id = ' . (int) $project_id . '
                ORDER BY v.file_name ASC, v.created_time DESC, v.version_id DESC';
        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($r = $this->db->sql_fetchrow($result))
        {
            $rows[] = [
                'version_id' => (int) ($r['version_id'] ?? 0),
                'file_id' => (int) ($r['file_id'] ?? 0),
                'file_name' => (string) ($r['file_name'] ?? ''),
                'username' => (string) ($r['username'] ?? ''),
                'source' => (string) ($r['source'] ?? ''),
                'created_time' => $this->fmt_time($r['created_time'] ?? 0),
                'content_hash' => (string) ($r['content_hash'] ?? ''),
                'change_note' => (string) ($r['change_note'] ?? ''),
            ];
        }
        $this->db->sql_freeresult($result);
        return $this->export_csv(['version_id', 'file_id', 'file_name', 'username', 'source', 'created_time', 'content_hash', 'change_note'], $rows);
    }



    private function build_tasks_csv($project_id)
    {
        $tasks = (isset($this->project_repo) && method_exists($this->project_repo, 'get_project_tasks'))
            ? (array) $this->project_repo->get_project_tasks($project_id, 200)
            : [];

        $rows = [];
        foreach ($tasks as $t)
        {
            $rows[] = [
                'task_id' => (int) ($t['task_id'] ?? 0),
                'title' => (string) ($t['title'] ?? ''),
                'status' => (string) ($t['status'] ?? ''),
                'priority' => (string) ($t['priority'] ?? ''),
                'assigned_to' => (int) ($t['assigned_to'] ?? 0),
                'assigned_username' => (string) ($t['assigned_username'] ?? ''),
                'file_id' => (int) ($t['file_id'] ?? 0),
                'file_name' => (string) ($t['file_name'] ?? ''),
                'created_username' => (string) ($t['created_username'] ?? ''),
                'created_time' => $this->fmt_time($t['created_time'] ?? 0),
                'updated_time' => $this->fmt_time($t['updated_time'] ?? 0),
                'description' => (string) ($t['description'] ?? ''),
            ];
        }

        return $this->export_csv(['task_id', 'title', 'status', 'priority', 'assigned_to', 'assigned_username', 'file_id', 'file_name', 'created_username', 'created_time', 'updated_time', 'description'], $rows);
    }



    private function collect_release_validation_rows($project_id)
    {
        $sql = 'SELECT file_name, file_content FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' . (int) $project_id;
        $result = $this->db->sql_query($sql);
        $files = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $name = str_replace('\\', '/', (string) $row['file_name']);
            if ($name !== '' && strtolower(basename($name)) !== '.placeholder')
            {
                $files[$name] = (string) $row['file_content'];
            }
        }
        $this->db->sql_freeresult($result);

        $rows = [];
        $add = function ($status, $category, $message, $file = '', $action = '', $example = '', $fixable = false, $line = 0, $excerpt = '') use (&$rows) {
            $rows[] = ['status' => $status, 'category' => $category, 'message' => $message, 'file' => $file, 'line' => (int) $line, 'excerpt' => (string) $excerpt, 'action' => $action, 'example' => $example, 'fixable' => $fixable ? 'yes' : 'no'];
        };
        $has = function ($name) use ($files) { return array_key_exists($name, $files); };
        $match = function ($pattern) use ($files) {
            foreach ($files as $name => $content) { if (preg_match($pattern, $name)) { return true; } }
            return false;
        };
        $is_validation_vendor_area = function ($name) {
            $name = strtolower(str_replace('\\', '/', (string) $name));
            return (bool) preg_match('#^(vendor/|node_modules/|lib/diff(?:/|\.php$)|styles/[^/]+/template/ace/|styles/[^/]+/theme/vendor/|assets/vendor/)#', $name);
        };

        if ($has('composer.json'))
        {
            $json = json_decode($files['composer.json'], true);
            if (is_array($json))
            {
                $add('pass', 'composer.json', 'composer.json valido.', 'composer.json');
                if (empty($json['name']) || strpos((string) $json['name'], '/') === false) { $add('error', 'composer.json', 'Campo name deve usar vendor/extension.', 'composer.json', 'Defina o name canônico da extensão.', '"name": "mundophpbb/workspace"'); }
                if (empty($json['type']) || (string) $json['type'] !== 'phpbb-extension') { $add('warning', 'composer.json', 'Recomendado type phpbb-extension.', 'composer.json', 'Ajuste o campo type.', '"type": "phpbb-extension"', true); }
                if (empty($json['license'])) { $add('warning', 'composer.json', 'Licenca ausente.', 'composer.json', 'Inclua uma licença.', '"license": "GPL-2.0-only"'); }
            }
            else { $add('error', 'composer.json', 'JSON invalido.', 'composer.json'); }
        }
        else { $add('error', 'Estrutura', 'composer.json ausente.', 'composer.json'); }

        $add($has('ext.php') ? 'pass' : 'error', 'Estrutura', $has('ext.php') ? 'ext.php presente.' : 'ext.php ausente.', 'ext.php');
        $add($has('config/services.yml') ? 'pass' : 'warning', 'Config', $has('config/services.yml') ? 'services.yml presente.' : 'services.yml ausente.', 'config/services.yml');
        $add($has('config/routing.yml') ? 'pass' : 'warning', 'Config', $has('config/routing.yml') ? 'routing.yml presente.' : 'routing.yml ausente.', 'config/routing.yml');
        $add($match('#^language/en/.+\.php$#') ? 'pass' : 'warning', 'Idioma', $match('#^language/en/.+\.php$#') ? 'language/en presente.' : 'language/en ausente.', 'language/en');
        $add($match('#^language/pt_br/.+\.php$#') ? 'pass' : 'warning', 'Idioma', $match('#^language/pt_br/.+\.php$#') ? 'language/pt_br presente.' : 'language/pt_br ausente.', 'language/pt_br');
        $add($match('#^migrations/.+\.php$#') ? 'pass' : 'warning', 'Migration', $match('#^migrations/.+\.php$#') ? 'migrations encontradas.' : 'Nenhuma migration encontrada.', 'migrations');

        foreach ($files as $name => $content)
        {
            if ($is_validation_vendor_area($name))
            {
                continue;
            }
            if (preg_match('#\.php$#', $name) && preg_match('/\?>\s*$/', $content)) { $add('warning', 'PHP', 'Evite fechar PHP puro com ?>.', $name, 'Remova o fechamento final para evitar saída acidental.', 'Remova apenas o ?> final.', true); }
            if (preg_match('/\b(?:TODO|FIXME)\b/i', $content))
            {
                $lines = preg_split('/\R/u', $content);
                foreach ($lines as $line_index => $line_text)
                {
                    if (!preg_match('/\b(TODO|FIXME)\b/i', $line_text, $todo_match))
                    {
                        continue;
                    }
                    $line_number = (int) $line_index + 1;
                    $excerpt = trim((string) $line_text);
                    if (function_exists('mb_substr')) { $excerpt = mb_substr($excerpt, 0, 220, 'UTF-8'); }
                    else { $excerpt = substr($excerpt, 0, 220); }
                    $token = strtoupper((string) $todo_match[1]);
                    $add('warning', 'Qualidade', $token . ' encontrado antes do release.', $name, 'Revise a linha indicada. Resolva, registre como tarefa ou remova o marcador antes do release.', 'Linha ' . $line_number . ': ' . $excerpt, false, $line_number, $excerpt);
                }
            }
        }

        return $rows;
    }

    private function build_release_checklist_markdown($project_id)
    {
        $rows = $this->collect_release_validation_rows($project_id);
        $errors = 0; $warnings = 0; $passes = 0;
        foreach ($rows as $r)
        {
            if ($r['status'] === 'error') { $errors++; }
            else if ($r['status'] === 'warning') { $warnings++; }
            else if ($r['status'] === 'pass') { $passes++; }
        }
        $md = "# phpBB release checklist\n\n";
        $md .= '- Generated at: ' . date('Y-m-d H:i:s') . "\n";
        $md .= '- Passed: ' . $passes . "\n";
        $md .= '- Warnings: ' . $warnings . "\n";
        $md .= '- Errors: ' . $errors . "\n\n";
        $md .= "## Items\n\n";
        foreach ($rows as $r)
        {
            $md .= '- **' . strtoupper($r['status']) . '** [' . $r['category'] . '] ' . $r['message'];
            if ($r['file'] !== '') { $md .= ' (`' . $r['file'] . '`)'; }
            if (!empty($r['line'])) { $md .= ' - linha ' . (int) $r['line']; }
            if (!empty($r['excerpt'])) { $md .= "\n  - Trecho: `" . str_replace('`', '\\`', $r['excerpt']) . "`"; }
            if (!empty($r['action'])) { $md .= "\n  - Como corrigir: " . $r['action']; }
            if (!empty($r['example'])) { $md .= "\n  - Exemplo: `" . str_replace('`', '\`', $r['example']) . "`"; }
            $md .= "\n";
        }
        return $md;
    }

    private function build_validation_report_csv($project_id)
    {
        return $this->export_csv(['status', 'category', 'message', 'file', 'line', 'excerpt', 'action', 'example', 'fixable'], $this->collect_release_validation_rows($project_id));
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