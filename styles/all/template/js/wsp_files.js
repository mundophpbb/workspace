/**
 * Mundo phpBB Workspace - File Operations
 * Versão 4.4 (SSOT + granular edit + toolbar ícone-only)
 * - Toolbar: NÃO injeta texto (save/changelog)
 * - Mantém title/aria-label (i18n) para acessibilidade/tooltip
 */
WSP.files = {
    autoSaveInterval: null,
    _dirtyBound: false,
    _saveCommandBound: false,
    _toolbarI18nApplied: false,

    _ui: null,
    _dirtyRafQueued: false,

    _getUI: function () {
        if (this._ui) return this._ui;

        this._ui = {
            $body: jQuery('body'),
            $currentFile: jQuery('#current-file'),
            $saveBtn: jQuery('#save-file'),
            $bbcodeBtn: jQuery('#copy-bbcode'),
            $modalBody: jQuery('#wsp-modal-body-custom')
        };
        return this._ui;
    },

    _endsWith: function (str, suffix) {
        if (window.WSP && typeof WSP._endsWith === 'function') return WSP._endsWith(str, suffix);

        str = String(str || '');
        suffix = String(suffix || '');
        if (!suffix) return true;
        if (typeof str.endsWith === 'function') return str.endsWith(suffix);
        return str.indexOf(suffix, str.length - suffix.length) !== -1;
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
        if (WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) return WSP.editor;
        if (WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) return WSP.editor.ace;
        return null;
    },

    /**
     * ✅ Edit real (granular): preferir WSP.canEditUI()
     */
    _canEdit: function () {
        if (window.WSP && typeof WSP.canEditUI === 'function') return !!WSP.canEditUI();
        if (window.WSP && typeof WSP.canWriteUI === 'function') return !!WSP.canWriteUI();

        // fallback antigo
        if (!WSP || !WSP.activeProjectId) return false;
        if (WSP.activeProjectLocked && !WSP.canManageAll) return false;
        return true;
    },

    _notifyLockedOrDenied: function () {
        // Se existir notifyLocked no core, usa
        if (window.WSP && typeof WSP.notifyLocked === 'function') return WSP.notifyLocked();

        var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PERMISSION') : '';
        if (!msg || msg === '[WSP_ERR_PERMISSION]') msg = 'Sem permissão.';
        if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(msg, 'error');
    },

    _postJson: function ($, url, data, onDone, onFailMsgKey) {
        url = (window.WSP && typeof WSP.normalizeUrl === 'function') ? WSP.normalizeUrl(url) : url;

        if (!url) {
            if (WSP && WSP.ui) WSP.ui.notify(WSP.lang('WSP_ERR_INVALID_DATA'), 'error');
            return;
        }

        if (window.WSP && typeof WSP.ajaxPostJson === 'function') {
            WSP.ajaxPostJson(url, data, function (r) { onDone(r); }, onFailMsgKey || 'WSP_ERROR_CRITICAL');
            return;
        }

        data = data || {};
        if (typeof data._nocache === 'undefined') data._nocache = Date.now();

        $.post(url, data, function (r) {
            onDone(r);
        }, 'json').fail(function () {
            WSP.ui.notify(WSP.lang(onFailMsgKey || 'WSP_ERROR_CRITICAL'), 'error');
        });
    },

    /**
     * Toolbar i18n: ÍCONE-ONLY
     * - Não seta texto nos botões
     * - Apenas garante title/aria-label traduzidos
     */
    applyToolbarTranslations: function () {
        if (this._toolbarI18nApplied) return;
        this._toolbarI18nApplied = true;

        var ui = this._getUI();
        var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

        // SAVE: ícone-only + title/aria (não injeta texto)
        if (ui.$saveBtn.length) {
            ui.$saveBtn.attr('title', saveTitle);
            ui.$saveBtn.attr('aria-label', saveTitle);

            // garante ícone se algum código tiver apagado
            if (!ui.$saveBtn.find('i.fa').length) {
                ui.$saveBtn.html('<i class="fa fa-save fa-fw"></i>');
            }
        }

        // CHANGELOG: ícone-only (não seta texto)
        jQuery('.generate-project-changelog').each(function () {
            var $el = jQuery(this);
            $el.attr('title', WSP.lang('WSP_GENERATE_CHANGELOG'));
            $el.attr('aria-label', WSP.lang('WSP_GENERATE_CHANGELOG'));
        });

        jQuery('.clear-project-changelog').each(function () {
            var $el = jQuery(this);
            $el.attr('title', WSP.lang('WSP_CLEAR_CHANGELOG'));
            $el.attr('aria-label', WSP.lang('WSP_CLEAR_CHANGELOG'));
        });

        // Outros data-wsp-i18n continuam traduzindo texto (se você usa)
        jQuery('[data-wsp-i18n]').each(function () {
            var $el = jQuery(this);
            var key = ($el.attr('data-wsp-i18n') || '').trim();
            if (key) $el.text(WSP.lang(key));
        });
    },

    renderBreadcrumbs: function (path) {
        var ui = this._getUI();
        path = (path || '').trim();

        if (!path) {
            ui.$currentFile.text(WSP.lang('WSP_SELECT_FILE'));
            return;
        }

        var parts = path.split('/');
        var out = ['<i class="fa fa-folder-open-o breadcrumb-folder-icon"></i> '];

        for (var i = 0; i < parts.length; i++) {
            out.push('<span class="breadcrumb-item">', this._escape(parts[i]), '</span>');
            if (i < parts.length - 1) out.push(' <i class="fa fa-angle-right breadcrumb-sep"></i> ');
        }

        ui.$currentFile.html(out.join(''));
    },

    bindEvents: function ($) {
        var self = this;
        var ui = self._getUI();
        var $body = ui.$body;

        self.applyToolbarTranslations();
        $body.off('.wsp_files');

        function isEditorReady() { return !!self._getEditor(); }

        function setLoadingEffect(on) {
            if (on) ui.$currentFile.addClass('loading-effect');
            else ui.$currentFile.removeClass('loading-effect');
        }

        function hasUnsavedChanges() {
            var ed = self._getEditor();
            if (!ed || !WSP.activeFileId) return false;
            return ed.getValue() !== (typeof WSP.originalContent === 'string' ? WSP.originalContent : '');
        }

        function restorePreviousSelection(prevActiveId) {
            if (!prevActiveId) {
                jQuery('.file-item').removeClass('active-file');
                self.renderBreadcrumbs('');
                return;
            }

            var $prevLink = jQuery('.load-file[data-id="' + prevActiveId + '"]');
            jQuery('.file-item').removeClass('active-file');

            if ($prevLink.length) {
                $prevLink.closest('.file-item').addClass('active-file');
                var prevPath = ($prevLink.attr('data-path') || $prevLink.text() || '').trim();
                self.renderBreadcrumbs(prevPath);
            } else {
                self.renderBreadcrumbs('');
            }
        }

        function setSaveButtonVisible(visible) {
            if (!ui.$saveBtn.length) return;

            var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

            if (visible) {
                ui.$saveBtn
                    .stop(true, true)
                    .fadeIn(200)
                    .attr('title', saveTitle)
                    .attr('aria-label', saveTitle);

                if (!ui.$saveBtn.find('i.fa').length) {
                    ui.$saveBtn.html('<i class="fa fa-save fa-fw"></i>');
                }
            } else {
                ui.$saveBtn.hide();
            }
        }

        // 1) LOAD FILE
        $body.on('click.wsp_files', '.load-file', function (e) {
            e.preventDefault();

            if (!isEditorReady()) {
                WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
                return;
            }

            var $link = jQuery(this);
            var fileId = $link.data('id');
            if (!fileId) return;

            if (hasUnsavedChanges() && !confirm(WSP.lang('WSP_UNSAVED_CHANGES'))) return;

            var prevActiveId = WSP.activeFileId || null;

            var fullPath = ($link.attr('data-path') || $link.text() || '').trim();
            self.renderBreadcrumbs(fullPath);

            jQuery('.file-item').removeClass('active-file');
            $link.closest('.file-item').addClass('active-file');

            setLoadingEffect(true);

            self._postJson($, window.wspVars.loadUrl, { file_id: fileId }, function (r) {
                setLoadingEffect(false);

                if (!r || !r.success) {
                    var errorMsg = (r && r.error) ? r.error : WSP.lang('WSP_ERROR_OPEN_FILE');
                    WSP.ui.notify(errorMsg, 'error');
                    restorePreviousSelection(prevActiveId);
                    return;
                }

                WSP.activeFileId = fileId;
                localStorage.setItem('wsp_active_file_id', fileId);

                var ed = self._getEditor();
                if (!ed) {
                    WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
                    restorePreviousSelection(prevActiveId);
                    return;
                }

                // ✅ Edit real (granular)
                ed.setReadOnly(!self._canEdit());

                var fileName = String(r.name || '').toLowerCase();

                if (fileName === 'changelog.txt') {
                    ed.session.setMode("ace/mode/diff");
                    ui.$bbcodeBtn.stop(true, true).fadeIn(200);
                } else if (self._endsWith(fileName, '.txt')) {
                    ed.session.setMode("ace/mode/text");
                    ui.$bbcodeBtn.hide();
                } else {
                    var modesMap = (WSP.modes && typeof WSP.modes === 'object') ? WSP.modes : ((window.WSP && window.WSP.modes) ? window.WSP.modes : {});
                    ed.session.setMode(modesMap[r.type] || 'ace/mode/text');
                    ui.$bbcodeBtn.hide();
                }

                var finalContent = (typeof r.content === 'string') ? r.content : '';
                ed.setValue(finalContent, -1);
                WSP.originalContent = finalContent;

                ed.resize();
                ed.focus();

                var $activeLink = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
                if ($activeLink.length) {
                    var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
                    $activeLink.text(cleanName).removeClass('is-dirty');
                }

                // ✅ Só mostra save se pode editar de verdade (ícone-only)
                setSaveButtonVisible(self._canEdit());

                if (typeof WSP.updateUIState === 'function') WSP.updateUIState();

                self.initAutoSave();
                self.bindDirtyDetector();
                self.bindSaveShortcut();
            }, 'WSP_ERROR_CRITICAL');
        });

        // 2) SAVE FILE
        $body.on('click.wsp_files', '#save-file', function () {
            if (!isEditorReady() || !WSP.activeFileId) return;

            if (!self._canEdit()) return self._notifyLockedOrDenied();

            var ed = self._getEditor();
            if (!ed) return;

            var $btn = ui.$saveBtn;
            if ($btn.prop('disabled')) return;

            var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

            // ícone-only loading + mantém title/aria
            $btn
                .prop('disabled', true)
                .attr('title', saveTitle)
                .attr('aria-label', saveTitle)
                .html('<i class="fa fa-spinner fa-spin fa-fw"></i>');

            var contentToSave = ed.getValue();

            self._postJson($, window.wspVars.saveUrl, { file_id: WSP.activeFileId, content: contentToSave }, function (r) {
                if (r && r.success) {
                    WSP.originalContent = contentToSave;
                    localStorage.removeItem('wsp_backup_' + WSP.activeFileId);

                    var $activeLink = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
                    if ($activeLink.length) {
                        var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
                        $activeLink.text(cleanName).removeClass('is-dirty');
                    }

                    // ícone-only success
                    $btn.html('<i class="fa fa-check fa-fw"></i>').addClass('btn-success-temporary');

                    setTimeout(function () {
                        if (!self._canEdit()) {
                            $btn.hide();
                            return;
                        }

                        $btn
                            .prop('disabled', false)
                            .attr('title', saveTitle)
                            .attr('aria-label', saveTitle)
                            .html('<i class="fa fa-save fa-fw"></i>')
                            .removeClass('btn-success-temporary');
                    }, 900);
                } else {
                    WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_SAVE'), 'error');
                    $btn
                        .prop('disabled', false)
                        .attr('title', saveTitle)
                        .attr('aria-label', saveTitle)
                        .html('<i class="fa fa-save fa-fw"></i>');
                }
            }, 'WSP_ERROR_CRITICAL');
        });
    },

    bindDirtyDetector: function () {
        if (this._dirtyBound) return;
        this._dirtyBound = true;

        var self = this;
        var ed = self._getEditor();
        if (!ed) return;

        var run = function () {
            self._dirtyRafQueued = false;

            if (!WSP.activeFileId) return;
            if (!self._canEdit()) return;

            var current = ed.getValue();
            var $link = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
            if (!$link.length) return;

            var rawName = ($link.text() || '');
            var nameNoStar = rawName.replace(/^\* /, '');
            var isDirty = (current !== WSP.originalContent);

            if (isDirty && rawName.indexOf('* ') !== 0) {
                $link.text('* ' + nameNoStar).addClass('is-dirty');
            } else if (!isDirty && rawName.indexOf('* ') === 0) {
                $link.text(nameNoStar).removeClass('is-dirty');
            }
        };

        var schedule = function () {
            if (self._dirtyRafQueued) return;
            self._dirtyRafQueued = true;
            (window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); })(run);
        };

        if (ed.session && typeof ed.session.on === 'function') ed.session.on('change', schedule);
        else if (typeof ed.on === 'function') ed.on('change', schedule);
    },

    bindSaveShortcut: function () {
        if (this._saveCommandBound) return;
        this._saveCommandBound = true;

        var self = this;
        var ui = self._getUI();
        var ed = self._getEditor();
        if (!ed) return;

        if (ed.commands && typeof ed.commands.addCommand === 'function') {
            ed.commands.addCommand({
                name: 'save',
                bindKey: { win: 'Ctrl-S', mac: 'Command-S' },
                exec: function () {
                    if (!self._canEdit()) return self._notifyLockedOrDenied();
                    ui.$saveBtn.click();
                }
            });
        }

        ui.$body.off('click.wsp_files', '#copy-bbcode')
            .on('click.wsp_files', '#copy-bbcode', function () {
                var content = ed.getValue();
                var name = jQuery('.active-file .load-file').text().replace(/^\* /, '');
                var bbcode = "[diff=" + name + "]\n" + content + "\n[/diff]";

                var $temp = jQuery("<textarea>").val(bbcode).appendTo("body").select();
                document.execCommand("copy");
                $temp.remove();

                WSP.ui.notify(WSP.lang('WSP_BBCODE_COPIED'), 'info');
            });
    },

    initAutoSave: function () {
        if (this.autoSaveInterval) clearInterval(this.autoSaveInterval);

        var self = this;

        this.autoSaveInterval = setInterval(function () {
            if (!self._canEdit()) return;

            var ed = self._getEditor();
            if (WSP.activeFileId && ed) {
                var current = ed.getValue();
                if (current !== WSP.originalContent) {
                    localStorage.setItem('wsp_backup_' + WSP.activeFileId, current);

                    var logMsg = WSP.lang('WSP_LOG_BACKUP_UPDATED', { '%s': WSP.activeFileId });
                    if (window.console && console.log) console.log("WSP: " + logMsg);
                }
            }
        }, 15000);
    }
};