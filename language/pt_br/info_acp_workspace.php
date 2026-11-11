<?php
/**
 * Mundo phpBB Workspace Extension for phpBB 3.3.x [ACP module - pt_br]
 *
 * @package mundophpbb/workspace
 * @copyright (c) 2026 Chico Gois
 * @license GNU General Public License, version 2 (GPL-2.0)
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
	'ACP_WORKSPACE_TITLE' => 'Workspace IDE',
	'ACP_WORKSPACE_SETTINGS' => 'Configurações do editor',
	'ACP_WORKSPACE_SETTINGS_EXPLAIN' => 'Padrões de todo o fórum para o editor de código (ACE) do Workspace. As alterações valem na próxima vez que o usuário abrir o Workspace.',
	'ACP_WORKSPACE_SETTINGS_SAVED' => 'As configurações do Workspace foram salvas.',
	'ACP_WORKSPACE_SETTINGS_RESET_DONE' => 'As configurações do Workspace foram restauradas para o padrão.',
	'ACP_WORKSPACE_GROUP_EDITOR' => 'Editor',
	'ACP_WORKSPACE_GROUP_PREVIEW' => 'Pré-visualização',
	'ACP_WORKSPACE_GROUP_LIMITS' => 'Limites',
	'ACP_WORKSPACE_PREVIEW_EXPLAIN' => 'Esta pré-visualização reflete as opções acima antes de você salvar.',
	'ACP_WORKSPACE_PREVIEW_ERROR' => 'Não foi possível carregar a pré-visualização porque os arquivos do editor ACE não foram encontrados. Verifique se a pasta ext/mundophpbb/workspace/styles/all/template/ace foi enviada por completo.',
	'ACP_WORKSPACE_THEME' => 'Tema de cores',
	'ACP_WORKSPACE_THEME_EXPLAIN' => 'Cores usadas pelo editor.',
	'ACP_WORKSPACE_THEMES_LIGHT' => 'Temas claros',
	'ACP_WORKSPACE_THEMES_DARK' => 'Temas escuros',
	'ACP_WORKSPACE_KEYBINDING' => 'Modo de teclado',
	'ACP_WORKSPACE_KEYBINDING_EXPLAIN' => 'Estilo de atalhos de teclado usado dentro do editor.',
	'ACP_WORKSPACE_KB_DEFAULT' => 'Padrão',
	'ACP_WORKSPACE_KB_VSCODE' => 'Visual Studio Code',
	'ACP_WORKSPACE_KB_SUBLIME' => 'Sublime Text',
	'ACP_WORKSPACE_KB_VIM' => 'Vim',
	'ACP_WORKSPACE_KB_EMACS' => 'Emacs',
	'ACP_WORKSPACE_FONT_SIZE' => 'Tamanho da fonte',
	'ACP_WORKSPACE_FONT_SIZE_EXPLAIN' => 'Entre 10 e 24 pixels.',
	'ACP_WORKSPACE_TAB_SIZE' => 'Largura do tab',
	'ACP_WORKSPACE_SOFT_TABS' => 'Indentar com espaços',
	'ACP_WORKSPACE_SOFT_TABS_EXPLAIN' => 'Desativado, a tecla Tab insere um caractere de tabulação. As diretrizes de código do phpBB usam tabs no PHP.',
	'ACP_WORKSPACE_WRAP' => 'Quebrar linhas longas',
	'ACP_WORKSPACE_AUTOCOMPLETE' => 'Autocompletar e snippets',
	'ACP_WORKSPACE_AUTOCOMPLETE_EXPLAIN' => 'Sugere palavras e trechos de código enquanto você digita.',
	'ACP_WORKSPACE_INDENT_GUIDES' => 'Mostrar guias de indentação',
	'ACP_WORKSPACE_HIGHLIGHT_LINE' => 'Destacar a linha atual',
	'ACP_WORKSPACE_PRINT_MARGIN' => 'Mostrar a margem de impressão',
	'ACP_WORKSPACE_MAX_FILE_KB' => 'Tamanho máximo de arquivo',
	'ACP_WORKSPACE_MAX_FILE_KB_EXPLAIN' => 'Maior arquivo que pode ser salvo ou enviado para um projeto. Entre %1$d e %2$d KiB.',
	'ACP_WORKSPACE_RESTORE_DEFAULTS' => 'Restaurar padrões',
	'ACP_WORKSPACE_RESTORE_DEFAULTS_CONFIRM' => 'Restaurar todas as configurações do Workspace para os valores padrão?',
	'LOG_WORKSPACE_SETTINGS_UPDATED' => '<strong>Alterou as configurações do Workspace IDE</strong>',
	'LOG_WORKSPACE_SETTINGS_RESET' => '<strong>Restaurou as configurações do Workspace IDE para o padrão</strong>',
	'ACP_WORKSPACE_GROUP_EPV' => 'Validador EPV oficial',
	'ACP_WORKSPACE_GROUP_EPV_EXPLAIN' => 'O validador do Workspace pode executar o EPV oficial do phpBB (Extension Pre Validator) antes de você enviar uma extensão ao phpBB.com. O Workspace nunca baixa nem instala o EPV: ele só executa uma cópia que você instalou em phpBB/tools/epv.',
	'ACP_WORKSPACE_EPV_ENABLED' => 'Executar o EPV oficial no validador',
	'ACP_WORKSPACE_EPV_ENABLED_EXPLAIN' => 'Desativado, o validador ignora o EPV e roda apenas as verificações internas.',
));
