<?php
/**
 * mundophpbb workspace extension [Portuguese Brazilian]
 *
 * @package   mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
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
    'WSP_WELCOME_MSG'           => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n *\n * NENHUM ARQUIVO ABERTO.\n *\n * 1. Selecione um arquivo na aba lateral.\n * 2. Edite o código.\n * 3. Use CTRL + S para salvar rapidamente.\n */\n",
    'WSP_EDITOR_START_MSG'      => 'Selecione um arquivo na sidebar para começar...',

    // =====================================================
    // Estados, Inicialização e Notificações
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
    // Revisao colaborativa por arquivo
    'WSP_REVIEW_PANEL' => 'Revisão do arquivo',
    'WSP_SELECT_FILE_REVIEW' => 'Selecione um arquivo para revisar.',
    'WSP_ADD_COMMENT' => 'Adicionar comentário',
    'WSP_COMMENT_LINE_PLACEHOLDER' => 'Linha (opcional)',
    'WSP_COMMENT_PLACEHOLDER' => 'Escreva um comentário de revisão...',
    'WSP_NO_COMMENTS' => 'Nenhum comentário neste arquivo.',
    'WSP_LOADING_COMMENTS' => 'Carregando comentários...',
    'WSP_ERROR_COMMENTS' => 'Não foi possível carregar os comentários.',
    'WSP_COMMENT_LINE' => 'Linha',
    'WSP_COMMENT_GENERAL' => 'Comentário geral',
    'WSP_COMMENT_RESOLVED' => 'Resolvido',
    'WSP_RESOLVE_COMMENT' => 'Resolver',
    'WSP_REOPEN_COMMENT' => 'Reabrir',
    'WSP_DELETE_COMMENT' => 'Excluir',
    'WSP_CONFIRM_DELETE_COMMENT' => 'Excluir este comentário?',
    'WSP_COMMENT_ADDED' => 'Comentário adicionado.',
    'WSP_ERR_COMMENT_NOT_FOUND' => 'Comentário não encontrado.',
    'WSP_ACTIVITY_COMMENT_ADDED' => 'comentou no arquivo',
    'WSP_ACTIVITY_COMMENT_RESOLVED' => 'resolveu comentário',
    'WSP_ACTIVITY_COMMENT_REOPENED' => 'reabriu comentário',
    'WSP_REVIEW_STATUS_NONE' => 'Sem revisão solicitada',
    'WSP_REVIEW_STATUS_PENDING' => 'Aguardando revisão',
    'WSP_REVIEW_STATUS_APPROVED' => 'Arquivo aprovado',
    'WSP_REVIEW_STATUS_CHANGES' => 'Ajustes solicitados',
    'WSP_REQUEST_REVIEW' => 'Solicitar revisão',
    'WSP_APPROVE_FILE' => 'Aprovar',
    'WSP_REQUEST_CHANGES' => 'Pedir ajustes',
    'WSP_LOADING_REVIEW_STATUS' => 'Carregando status de revisão...',
    'WSP_REVIEW_STATUS_UPDATED' => 'Status de revisão atualizado.',
    'WSP_ERROR_REVIEW_STATUS' => 'Não foi possível atualizar o status de revisão.',
    'WSP_REVIEW_NOTE_PROMPT' => 'Observação para a revisão:',
    'WSP_ACTIVITY_REVIEW_REQUESTED' => 'solicitou revisão',
    'WSP_ACTIVITY_REVIEW_APPROVED' => 'aprovou arquivo',
    'WSP_ACTIVITY_REVIEW_CHANGES_REQUESTED' => 'solicitou ajustes',
    'WSP_ACTIVITY_COMMENT_DELETED' => 'excluiu comentário',

    // Ferramentas (Search, Replace, Diff, Cache)
    // =====================================================
    'WSP_SEARCH_REPLACE'        => 'Buscar & Substituir',
    'WSP_SEARCH_TERM'           => 'Termo de busca',
    'WSP_REPLACE_TERM'          => 'Substituir por',
    'WSP_REPLACE_ALL'           => 'Substituir em tudo',
    'WSP_REPLACE_SUCCESS'       => 'Sucesso! %d alteração(ões) realizada(s).',
    'WSP_TOOLS_SEARCH_SUCCESS'  => 'Substituição finalizada: %d arquivos modificados.',
    'WSP_TOOLS_SEARCH_NEED_PROJECT' => 'Abra um projeto para usar a busca.',
    'WSP_TOOLS_SEARCH_TERM_REQUIRED' => 'Digite o termo que deseja procurar.',
    'WSP_TOOLS_SEARCH_INTERFACE_ERROR' => 'Interface de busca não carregada.',
    'WSP_TOOLS_SEARCH_CONFIRM'  => 'Deseja realmente substituir todas as ocorrências neste projeto?',
    'WSP_DIFF_TITLE'            => 'Comparação de arquivos',
    'WSP_DIFF_GENERATE'         => 'Gerar comparação',
    'WSP_DIFF_SELECT_ORIG'      => 'Arquivo original',
    'WSP_DIFF_SELECT_MOD'       => 'Arquivo modificado',
    'WSP_LABEL_DIFF'            => 'Diff: %s',
    'WSP_TOOLS_DIFF_MIN_FILES'  => 'Você precisa de pelo menos 2 arquivos para comparar.',
    'WSP_TOOLS_DIFF_SAME_FILES' => 'Escolha arquivos diferentes para comparar.',
    'WSP_TOOLS_COMPARING'       => 'Comparando...',
    
    // Cache / UI
    'WSP_REFRESH_CACHE'     => 'Limpar cache do phpBB',
    'WSP_CACHE_CLEANED'     => 'Cache do phpBB limpo com sucesso.',
    'WSP_TOGGLE_FULLSCREEN' => 'Tela cheia',

    // =====================================================
    // Changelog
    // =====================================================
    'WSP_GENERATE_CHANGELOG'    => 'Consolidar Versão',
    'WSP_GENERATE_CHANGELOG_AT' => 'Consolidar Versão - %s',
    'WSP_CLEAR_CHANGELOG'       => 'Limpar Histórico',
    'WSP_NOTIFY_CHANGELOG_OK'   => 'Changelog consolidado!',
    'WSP_HISTORY_CLEANED'       => 'Histórico do projeto limpo.',
    'WSP_HISTORY_CLEANED_AT'    => 'Histórico do projeto limpo em %s',

    // =====================================================
    // Lock / Unlock (Projeto)
    // =====================================================
    'WSP_PROJECT_LOCK'          => 'Trancar projeto',
    'WSP_PROJECT_UNLOCK'        => 'Destrancar projeto',
    'WSP_PROJECT_LOCKED_MSG'    => "Projeto trancado.\nSomente um administrador do Workspace pode destrancar.\n",
    'WSP_ERR_PROJECT_LOCKED'    => 'Este projeto está trancado no momento.',
    'WSP_LOG_PROJECT_LOCKED'    => 'Projeto trancado',
    'WSP_LOG_PROJECT_UNLOCKED'  => 'Projeto destrancado',

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
    'WSP_CONFIRM_DELETE_FOLDER' => "Excluir a pasta '%s' e todos os arquivos e subpastas?",
    'WSP_CONFIRM_CLEAR_CHANGE'  => 'Deseja limpar todo o histórico do changelog?',
    'WSP_CONFIRM_REPLACE_ALL'   => 'Deseja substituir em todo o projeto?',

    // =====================================================
    // Log do Changelog
    // =====================================================
    'WSP_LOG_PROJECT_RENAMED' => 'Projeto renomeado: %s',
    'WSP_LOG_PROJECT_CREATED'   => 'PROJETO CRIADO EM %s',
    'WSP_LOG_UPLOAD_UPDATE'     => 'Arquivo atualizado (100%%) - %s',
    'WSP_LOG_UPLOAD_NEW'        => 'Novo arquivo (Upload): %s',
    'WSP_LOG_FILE_CREATED'      => 'Novo arquivo: %s',
    'WSP_LOG_FILE_CHANGED'      => 'Alterado: %s',
    'WSP_LOG_DIFF_LABEL'        => 'Alterações (Diff)',

    // ✅ CORRIGIDOS: remover "\$" (invalida o sprintf no PHP 8+)
    'WSP_LOG_REPLACE_ACTION'    => "Substituição: '%1\$s' por '%2\$s' em %3\$s", // <- (mantém compat se você quiser; ver nota abaixo)
    'WSP_LOG_FOLDER_MOVE'       => 'Pasta movida/renomeada: %1$s → %2$s',
    'WSP_LOG_FILE_MOVE_ACTION'  => 'Arquivo movido: %1$s → %2$s',
    'WSP_LOG_DELETE_ACTION'     => 'Excluído: %s',
    'WSP_LOG_RENAME_ACTION'     => 'Renomeado: %1$s → %2$s',

    'WSP_LOG_CONTENT_MODIFIED_FALLBACK' => '(O conteúdo deste arquivo foi modificado)',

    // =====================================================
    // Erros
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

    // Erros extras do backend
    'WSP_ERR_NO_CONTENT'        => 'Nenhum conteúdo recebido.',
    'WSP_ERR_CONTENT_PROCESS'   => 'Erro ao processar o conteúdo.',
    'WSP_ERR_DIFF_LIB_MISSING'  => 'Biblioteca de Diff ausente no servidor.',
    'WSP_ERR_CACHE_PURGE_FAILED'=> 'Não foi possível limpar o cache do phpBB.',
    'WSP_ERR_ZIP_NOT_AVAILABLE' => 'O servidor não possui suporte a ZIP (ZipArchive).',
    'WSP_ERR_ZIP_CREATE_FAILED' => 'Não foi possível gerar o arquivo ZIP.',

    // =====================================================
    // Modais, UI e Extras
    // =====================================================
    'WSP_MODAL_TITLE_SELECT'    => 'Selecionar Projeto',
    'WSP_MODAL_TITLE_MOVE'      => 'Mover para...',
    'WSP_UI_CANCEL'             => 'Cancelar',
    'WSP_UI_CONFIRM'            => 'Confirmar',
    'WSP_UI_ROOT_FOCUS'         => 'Foco retornado para a raiz do projeto.',
    'WSP_UI_SELECT_FILE'        => 'Selecione um arquivo',
    'WSP_UI_SPLITTER_READY'     => 'Divisor de tela carregado.',

    'WSP_TYPE_HERE'             => 'Digite aqui...',
    'WSP_SEARCH_PLACEHOLDER'    => 'Ex: function_name ou texto',
    'WSP_REPLACE_PLACEHOLDER'   => 'Novo texto para substituir...',
    'WSP_SEARCH_RESULTS_HINT'   => 'Os resultados aparecerão aqui após a busca...',

    // Skeleton
    'WSP_GENERATE_SKELETON'     => 'Gerador de Estrutura (Skeleton)',
    'WSP_SKEL_VENDOR'           => 'Fornecedor (Vendor)',
    'WSP_SKEL_NAME'             => 'Nome da Extensão',
    'WSP_SKEL_VENDOR_PLACEHOLDER' => 'ex: mundophpbb',
    'WSP_SKEL_NAME_PLACEHOLDER'   => 'ex: topictranslate',
    'WSP_RUN_GENERATOR'         => 'Gerar Estrutura Agora',

    // Atalhos
    'WSP_SHORTCUTS'             => 'Atalhos de Teclado',
    'WSP_FILTER_EXPLORER'       => 'Filtrar Explorador',
    'WSP_TOGGLE_CONSOLE'        => 'Alternar Console',
    'WSP_ZEN_MODE'              => 'Modo Zen (Tela Cheia)',
    'WSP_SHOW_SHORTCUTS'        => 'Exibir este guia de atalhos',

    // Temas
    'WSP_CHANGE_THEME'          => 'Alterar Tema do Editor',

    // Botões
    'WSP_SAVE'                  => 'Salvar',
    'WSP_SAVE_CHANGES'          => 'Salvar alterações',
    'WSP_SAVE_BTN'              => 'Salvar',
    'WSP_COPY_BBCODE'           => 'Copiar BBCode',
    'WSP_BBCODE_COPIED'         => 'BBCode copiado para a área de transferência!',

    // Extras
    'WSP_EDITOR_LOADING'        => 'O editor ainda está carregando. Aguarde...',
    'WSP_ERROR_OPEN_FILE'       => 'Não foi possível abrir o arquivo.',
    'WSP_ERROR_SAVE'            => 'Não foi possível salvar o arquivo.',
    'WSP_ERR_SAVE'              => 'Falha ao salvar o arquivo.',
    'WSP_UNSAVED_CHANGES'       => 'Existem alterações não salvas. Deseja continuar mesmo assim?',
    'WSP_ERROR_PROJECT_CREATE'  => 'Falha ao criar o projeto.',
    'WSP_ERR_CRITICAL_ACE'      => 'Falha crítica: O editor ACE não pôde ser inicializado.',
    'WSP_LOG_BACKUP_UPDATED'    => 'Backup local atualizado (arquivo %s).',
    'WSP_LOG_FILE_OPEN'         => 'Arquivo aberto: %s',

    // Colaboração
    'WSP_COLLAB_WORKSPACE'    => 'Área colaborativa',
    'WSP_NO_ACTIVE_PROJECT'   => 'Nenhum projeto ativo',
    'WSP_ROLE_OWNER'          => 'Responsável',
    'WSP_ROLE_COLLAB'         => 'Colaborador',
    'WSP_ROLE_VIEWER'         => 'Leitor',
    'WSP_TEAM_SIZE'           => 'Tamanho da equipe',
    'WSP_MEMBERS'             => 'membro(s)',
    'WSP_STATUS_OPEN'         => 'Aberto para colaboração',
    'WSP_STATUS_LOCKED'       => 'Projeto trancado',

    // Colaboração avançada

    'WSP_TEAM_PANEL' => 'Equipe do projeto',
    'WSP_TEAM_PANEL_DESC' => 'Gerencie quem participa e o papel de cada pessoa.',
    'WSP_ADD_MEMBER' => 'Adicionar membro',
    'WSP_MEMBER_USERNAME' => 'Nome de usuario',
    'WSP_INVITE' => 'Convidar',
    'WSP_REMOVE_MEMBER' => 'Remover membro',
    'WSP_NO_MEMBERS' => 'Nenhum membro listado.',
    'WSP_ACTIVITY_TITLE' => 'Atividade recente',
    'WSP_ACTIVITY_DESC' => 'Linha do tempo das ações colaborativas.',
    'WSP_NO_ACTIVITY' => 'Nenhuma atividade recente.',
    'WSP_SYSTEM_USER' => 'Sistema',
    'WSP_ERR_USER_NOT_FOUND' => 'Usuário não encontrado.',
    'WSP_LOG_MEMBER_ADDED' => 'Membro adicionado: %1$s (%2$s)',
    'WSP_MEMBER_UPDATED' => 'Membro atualizado.',
    'WSP_CONFIRM_REMOVE_MEMBER' => 'Remover este membro do projeto?',
    'WSP_ACTIVITY_PROJECT_CREATED' => 'criou o projeto',
    'WSP_ACTIVITY_PROJECT_RENAMED' => 'renomeou o projeto',
    'WSP_ACTIVITY_PROJECT_LOCKED' => 'trancou o projeto',
    'WSP_ACTIVITY_PROJECT_UNLOCKED' => 'destrancou o projeto',
    'WSP_ACTIVITY_MEMBER_ADDED' => 'adicionou membro',
    'WSP_ACTIVITY_MEMBER_ROLE_CHANGED' => 'alterou o papel de membro',
    'WSP_ACTIVITY_MEMBER_REMOVED' => 'removeu membro',
    'WSP_ACTIVITY_FILE_UPLOADED_UPDATE' => 'atualizou por upload',
    'WSP_ACTIVITY_FILE_UPLOADED_NEW' => 'enviou novo arquivo',
    'WSP_ACTIVITY_FILE_CREATED' => 'criou arquivo',
    'WSP_ACTIVITY_FILE_SAVED' => 'salvou arquivo',
    'WSP_ACTIVITY_FILE_RENAMED' => 'renomeou arquivo',
    'WSP_ACTIVITY_FILE_MOVED' => 'moveu arquivo',
    'WSP_ACTIVITY_FILE_DELETED' => 'excluiu arquivo',

    // Revisao colaborativa por arquivo

    // Ferramentas
    'WSP_DIFF_NO_CHANGES'       => 'Sem alterações',
));


