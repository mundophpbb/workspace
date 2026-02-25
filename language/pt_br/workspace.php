<?php
/**
 * mundophpbb workspace extension [Portuguese Brazilian]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */

if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = array();
}

$lang = array_merge($lang, array(

    // =====================================================
    // Interface Principal & Core
    // =====================================================
    'WSP_TITLE'                 => 'Workspace IDE',
    'WSP_EXPLORER'              => 'Explorador',
    'WSP_PROJECT_LABEL'         => 'Projeto',
    'WSP_SELECT_FILE'           => 'Selecione um arquivo para editar',
    'WSP_SELECT_TO_BEGIN'       => 'Abra ou crie um projeto para listar os arquivos.',
    'WSP_ACTIVE_FOLDER'         => 'Pasta',
    'WSP_ACTIVE_FOLDER_TITLE'   => 'Pasta atualmente selecionada',
    'WSP_ROOT'                  => 'Raiz',
    'WSP_CLOSE'                 => 'Fechar',
    'WSP_OK'                    => 'Confirmar',
    'WSP_CANCEL'                => 'Cancelar',
    'WSP_RENAME'                => 'Renomear',
    'WSP_DELETE'                => 'Excluir',

    // Editor Welcome
    'WSP_WELCOME_MSG'           => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n * \n * NENHUM ARQUIVO ABERTO.\n * \n * 1. Selecione um arquivo na aba lateral.\n * 2. Edite o código.\n * 3. Use CTRL + S para salvar rapidamente.\n */\n",
    'WSP_EDITOR_START_MSG'      => 'Selecione um arquivo na sidebar para começar...',

    // =====================================================
    // Estados, Inicialização e Notificações (JS)
    // =====================================================
    'WSP_LOADING'               => 'Carregando...',
    'WSP_LOADING_FILE'          => 'Carregando arquivo...',
    'WSP_PROCESSING'            => 'Processando...',
    'WSP_SAVING'                => 'Salvando...',
    'WSP_SAVING_MSG'            => 'Salvando alterações...',
    'WSP_SAVED'                 => 'Alterações salvas!',
    'WSP_SAVED_SHORT'           => 'Salvo!',
    'WSP_SAVE_SUCCESS'          => 'Arquivo salvo com sucesso!',
    'WSP_COPIED'                => 'Copiado!',
    'WSP_INIT_START'            => 'Iniciando módulos da IDE...',
    'WSP_READY'                 => 'IDE pronta para uso.',
    'WSP_TIMEOUT'               => 'Erro de Timeout: As dependências demoraram demais para carregar.',
    'WSP_MODULE_LOADED'         => 'Módulo [%s] carregado.',
    'WSP_MODULE_ERROR'          => 'Erro no módulo [%s]:',

    // =====================================================
    // Projetos
    // =====================================================
    'WSP_NEW_PROJECT'           => 'Novo Projeto',
    'WSP_OPEN_PROJECT'          => 'Abrir Projeto',
    'WSP_RENAME_PROJECT'        => 'Renomear Projeto',
    'WSP_RENAME_PROJECT_TITLE'  => 'Novo nome do Projeto:',
    'WSP_DEFAULT_DESC'          => 'Criado via Workspace IDE',
    'WSP_NO_PROJECTS'           => 'Nenhum projeto encontrado.',
    'WSP_PROJECT_NOT_FOUND'     => 'Projeto não encontrado.',
    'WSP_EMPTY_PROJECT'         => 'Projeto vazio',
    'WSP_EMPTY_PROJECT_DESC'    => 'Este projeto ainda não possui arquivos.',
    'WSP_DOWNLOAD_PROJECT'      => 'Baixar Projeto (ZIP)',
    'WSP_LABEL_ACTIVE_PROJECT'  => 'Projeto Ativo',
    'WSP_LABEL_CLICK_OPEN'      => 'Clique para abrir',

    // =====================================================
    // Árvore de Arquivos (Tree)
    // =====================================================
    'WSP_TREE_ROOT'             => 'Raiz',
    'WSP_TREE_NEW_FILE'         => 'Novo Arquivo',
    'WSP_TREE_NEW_FOLDER'       => 'Nova Pasta',
    'WSP_TREE_RENAME'           => 'Renomear',
    'WSP_TREE_DELETE'           => 'Excluir',
    'WSP_TREE_MOVE'             => 'Mover',
    'WSP_ADD_FILE'              => 'Novo arquivo',
    'WSP_NEW_ROOT_FILE'         => 'Novo arquivo na raiz',
    'WSP_NEW_ROOT_FOLDER_TITLE' => 'Nova Pasta',
    'WSP_PROMPT_NEW_FILE'       => 'Novo arquivo em %s',
    'WSP_PROMPT_NEW_FOLDER'     => 'Nova subpasta em %s',
    'WSP_PROMPT_ROOT_FILE'      => 'Novo arquivo na Raiz:',
    'WSP_PROMPT_ROOT_FOLDER'    => 'Nova pasta na Raiz:',
    'WSP_DRAG_UPLOAD_HINT'      => 'Arraste pastas aqui ou use o botão de upload.',
    'WSP_LABEL_MOVE_ROOT'       => 'Mover para Raiz ( / )',

    // =====================================================
    // Ferramentas (Search, Replace, Diff, Cache)
    // =====================================================
    'WSP_SEARCH_REPLACE'        => 'Buscar & Substituir',
    'WSP_SEARCH_TERM'           => 'Termo de busca',
    'WSP_REPLACE_TERM'          => 'Substituir por',
    'WSP_REPLACE_ALL'           => 'Substituir em tudo',
    'WSP_REPLACE_SUCCESS'       => 'Sucesso! %d alteração(ões) realizadas.',
    'WSP_TOOLS_SEARCH_SUCCESS'  => 'Substituição finalizada: %d arquivos modificados.',
    'WSP_TOOLS_SEARCH_NEED_PROJECT'    => 'Abra um projeto para usar a busca.',
    'WSP_TOOLS_SEARCH_TERM_REQUIRED'   => 'Digite o termo que deseja procurar.',
    'WSP_TOOLS_SEARCH_INTERFACE_ERROR' => 'Interface de busca não carregada.',
    'WSP_TOOLS_SEARCH_CONFIRM'         => 'Deseja realmente substituir todas as ocorrências neste projeto?',

    'WSP_DIFF_TITLE'            => 'Comparação de arquivos',
    'WSP_DIFF_GENERATE'         => 'Gerar comparação',
    'WSP_DIFF_SELECT_ORIG'      => 'Arquivo original',
    'WSP_DIFF_SELECT_MOD'       => 'Arquivo modificado',
    'WSP_LABEL_DIFF'            => 'Diff: %s',
    'WSP_TOOLS_DIFF_MIN_FILES'  => 'Você precisa de pelo menos 2 arquivos para comparar.',
    'WSP_TOOLS_DIFF_SAME_FILES' => 'Escolha arquivos diferentes para comparar.',
    'WSP_TOOLS_COMPARING'       => 'Comparando...',

    // =====================================================
    // Changelog (Toolbar + Headers no arquivo)
    // =====================================================
    'WSP_GENERATE_CHANGELOG'    => 'Consolidar Versão',
    'WSP_GENERATE_CHANGELOG_AT' => 'Consolidar Versão - %s',
    'WSP_CLEAR_CHANGELOG'       => 'Limpar Histórico',
    'WSP_NOTIFY_CHANGELOG_OK'   => 'Changelog consolidado!',
    'WSP_HISTORY_CLEANED'       => 'Histórico do projeto limpo.',
    'WSP_HISTORY_CLEANED_AT'    => 'Histórico do projeto limpo em %s',

    // Cache / UI
    'WSP_REFRESH_CACHE'         => 'Limpar cache do phpBB',
    'WSP_CACHE_CLEANED'         => 'Cache do phpBB limpo com sucesso.',
    'WSP_TOGGLE_FULLSCREEN'     => 'Tela cheia',

    // =====================================================
    // Upload e Drag & Drop
    // =====================================================
    'WSP_UPLOAD_FILES'          => 'Enviar Arquivos',
    'WSP_UPLOADING'             => 'Enviando arquivos...',
    'WSP_UPLOAD_PROCESSING'     => 'Processando upload...',
    'WSP_UPLOAD_LIST_UPDATED'   => 'Árvore de arquivos atualizada com sucesso.',
    'WSP_UPLOAD_FAILED'         => 'Falha no upload de: %s',
    'WSP_UPLOAD_NEED_PROJECT'   => 'Selecione um projeto primeiro.',
    'WSP_UPLOAD_SENDING_COUNT'  => 'Enviando %d arquivo(s)...',
    'WSP_UPLOAD_DROP_PROJECT'   => 'Erro: Você precisa abrir um projeto antes de soltar arquivos.',

    // =====================================================
    // Prompts e Confirmações
    // =====================================================
    'WSP_PROMPT_NAME'           => 'Digite o nome:',
    'WSP_PROMPT_PROJECT_NAME'   => 'Nome do novo projeto:',
    'WSP_PROMPT_FILE_NAME'      => 'Nome do arquivo (ex: includes/funcoes.php):',
    'WSP_PROMPT_RENAME_FILE'    => 'Novo nome para o arquivo:',
    'WSP_PROMPT_RENAME_FOLDER'  => 'Novo nome da pasta:',

    'WSP_UI_ACTION_WARNING'     => 'Atenção: Esta ação não poderá ser desfeita.',
    'WSP_CONFIRM_DELETE'        => 'Tem certeza que deseja excluir este projeto permanentemente?',
    'WSP_CONFIRM_DELETE_PROJ'   => 'Deseja APAGAR este projeto e todos os seus arquivos permanentemente?',
    'WSP_CONFIRM_FILE_DELETE'   => 'Deseja realmente apagar este arquivo?',
    'WSP_CONFIRM_DELETE_FILE'   => 'Excluir este arquivo permanentemente?',
    'WSP_CONFIRM_DELETE_FOLDER' => "Excluir a pasta '%s' e todos os arquivos?",
    'WSP_CONFIRM_CLEAR_CHANGE'  => 'Deseja limpar todo o histórico do changelog?',
    'WSP_CONFIRM_REPLACE_ALL'   => 'Deseja substituir em todo o projeto?',

    // =====================================================
    // Mensagens de Log (Changelog.txt)
    // =====================================================
    'WSP_LOG_PROJECT_CREATED'   => 'PROJETO CRIADO EM %s',
    'WSP_LOG_UPLOAD_UPDATE'     => 'Upload (Atualização): %s',
    'WSP_LOG_UPLOAD_NEW'        => 'Novo arquivo (Upload): %s',
    'WSP_LOG_FILE_CREATED'      => 'Novo arquivo: %s',
    'WSP_LOG_FILE_CHANGED'      => 'Alterado: %s',
    'WSP_LOG_DIFF_LABEL'        => 'Alterações (Diff)',
    'WSP_LOG_REPLACE_ACTION'    => "Substituição: '%1\$s' por '%2\$s' em %3\$s",
    'WSP_LOG_FOLDER_MOVE'       => 'Pasta movida/renomeada: %1\$s -> %2\$s',
    'WSP_LOG_FILE_MOVE_ACTION'  => 'Arquivo movido: %1\$s -> %2\$s',
    'WSP_LOG_DELETE_ACTION'     => 'Excluído: %s',
    'WSP_LOG_RENAME_ACTION'     => 'Renomeado: %1\$s -> %2\$s',
    'WSP_LOG_CONTENT_MODIFIED_FALLBACK' => '(O conteúdo deste arquivo foi modificado)',

    // =====================================================
    // Erros de Sistema / Backend (PHP + JS)
    // =====================================================
    'WSP_ERR_PERMISSION'        => 'Você não tem permissão para acessar o Workspace.',
    'WSP_ERR_INVALID_ID'        => 'ID inválido.',
    'WSP_ERR_INVALID_DATA'      => 'Dados inválidos enviados.',
    'WSP_ERR_INVALID_NAME'      => 'O nome não pode ficar vazio.',
    'WSP_ERR_PROJECT_NOT_FOUND' => 'Projeto não encontrado.',
    'WSP_ERR_FILE_NOT_FOUND'    => 'Arquivo não encontrado.',
    'WSP_ERR_FILE_EXISTS'       => 'Já existe um arquivo com este nome neste local.',
    'WSP_ERR_INVALID_EXT'       => 'Extensão de arquivo não permitida.',
    'WSP_ERR_DELETE_FAILED'     => 'Falha ao tentar excluir os dados do banco.',
    'WSP_ERROR_CRITICAL'        => 'Falha crítica ao carregar arquivo. Verifique sua conexão.',
    'WSP_CRITICAL_ACE'          => 'Falha crítica: O editor ACE não pôde ser inicializado.',

    // Erros extras do backend (para i18n total)
    'WSP_ERR_NO_CONTENT'        => 'Nenhum conteúdo recebido.',
    'WSP_ERR_CONTENT_PROCESS'   => 'Erro ao processar o conteúdo.',
    'WSP_ERR_DIFF_LIB_MISSING'  => 'Biblioteca de Diff ausente no servidor.',
    'WSP_ERR_CACHE_PURGE_FAILED'=> 'Não foi possível limpar o cache do phpBB.',
    'WSP_ERR_ZIP_NOT_AVAILABLE' => 'O servidor não possui suporte a ZIP (ZipArchive).',
    'WSP_ERR_ZIP_CREATE_FAILED' => 'Não foi possível gerar o arquivo ZIP.',

    // =====================================================
    // Modais e UI (JS)
    // =====================================================
    'WSP_MODAL_TITLE_SELECT'    => 'Selecionar Projeto',
    'WSP_MODAL_TITLE_MOVE'      => 'Mover para...',
    'WSP_UI_CANCEL'             => 'Cancelar',
    'WSP_UI_CONFIRM'            => 'Confirmar',
    'WSP_UI_ROOT_FOCUS'         => 'Foco retornado para a raiz do projeto.',
    'WSP_UI_SELECT_FILE'        => 'Selecione um arquivo',
    'WSP_UI_SPLITTER_READY'     => 'Divisor de tela carregado.',

    // =====================================================
    // Placeholders e Dicas
    // =====================================================
    'WSP_TYPE_HERE'             => 'Digite aqui...',
    'WSP_SEARCH_PLACEHOLDER'    => 'Ex: function_name ou texto',
    'WSP_REPLACE_PLACEHOLDER'   => 'Novo texto para substituir...',
    'WSP_SEARCH_RESULTS_HINT'   => 'Os resultados aparecerão aqui após a busca...',

    // =====================================================
    // Gerador de Skeleton
    // =====================================================
    'WSP_GENERATE_SKELETON'     => 'Gerador de Estrutura (Skeleton)',
    'WSP_SKEL_VENDOR'           => 'Fornecedor (Vendor)',
    'WSP_SKEL_NAME'             => 'Nome da Extensão',
    'WSP_SKEL_VENDOR_PLACEHOLDER' => 'ex: mundophpbb',
    'WSP_SKEL_NAME_PLACEHOLDER'   => 'ex: topictranslate',
    'WSP_RUN_GENERATOR'         => 'Gerar Estrutura Agora',

    // =====================================================
    // Atalhos
    // =====================================================
    'WSP_SHORTCUTS'             => 'Atalhos de Teclado',
    'WSP_FILTER_EXPLORER'       => 'Filtrar Explorador',
    'WSP_TOGGLE_CONSOLE'        => 'Alternar Console',
    'WSP_ZEN_MODE'              => 'Modo Zen (Tela Cheia)',
    'WSP_SHOW_SHORTCUTS'        => 'Exibir este guia de atalhos',

    // =====================================================
    // Temas
    // =====================================================
    'WSP_CHANGE_THEME'          => 'Alterar Tema do Editor',

    // =====================================================
    // Botões de Ação
    // =====================================================
    'WSP_SAVE'                  => 'Salvar',
    'WSP_SAVE_CHANGES'          => 'Salvar alterações',
    'WSP_SAVE_BTN'              => 'Salvar', // alias para compatibilidade (wsp_editor.js)
    'WSP_COPY_BBCODE'           => 'Copiar BBCode',
    'WSP_BBCODE_COPIED'         => 'BBCode copiado para a área de transferência!',

    // =====================================================
    // Extras (JS) - chaves usadas em módulos auxiliares
    // =====================================================
    'WSP_EDITOR_LOADING'        => 'O editor ainda está carregando. Aguarde...',
    'WSP_ERROR_OPEN_FILE'       => 'Não foi possível abrir o arquivo.',
    'WSP_ERROR_SAVE'            => 'Não foi possível salvar o arquivo.',
    'WSP_ERR_SAVE'              => 'Falha ao salvar o arquivo.',
    'WSP_UNSAVED_CHANGES'       => 'Existem alterações não salvas. Deseja continuar mesmo assim?',
    'WSP_ERROR_PROJECT_CREATE'  => 'Falha ao criar o projeto.',
    'WSP_ERR_CRITICAL_ACE'      => 'Falha crítica: O editor ACE não pôde ser inicializado.',
    'WSP_LOG_BACKUP_UPDATED'    => 'Backup local atualizado (arquivo %s).',
    'WSP_LOG_FILE_OPEN'         => 'Arquivo aberto: %s',

    // Backend Tools
    'WSP_DIFF_NO_CHANGES'       => 'Sem alterações',
    
    

));