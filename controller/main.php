<?php
namespace mundophpbb\workspace\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class main
{
    protected $helper;
    protected $template;
    protected $db;
    protected $table_prefix;
    protected $request;
    protected $user;
    protected $auth;
    protected $phpbb_root_path;

    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        $db,
        $table_prefix,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $phpbb_root_path
    ) {
        $this->helper = $helper;
        $this->template = $template;
        $this->db = $db;
        $this->table_prefix = $table_prefix;
        $this->request = $request;
        $this->user = $user;
        $this->auth = $auth;
        $this->phpbb_root_path = $phpbb_root_path;
    }

    /**
     * Interface Principal da IDE
     */
    public function handle()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
        }

        $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
        $this->template->destroy_block_vars('projects');

        $board_url = generate_board_url() . '/';
        $wsp_url_path = $board_url . 'ext/mundophpbb/workspace/styles/all';
        $js_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/styles/all/template/workspace.js';
        $wsp_version = (file_exists($js_path)) ? filemtime($js_path) : time();

        $this->template->assign_vars([
            'T_WSP_ASSETS'            => $wsp_url_path,
            'T_WSP_ACE_PATH'          => $wsp_url_path . '/template/ace',
            'WSP_VERSION'             => $wsp_version,
            'U_WORKSPACE_MAIN'        => $this->helper->route('mundophpbb_workspace_main'),
            'U_WORKSPACE_LOAD'        => $this->helper->route('mundophpbb_workspace_load', [], false),
            'U_WORKSPACE_SAVE'        => $this->helper->route('mundophpbb_workspace_save', [], false),
            'U_WORKSPACE_ADD'         => $this->helper->route('mundophpbb_workspace_add', [], false),
            'U_WORKSPACE_ADD_FILE'    => $this->helper->route('mundophpbb_workspace_add_file', [], false),
            'U_WORKSPACE_UPLOAD'      => $this->helper->route('mundophpbb_workspace_upload', [], false),
            'U_WORKSPACE_RENAME'      => $this->helper->route('mundophpbb_workspace_rename', [], false),
            'U_WORKSPACE_DELETE_FILE' => $this->helper->route('mundophpbb_workspace_delete_file', [], false),
            'U_WORKSPACE_DELETE'      => $this->helper->route('mundophpbb_workspace_delete_project', [], false),
            'U_WORKSPACE_DIFF'        => $this->helper->route('mundophpbb_workspace_diff', [], false),
            'U_WORKSPACE_SEARCH'      => $this->helper->route('mundophpbb_workspace_search', [], false),
            'U_WORKSPACE_REPLACE'     => $this->helper->route('mundophpbb_workspace_replace', [], false),
        ]);

        $sql = 'SELECT project_id, project_name
            FROM ' . $this->table_prefix . 'workspace_projects
            WHERE user_id = ' . (int) $this->user->data['user_id'] . '
            ORDER BY project_id DESC';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $project_id = (int) $row['project_id'];
            $this->template->assign_block_vars('projects', [
                'ID'         => $project_id,
                'NAME'       => $row['project_name'],
                'U_DOWNLOAD' => $this->helper->route('mundophpbb_workspace_download', ['project_id' => $project_id]),
            ]);

            $sql_files = 'SELECT file_id, file_name, file_type
                FROM ' . $this->table_prefix . 'workspace_files
                WHERE project_id = ' . $project_id . '
                ORDER BY file_name ASC';
            $res_files = $this->db->sql_query($sql_files);
            while ($f_row = $this->db->sql_fetchrow($res_files))
            {
                $this->template->assign_block_vars('projects.files', [
                    'F_ID'   => (int) $f_row['file_id'],
                    'F_NAME' => $f_row['file_name'],
                    'F_TYPE' => strtolower($f_row['file_type']),
                ]);
            }
            $this->db->sql_freeresult($res_files);
        }
        $this->db->sql_freeresult($result);

        return $this->helper->render('workspace_main.html', $this->user->lang('WSP_TITLE'));
    }

    /**
     * Upload de Arquivos (Suporta subpastas e sobrescrita dinâmica)
     */
    public function upload_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = $this->request->variable('project_id', 0);
        $filename = $this->request->variable('full_path', '', true);
        $file = $this->request->file('file');
        
        if (empty($filename) && isset($file['name'])) $filename = $file['name'];
        if (!$project_id || !$filename || !isset($file['name']))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        $content = file_get_contents($file['tmp_name']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';

        $sql = 'SELECT file_id FROM ' . $this->table_prefix . 'workspace_files
            WHERE project_id = ' . (int) $project_id . '
            AND file_name = "' . $this->db->sql_escape($filename) . '"';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row)
        {
            $sql_update = 'UPDATE ' . $this->table_prefix . 'workspace_files
                SET file_content = "' . $this->db->sql_escape($content) . '", file_time = ' . time() . ', file_type = "' . $this->db->sql_escape($ext) . '"
                WHERE file_id = ' . (int) $row['file_id'];
            $this->db->sql_query($sql_update);
        }
        else
        {
            $file_ary = [
                'project_id'   => (int) $project_id,
                'file_name'    => $filename,
                'file_content' => $content,
                'file_type'    => $ext,
                'file_time'    => time()
            ];
            $this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'workspace_files ' . $this->db->sql_build_array('INSERT', $file_ary));
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Criação de novo arquivo com Boilerplate inteligente (Suporte LUA Adicionado)
     */
    public function add_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = $this->request->variable('project_id', 0);
        $filename = trim($this->request->variable('name', '', true));
        if (!$project_id || !$filename)
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'txt';
        $initial_content = "";
        
        switch ($ext)
        {
            case 'php': 
                $initial_content = "<?php\n\n// Arquivo: " . $filename . "\n"; 
                break;
            case 'lua':
                $initial_content = "--[[\n    Arquivo: " . $filename . "\n    Mundo phpBB Workspace\n--]]\n\nlocal function main()\n    print('Lua Script Ativo')\nend\n\nmain()\n";
                break;
            case 'py': 
                $initial_content = "# -*- coding: utf-8 -*-\n# Arquivo: " . $filename . "\n"; 
                break;
            case 'js':
            case 'ts': 
                $initial_content = "// Arquivo: " . $filename . "\n"; 
                break;
            case 'css':
            case 'sql': 
                $initial_content = "/* Arquivo: " . $filename . " */\n"; 
                break;
            default: 
                $initial_content = "// Arquivo: " . $filename . "\n"; 
                break;
        }

        $file_ary = [
            'project_id'   => (int) $project_id,
            'file_name'    => $filename,
            'file_content' => $initial_content,
            'file_type'    => $ext,
            'file_time'    => time()
        ];
        $this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'workspace_files ' . $this->db->sql_build_array('INSERT', $file_ary));

        return new JsonResponse(['success' => true, 'file_id' => (int) $this->db->sql_nextid()]);
    }

    public function load_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = $this->request->variable('file_id', 0);
        $sql = 'SELECT file_content, file_name, file_type FROM ' . $this->table_prefix . 'workspace_files WHERE file_id = ' . (int) $file_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row)
        {
            return new JsonResponse([
                'success' => true,
                'content' => (string) html_entity_decode($row['file_content'], ENT_QUOTES, 'UTF-8'),
                'name'    => (string) $row['file_name'],
                'type'    => strtolower((string) $row['file_type'])
            ]);
        }

        return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_FILE_NOT_FOUND')]);
    }

    /**
     * Salvamento com Transação SQL
     */
    public function save_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = $this->request->variable('file_id', 0);
        $content = $this->request->variable('content', '', true);

        $this->db->sql_transaction('begin');
        $sql = 'UPDATE ' . $this->table_prefix . 'workspace_files
            SET file_content = "' . $this->db->sql_escape($content) . '", file_time = ' . time() . '
            WHERE file_id = ' . (int) $file_id;
        
        if ($this->db->sql_query($sql))
        {
            $this->db->sql_transaction('commit');
            return new JsonResponse(['success' => true]);
        }
        $this->db->sql_transaction('rollback');

        return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_SAVE_FAILED')]);
    }

    public function download_project($project_id)
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            trigger_error($this->user->lang('WSP_ERR_PERMISSION', $this->user->lang('ACL_U_WORKSPACE_ACCESS')));
        }

        $sql = 'SELECT project_name FROM ' . $this->table_prefix . 'workspace_projects WHERE project_id = ' . (int) $project_id;
        $result = $this->db->sql_query($sql);
        $project = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $zip = new \ZipArchive();
        $temp_file = tempnam(sys_get_temp_dir(), 'zip');
        $zip->open($temp_file, \ZipArchive::CREATE);

        $sql = 'SELECT file_name, file_content FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' . (int) $project_id;
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $zip->addFromString($row['file_name'], $row['file_content']);
        }
        $this->db->sql_freeresult($result);
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename="' . str_replace(' ', '_', $project['project_name']) . '.zip"');
        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    public function rename_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $file_id = $this->request->variable('file_id', 0);
        $new_name = trim($this->request->variable('new_name', '', true));
        $ext = strtolower(pathinfo($new_name, PATHINFO_EXTENSION)) ?: 'txt';

        $this->db->sql_query('UPDATE ' . $this->table_prefix . 'workspace_files SET file_name = "' . $this->db->sql_escape($new_name) . '", file_type = "' . $this->db->sql_escape($ext) . '" WHERE file_id = ' . (int) $file_id);

        return new JsonResponse(['success' => true, 'new_type' => $ext]);
    }

    public function delete_file()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'workspace_files WHERE file_id = ' . (int) $this->request->variable('file_id', 0));

        return new JsonResponse(['success' => true]);
    }

    public function delete_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $id = (int) $this->request->variable('project_id', 0);
        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'workspace_files WHERE project_id = ' . $id);
        $this->db->sql_query('DELETE FROM ' . $this->table_prefix). 'workspace_projects WHERE project_id = ' . $id;

        return new JsonResponse(['success' => true]);
    }

    public function add_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $name = $this->request->variable('name', '', true);
        if (!$name)
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_NAME')]);
        }

        $sql_ary = [
            'project_name' => $name,
            'project_desc' => $this->user->lang('WSP_DEFAULT_DESC'),
            'project_time' => time(),
            'user_id'      => (int) $this->user->data['user_id']
        ];
        $this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'workspace_projects ' . $this->db->sql_build_array('INSERT', $sql_ary));

        return new JsonResponse(['success' => true]);
    }

    public function search_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = $this->request->variable('project_id', 0);
        $search_term = $this->request->variable('search', '', true);
        if (!$project_id || empty($search_term))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        $sql = 'SELECT file_id, file_name FROM ' . $this->table_prefix . 'workspace_files
            WHERE project_id = ' . (int) $project_id . '
            AND file_content LIKE "%' . $this->db->sql_escape($search_term) . '%"';
        $result = $this->db->sql_query($sql);
        $matches = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $matches[] = ['id' => $row['file_id'], 'name' => $row['file_name']];
        }
        $this->db->sql_freeresult($result);

        return new JsonResponse(['success' => true, 'matches' => $matches]);
    }

    public function replace_project()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $project_id = $this->request->variable('project_id', 0);
        $file_id = $this->request->variable('file_id', 0);
        $search_term = $this->request->variable('search', '', true);
        $replace_term = $this->request->variable('replace', '', true);
        if (!$project_id || empty($search_term))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')]);
        }

        $sql_where = "project_id = " . (int) $project_id;
        if ($file_id > 0) $sql_where .= " AND file_id = " . (int) $file_id;
        $sql = 'SELECT file_id, file_content FROM ' . $this->table_prefix . 'workspace_files WHERE ' . $sql_where . ' AND file_content LIKE "%' . $this->db->sql_escape($search_term) . '%"';
        $result = $this->db->sql_query($sql);
        $updated_count = 0;
        while ($row = $this->db->sql_fetchrow($result))
        {
            $new_content = str_replace($search_term, $replace_term, $row['file_content']);
            $this->db->sql_query('UPDATE ' . $this->table_prefix . 'workspace_files SET file_content = "' . $this->db->sql_escape($new_content) . '", file_time = ' . time() . ' WHERE file_id = ' . (int) $row['file_id']);
            $updated_count++;
        }
        $this->db->sql_freeresult($result);

        return new JsonResponse(['success' => true, 'updated' => $updated_count]);
    }

    public function generate_diff()
    {
        if (!$this->auth->acl_get('u_workspace_access'))
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')]);
        }

        $v1 = $this->get_file_content($this->request->variable('original_id', 0));
        $v2 = $this->get_file_content($this->request->variable('modified_id', 0));
        $filename = $this->request->variable('filename', 'arquivo.php', true);
        $lib_path = $this->phpbb_root_path . 'ext/mundophpbb/workspace/lib/';

        try
        {
            if (!file_exists($lib_path . 'Diff.php'))
            {
                return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_SERVER_500')]);
            }

            require_once($lib_path . 'Diff.php');
            require_once($lib_path . 'Diff/Renderer/Abstract.php');
            require_once($lib_path . 'Diff/Renderer/Text/Unified.php');

            $diffClass = class_exists('\Diff') ? '\Diff' : 'Diff';
            $diff = new $diffClass(explode("\n", $v1), explode("\n", $v2));
            $rendererClass = class_exists('\Diff_Renderer_Text_Unified') ? '\Diff_Renderer_Text_Unified' : 'Diff_Renderer_Text_Unified';
            $renderer = new $rendererClass();

            $bbcode_final = "[diff=$filename]\n" . $diff->render($renderer) . "\n[/diff]";
            return new JsonResponse(['success' => true, 'bbcode' => $bbcode_final, 'filename' => $filename]);
        }
        catch (\Exception $e)
        {
            return new JsonResponse(['success' => false, 'error' => $this->user->lang('WSP_ERR_SERVER_500') . ': ' . $e->getMessage()]);
        }
    }

    private function get_file_content($file_id)
    {
        $sql = 'SELECT file_content FROM ' . $this->table_prefix . 'workspace_files WHERE file_id = ' . (int) $file_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? $row['file_content'] : '';
    }
}