$lang = array_merge($lang, [
    'WSP_VERSION_HISTORY' => 'Histórico de versões',
    'WSP_REFRESH_VERSIONS' => 'Atualizar versões',
    'WSP_LOADING_VERSIONS' => 'Carregando versões...',
    'WSP_NO_VERSIONS' => 'Ainda não há snapshots para este arquivo. O histórico será criado automaticamente antes de cada salvamento com alteração.',
    'WSP_ERROR_VERSIONS' => 'Não foi possível carregar o histórico de versões.',
    'WSP_VIEW_VERSION' => 'Ver',
    'WSP_RESTORE_VERSION' => 'Restaurar',
    'WSP_CONFIRM_RESTORE_VERSION' => 'Restaurar esta versão? O conteúdo atual será salvo como snapshot antes da restauração.',
    'WSP_VERSION_VIEWING' => 'Versão anterior carregada em modo de visualização. Para voltar ao conteúdo atual, abra o arquivo novamente.',
    'WSP_VERSION_RESTORED' => 'Versão restaurada com sucesso.',
    'WSP_ERR_VERSION_NOT_FOUND' => 'Versão não encontrada.',
    'WSP_VERSION_BEFORE_SAVE' => 'Snapshot automático antes do salvamento.',
    'WSP_VERSION_BEFORE_RESTORE' => 'Snapshot automático antes da restauração.',
    'WSP_LOG_FILE_RESTORED' => 'Arquivo restaurado a partir de versão anterior: %s',
    'WSP_VERSION_SOURCE_SAVE' => 'Antes de salvar',
    'WSP_VERSION_SOURCE_UPLOAD' => 'Upload',
    'WSP_VERSION_SOURCE_RESTORE' => 'Antes de restaurar',
    'WSP_VERSION_SOURCE_MANUAL' => 'Manual',
    'WSP_ACTIVITY_FILE_VERSION_RESTORED' => 'restaurou uma versão anterior de',
    'WSP_VERSION_VIEW_MODE_SAVE_BLOCKED' => 'Você está visualizando uma versão anterior. Reabra o arquivo atual ou restaure a versão antes de salvar.',
    'WSP_LOCK_FILE' => 'Bloquear arquivo para edição',
    'WSP_UNLOCK_FILE' => 'Liberar arquivo',
    'WSP_FILE_UNLOCKED' => 'Arquivo livre para edição.',
    'WSP_FILE_LOCKED_BY_YOU' => 'Arquivo bloqueado por você.',
    'WSP_FILE_LOCKED_BY_USER' => 'Arquivo bloqueado por %s.',
    'WSP_FILE_LOCKED_SAVE_BLOCKED' => 'Este arquivo está bloqueado por outro usuário. Salve depois que ele for liberado.',
    'WSP_ERR_FILE_LOCKED_BY' => 'Este arquivo está bloqueado por %s.',
    'WSP_ERROR_FILE_LOCK' => 'Não foi possível atualizar o bloqueio do arquivo.',
    'WSP_ACTIVITY_FILE_LOCKED' => 'bloqueou o arquivo',
    'WSP_ACTIVITY_FILE_UNLOCKED' => 'liberou o arquivo',
]);


