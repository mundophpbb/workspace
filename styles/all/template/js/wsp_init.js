/**
 * Mundo phpBB Workspace - Inicializador Blindado
 * Versão 4.2 (SSOT core): usa WSP.canWriteUI() do wsp_core.js
 */
(function ($) {
    'use strict';

    var MAX_DEP_TRIES = 200; // ~20s
    var depTries = 0;
    var started = false;

    function hasWorkspaceDom() {
        return !!document.getElementById('editor');
    }

    function safeLang(key, replacements) {
        try {
            if (window.WSP && typeof WSP.lang === 'function') return WSP.lang(key, replacements);
        } catch (e) {}
        return '[' + key + ']';
    }

    function getEditor() {
        // Compat core/legado
        if (window.WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) {
            return WSP.editor;
        }
        if (window.WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) {
            return WSP.editor.ace;
        }
        return null;
    }

    /**
     * ✅ SSOT: prefere WSP.canWriteUI() do core
     * Fallback apenas se core antigo estiver carregado.
     */
    function canWriteUI() {
        if (!window.WSP) return false;

        if (typeof WSP.canWriteUI === 'function') {
            return !!WSP.canWriteUI();
        }

        // fallback compat (core antigo)
        if (typeof WSP.canEditActiveProjectUI === 'function') {
            return !!WSP.canEditActiveProjectUI();
        }
        if (WSP.activeProjectId && WSP.activeProjectLocked && !WSP.canManageAll) return false;

        if (window.wspVars) {
            var locked = window.wspVars.activeLocked || window.wspVars.WSP_ACTIVE_LOCKED || 0;
            var canManageAll = window.wspVars.canManageAll || window.wspVars.WSP_CAN_MANAGE_ALL || 0;
            locked = (locked === true || locked === 1 || locked === '1');
            canManageAll = (canManageAll === true || canManageAll === 1 || canManageAll === '1');
            if (locked && !canManageAll) return false;
        }

        return true;
    }

    function endsWith(str, suffix) {
        // ✅ usa helper do core se existir
        if (window.WSP && typeof WSP._endsWith === 'function') {
            return WSP._endsWith(str, suffix);
        }

        // fallback
        str = String(str || '');
        suffix = String(suffix || '');
        if (!suffix) return true;
        if (typeof str.endsWith === 'function') return str.endsWith(suffix);
        return str.indexOf(suffix, str.length - suffix.length) !== -1;
    }

    function startWorkspace() {
        if (started) return;
        if (!hasWorkspaceDom()) return;
        started = true;

        var $currentFile = $('#current-file');
        var $saveBtn = $('#save-file');
        var $bbcodeBtn = $('#copy-bbcode');

        console.log("WSP: " + safeLang('WSP_INIT_START'));

        $.ajaxSetup({ cache: false });

        // 1) Inicializa ACE
        if (!window.WSP || typeof WSP.initEditor !== 'function' || !WSP.initEditor()) {
            console.error("WSP: " + safeLang('WSP_CRITICAL_ACE'));
            return;
        }

        // 2) Init/Render/Bind módulos
        var modules = [
            { name: 'UI', ref: WSP.ui },
            { name: 'Tree', ref: WSP.tree },
            { name: 'Files', ref: WSP.files },
            { name: 'Projects', ref: WSP.projects },
            { name: 'Upload', ref: WSP.upload },
            { name: 'Tools', ref: WSP.tools }
        ];

        for (var i = 0; i < modules.length; i++) {
            var m = modules[i];
            try {
                if (!m.ref) continue;

                if (typeof m.ref.init === 'function') m.ref.init($);
                if (typeof m.ref.render === 'function') m.ref.render($);
                if (typeof m.ref.bindEvents === 'function') m.ref.bindEvents($);

                console.log("WSP: " + safeLang('WSP_MODULE_LOADED', { '%s': m.name }));
            } catch (e) {
                console.error("WSP: " + safeLang('WSP_MODULE_ERROR', { '%s': m.name }), e);
            }
        }

        // 3) Estado visual toolbar (Core)
        if (typeof WSP.updateUIState === 'function') {
            WSP.updateUIState();
        }

        // 4) RESTAURAÇÃO (pós-F5)
        var savedFileId = localStorage.getItem('wsp_active_file_id');
        var hasProject = !!WSP.activeProjectId;

        if (savedFileId && hasProject && window.wspVars && window.wspVars.loadUrl) {
            $currentFile
                .addClass('is-loading')
                .removeClass('is-empty')
                .text(safeLang('WSP_LOADING_FILE'));

            $.post(window.wspVars.loadUrl, { file_id: savedFileId, _nocache: Date.now() }, function (r) {
                if (r && r.success) {
                    WSP.activeFileId = savedFileId;

                    var ed = getEditor();
                    if (!ed) {
                        localStorage.removeItem('wsp_active_file_id');
                        $currentFile.removeClass('is-loading').addClass('is-empty').text(safeLang('WSP_SELECT_FILE'));
                        return;
                    }

                    var content = (typeof r.content === 'string') ? r.content : '';
                    ed.setValue(content, -1);

                    // ✅ SSOT do core
                    ed.setReadOnly(!canWriteUI());

                    WSP.originalContent = content;

                    if (WSP.ui && typeof WSP.ui.updateBreadcrumbs === 'function') {
                        WSP.ui.updateBreadcrumbs(r.name);
                    } else {
                        $currentFile.text(r.name || safeLang('WSP_SELECT_FILE'));
                    }

                    var nameLower = String(r.name || '').toLowerCase();
                    if (nameLower === 'changelog.txt') {
                        ed.session.setMode("ace/mode/diff");
                        $bbcodeBtn.show();
                    } else if (endsWith(nameLower, '.txt')) {
                        ed.session.setMode("ace/mode/text");
                        $bbcodeBtn.hide();
                    } else {
                        var mode = (WSP.modes && WSP.modes[r.type]) ? WSP.modes[r.type] : 'ace/mode/text';
                        ed.session.setMode(mode);
                        $bbcodeBtn.hide();
                    }

                    // Save só se puder escrever
                    if (canWriteUI()) $saveBtn.show();
                    else $saveBtn.hide();

                    $currentFile.removeClass('is-loading');

                    setTimeout(function () {
                        var $target = $('.load-file[data-id="' + savedFileId + '"]');
                        if ($target.length) {
                            $('.file-item').removeClass('active-file');
                            $target.closest('.file-item').addClass('active-file');

                            $target.parents('.folder-content').show();
                            $target.parents('.folder-item')
                                .addClass('is-open')
                                .find('> .folder-title i.icon')
                                .removeClass('fa-folder').addClass('fa-folder-open');
                        }
                    }, 300);

                    if (typeof WSP.updateUIState === 'function') {
                        WSP.updateUIState();
                    }
                } else {
                    localStorage.removeItem('wsp_active_file_id');
                    $currentFile.removeClass('is-loading').addClass('is-empty').text(safeLang('WSP_SELECT_FILE'));
                }
            }, 'json').fail(function () {
                localStorage.removeItem('wsp_active_file_id');
                $currentFile.removeClass('is-loading').addClass('is-empty').text(safeLang('WSP_SELECT_FILE'));
            });
        }

        console.log("WSP: " + safeLang('WSP_READY'));
    }

    function checkDeps() {
        if (!hasWorkspaceDom()) return;
        if (started) return;

        depTries++;

        var libsReady = (typeof jQuery !== 'undefined' && typeof ace !== 'undefined' && typeof window.wspVars !== 'undefined');
        var modulesReady = (window.WSP && WSP.ui && WSP.tree && WSP.files && WSP.projects && WSP.upload && WSP.tools);

        if (libsReady && modulesReady) {
            $(startWorkspace);
            return;
        }

        if (depTries >= MAX_DEP_TRIES) {
            console.error("WSP: " + safeLang('WSP_TIMEOUT'));
            return;
        }

        setTimeout(checkDeps, 100);
    }

    checkDeps();

})(jQuery);