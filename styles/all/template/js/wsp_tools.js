/**
 * Mundo phpBB Workspace - Tools (Diff, Search, Cache)
 * Versão 4.3 (SSOT core): mesma lógica, com lock SSOT no que escreve + proteção do editor
 */
WSP.tools = {
    _ui: null,

    _getUI: function () {
        if (this._ui) return this._ui;

        this._ui = {
            $body: jQuery('body'),
            $doc: jQuery(document),

            $searchModal: jQuery('#search-replace-modal'),
            $searchTerm: jQuery('#wsp-search-term'),
            $replaceTerm: jQuery('#wsp-replace-term'),
            $searchProjectId: jQuery('#search-project-id'),
            $execReplaceBtn: jQuery('#exec-replace-btn'),

            $diffModal: jQuery('#diff-modal'),
            $diffOrig: jQuery('#diff-original'),
            $diffMod: jQuery('#diff-modified'),
            $genDiffBtn: jQuery('#generate-diff-btn'),

            $currentFile: jQuery('#current-file'),
            $copyBbcode: jQuery('#copy-bbcode'),
            $saveFile: jQuery('#save-file'),

            $refreshCacheBtn: jQuery('#refresh-phpbb-cache')
        };

        return this._ui;
    },

    _escape: function (s) {
        if (window.WSP && typeof WSP._escapeHtml === 'function') return WSP._escapeHtml(s);

        s = String(s == null ? '' : s);
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    _getEditor: function () {
        if (window.WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) {
            return WSP.editor;
        }
        if (window.WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) {
            return WSP.editor.ace;
        }
        return null;
    },

    _canWrite: function () {
        if (window.WSP && typeof WSP.canWriteUI === 'function') return !!WSP.canWriteUI();

        // fallback (core antigo)
        if (!window.WSP || !WSP.activeProjectId) return false;
        if (typeof WSP.canEditActiveProjectUI === 'function') return !!WSP.canEditActiveProjectUI();
        if (WSP.activeProjectLocked && !WSP.canManageAll) return false;
        return true;
    },

    _notifyLocked: function () {
        if (window.WSP && typeof WSP.notifyLocked === 'function') return WSP.notifyLocked();

        var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_PROJECT_LOCKED_MSG') : '';
        if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PROJECT_LOCKED') : 'Projeto trancado.';
        if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(msg, "warning");
    },

    _hasUnsavedChanges: function () {
        var ed = this._getEditor();
        if (!ed) return false;
        if (!WSP.activeFileId) return false;

        var original = (typeof WSP.originalContent === 'string') ? WSP.originalContent : '';
        return ed.getValue() !== original;
    },

    _confirmLoseChanges: function () {
        if (!this._hasUnsavedChanges()) return true;
        // Reusa a mesma chave do Files (já existe no seu sistema)
        return confirm(WSP.lang('WSP_UNSAVED_CHANGES'));
    },

    _postJson: function ($, url, data, cbOk, cbErr, cbAlways) {
        url = (window.WSP && typeof WSP.normalizeUrl === 'function') ? WSP.normalizeUrl(url) : url;

        if (!url) {
            cbErr && cbErr(null);
            cbAlways && cbAlways();
            return;
        }

        if (window.WSP && typeof WSP.ajaxPostJson === 'function') {
            WSP.ajaxPostJson(url, data, function (r) {
                cbOk && cbOk(r);
                cbAlways && cbAlways();
            }, 'WSP_ERROR_CRITICAL');
            return;
        }

        return $.post(url, data, function (r) {
            if (r && r.success) cbOk && cbOk(r);
            else cbErr && cbErr(r);
        }, 'json').fail(function () {
            cbErr && cbErr(null);
        }).always(function () {
            cbAlways && cbAlways();
        });
    },

    bindEvents: function ($) {
        var self = this;
        var ui = self._getUI();
        var $body = ui.$body;

        $body.off('.wsp_tools');
        ui.$doc.off('keydown.wsp_tools');

        // 1) BUSCA/REPLACE
        $body.on('click.wsp_tools', '.open-search-replace', function (e) {
            e.preventDefault();

            var projectId = WSP.activeProjectId;
            if (!projectId) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), "warning");
            if (!ui.$searchModal.length) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), "error");

            ui.$searchTerm.val('');
            ui.$replaceTerm.val('');
            ui.$searchProjectId.val(projectId);

            ui.$searchModal.fadeIn(200, function () { ui.$searchTerm.focus(); });
        });

        $body.on('click.wsp_tools', '#exec-replace-btn', function (e) {
            e.preventDefault();

            // ✅ replace escreve -> lock SSOT
            if (!self._canWrite()) return self._notifyLocked();

            // ✅ protege editor (evita perder alterações antes do refresh/reload)
            if (!self._confirmLoseChanges()) return;

            var $btn = jQuery(this);
            var projectId = ui.$searchProjectId.val() || WSP.activeProjectId;

            var data = {
                project_id: projectId,
                file_id: WSP.activeFileId || 0,
                search: ui.$searchTerm.val(),
                replace: ui.$replaceTerm.val()
            };

            if (!data.search) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_TERM_REQUIRED'), "warning");

            WSP.ui.confirm(WSP.lang('WSP_TOOLS_SEARCH_CONFIRM'), function () {
                $btn.prop('disabled', true)
                    .html('<i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_PROCESSING'));

                self._postJson($, window.wspVars.replaceUrl, data, function (r) {
                    WSP.ui.notify(WSP.lang('WSP_REPLACE_SUCCESS', { '%d': r.updated }), "success");

                    self._clearBackups(data.file_id);

                    ui.$searchModal.fadeOut(200);
                    WSP.ui.seamlessRefresh();

                    if (WSP.activeFileId) jQuery('.active-file .load-file').trigger('click');
                }, function (r) {
                    WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), "error");
                }, function () {
                    $btn.prop('disabled', false).text(WSP.lang('WSP_REPLACE_ALL'));
                });
            });
        });

        // 2) DIFF
        $body.on('click.wsp_tools', '#open-diff-tool', function (e) {
            e.preventDefault();

            if (!WSP.activeProjectId) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), "info");
            if (!ui.$diffModal.length || !ui.$diffOrig.length || !ui.$diffMod.length) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), "error");
            }

            var foundFiles = 0;
            var optHtml = [];

            jQuery('.load-file').each(function () {
                var $a = jQuery(this);
                var id = $a.data('id');
                var path = ($a.attr('data-path') || $a.text().trim() || '');
                if (!id) return;
                if (path.indexOf('.placeholder') !== -1) return;

                optHtml.push('<option value="', String(id), '">', self._escape(path), '</option>');
                foundFiles++;
            });

            if (foundFiles < 2) return WSP.ui.notify(WSP.lang('WSP_TOOLS_DIFF_MIN_FILES'), "info");

            ui.$diffOrig.empty().html(optHtml.join(''));
            ui.$diffMod.empty().html(optHtml.join(''));
            ui.$diffModal.fadeIn(200);
        });

        $body.on('click.wsp_tools', '#generate-diff-btn', function (e) {
            e.preventDefault();

            // ✅ protege editor (diff sobrescreve conteúdo atual)
            if (!self._confirmLoseChanges()) return;

            var $btn = jQuery(this);
            var originalId = ui.$diffOrig.val();
            var modifiedId = ui.$diffMod.val();
            var filename = ui.$diffOrig.find('option:selected').text();

            if (originalId === modifiedId) {
                return WSP.ui.notify(WSP.lang('WSP_TOOLS_DIFF_SAME_FILES'), "warning");
            }

            $btn.prop('disabled', true).text(WSP.lang('WSP_TOOLS_COMPARING'));

            self._postJson($, window.wspVars.diffUrl, {
                original_id: originalId,
                modified_id: modifiedId,
                filename: filename
            }, function (r) {
                ui.$diffModal.fadeOut(200);

                // Modo visual
                WSP.activeFileId = null;

                var ed = self._getEditor();
                if (ed) {
                    ed.setReadOnly(true);
                    ed.session.setMode("ace/mode/diff");
                    ed.setValue(r.bbcode || '', -1);
                    ed.focus();
                } else {
                    WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
                }

                ui.$copyBbcode.stop(true, true).fadeIn(300);
                ui.$saveFile.hide();

                ui.$currentFile.html('<i class="fa fa-columns diff-label-icon"></i> ' +
                    self._escape(WSP.lang('WSP_LABEL_DIFF', { '%s': filename })));

                if (typeof WSP.updateUIState === 'function') WSP.updateUIState();
            }, function (r) {
                WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), "error");
            }, function () {
                $btn.prop('disabled', false).text(WSP.lang('WSP_DIFF_GENERATE'));
            });
        });

        // 3) CACHE phpBB
        $body.on('click.wsp_tools', '#refresh-phpbb-cache', function (e) {
            e.preventDefault();

            var $btn = jQuery(this);
            var $icon = $btn.find('i').addClass('fa-spin');

            $btn.addClass('btn-cache-loading');
            WSP.ui.notify(WSP.lang('WSP_PROCESSING'), "info");

            self._postJson($, window.wspVars.refreshCacheUrl, {}, function () {
                WSP.ui.notify(WSP.lang('WSP_CACHE_CLEANED'), "success");
                setTimeout(function () { window.location.reload(true); }, 1200);
            }, function (r) {
                WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), "error");
            }, function () {
                $icon.removeClass('fa-spin');
                $btn.removeClass('btn-cache-loading');
            });
        });

        // 4) ESC fecha modais
        ui.$doc.on('keydown.wsp_tools', function (e) {
            var isEsc = (e.key === "Escape" || e.keyCode === 27);
            if (isEsc) {
                ui.$searchModal.fadeOut(150);
                ui.$diffModal.fadeOut(150);
            }
        });
    },

    _clearBackups: function (fileId) {
        if (!fileId || fileId === 0 || fileId === '0') {
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var key = localStorage.key(i);
                if (key && key.indexOf('wsp_backup_') === 0) localStorage.removeItem(key);
            }
        } else {
            localStorage.removeItem('wsp_backup_' + fileId);
        }
    }
};