$lang = array_merge($lang, [
    'WSP_NOTIFICATIONS' => 'Notificações',
    'WSP_MARK_ALL_READ' => 'Marcar tudo como lido',
    'WSP_LOADING_NOTIFICATIONS' => 'Carregando notificações...',
    'WSP_ERROR_NOTIFICATIONS' => 'Não foi possível carregar as notificações.',
    'WSP_NO_NOTIFICATIONS' => 'Nenhuma notificação.',
    'WSP_NOTIFY_MEMBER_ADDED' => 'adicionou você ao projeto',
    'WSP_NOTIFY_MEMBER_REMOVED' => 'removeu você do projeto',
    'WSP_NOTIFY_ROLE_CHANGED' => 'alterou seu papel no projeto',
    'WSP_NOTIFY_TEAM_CHANGED' => 'atualizou a equipe do projeto',
    'WSP_NOTIFY_COMMENT_ADDED' => 'comentou no arquivo',
    'WSP_NOTIFY_REVIEW_REQUESTED' => 'solicitou revisão do arquivo',
    'WSP_NOTIFY_REVIEW_APPROVED' => 'aprovou o arquivo',
    'WSP_NOTIFY_REVIEW_CHANGES_REQUESTED' => 'solicitou ajustes no arquivo',
    'WSP_NOTIFY_FILE_SAVED' => 'salvou alterações em',
    'WSP_NOTIFY_VERSION_RESTORED' => 'restaurou versão anterior de',
]);


