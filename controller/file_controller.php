<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mundo phpBB Workspace - File Controller
 * Versão 3.9: Gestão de I/O + Changelog com Diff (Apenas Alterações) + i18n Total
 *
 * Atualizações aplicadas (sem omissões):
 * - i18n puro no changelog (remove strings hardcoded "Alterações (Diff)" e "(O conteúdo...)")
 * - Normalização de line-endings (\r\n e \r -> \n) antes de diff/salvar (evita diff "arquivo inteiro")
 * - Updates SQL usando sql_build_array('UPDATE') (compatível com múltiplos bancos)
 * - Guards de permissão u_workspace_access também em rename/move/delete
 * - file_type mais inteligente para Dockerfile/Makefile/.htaccess/.placeholder
 */
class file_controller extends base_controller
{
    /**
     * Realiza o upload de ficheiros preservando caminhos de pastas (full_path)
     * + BLINDAGEM: Suporte a descodificação Base64 para evitar bloqueios de Firewall
     */
    public function upload_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = (int) $this->request->variable('project_id', 0);
        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
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
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_EXT')]);
        }

        // Conteúdo
        $content = false;

        if ($is_encoded && !empty($encoded_content))
        {
            if (preg_match('/^data:.*?;base64,/', $encoded_content))
            {
                $encoded_content = preg_replace('/^data:.*?;base64,/', '', $encoded_content);
            }

            // strict base64 decode (evita lixo)
            $content = base64_decode($encoded_content, true);
        }
        else if (isset($file['tmp_name']) && !empty($file['tmp_name']))
        {
            $content = @file_get_contents($file['tmp_name']);
        }
        else
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_NO_CONTENT')]);
        }

        if ($content === false)
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_CONTENT_PROCESS')]);
        }

        // Normaliza line endings (evita diff gigante)
        $content = str_replace(["\r\n", "\r"], "\n", (string) $content);

        // file_type inteligente
        $base = basename(str_replace('\\', '/', $filename));
        if ($base === '.placeholder')
        {
            $ext = 'txt';
        }
        else if ($base === 'Dockerfile')
        {
            $ext = 'dockerfile';
        }
        else if ($base === 'Makefile')
        {
            $ext = 'makefile';
        }
        else if ($base === '.htaccess')
        {
            $ext = 'htaccess';
        }
        else
        {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';
        }

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
            $old = str_replace(["\r\n", "\r"], "\n", (string) $row['file_content']);

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
        return new JsonResponse(['success' => true]);
    }

    /**
     * Adiciona um novo ficheiro
     */
    public function add_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = (int) $this->request->variable('project_id', 0);
        $filename   = trim($this->request->variable('name', '', true));

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $filename = $this->sanitize_rel_path($filename);
        if (!$project_id || $filename === '')
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        if (!$this->is_placeholder_path($filename) && !$this->is_extension_allowed($filename))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_EXT')]);
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
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_FILE_EXISTS')]);
        }

        // file_type inteligente
        $base = basename(str_replace('\\', '/', $filename));
        if ($base === '.placeholder')
        {
            $ext = 'txt';
        }
        else if ($base === 'Dockerfile')
        {
            $ext = 'dockerfile';
        }
        else if ($base === 'Makefile')
        {
            $ext = 'makefile';
        }
        else if ($base === '.htaccess')
        {
            $ext = 'htaccess';
        }
        else
        {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';
        }

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

        return new JsonResponse(['success' => true, 'file_id' => $file_id]);
    }

    /**
     * Carrega o conteúdo de um ficheiro para o editor
     */
    public function load_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = (int) $this->request->variable('file_id', 0);
        $access = $this->assert_file_access($file_id, 'view');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $sql = 'SELECT file_content, file_name, file_type
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE file_id = ' . (int) $file_id;

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row)
        {
            $content = (string) $row['file_content'];
            // Normaliza line endings na saída também
            $content = str_replace(["\r\n", "\r"], "\n", $content);

            return new JsonResponse([
                'success' => true,
                'content' => (string) html_entity_decode($content, ENT_QUOTES, 'UTF-8'),
                'name'    => (string) $row['file_name'],
                'type'    => strtolower((string) $row['file_type']),
            ]);
        }

        return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_FILE_NOT_FOUND')]);
    }

    /**
     * Grava as alterações do editor
     */
    public function save_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = (int) $this->request->variable('file_id', 0);
        $content = $this->request->variable('content', '', true);

        $access = $this->assert_file_access($file_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        // Normaliza line endings antes de comparar/salvar
        $content = str_replace(["\r\n", "\r"], "\n", (string) $content);

        $sql_old = 'SELECT project_id, file_name, file_content
                    FROM ' . $this->table_prefix . 'workspace_files
                    WHERE file_id = ' . (int) $file_id;

        $result_old = $this->db->sql_query($sql_old);
        $row_old    = $this->db->sql_fetchrow($result_old);
        $this->db->sql_freeresult($result_old);

        $this->db->sql_transaction('begin');

        if ($row_old)
        {
            $old = str_replace(["\r\n", "\r"], "\n", (string) $row_old['file_content']);

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

        return new JsonResponse(['success' => true]);
    }

    /**
     * Renomeia um ficheiro
     */
    public function rename_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id  = (int) $this->request->variable('file_id', 0);
        $new_name = $this->sanitize_rel_path($this->request->variable('new_name', '', true));

        if ($new_name === '')
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        if (!$this->is_placeholder_path($new_name) && !$this->is_extension_allowed($new_name))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_EXT')]);
        }

        $access = $this->assert_file_access($file_id, 'rename');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $project_id = (int) $access['project_id'];

        $sql_old = 'SELECT file_name
                    FROM ' . $this->table_prefix . 'workspace_files
                    WHERE file_id = ' . (int) $file_id;

        $result_old = $this->db->sql_query($sql_old);
        $row_old    = $this->db->sql_fetchrow($result_old);
        $this->db->sql_freeresult($result_old);

        $this->db->sql_transaction('begin');

        if ($row_old)
        {
            $this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_RENAME_ACTION', (string) $row_old['file_name'], $new_name));
        }

        $base = basename(str_replace('\\', '/', $new_name));
        if ($base === '.placeholder')
        {
            $new_ext = 'txt';
        }
        else if ($base === 'Dockerfile')
        {
            $new_ext = 'dockerfile';
        }
        else if ($base === 'Makefile')
        {
            $new_ext = 'makefile';
        }
        else if ($base === '.htaccess')
        {
            $new_ext = 'htaccess';
        }
        else
        {
            $new_ext = strtolower(pathinfo($new_name, PATHINFO_EXTENSION)) ?: 'txt';
        }

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
        return new JsonResponse(['success' => true]);
    }

    /**
     * Move um ficheiro para uma nova pasta (virtual)
     */
    public function move_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id  = (int) $this->request->variable('file_id', 0);
        $new_path = $this->sanitize_rel_path($this->request->variable('new_path', '', true));

        if (!$file_id || $new_path === '')
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        if (!$this->is_placeholder_path($new_path) && !$this->is_extension_allowed($new_path))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_EXT')]);
        }

        $access = $this->assert_file_access($file_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $project_id = (int) $access['project_id'];

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
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_FILE_EXISTS')]);
        }

        $sql_old = 'SELECT file_name
                    FROM ' . $this->table_prefix . 'workspace_files
                    WHERE file_id = ' . (int) $file_id;

        $result_old = $this->db->sql_query($sql_old);
        $row_old    = $this->db->sql_fetchrow($result_old);
        $this->db->sql_freeresult($result_old);

        $this->db->sql_transaction('begin');

        if ($row_old)
        {
            $this->log_to_changelog($project_id, $this->user->lang('WSP_LOG_FILE_MOVE_ACTION', (string) $row_old['file_name'], $new_path));
        }

        $base = basename(str_replace('\\', '/', $new_path));
        if ($base === '.placeholder')
        {
            $new_ext = 'txt';
        }
        else if ($base === 'Dockerfile')
        {
            $new_ext = 'dockerfile';
        }
        else if ($base === 'Makefile')
        {
            $new_ext = 'makefile';
        }
        else if ($base === '.htaccess')
        {
            $new_ext = 'htaccess';
        }
        else
        {
            $new_ext = strtolower(pathinfo($new_path, PATHINFO_EXTENSION)) ?: 'txt';
        }

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
        return new JsonResponse(['success' => true]);
    }

    /**
     * Elimina um ficheiro permanentemente
     */
    public function delete_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = (int) $this->request->variable('file_id', 0);
        $access  = $this->assert_file_access($file_id, 'delete');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $sql_name = 'SELECT file_name
                     FROM ' . $this->table_prefix . 'workspace_files
                     WHERE file_id = ' . (int) $file_id;

        $result_name = $this->db->sql_query($sql_name);
        $row_name    = $this->db->sql_fetchrow($result_name);
        $this->db->sql_freeresult($result_name);

        $this->db->sql_transaction('begin');

        if ($row_name)
        {
            $this->log_to_changelog((int) $access['project_id'], $this->user->lang('WSP_LOG_DELETE_ACTION', (string) $row_name['file_name']));
        }

        $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'workspace_files
             WHERE file_id = ' . (int) $file_id
        );

        $this->db->sql_transaction('commit');

        return new JsonResponse(['success' => true]);
    }

    /**
     * Helper para logs no changelog.txt - MOSTRA APENAS O QUE MUDOU (Unified Diff)
     */
    private function log_to_changelog($project_id, $action, $old_content = null, $new_content = null)
    {
        $date = date('d/m/Y H:i');
        $action = (string) $action;

        // Normaliza line endings para diff consistente
        if ($old_content !== null) $old_content = str_replace(["\r\n", "\r"], "\n", (string) $old_content);
        if ($new_content !== null) $new_content = str_replace(["\r\n", "\r"], "\n", (string) $new_content);

        $log_entry = "[$date] $action\n";

        // Se houver conteúdo para comparar e eles forem diferentes
        if ($old_content !== null && $new_content !== null && $old_content !== $new_content)
        {
            $lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';
            if (file_exists($lib_path . 'Diff.php'))
            {
                require_once($lib_path . 'Diff.php');
                require_once($lib_path . 'Diff/Renderer/Abstract.php');
                require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

                $diff = new \Diff(explode("\n", $old_content), explode("\n", $new_content));
                $renderer = new \Diff_Renderer_Text_Unified();
                $diff_text = $diff->render($renderer);

                if (!empty(trim((string) $diff_text)))
                {
                    // i18n puro (sem hardcode PT)
                    $log_entry .= $this->user->lang('WSP_LOG_DIFF_LABEL') . ":\n" . $diff_text . "\n";
                }
            }
            else
            {
                // Fallback básico se a biblioteca não existir (i18n)
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
            // Normaliza conteúdo existente antes de concatenar
            $existing = str_replace(["\r\n", "\r"], "\n", (string) $row['file_content']);

            // Prepend: Adiciona no topo do changelog.txt para leitura imediata
            $new_changelog = $log_entry . str_repeat("-", 40) . "\n" . $existing;

            $sql_ary = [
                'file_content' => $new_changelog,
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