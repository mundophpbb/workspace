<?php
/**
 * mundophpbb workspace extension [Portuguese Brazilian]
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = array(); }

$lang = array_merge($lang, array(
	// 1. Interface Principal e Branding
	'WSP_TITLE'				=> 'Mundo phpBB Workspace',
	'WSP_EXPLORER'			=> 'Explorador',
	'WSP_PROJECT_LABEL'		=> 'Projeto',
	'WSP_WELCOME_MSG'		=> "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n * \n * NENHUM ARQUIVO ABERTO.\n * \n * 1. Selecione um arquivo na aba lateral.\n * 2. Edite o código (um '*' aparecerá se houver mudanças).\n * 3. Use CTRL + S para salvar rapidamente.\n */\n",

	// 2. Status do Editor e Ações Principais
	'WSP_SELECT_FILE'		=> 'Selecione um arquivo para editar',
	'WSP_SAVE_CHANGES'		=> 'SALVAR ALTERAÇÕES',
	'WSP_SAVING'			=> 'SALVANDO...',
	'WSP_SAVED'				=> 'SALVO!',
	'WSP_LOADING'			=> 'Carregando...',
	'WSP_PROCESSING'		=> 'Processando...',
	'WSP_OK'				=> 'OK',
	'WSP_CANCEL'			=> 'Cancelar',
	'WSP_CLOSE'				=> 'Fechar',
	'WSP_RENAME'			=> 'Renomear',
	'WSP_COPIED'			=> 'Copiado!',

	// 3. Gerenciamento de Projetos e Arquivos
	'WSP_NEW_PROJECT'		=> 'Novo Projeto',
	'WSP_ADD_FILE'			=> 'Adicionar Arquivo/Pasta',
	'WSP_PROMPT_NAME'		=> 'Digite o nome do novo projeto:',
	'WSP_PROMPT_FILE_NAME'	=> 'Nome do arquivo (ex: includes/funcoes.php):',
	'WSP_DOWNLOAD_ZIP'		=> 'Baixar Projeto (ZIP)',
	'WSP_GENERATE_ZIP'		=> 'Gerando pacote compactado...',
	'WSP_UPLOADING'			=> 'Enviando: ',
	'WSP_NO_PROJECTS'		=> 'Nenhum projeto encontrado.',
	'WSP_DEFAULT_DESC'		=> 'Criado via Workspace IDE',
	'WSP_FILE_ELIMINATED'	=> '/* Arquivo eliminado */',

	// 4. Ferramentas: Comparação de Arquivos (Diff)
	'WSP_DIFF_TITLE'		=> 'Comparação de Arquivos',
	'WSP_DIFF_SELECT_ORIG'	=> 'Arquivo Original (Antigo)',
	'WSP_DIFF_SELECT_MOD'	=> 'Arquivo Modificado (Novo)',
	'WSP_DIFF_GENERATE'		=> 'Gerar Comparação',
	'WSP_DIFF_GENERATING'	=> 'Gerando...',
	'WSP_DIFF_BBCODE'		=> 'BBCode Gerado (Pronto para o fórum)',
	'WSP_DIFF_PREVIEW'		=> 'Visualização do Diff',
	'WSP_COPY_BBCODE'		=> 'Copiar BBCode',

	// 5. Ferramentas: Procurar e Substituir
	'WSP_SEARCH_REPLACE'	=> 'Procurar e Substituir no Projeto',
	'WSP_SEARCH_FOR'		=> 'Procurar por',
	'WSP_REPLACE_WITH'		=> 'Substituir por',
	'WSP_REPLACE_ALL'		=> 'Substituir em Tudo',
	'WSP_REPLACE_SUCCESS'	=> 'Sucesso! %d alteração(ões) feita(s).',
	'WSP_SEARCH_NO_RESULTS'	=> 'Nenhum arquivo encontrado com este termo.',
	'WSP_SEARCH_EMPTY_ERR'	=> 'Digite o termo de busca.',
	'WSP_REPLACE_ONLY_FILE'	=> 'Substituir no arquivo aberto de: ',
	'WSP_REPLACE_IN_PROJECT' => 'Substituir em todo o projeto: ',
	'WSP_FILTER_PLACEHOLDER' => 'Filtrar arquivos por nome...',

	// 6. Confirmações
	'WSP_CONFIRM_DELETE'	=> 'Tem certeza que deseja excluir este projeto e todos os seus arquivos? Esta ação é irreversível.',
	'WSP_CONFIRM_FILE_DELETE'=> 'Deseja realmente apagar este arquivo?',
	'WSP_CONFIRM_REPLACE_FILE' => 'Deseja substituir o texto APENAS neste arquivo aberto?',
	'WSP_CONFIRM_REPLACE_ALL'  => 'Deseja realmente substituir em TODO o projeto?',

	// 7. Mensagens de Erro e Segurança
	'WSP_ERR_PERMISSION'	=> 'Você não tem permissão de Fundador para acessar esta ferramenta.',
	'WSP_ERR_INVALID_ID'	=> 'O ID do arquivo ou projeto é inválido.',
	'WSP_ERR_FILE_NOT_FOUND'=> 'O arquivo solicitado não foi encontrado.',
	'WSP_ERR_FILE_EXISTS'	=> 'Erro: Já existe um arquivo com este nome neste projeto.',
	'WSP_ERR_INVALID_DATA'	=> 'Os dados enviados são inválidos.',
	'WSP_ERR_INVALID_NAME'	=> 'O nome não pode ficar vazio.',
	'WSP_ERR_INVALID_FILES'	=> 'Selecione arquivos válidos para gerar o Diff.',
	'WSP_ERR_SAVE_FAILED'	=> 'Erro interno ao tentar salvar o arquivo.',
	'WSP_ERR_SERVER_500'	=> "ALERTA DE SISTEMA!\nOcorreu um erro 500 no servidor.\n\nProvavelmente a pasta 'lib/' com a biblioteca DIFF não existe ou está no local errado.",
	'WSP_ERR_COPY'			=> 'Erro ao copiar: ',
));