$lang = array_merge($lang, [
    'WSP_NOTIFY_FILE_UPLOADED_UPDATE' => 'atualizou por upload',
    'WSP_NOTIFY_FILE_UPLOADED_NEW' => 'enviou novo arquivo',
]);

$lang = array_merge($lang, [
    'WSP_EXPORT_COLLAB_PACKAGE' => 'Exportar pacote colaborativo',
    'WSP_ACTIVITY_COLLAB_EXPORTED' => 'exportou pacote colaborativo',
]);

$lang = array_merge($lang, [
    'WSP_TASK_BOARD' => 'Quadro de tarefas',
    'WSP_TASK_BOARD_DESC' => 'Acompanhe pendências, responsáveis e progresso do projeto.',
    'WSP_ADD_TASK' => 'Adicionar tarefa',
    'WSP_DELETE_TASK' => 'Excluir tarefa',
    'WSP_TASK_TITLE' => 'Título da tarefa',
    'WSP_TASK_DESCRIPTION' => 'Descrição da tarefa',
    'WSP_TASK_UNASSIGNED' => 'Sem responsável',
    'WSP_TASK_STATUS_TODO' => 'A fazer',
    'WSP_TASK_STATUS_DOING' => 'Em andamento',
    'WSP_TASK_STATUS_DONE' => 'Concluída',
    'WSP_TASK_PRIORITY_LOW' => 'Baixa',
    'WSP_TASK_PRIORITY_NORMAL' => 'Normal',
    'WSP_TASK_PRIORITY_HIGH' => 'Alta',
    'WSP_NO_TASKS' => 'Nenhuma tarefa cadastrada para este projeto.',
    'WSP_TASK_UPDATED' => 'Tarefa atualizada.',
    'WSP_CONFIRM_DELETE_TASK' => 'Excluir esta tarefa?',
    'WSP_ACTIVITY_TASK_CREATED' => 'criou tarefa',
    'WSP_ACTIVITY_TASK_UPDATED' => 'atualizou tarefa',
    'WSP_ACTIVITY_TASK_COMPLETED' => 'concluiu tarefa',
    'WSP_ACTIVITY_TASK_DELETED' => 'excluiu tarefa',
    'WSP_NOTIFY_TASK_ASSIGNED' => 'atribuiu uma tarefa a você',
    'WSP_NOTIFY_TASK_UPDATED' => 'atualizou tarefa atribuída a você',
]);

