/**
 * Mundo phpBB Workspace - Inicializador Blindado
 * Versão 4.1: i18n Pura, CSS de Estado e Log Estruturado (Sem omissões)
 * Gerencia o carregamento de módulos, dependências e restauração de estado.
 */
(function ($) {
    'use strict';

    var MAX_DEP_TRIES = 200; // 20 segundos de limite
    var depTries = 0;

    /**
     * Verifica se o elemento do editor existe na página atual.
     */
    var hasWorkspaceDom = function () {
        return !!document.getElementById('editor');
    };

    /**
     * Função principal de ativação da IDE.
     */
    var startWorkspace = function () {
        if (!hasWorkspaceDom()) return;

        // Log de início traduzido
        console.log("WSP: " + WSP.lang('WSP_INIT_START'));

        // Desativa cache global para AJAX
        $.ajaxSetup({ cache: false });

        // 1. Inicializa o Editor ACE primeiro (Dependência base)
        if (!window.WSP || typeof WSP.initEditor !== 'function' || !WSP.initEditor()) {
            console.error("WSP: " + WSP.lang('WSP_CRITICAL_ACE'));
            return;
        }

        // 2. Ordem de Inicialização dos Módulos (Init -> Bind)
        var modules = [
            { name: 'UI', ref: WSP.ui },
            { name: 'Tree', ref: WSP.tree },
            { name: 'Files', ref: WSP.files },
            { name: 'Projects', ref: WSP.projects },
            { name: 'Upload', ref: WSP.upload },
            { name: 'Tools', ref: WSP.tools }
        ];

        modules.forEach(function (m) {
            try {
                if (m.ref) {
                    // Executa o ciclo de vida do módulo
                    if (typeof m.ref.init === 'function') m.ref.init($);
                    if (typeof m.ref.render === 'function') m.ref.render($); 
                    if (typeof m.ref.bindEvents === 'function') m.ref.bindEvents($);
                    
                    // Log de sucesso com placeholder para o nome do módulo
                    var msg = WSP.lang('WSP_MODULE_LOADED', {'%s': m.name});
                    console.log("WSP: " + msg);
                }
            } catch (e) {
                // Log de erro com placeholder
                var errMsg = WSP.lang('WSP_MODULE_ERROR', {'%s': m.name});
                console.error("WSP: " + errMsg, e);
            }
        });

        // 3. Sincroniza o estado visual da Toolbar (Core)
        WSP.updateUIState();

        /**
         * 4. RESTAURAÇÃO DE ESTADO (PÓS-F5)
         * Recupera o último arquivo aberto do localStorage
         */
        var savedFileId = localStorage.getItem('wsp_active_file_id');
        var hasProject = !!WSP.activeProjectId;

        if (savedFileId && hasProject) {
            WSP.activeFileId = savedFileId;

            // Feedback visual usando classes CSS e tradução motorizada
            $('#current-file')
                .addClass('is-loading')
                .removeClass('is-empty')
                .text(WSP.lang('WSP_LOADING_FILE'));

            // Tenta carregar do servidor (autoridade máxima)
            $.post(window.wspVars.loadUrl, { 
                file_id: savedFileId, 
                _nocache: Date.now() 
            }, function (r) {
                if (r && r.success) {
                    // Configuração do Editor
                    WSP.editor.setValue(r.content || '', -1);
                    WSP.editor.setReadOnly(false);
                    WSP.originalContent = r.content;

                    // Atualiza Breadcrumbs (agora via UI Module)
                    if (WSP.ui && typeof WSP.ui.updateBreadcrumbs === 'function') {
                        WSP.ui.updateBreadcrumbs(r.name);
                    }

                    // Configura modo do ACE
                    var nameLower = (r.name || '').toLowerCase();
                    if (nameLower === 'changelog.txt') {
                        WSP.editor.session.setMode("ace/mode/diff");
                        $('#copy-bbcode').show();
                    } else {
                        var mode = (WSP.modes && WSP.modes[r.type]) ? WSP.modes[r.type] : 'ace/mode/text';
                        WSP.editor.session.setMode(mode);
                        $('#copy-bbcode').hide();
                    }

                    $('#save-file').show();
                    $('#current-file').removeClass('is-loading');

                    // Re-expande a árvore lateral (Sidebar Sync)
                    setTimeout(function () {
                        var $target = $('.load-file[data-id="' + savedFileId + '"]');
                        if ($target.length) {
                            $('.file-item').removeClass('active-file');
                            $target.closest('.file-item').addClass('active-file');
                            
                            // Interface: Abre as pastas pai de forma recursiva
                            $target.parents('.folder-content').show();
                            $target.parents('.folder-item').addClass('is-open')
                                .find('> .folder-title i.icon')
                                .removeClass('fa-folder').addClass('fa-folder-open');
                        }
                    }, 300);
                } else {
                    // Falha na restauração (limpa ID órfão)
                    localStorage.removeItem('wsp_active_file_id');
                    $('#current-file')
                        .removeClass('is-loading')
                        .addClass('is-empty')
                        .text(WSP.lang('WSP_SELECT_FILE'));
                }
            }, 'json');
        }

        // Log de conclusão
        console.log("WSP: " + WSP.lang('WSP_READY'));
    };

    /**
     * Verificador de Dependências (Polling)
     * Garante que bibliotecas externas e módulos WSP existam antes de rodar.
     */
    var checkDeps = function () {
        if (!hasWorkspaceDom()) return;

        depTries++;

        var libsReady = (typeof jQuery !== 'undefined' && typeof ace !== 'undefined' && typeof window.wspVars !== 'undefined');
        var modulesReady = (window.WSP && WSP.ui && WSP.tree && WSP.files && WSP.projects && WSP.upload && WSP.tools);

        if (libsReady && modulesReady) {
            $(document).ready(startWorkspace);
            return;
        }

        if (depTries >= MAX_DEP_TRIES) {
            console.error("WSP: " + WSP.lang('WSP_TIMEOUT'));
            return;
        }

        setTimeout(checkDeps, 100);
    };

    // Inicia a verificação de dependências
    checkDeps();

})(jQuery);