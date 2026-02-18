<?php
/**
 * mundophpbb workspace extension [English]
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
'WSP_EXPLORER' => 'Explorer',
'WSP_PROJECT_LABEL' => 'Project',
'WSP_SELECT_FILE' => 'Select a file to edit',
'WSP_LOADING' => 'Loading...',
'WSP_PROCESSING' => 'Processing...',
// Mensagem de Boas-vindas (Área do Editor)
'WSP_WELCOME_MSG' => "/*\n * MUNDO PHPBB WORKSPACE\n * =====================\n * \n * NO FILE OPEN.\n * \n * 1. Select a file in the side tab.\n * 2. Edit the code (a '*' will appear if there are changes).\n * 3. Use CTRL + S to save quickly.\n */\n",
// Ações Principais e Status
'WSP_SAVE_CHANGES' => 'SAVE CHANGES',
'WSP_SAVING' => 'SAVING...',
'WSP_SAVED' => 'SAVED!',
'WSP_OK' => 'OK',
'WSP_CANCEL' => 'Cancel',
'WSP_CLOSE' => 'Close',
'WSP_RENAME' => 'Rename',
'WSP_COPIED' => 'Copied!',
// Gerenciamento de Projetos e Arquivos
'WSP_NEW_PROJECT' => 'New Project',
'WSP_ADD_FILE' => 'Add File/Folder',
'WSP_PROMPT_NAME' => 'Enter the name of the new project:',
'WSP_PROMPT_FILE_NAME' => 'File name (ex: includes/functions.php):',
'WSP_DOWNLOAD_ZIP' => 'Download Project (ZIP)',
'WSP_GENERATE_ZIP' => 'Generating compressed package...',
'WSP_UPLOADING' => 'Uploading: ',
'WSP_NO_PROJECTS' => 'No projects found.',
'WSP_DEFAULT_DESC' => 'Created via Workspace IDE',
'WSP_FILTER_PLACEHOLDER' => 'Filter files by name...',
// Ferramenta de Diferença (Diff)
'WSP_DIFF_TITLE' => 'File Comparison',
'WSP_DIFF_SELECT_ORIG' => 'Original File (Old)',
'WSP_DIFF_SELECT_MOD' => 'Modified File (New)',
'WSP_DIFF_GENERATE' => 'Generate Comparison',
'WSP_DIFF_GENERATING' => 'Generating...',
'WSP_DIFF_BBCODE' => 'Generated BBCode (Ready for the forum)',
'WSP_DIFF_PREVIEW' => 'Diff Preview',
'WSP_COPY_BBCODE' => 'Copy BBCode',
// Busca e Substituição
'WSP_SEARCH_REPLACE' => 'Search and Replace in Project',
'WSP_SEARCH_FOR' => 'Search for',
'WSP_REPLACE_WITH' => 'Replace with',
'WSP_REPLACE_ALL' => 'Replace All',
'WSP_REPLACE_SUCCESS' => 'Success! %d change(s) made.',
'WSP_SEARCH_NO_RESULTS' => 'No file found with this term.',
'WSP_SEARCH_EMPTY_ERR' => 'Enter the search term.',
'WSP_REPLACE_ONLY_FILE' => 'Replace in the open file from: ',
'WSP_REPLACE_IN_PROJECT' => 'Replace in the entire project: ',
// Confirmações
'WSP_CONFIRM_DELETE' => 'Are you sure you want to delete this project and all its files? This action is irreversible.',
'WSP_CONFIRM_FILE_DELETE' => 'Do you really want to delete this file?',
'WSP_CONFIRM_REPLACE_FILE' => 'Do you want to replace the text ONLY in this open file?',
'WSP_CONFIRM_REPLACE_ALL' => 'Do you really want to replace in the ENTIRE project?',
// Mensagens de Erro e Segurança
'WSP_ERR_PERMISSION' => 'You do not have Founder permission to access this tool.',
'WSP_ERR_INVALID_ID' => 'The file or project ID is invalid.',
'WSP_ERR_FILE_NOT_FOUND' => 'The requested file was not found.',
'WSP_ERR_FILE_EXISTS' => 'A file or folder with this name already exists.',
'WSP_ERR_INVALID_DATA' => 'The submitted data is invalid.',
'WSP_ERR_INVALID_NAME' => 'The name cannot be empty.',
'WSP_ERR_INVALID_FILES' => 'Select valid files to generate the Diff.',
'WSP_ERR_SAVE_FAILED' => 'Internal error when trying to save the file.',
'WSP_ERR_SERVER_500' => "SYSTEM ALERT!\nA 500 error occurred on the server.\n\nProbably the 'lib/' folder with the DIFF library does not exist or is in the wrong place.",
'WSP_ERR_COPY' => 'Copy error: ',
'WSP_FILE_ELIMINATED' => '/* File deleted */',
// Conteúdos Iniciais de Arquivos
'WSP_FILE' => 'File',
// Conteúdo Inicial Específico
'WSP_LUA_ACTIVE' => 'Lua Script Active',
// Changelog
'WSP_CHANGELOG_TITLE' => 'MUNDO PHPBB WORKSPACE - AUTOMATIC CHANGELOG',
'WSP_GENERATED_ON' => 'Generated on',
// Novos para JS
'WSP_CTX_NEW_FILE' => 'New File here',
'WSP_CTX_NEW_FOLDER' => 'New Subfolder here',
'WSP_CTX_DELETE_FOLDER' => 'Delete Folder',
'WSP_NEW_ROOT_FILE' => 'New file in the project root',
'WSP_NEW_ROOT_FOLDER' => 'Root folder name',
'WSP_NEW_FILE_IN' => 'New file in ',
'WSP_NEW_FOLDER_IN' => 'New subfolder in ',
'WSP_ERR_NO_DELETE_URL' => 'Error: deleteFolderUrl not configured.',
'WSP_CONFIRM_DELETE_FOLDER' => "Delete the folder '{path}' and all its files permanently?",
'WSP_ERR_COMM' => 'Critical Communication Error.',
// Nova chave adicionada
'WSP_UPLOAD_FILES' => 'Upload Files',
// Novas chaves adicionadas com base na análise do template
'WSP_DRAG_UPLOAD' => 'Drag files or folders here to upload',
'WSP_GENERATE_CHANGELOG' => 'Generate Changelog',
'WSP_NEW_ROOT_FOLDER_TITLE' => 'New Folder in Root',
'WSP_TOGGLE_FULLSCREEN' => 'Toggle Full Screen',
));