$lang = array_merge($lang, [
    'WSP_VALIDATE_RELEASE' => 'Validar release phpBB',
    'WSP_VALIDATE_RUN_AGAIN' => 'Validar novamente',
    'WSP_VALIDATOR_WAITING' => 'Clique para executar o checklist de release.',
    'WSP_VALIDATOR_RUNNING' => 'Validando estrutura do projeto...',
    'WSP_VALIDATOR_READY' => 'Pronto para revisão',
    'WSP_VALIDATOR_NOT_READY' => 'Ajustes necessários',
    'WSP_VALIDATOR_ERRORS' => 'erros',
    'WSP_VALIDATOR_WARNINGS' => 'avisos',
    'WSP_VALIDATOR_CHECKS' => 'checks',
    'WSP_VALIDATOR_FILES' => 'arquivos',
    'WSP_VALIDATOR_NO_CHECKS' => 'Nenhum check retornado.',
    'WSP_VALIDATOR_SCOPE_LABEL' => 'Escopo da validação',
    'WSP_VALIDATOR_SCOPE_NORMAL' => 'Validação normal',
    'WSP_VALIDATOR_SCOPE_FULL' => 'Validação completa',
    'WSP_VALIDATOR_SCOPE_PHPBB_EXT_DB' => 'phpBB Extension DB',
    'WSP_VALIDATOR_NO_ISSUES' => 'Nenhum problema encontrado.',
    'WSP_VALIDATOR_ISSUES' => 'Problemas encontrados',
    'WSP_VALIDATOR_ACTION' => 'Como corrigir',
    'WSP_VALIDATOR_LINE' => 'Linha',
    'WSP_VALIDATOR_EXCERPT' => 'Trecho',
    'WSP_RELEASE_CHECKLIST' => 'Checklist de release',
    'WSP_CLOSE' => 'Fechar',
    'WSP_ACTIVITY_RELEASE_VALIDATED' => 'executou validação de release',
]);

