<?php
/**
 *
 * mundophpbb workspace extension [Portuguese Brazilian]
 *
 * @package mundophpbb workspace
 * @copyright (c) 2026 mundophpbb
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 *
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

$lang = array_merge($lang, array(
	'WSP_TITLE'			=> 'Mundo phpBB Workspace',
	'WSP_EXPLORER'			=> 'Explorador',
	'WSP_NEW_PROJECT'		=> 'Novo Projeto',
	'WSP_ADD_FILE'			=> 'Adicionar Arquivo',
	'WSP_SELECT_FILE'		=> 'Selecione um arquivo para editar',
	'WSP_SAVE_CHANGES'		=> 'Salvar Alterações',
	'WSP_SAVING'			=> 'Salvando...',
	'WSP_SAVED'				=> 'Salvo!',
	'WSP_LOADING'			=> 'Carregando...',
	'WSP_NO_PROJECTS'		=> 'Nenhum projeto encontrado.',
	'WSP_PROMPT_NAME'		=> 'Digite o nome do novo projeto:',
	'WSP_PROMPT_FILE_NAME'	=> 'Nome do arquivo (ex: includes/funcoes.php):',
	'WSP_PROJECT_LABEL'		=> 'Projeto',
	'WSP_DEFAULT_DESC'		=> 'Criado via Workspace IDE',

	// Mensagens de Erro e Travas de Segurança
	'WSP_ERR_PERMISSION'	=> 'Você não tem permissão de Fundador para acessar esta ferramenta.',
	'WSP_ERR_INVALID_ID'	=> 'O ID do arquivo ou projeto é inválido.',
	'WSP_ERR_FILE_NOT_FOUND'=> 'O arquivo solicitado não foi encontrado.',
	'WSP_ERR_FILE_EXISTS'	=> 'Erro: Já existe um arquivo com este nome neste projeto.',
	'WSP_ERR_INVALID_DATA'	=> 'Os dados enviados são inválidos.',
	'WSP_ERR_INVALID_NAME'	=> 'O nome não pode ficar vazio.',
	'WSP_ERR_INVALID_FILES'	=> 'Selecione arquivos válidos para gerar o Diff.',
	'WSP_ERR_SAVE_FAILED'	=> 'Erro interno ao tentar salvar o arquivo.',

	// Ações e Confirmações
	'WSP_CONFIRM_DELETE'	=> 'Tem certeza que deseja excluir este projeto e todos os seus arquivos? Esta ação é irreversível.',
	'WSP_CONFIRM_FILE_DELETE'=> 'Deseja realmente apagar este arquivo?',
	'WSP_DOWNLOAD_ZIP'		=> 'Baixar Projeto (ZIP)',
	'WSP_GENERATE_ZIP'		=> 'Gerando pacote compactado...',
	'WSP_RENAME'			=> 'Renomear',
	'WSP_CLOSE'				=> 'Fechar',

	// Diff & Patch Wizard
	'WSP_DIFF_TITLE'		=> 'Comparação de Arquivos',
	'WSP_DIFF_SELECT_ORIG'	=> 'Arquivo Original (Antigo)',
	'WSP_DIFF_SELECT_MOD'	=> 'Arquivo Modificado (Novo)',
	'WSP_DIFF_GENERATE'		=> 'Gerar Comparação',
	'WSP_DIFF_BBCODE'		=> 'BBCode Gerado (Pronto para o fórum)',
	'WSP_DIFF_PREVIEW'		=> 'Visualização do Diff',
	'WSP_COPY_BBCODE'		=> 'Copiar BBCode',
	'WSP_COPIED'			=> 'Copiado!',

	// Procurar e Substituir
	'WSP_SEARCH_REPLACE'	=> 'Procurar e Substituir no Projeto',
	'WSP_SEARCH_FOR'		=> 'Procurar por',
	'WSP_REPLACE_WITH'		=> 'Substituir por',
	'WSP_REPLACE_ALL'		=> 'Substituir Tudo',
	'WSP_REPLACE_SUCCESS'	=> 'Sucesso! %d arquivos foram modificados.',
	'WSP_SEARCH_NO_RESULTS'	=> 'Nenhum arquivo encontrado com este termo.',
));