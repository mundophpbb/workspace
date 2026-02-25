<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mundo phpBB Workspace - Tool Controller
 * Versão 4.3: Diff Inteligente & Normalização de Finais de Linha
 * Resolve o problema de exibir o arquivo inteiro no Diff/Changelog.
 *
 * Atualizações (sem omissões):
 * - i18n total no header do changelog (não grava chaves WSP_... dentro do arquivo)
 * - ACL u_workspace_access aplicado também em generate/clear/diff/cache
 * - Updates SQL compatíveis com múltiplos bancos (sql_build_array) (evita aspas duplas quebrando SQLite/Postgres)
 * - Diff "sem mudanças" também retorna BBCode completo [diff=...][/diff]
 */
class tool_controller extends base_controller
{
    /**
     * Procura um termo em todos os arquivos de um projeto (Busca Global)
     */
    public function search_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $project_id  = (int) $this->request->variable('project_id', 0);
        $search_term = $this->request->variable('search', '', true);

        if (!$project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if ($search_term === '')
        {
            // Melhor feedback para UI
            return $this->json_error('WSP_TOOLS_SEARCH_TERM_REQUIRED');
        }

        $access = $this->assert_project_access($project_id, 'view');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $like = '%' . $this->db->sql_escape($search_term) . '%';

        $sql = 'SELECT file_id, file_name
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . $project_id . "
                  AND file_content LIKE '" . $like . "'";

        $result  = $this->db->sql_query($sql);
        $matches = [];

        while ($row = $this->db->sql_fetchrow($result))
        {
            // Ignora o changelog na busca global
            if ($row['file_name'] === 'changelog.txt')
            {
                continue;
            }

            $matches[] = [
                'id'   => (int) $row['file_id'],
                'name' => (string) $row['file_name'],
            ];
        }
        $this->db->sql_freeresult($result);

        return new JsonResponse(['success' => true, 'matches' => $matches]);
    }

    /**
     * Substitui termos em arquivos e registra auditoria no log
     */
    public function replace_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $project_id   = (int) $this->request->variable('project_id', 0);
        $file_id      = (int) $this->request->variable('file_id', 0);
        $search_term  = $this->request->variable('search', '', true);
        $replace_term = $this->request->variable('replace', '', true);

        if (!$project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if ($search_term === '')
        {
            return $this->json_error('WSP_TOOLS_SEARCH_TERM_REQUIRED');
        }

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $sql_where = 'project_id = ' . $project_id;
        if ($file_id > 0)
        {
            $sql_where .= ' AND file_id = ' . $file_id;
        }

        $like = '%' . $this->db->sql_escape($search_term) . '%';
        $sql = 'SELECT file_id, file_content, file_name
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE ' . $sql_where . "
                  AND file_content LIKE '" . $like . "'";

        $result        = $this->db->sql_query($sql);
        $updated_count = 0;

        while ($row = $this->db->sql_fetchrow($result))
        {
            if ($row['file_name'] === 'changelog.txt')
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

                $this->db->sql_query($update_sql);

                // Log traduzido (string WSP_LOG_REPLACE_ACTION usa %1$s etc)
                $log_msg = sprintf(
                    $this->user->lang('WSP_LOG_REPLACE_ACTION'),
                    $search_term,
                    $replace_term,
                    (string) $row['file_name']
                );

                $this->log_to_changelog_internal($project_id, $log_msg);

                $updated_count++;
            }
        }
        $this->db->sql_freeresult($result);