$lang = array_merge($lang, [
    'WSP_VALIDATOR_FIXABLE' => 'correções seguras',
    'WSP_VALIDATOR_SAFE_FIX_AVAILABLE' => 'Correção automática segura disponível',
    'WSP_VALIDATOR_APPLY_SAFE_FIXES' => 'Aplicar correções seguras',
    'WSP_VALIDATOR_APPLY_SAFE_FIXES_CONFIRM' => 'Aplicar somente as correções automáticas seguras? O conteúdo atual dos arquivos alterados será salvo no histórico de versões antes da mudança.',
    'WSP_VALIDATOR_FIXES_APPLIED' => '%d correções seguras aplicadas.',
    'WSP_ACTIVITY_RELEASE_FIXES_APPLIED' => 'aplicou correções seguras de release',
    'WSP_VALIDATE_RELEASE_SHORT' => 'Validar phpBB',
    'WSP_COLLAB_SHORT' => 'Colaboração',
    'WSP_TOGGLE_COLLAB_PANEL' => 'Mostrar ou ocultar colaboração',
    'WSP_SHOW_COLLAB_PANEL' => 'Mostrar colaboração',
    'WSP_HIDE_COLLAB_PANEL' => 'Ocultar colaboração',
    'WSP_EDITOR_FOCUS_MODE' => 'Modo foco do editor',
    'WSP_COLLAB_TAB_TEAM' => 'Equipe',
    'WSP_COLLAB_TAB_TASKS' => 'Tarefas',
    'WSP_COLLAB_TAB_REVIEW' => 'Revisão',
    'WSP_COLLAB_TAB_ACTIVITY' => 'Atividade',
    'WSP_MENU_NEW' => 'Novo',
    'WSP_MENU_FILE' => 'Arquivo',
    'WSP_MENU_PROJECT' => 'Projeto',
    'WSP_MENU_EDITOR' => 'Editor',
    'WSP_MENU_LOCKS' => 'Bloqueios',
    'WSP_MENU_EXPORT' => 'Exportar',
    'WSP_TREE_ACTIONS_HINT' => 'Renomear, mover e excluir arquivo/pasta ficam nos ícones da árvore lateral.',
    'WSP_LOCKS_HINT' => 'Os bloqueios de arquivo aparecem quando há arquivo aberto e permissão de edição.',
]);

