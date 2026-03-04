/**
 * Mundo phpBB Workspace - UI & Modals
 * Versão 4.2 (SSOT core): Lock-aware + i18n pura + delegação + splitter leve
 */
WSP.ui = {
    _ui: null,

    _getUI: function () {
        if (this._ui) return this._ui;

        this._ui = {
            $body: jQuery('body'),
            $doc: jQuery(document),

            $modal: jQuery('#wsp-custom-modal'),
            $modalTitle: jQuery('#wsp-modal-title'),
            $modalBody: jQuery('#wsp-modal-body-custom'),
            $modalInput: jQuery('#wsp-modal-input'),
            $modalOk: jQuery('#wsp-modal-ok'),
            $modalCancel: jQuery('#wsp-modal-cancel'),

            $notify: jQuery('#wsp-notify-container'),

            $sidebar: jQuery('#sidebar-dropzone'),
            $splitter: jQuery('#wsp-splitter'),

            $projectList: jQuery('#project-list'),
            $currentFile: jQuery('#current-file'),

            $copyBbcode: jQuery('#copy-bbcode'),
            $saveFile: jQuery('#save-file')
        };

        return this._ui;
    },

    _escape: function (s) {
        s = String(s == null ? '' : s);
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    _getEditor: function () {
        // Compat core/legado
        if (window.WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) {
            return WSP.editor;
        }
        if (window.WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) {
            return WSP.editor.ace;
        }
        return null;
    },

    /**
     * Inicialização
     */
    init: function ($) {
        this.injectModal($);
        this.bindEvents($);
        this.initSplitter();

        // Aplica estado visual do lock (se já houver sidebar renderizada)
        this.applyLockUIState();
    },

    /**
     * ✅ SSOT/Compat:
     * Mantido como alias para não quebrar chamadas antigas.
     * Preferir: WSP.canWriteUI() (core).
     */
    _canWrite: function () {
        if (window.WSP && typeof WSP.canWriteUI === 'function') {
            return !!WSP.canWriteUI();
        }

        // fallback (core antigo)
        if (!window.WSP || !WSP.activeProjectId) return false;
        if (typeof WSP.canEditActiveProjectUI === 'function') return !!WSP.canEditActiveProjectUI();
        if (WSP.activeProjectLocked && !WSP.canManageAll) return false;

        if (window.wspVars) {
            var locked = window.wspVars.activeLocked || window.wspVars.WSP_ACTIVE_LOCKED || 0;
            var canManageAll = window.wspVars.canManageAll || window.wspVars.WSP_CAN_MANAGE_ALL || 0;
            locked = (locked === true || locked === 1 || locked === '1');
            canManageAll = (canManageAll === true || canManageAll === 1 || canManageAll === '1');
            if (locked && !canManageAll) return false;
        }

        return true;
    },

    /**
     * Aplica estado visual de lock na sidebar (ícones de contexto).
     * - Agora usando SSOT do core: WSP.canWriteUI()
     */
    applyLockUIState: function () {
        var locked = false;

        // Sem projeto ativo -> não trava UI
        if (!window.WSP || !WSP.activeProjectId) {
            locked = false;
        } else if (typeof WSP.canWriteUI === 'function') {
            locked = !WSP.canWriteUI(); // ✅ SSOT
        } else {
            locked = !this._canWrite(); // fallback compat
        }

        var val = locked ? '1' : '0';
        jQuery('.folder-context-actions i, .file-context-actions i')
            .toggleClass('wsp-disabled', locked)
            .attr('data-wsp-disabled', val);
    },

    /**
     * Injeta Modais + Container de Notificações (Singleton)
     */
    injectModal: function ($) {
        var ui = this._getUI();

        if (!ui.$modal.length) {
            ui.$body.append(
                '<div id="wsp-custom-modal">' +
                    '<div id="wsp-modal-card">' +
                        '<h4 id="wsp-modal-title"></h4>' +
                        '<div id="wsp-modal-body-custom" style="display:none;"></div>' +
                        '<input type="text" id="wsp-modal-input" style="display:none;">' +
                        '<div class="wsp-modal-footer">' +
                            '<button id="wsp-modal-cancel" class="button">' + WSP.lang('WSP_UI_CANCEL') + '</button>' +
                            '<button id="wsp-modal-ok" class="button secondary">' + WSP.lang('WSP_UI_CONFIRM') + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        }

        if (!ui.$notify.length) {
            ui.$body.append('<div id="wsp-notify-container"></div>');
        }

        // Atualiza cache após injeção
        this._ui = null;
        ui = this._getUI();

        // Fechar modal ao clicar no fundo
        ui.$body.off('mousedown.wsp_modal').on('mousedown.wsp_modal', '#wsp-custom-modal', function (e) {
            if (jQuery(e.target).attr('id') === 'wsp-custom-modal') {
                jQuery('#wsp-custom-modal').hide();
            }
        });
    },

    /**
     * Notificações Toast
     */
    notify: function (message, type) {
        type = type || 'success';

        var ui = this._getUI();
        var id = 'notif-' + Date.now() + '-' + Math.floor(Math.random() * 100000);

        var icons = {
            success: 'check-circle',
            error: 'exclamation-triangle',
            info: 'info-circle',
            warning: 'exclamation-circle'
        };

        var html =
            '<div id="' + id + '" class="wsp-notification is-' + type + '">' +
                '<i class="fa fa-' + (icons[type] || icons.info) + '"></i>' +
                '<span>' + message + '</span>' +
            '</div>';

        ui.$notify.append(html);

        setTimeout(function () {
            jQuery('#' + id).fadeOut(400, function () { jQuery(this).remove(); });
        }, 4000);
    },

    /**
     * Diálogo Prompt / Lista (mesma API)
     */
    prompt: function (title, defaultValue, callback) {
        callback = callback || function () { };

        var ui = this._getUI();

        ui.$modalTitle.text(title);
        ui.$modalBody.hide().empty();

        ui.$modalOk.off('.wsp_prompt');
        ui.$modalCancel.off('.wsp_prompt');
        ui.$modalInput.off('.wsp_prompt');

        if (defaultValue === 'LIST_MODE') {
            ui.$modalInput.hide();
            ui.$modalOk.hide();
            ui.$modalBody.show();
        } else {
            ui.$modalInput.val(defaultValue || '').show();
            ui.$modalOk.show();
        }

        ui.$modal.css('display', 'flex');

        if (defaultValue !== 'LIST_MODE') {
            ui.$modalInput.focus().select();

            ui.$modalOk.on('click.wsp_prompt', function () {
                var val = ui.$modalInput.val();
                ui.$modal.hide();
                callback(val);
            });

            ui.$modalInput.on('keypress.wsp_prompt', function (e) {
                if (e.which === 13) ui.$modalOk.trigger('click');
            });
        }

        ui.$modalCancel.on('click.wsp_prompt', function () {
            ui.$modal.hide();
        });
    },

    /**
     * Confirmação de ação crítica
     */
    confirm: function (title, callback) {
        var self = this;
        self.prompt(title, 'LIST_MODE');

        var ui = self._getUI();
        ui.$modalBody.html('<p style="margin:10px 0; color:#aaa;">' + self._escape(WSP.lang('WSP_UI_ACTION_WARNING')) + '</p>').show();

        ui.$modalOk.show().off('.wsp_confirm').on('click.wsp_confirm', function () {
            ui.$modal.hide();
            callback();
        });
    },

    /**
     * Atualização da Sidebar com Reconstrução da Árvore
     */
    seamlessRefresh: function (fileIdToOpen) {
        var self = this;
        var ui = self._getUI();
        var currentUrl = window.location.href;

        var openFolders = [];
        jQuery('.folder-item').each(function () {
            var $it = jQuery(this);
            var $content = $it.find('> .folder-content');
            if ($content.length && $content.is(':visible')) {
                var path = $it.find('> .folder-title').attr('data-path');
                if (path) openFolders.push(path);
            }
        });

        jQuery.get(currentUrl, { _nocache: Date.now() }, function (data) {
            var $page = jQuery('<div>').append(jQuery.parseHTML(data));
            var $newSidebar = $page.find('#project-list');

            if (!$newSidebar.length) return;

            ui.$projectList.html($newSidebar.html());

            if (window.WSP.tree && typeof window.WSP.tree.render === 'function') {
                window.WSP.tree.render(jQuery);
                window.WSP.tree.bindEvents(jQuery);
            }

            if (openFolders.length) {
                var $titles = jQuery('.folder-title');
                for (var i = 0; i < openFolders.length; i++) {
                    var path = openFolders[i];
                    var $folder = $titles.filter(function () {
                        return jQuery(this).attr('data-path') === path;
                    });

                    if ($folder.length) {
                        $folder.next('.folder-content').show();
                        $folder.find('i.icon').removeClass('fa-folder').addClass('fa-folder-open');
                        $folder.closest('.folder-item').addClass('is-open');
                    }
                }
            }

            if (window.WSP.activeFileId) {
                var $active = jQuery('.load-file[data-id="' + window.WSP.activeFileId + '"]');
                if ($active.length) {
                    $active.closest('.file-item').addClass('active-file');
                } else {
                    window.WSP.activeFileId = null;
                    localStorage.removeItem('wsp_active_file_id');
                }
            }

            // 6) Abre arquivo recém-criado (se solicitado) — protegido contra perda de alterações
if (fileIdToOpen) {
    setTimeout(function () {
        try {
            // Usa o helper do próprio WSP.ui (compat core/legado)
            var ed = self._getEditor ? self._getEditor() : null;

            if (window.WSP && WSP.activeFileId && ed && typeof ed.getValue === 'function') {
                var original = (typeof WSP.originalContent === 'string') ? WSP.originalContent : '';
                var current = ed.getValue();

                if (current !== original) {
                    if (!confirm(WSP.lang('WSP_UNSAVED_CHANGES'))) {
                        return; // não troca de arquivo
                    }
                }
            }
        } catch (e) {}

        var $target = jQuery('.load-file[data-id="' + fileIdToOpen + '"]');
        if ($target.length) $target.trigger('click');
    }, 200);
}

            self.initSplitter();

            // ✅ reaplica lock visual via SSOT
            self.applyLockUIState();

            if (window.WSP && typeof WSP.updateUIState === 'function') {
                WSP.updateUIState();
            }
        }, 'html').fail(function () {
            if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
                WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), 'error');
            }
        });
    },

    /**
     * EVENTOS DELEGADOS (UI Global)
     */
    bindEvents: function ($) {
        var self = this;
        var ui = self._getUI();
        var $body = ui.$body;

        $body.off('.wsp_ui');

        $body.on('click.wsp_ui', '#wsp-active-folder-indicator', function () {
            if (window.WSP.tree && window.WSP.tree.clearActiveFolder) {
                window.WSP.tree.clearActiveFolder();
                self.notify(WSP.lang('WSP_UI_ROOT_FOCUS'), "info");
            }
        });

        $body.on('input.wsp_ui', '#wsp-sidebar-filter', function () {
            var term = String(jQuery(this).val() || '').toLowerCase();

            var $files = jQuery('.file-item');
            var $folders = jQuery('.folder-item');

            if (term === '') {
                $files.show();
                $folders.show();
                return;
            }

            $files.each(function () {
                var fileName = jQuery(this).find('.load-file').text().toLowerCase();
                jQuery(this).toggle(fileName.indexOf(term) > -1);
            });

            $folders.each(function () {
                var $f = jQuery(this);
                var hasMatch = $f.find('.file-item:visible').length > 0;
                $f.toggle(hasMatch);

                if (hasMatch) {
                    $f.find('> .folder-content').show();
                    $f.find('> .folder-title i.icon').removeClass('fa-folder').addClass('fa-folder-open');
                    $f.addClass('is-open');
                }
            });
        });

        $body.on('click.wsp_ui', '#toggle-fullscreen', function () {
            jQuery('.workspace-container').toggleClass('fullscreen-mode');
            jQuery(this).find('i').toggleClass('fa-expand fa-compress');

            var ed = self._getEditor();
            if (ed) setTimeout(function () { ed.resize(); }, 150);
        });
    },

    /**
     * Breadcrumbs do arquivo atual
     */
    updateBreadcrumbs: function (path) {
        var ui = this._getUI();

        if (!path) {
            var msg = WSP.lang('WSP_UI_SELECT_FILE');
            if (msg === '[WSP_UI_SELECT_FILE]') msg = WSP.lang('WSP_SELECT_FILE');
            ui.$currentFile.text(msg);
            return;
        }

        var parts = String(path).split('/');
        var out = ['<i class="fa fa-folder-open-o" style="color:var(--wsp-accent); margin-right:5px;"></i> '];

        for (var i = 0; i < parts.length; i++) {
            out.push('<span class="breadcrumb-item">', this._escape(parts[i]), '</span>');
            if (i < parts.length - 1) {
                out.push(' <i class="fa fa-angle-right breadcrumb-separator"></i> ');
            }
        }

        ui.$currentFile.html(out.join(''));
    },

    /**
     * Splitter (otimizado com rAF)
     */
    initSplitter: function () {
        var self = this;
        var ui = self._getUI();

        var $sidebar = ui.$sidebar;
        var $splitter = ui.$splitter;

        if (!$splitter.length || !$sidebar.length) return;

        var isDragging = false;
        var startX = 0;
        var startWidth = 0;

        var queued = false;
        var lastPageX = 0;

        var savedWidth = localStorage.getItem('wsp_sidebar_width');
        if (savedWidth) $sidebar.css('width', savedWidth + 'px');

        ui.$doc.off('.wsp_splitter');
        $splitter.off('.wsp_splitter');

        function clampWidth(w) { return Math.max(180, Math.min(620, w)); }

        function scheduleUpdate() {
            if (queued) return;
            queued = true;

            (window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); })(function () {
                queued = false;
                if (!isDragging) return;

                var delta = lastPageX - startX;
                var newWidth = clampWidth(startWidth + delta);

                $sidebar.css('width', newWidth + 'px');
                localStorage.setItem('wsp_sidebar_width', newWidth);

                var ed = self._getEditor();
                if (ed) ed.resize();
            });
        }

        $splitter.on('mousedown.wsp_splitter', function (e) {
            isDragging = true;
            startX = e.pageX;
            startWidth = $sidebar.outerWidth();
            ui.$body.addClass('is-resizing');
            $splitter.addClass('dragging');
        });

        ui.$doc.on('mousemove.wsp_splitter', function (e) {
            if (!isDragging) return;
            lastPageX = e.pageX;
            scheduleUpdate();
        });

        ui.$doc.on('mouseup.wsp_splitter', function () {
            if (!isDragging) return;
            isDragging = false;
            ui.$body.removeClass('is-resizing');
            $splitter.removeClass('dragging');
        });

        ui.$doc.on('keydown.wsp_splitter', function (e) {
            if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                e.preventDefault();

                var step = (e.key === 'ArrowLeft') ? -30 : 30;
                var current = $sidebar.outerWidth();
                var newW = clampWidth(current + step);

                $sidebar.css('width', newW + 'px');
                localStorage.setItem('wsp_sidebar_width', newW);

                var ed = self._getEditor();
                if (ed) ed.resize();
            }
        });

        console.log("WSP: " + WSP.lang('WSP_UI_SPLITTER_READY'));
    }
};