<?php
namespace mundophpbb\workspace\controller;

/**
 * Mundo phpBB Workspace - Base Controller
 * Versão 4.0: Suporte Multilingue & Helper de Resposta JSON
 * Fornece dependências principais e utilitários de segurança para a IDE.
 */
abstract class base_controller
{
    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var string */
    protected $table_prefix;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var string */
    protected $phpbb_root_path;

    /**
     * Segurança: Whitelist expandida para suporte universal a múltiplas linguagens.
     * Impede a execução de binários ou arquivos perigosos fora do escopo da IDE.
     */
    protected $allowed_extensions = [
        // Web & Front-end
        'php', 'js', 'ts', 'css', 'scss', 'sass', 'less', 'html', 'htm', 'twig', 'svg',
        // Dados & Documentação
        'json', 'xml', 'yml', 'yaml', 'sql', 'md', 'csv', 'txt', 'log',
        // Programação Geral
        'c', 'cpp', 'h', 'hpp', 'cs', 'java', 'py', 'rb', 'pl', 'pm', 'lua', 'go', 'rs',
        'kt', 'swift', 'dart', 'r', 'scala',
        // Scripts & DevOps
        'sh', 'bash', 'bat', 'ps1', 'dockerfile', 'makefile',
        // Configurações
        'ini', 'htaccess', 'conf'
    ];

    /**
     * Construtor Base
     */
    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\db\driver\driver_interface $db,
        $table_prefix,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $phpbb_root_path
    ) {
        $this->helper          = $helper;
        $this->template        = $template;
        $this->db              = $db;
        $this->table_prefix    = (string) $table_prefix;
        $this->request         = $request;
        $this->user            = $user;
        $this->auth            = $auth;
        $this->phpbb_root_path = (string) $phpbb_root_path;

        /**
         * MULTILÍNGUE (CRÍTICO):
         * Garante que o arquivo de idioma da extensão esteja carregado
         * em TODAS as rotas que estendem este controller (incluindo AJAX/JSON).
         * Sem isso, $this->user->lang('WSP_...') pode retornar a própria chave.
         */
        if (method_exists($this->user, 'add_lang_ext'))
        {
            $this->user->add_lang_ext('mundophpbb/workspace', 'workspace');
        }
    }

    /**
     * Retorna uma resposta JSON de erro traduzida
     * @param string $lang_key Chave de tradução
     * @param array $params Parâmetros opcionais (placeholders) para sprintf do phpBB
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    protected function json_error($lang_key, array $params = [])
    {
        // Suporte a placeholders: $this->user->lang('KEY', $a, $b...) / ou array (dependendo da versão)
        $msg = empty($params)
            ? $this->user->lang($lang_key)
            : call_user_func_array([$this->user, 'lang'], array_merge([$lang_key], $params));

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'success' => false,
            'error'   => $msg,
        ]);
    }

    /**
     * Validador de extensão via whitelist.
     * @param string $filename Nome ou caminho do arquivo
     * @return bool
     */
    protected function is_extension_allowed($filename)
    {
        $filename = (string) $filename;

        // Permite arquivos técnicos essenciais e placeholders de pasta (mesmo sem extensão)
        $base = basename(str_replace('\\', '/', $filename));
        if ($base === '.placeholder' || $base === '.htaccess' || $base === 'changelog.txt' || $base === 'Dockerfile' || $base === 'Makefile')
        {
            return true;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowed_extensions, true);
    }

    /**
     * Normaliza e valida caminhos relativos para evitar Directory Traversal (../).
     * @param string $path
     * @return string Retorna string vazia se o caminho for malicioso ou inválido.
     */
    protected function sanitize_rel_path($path)
    {
        $path = (string) $path;
        $path = str_replace("\0", '', $path); // Remove null bytes
        $path = trim($path);

        if ($path === '')
        {
            return '';
        }

        // Normaliza barras para o padrão Unix
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        // Bloqueia tentativas de subir níveis de diretório ou caminhos absolutos
        if ($path === '..' || strpos($path, '../') !== false || strpos($path, './') === 0 || strpos($path, '..') !== false)
        {
            return '';
        }

        // Remove a barra inicial para manter o path relativo à raiz do projeto
        return ltrim($path, '/');
    }

    /**
     * Identifica se o arquivo é um marcador de pasta (folder marker).
     */
    protected function is_placeholder_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        return (basename($path) === '.placeholder' || substr($path, -strlen('/.placeholder')) === '/.placeholder');
    }

    /**
     * Gate central de segurança para acesso ao PROJETO.
     * Valida se o projeto existe e pertence ao usuário logado ou se é administrador.
     * @param int $project_id
     * @param string $capability view|edit|create|rename|delete|manage
     * @return array ['ok'=>bool, 'error'=>string]
     */
    protected function assert_project_access($project_id, $capability = 'view')
    {
        $project_id = (int) $project_id;
        if (!$project_id)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA')];
        }

        $sql = 'SELECT user_id
                FROM ' . $this->table_prefix . 'workspace_projects
                WHERE project_id = ' . $project_id;

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PROJECT_NOT_FOUND')];
        }

        // Permite acesso se for o dono OU se tiver permissão administrativa global
        if ((int) $row['user_id'] === (int) $this->user->data['user_id'] || $this->auth->acl_get('u_workspace_manage_all'))
        {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_PERMISSION')];
    }

    /**
     * Gate central de segurança para acesso ao ARQUIVO.
     * @param int $file_id
     * @param string $capability view|edit|create|rename|delete|manage
     * @return array ['ok'=>bool, 'error'=>string, 'project_id'=>int|null]
     */
    protected function assert_file_access($file_id, $capability = 'view')
    {
        $file_id = (int) $file_id;
        if (!$file_id)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_INVALID_DATA'), 'project_id' => null];
        }

        // Recupera o ID do projeto vinculado ao arquivo
        $sql = 'SELECT f.project_id
                FROM ' . $this->table_prefix . 'workspace_files f
                WHERE f.file_id = ' . $file_id;

        $result = $this->db->sql_query($sql);
        $row    = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return ['ok' => false, 'error' => $this->user->lang('WSP_ERR_FILE_NOT_FOUND'), 'project_id' => null];
        }

        $project_id = (int) $row['project_id'];

        // Valida se o usuário tem acesso ao projeto desse arquivo
        $proj_check = $this->assert_project_access($project_id, $capability);
        if (!$proj_check['ok'])
        {
            return ['ok' => false, 'error' => $proj_check['error'], 'project_id' => $project_id];
        }

        return ['ok' => true, 'error' => '', 'project_id' => $project_id];
    }
}