$lang = array_merge($lang, [
    'WSP_COLLAB_MODE' => 'Modo de colaboração',
    'WSP_COLLAB_MODE_PRIVATE' => 'Privado',
    'WSP_COLLAB_MODE_PM_REQUEST' => 'Pedidos por MP',
    'WSP_COLLAB_MODE_UPDATED' => 'Modo de colaboração atualizado.',
    'WSP_REQUEST_COLLABORATION' => 'Solicitar colaboração',
    'WSP_REQUEST_ROLE' => 'Papel desejado',
    'WSP_REQUEST_MESSAGE' => 'Mensagem para o responsável',
    'WSP_REQUEST_MESSAGE_PLACEHOLDER' => 'Explique como você quer ajudar neste projeto.',
    'WSP_COLLAB_REQUEST_SENT' => 'Solicitação de colaboração enviada por mensagem privada.',
    'WSP_COLLAB_REQUEST_SAVED_NO_PM' => 'A solicitação foi registrada, mas a mensagem privada não pôde ser enviada neste ambiente.',
    'WSP_ERR_COLLAB_REQUEST_FAILED' => 'Não foi possível enviar a solicitação de colaboração.',
    'WSP_ERR_COLLAB_REQUESTS_DISABLED' => 'Este projeto não está aceitando pedidos de colaboração.',
    'WSP_ERR_ALREADY_MEMBER' => 'Você já é membro deste projeto.',
    'WSP_PM_COLLAB_SUBJECT' => 'Pedido de colaboração no Workspace: %s',
    'WSP_PM_COLLAB_BODY' => "Olá,\n\n%s gostaria de colaborar no projeto \"%s\" do Workspace.\n\nPapel desejado: %s\n\nMensagem:\n%s\n\nAbra o painel de equipe do projeto se quiser adicionar este usuário como membro.",
    'WSP_PM_COLLAB_NO_MESSAGE' => '(Sem mensagem adicional.)',
    'WSP_NOTIFY_COLLAB_REQUESTED' => 'solicitou colaboração no seu projeto',
    'WSP_LOG_COLLAB_MODE_CHANGED' => 'Modo de colaboração alterado para %s',
    'WSP_ACTIVITY_COLLABORATION_REQUESTED' => 'solicitou colaboração no projeto',
    'WSP_ACTIVITY_COLLABORATION_MODE_CHANGED' => 'alterou o modo de colaboração para',
]);