        return new JsonResponse(['success' => true, 'updated' => $updated_count]);
    }

    /**
     * Adiciona um cabeçalho de Consolidação/Versão ao changelog
     * (i18n puro: grava texto traduzido, não grava chave WSP_...)
     */
    public function generate_changelog()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $project_id = (int) $this->request->variable('project_id', 0);
        if (!$project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $sql = 'SELECT file_id, file_content
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . $project_id . " AND file_name = 'changelog.txt'";

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return $this->json_error('WSP_ERR_FILE_NOT_FOUND');
        }

        $date_str = date('d/m/Y H:i');

        // Header 100% traduzido
        $header  = "\n" . str_repeat("#", 60) . "\n";
        // Requer a chave: WSP_GENERATE_CHANGELOG_AT = "Consolidar Versão - %s"
        $header .= "# " . $this->user->lang('WSP_GENERATE_CHANGELOG_AT', $date_str) . "\n";
        $header .= str_repeat("#", 60) . "\n\n";

        $existing = str_replace(["\r\n", "\r"], "\n", (string) $row['file_content']);
        $final_content = $header . $existing;

        $sql_ary = [
            'file_content' => $final_content,
            'file_time'    => time(),
        ];

        $update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
                       SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                       WHERE file_id = ' . (int) $row['file_id'];

        $this->db->sql_query($update_sql);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Limpa o conteúdo do histórico (Sincronizado com i18n)
     * (i18n puro: grava texto traduzido, não grava chave WSP_...)
     */
    public function clear_changelog()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $project_id = (int) $this->request->variable('project_id', 0);
        if (!$project_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        $access = $this->assert_project_access($project_id, 'edit');
        if (!$access['ok'])
        {
            return new JsonResponse(['success' => false, 'error' => $access['error']]);
        }

        $date_str = date('d/m/Y H:i');

        $header  = str_repeat("=", 60) . "\n";
        // Requer a chave: WSP_HISTORY_CLEANED_AT = "Histórico do projeto limpo em %s"
        $header .= "  " . $this->user->lang('WSP_HISTORY_CLEANED_AT', $date_str) . "\n";
        $header .= str_repeat("=", 60) . "\n\n";

        $sql_ary = [
            'file_content' => $header,
            'file_time'    => time(),
        ];

        $update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
                       SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                       WHERE project_id = ' . $project_id . " AND file_name = 'changelog.txt'";

        $this->db->sql_query($update_sql);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Gera comparação Diff com NORMALIZAÇÃO DE LINE-ENDINGS
     */
    public function generate_diff()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        $v1_id    = (int) $this->request->variable('original_id', 0);
        $v2_id    = (int) $this->request->variable('modified_id', 0);
        $filename = $this->request->variable('filename', 'arquivo.php', true);

        if (!$v1_id || !$v2_id)
        {
            return $this->json_error('WSP_ERR_INVALID_DATA');
        }

        if ($v1_id === $v2_id)
        {
            // Front já bloqueia, mas o backend também deve bloquear
            return $this->json_error('WSP_TOOLS_DIFF_SAME_FILES');
        }

        $a1 = $this->assert_file_access($v1_id, 'view');
        $a2 = $this->assert_file_access($v2_id, 'view');

        if (!$a1['ok']) return new JsonResponse(['success' => false, 'error' => $a1['error']]);
        if (!$a2['ok']) return new JsonResponse(['success' => false, 'error' => $a2['error']]);

        // Busca conteúdos
        $v1 = (string) $this->get_file_content($v1_id);
        $v2 = (string) $this->get_file_content($v2_id);

        /**
         * FIX CRUCIAL: Normalização de finais de linha
         * Converte \r\n (Windows) ou \r (Mac antigo) para \n (Unix)
         * Sem isso, o Diff entende que TODAS as linhas mudaram.
         */
        $v1 = str_replace(["\r\n", "\r"], "\n", $v1);
        $v2 = str_replace(["\r\n", "\r"], "\n", $v2);

        $lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';
        if (!file_exists($lib_path . 'Diff.php'))
        {
            return $this->json_error('WSP_ERR_DIFF_LIB_MISSING');
        }

        require_once($lib_path . 'Diff.php');
        require_once($lib_path . 'Diff/Renderer/Abstract.php');
        require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

        // Compara arrays de linhas normalizadas
        $diff      = new \Diff(explode("\n", $v1), explode("\n", $v2));
        $renderer  = new \Diff_Renderer_Text_Unified();
        $diff_text = $diff->render($renderer);

        // Sempre retorna BBCode completo (para o botão Copiar BBCode funcionar certo)
        if (empty(trim((string) $diff_text)))
        {
            $no_changes = '--- ' . $this->user->lang('WSP_DIFF_NO_CHANGES') . ' ---';
            return new JsonResponse([
                'success'  => true,
                'bbcode'   => "[diff=$filename]\n" . $no_changes . "\n[/diff]",
                'filename' => $filename,
            ]);
        }

        return new JsonResponse([
            'success'  => true,
            'bbcode'   => "[diff=$filename]\n" . $diff_text . "\n[/diff]",
            'filename' => $filename,
        ]);
    }

    /**
     * Limpa cache do phpBB via IDE
     */
    public function refresh_cache()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return $this->json_error('WSP_ERR_PERMISSION');
        }

        global $phpbb_container;

        try {
            $phpbb_container->get('cache')->purge();
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
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

    private function log_to_changelog_internal($project_id, $message)
    {
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
            // Normaliza o log existente antes de concatenar para manter o padrão \n
            $existing_content = str_replace(["\r\n", "\r"], "\n", (string) $row['file_content']);
            $new_content      = $log_entry . $existing_content;

            $sql_ary = [
                'file_content' => $new_content,
                'file_time'    => time(),
            ];

            $update_sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
                           SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                           WHERE file_id = ' . (int) $row['file_id'];

            $this->db->sql_query($update_sql);
        }
    }
}