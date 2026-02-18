<?php
/**
 * mundophpbb workspace extension [Portuguese Brazilian]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */
/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
exit;
}
if (empty($lang) || !is_array($lang))
{
$lang = array();
}
// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct.
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %shere%s' is fine.
$lang = array_merge($lang, array(
// Interface Principal e Branding
'WSP_TITLE' => 'Mundo phpBB Workspace',
'WSP_EXPLORER' => 'Explorador',
'WSP_PROJECT_LABEL' => 'Projeto',
'WSP_SELECT_FILE' => 'Selecione um arquivo para editar',
'WSP_LOADING' => 'Carregando...',
'WSP_PROCESSING' => 'Processando...',
// Mensagem de Boas-vindas (Área do Editor)
'WSP_WELCOME_MSG' => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n * \n * NENHUM ARQUIVO ABERTO.\n * \n * 1. Selecione um arquivo na aba lateral.\n * 2. Edite o código (um '*' aparecerá se houver mudanças).\n * 3. Use CTRL + S para salvar rapidamente.\n */\n",
// Ações Principais e Status
'WSP_SAVE_CHANGES' => 'SALVAR ALTERAÇÕES',
'WSP_SAVING' => 'SALVANDO...',
'WSP_SAVED' => 'SALVO!',
'WSP_OK' => 'OK',
'WSP_CANCEL' => 'Cancelar',
'WSP_CLOSE' => 'Fechar',
'WSP_RENAME' => 'Renomear',
'WSP_COPIED' => 'Copiado!',
// Gerenciamento de Projetos e Arquivos
'WSP_NEW_PROJECT' => 'Novo Projeto',
'WSP_ADD_FILE' => 'Adicionar Arquivo/Pasta',
'WSP_PROMPT_NAME' => 'Digite o nome do novo projeto:',
'WSP_PROMPT_FILE_NAME' => 'Nome do arquivo (ex: includes/funcoes.php):',
'WSP_DOWNLOAD_ZIP' => 'Baixar Projeto (ZIP)',
'WSP_GENERATE_ZIP' => 'Gerando pacote compactado...',
'WSP_UPLOADING' => 'Enviando: ',
'WSP_NO_PROJECTS' => 'Nenhum projeto encontrado.',
'WSP_DEFAULT_DESC' => 'Criado via Workspace IDE',
'WSP_FILTER_PLACEHOLDER' => 'Filtrar arquivos por nome...',
// Ferramenta de Diferença (Diff)
'WSP_DIFF_TITLE' => 'Comparação de Arquivos',
'WSP_DIFF_SELECT_ORIG' => 'Arquivo Original (Antigo)',
'WSP_DIFF_SELECT_MOD' => 'Arquivo Modificado (Novo)',
'WSP_DIFF_GENERATE' => 'Gerar Comparação',
'WSP_DIFF_GENERATING' => 'Gerando...',
'WSP_DIFF_BBCODE' => 'BBCode Gerado (Pronto para o fórum)',
'WSP_DIFF_PREVIEW' => 'Visualização do Diff',
'WSP_COPY_BBCODE' => 'Copiar BBCode',
// Busca e Substituição
'WSP_SEARCH_REPLACE' => 'Procurar e Substituir no Projeto',
'WSP_SEARCH_FOR' => 'Procurar por',
'WSP_REPLACE_WITH' => 'Substituir por',
'WSP_REPLACE_ALL' => 'Substituir em Tudo',
'WSP_REPLACE_SUCCESS' => 'Sucesso! %d alteração(ões) feita(s).',
'WSP_SEARCH_NO_RESULTS' => 'Nenhum arquivo encontrado com este termo.',
'WSP_SEARCH_EMPTY_ERR' => 'Digite o termo de busca.',
'WSP_REPLACE_ONLY_FILE' => 'Substituir no arquivo aberto de: ',
'WSP_REPLACE_IN_PROJECT' => 'Substituir em todo o projeto: ',
// Confirmações
'WSP_CONFIRM_DELETE' => 'Tem certeza que deseja excluir este projeto e todos os seus arquivos? Esta ação é irreversível.',
'WSP_CONFIRM_FILE_DELETE' => 'Deseja realmente apagar este arquivo?',
'WSP_CONFIRM_REPLACE_FILE' => 'Deseja substituir o texto APENAS neste arquivo aberto?',
'WSP_CONFIRM_REPLACE_ALL' => 'Deseja realmente substituir em TODO o projeto?',
// Mensagens de Erro e Segurança
'WSP_ERR_PERMISSION' => 'Você não tem permissão de Fundador para acessar esta ferramenta.',
'WSP_ERR_INVALID_ID' => 'O ID do arquivo ou projeto é inválido.',
'WSP_ERR_FILE_NOT_FOUND' => 'O arquivo solicitado não foi encontrado.',
'WSP_ERR_FILE_EXISTS' => 'Já existe um arquivo ou pasta com este nome.',
'WSP_ERR_INVALID_DATA' => 'Os dados enviados são inválidos.',
'WSP_ERR_INVALID_NAME' => 'O nome não pode ficar vazio.',
'WSP_ERR_INVALID_FILES' => 'Selecione arquivos válidos para gerar o Diff.',
'WSP_ERR_SAVE_FAILED' => 'Erro interno ao tentar salvar o arquivo.',
'WSP_ERR_SERVER_500' => "ALERTA DE SISTEMA!\nOcorreu um erro 500 no servidor.\n\nProvavelmente a pasta 'lib/' com a biblioteca DIFF não existe ou está no local errado.",
'WSP_ERR_COPY' => 'Erro ao copiar: ',
'WSP_FILE_ELIMINATED' => '/* Arquivo eliminado */',
// Conteúdos Iniciais de Arquivos
'WSP_FILE' => 'Arquivo',
// Conteúdo Inicial Específico
'WSP_LUA_ACTIVE' => 'Lua Script Ativo',
// Changelog
'WSP_CHANGELOG_TITLE' => 'MUNDO PHPBB WORKSPACE - CHANGELOG AUTOMÁTICO',
'WSP_GENERATED_ON' => 'Gerado em',
// Novos para JS
'WSP_CTX_NEW_FILE' => 'Novo Arquivo aqui',
'WSP_CTX_NEW_FOLDER' => 'Nova Subpasta aqui',
'WSP_CTX_DELETE_FOLDER' => 'Excluir Pasta',
'WSP_NEW_ROOT_FILE' => 'Novo arquivo na raiz do projeto',
'WSP_NEW_ROOT_FOLDER' => 'Nome da pasta na raiz',
'WSP_NEW_FILE_IN' => 'Novo arquivo em ',
'WSP_NEW_FOLDER_IN' => 'Nova subpasta em ',
'WSP_ERR_NO_DELETE_URL' => 'Erro: deleteFolderUrl não configurada.',
'WSP_CONFIRM_DELETE_FOLDER' => "Excluir a pasta '{path}' e todos os seus arquivos permanentemente?",
'WSP_ERR_COMM' => 'Erro Crítico de Comunicação.',
// Nova chave adicionada
'WSP_UPLOAD_FILES' => 'Enviar Arquivos',
// Novas chaves adicionadas com base na análise do template
'WSP_DRAG_UPLOAD' => 'Arraste arquivos ou pastas aqui para upload',
'WSP_GENERATE_CHANGELOG' => 'Gerar Changelog',
'WSP_NEW_ROOT_FOLDER_TITLE' => 'Nova Pasta na Raiz',
'WSP_TOGGLE_FULLSCREEN' => 'Alternar Tela Cheia',
));