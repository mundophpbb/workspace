/**
 * Mundo phpBB Workspace - Upload & DragDrop
 * Versão 4.2 (SSOT core): Lock-aware + i18n pura + normalização blindada
 */
WSP.upload = {
    _refreshTimer: null,
    _pendingUploads: 0,
    _ui: null,

    _getUI: function () {
        if (this._ui) return this._ui;
        this._ui = {
            $body: jQuery('body'),
            $zone: jQuery('#sidebar-dropzone'),
            $uploadInput: jQuery('#wsp-upload-input')
        };
        return this._ui;
    },

    _endsWith: function (str, suffix) {
        // ✅ helper do core se existir
        if (window.WSP && typeof WSP._endsWith === 'function') {
            return WSP._endsWith(str, suffix);
        }
        // fallback
        str = String(str || '');
        suffix = String(suffix || '');
        if (!suffix) return true;
        if (typeof str.endsWith === 'function') return str.endsWith(suffix);
        return str.indexOf(suffix, str.length - suffix.length) !== -1;
    },

    /**
     * ✅ SSOT/Compat: preferir WSP.canWriteUI() do core
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
     * ✅ SSOT/Compat: preferir WSP.notifyLocked() do core
     */
    _notifyLocked: function () {
        if (window.WSP && typeof WSP.notifyLocked === 'function') {
            WSP.notifyLocked();
            return;
        }

        var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_PROJECT_LOCKED_MSG') : '';
        if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') {
            msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PROJECT_LOCKED') : 'Projeto trancado.';
        }
        if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
            WSP.ui.notify(msg, "warning");
        }
    },

    _guardWrite: function () {
        if (!this._canWrite()) {
            this._notifyLocked();
            return false;
        }
        return true;
    },

    _normalizePath: function (p) {
        if (!p) return '';
        p = String(p).trim().replace(/\\/g, '/');
        p = p.replace(/^\/+/, '');
        p = p.replace(/(\.\.\/)+/g, '');
        p = p.replace(/(^|\/)\.\//g, '$1');
        p = p.replace(/\/{2,}/g, '/');
        return p;
    },

    _isIgnoredFile: function (name) {
        var ignored = ['thumbs.db', '.ds_store', 'desktop.ini', '__macosx'];
        var base = String(name || '').toLowerCase();
        for (var i = 0; i < ignored.length; i++) {
            if (base.indexOf(ignored[i]) !== -1) return true;
        }
        return false;
    },

    _scheduleRefresh: function () {
        var self = this;
        clearTimeout(self._refreshTimer);

        self._refreshTimer = setTimeout(function () {
            if (self._pendingUploads <= 0) {
                self._pendingUploads = 0;
                WSP.ui.seamlessRefresh();
                WSP.ui.notify(WSP.lang('WSP_UPLOAD_LIST_UPDATED'), "success");
            }
        }, 800);
    },

    performUpload: function (file, projectId, customPath) {
        var self = this;
        if (!file || !projectId) return;

        if (!self._guardWrite()) return;

        var finalPath = self._normalizePath(customPath || file.name);
        if (!finalPath) return;
        if (self._isIgnoredFile(finalPath)) return;

        var reader = new FileReader();
        reader.onload = function (e) {
            var formData = new FormData();
            formData.append('file_content', e.target.result);
            formData.append('project_id', projectId);
            formData.append('full_path', finalPath);
            formData.append('is_encoded', '1');

            self._pendingUploads++;

            jQuery.ajax({
                url: window.wspVars.uploadUrl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,

                success: function (r) {
                    if (!r || !r.success) {
                        var errorMsg = (r && r.error) ? r.error : WSP.lang('WSP_UPLOAD_FAILED', { '%s': finalPath });
                        WSP.ui.notify(errorMsg, "error");
                    }
                },

                error: function () {
                    var msg = WSP.lang('WSP_ERROR_CRITICAL');
                    if (!msg || msg === '[WSP_ERROR_CRITICAL]') {
                        msg = WSP.lang('WSP_UPLOAD_FAILED', { '%s': finalPath });
                    }
                    WSP.ui.notify(msg, "error");
                },

                complete: function () {
                    self._pendingUploads--;
                    self._scheduleRefresh();
                }
            });
        };

        reader.readAsDataURL(file);
    },

    traverseFileTree: function (item, path, projectId) {
        var self = this;

        if (!self._guardWrite()) return;

        path = self._normalizePath(path || '');
        if (path && !self._endsWith(path, '/')) path += '/';

        if (!item) return;

        if (item.isFile) {
            item.file(function (file) {
                self.performUpload(file, projectId, path + file.name);
            });
        } else if (item.isDirectory) {
            var dirReader = item.createReader();
            dirReader.readEntries(function (entries) {
                if (!entries || entries.length === 0) {
                    var blob = new Blob([""], { type: 'text/plain' });
                    var placeholder = new File([blob], ".placeholder");
                    self.performUpload(placeholder, projectId, path + item.name + "/.placeholder");
                    return;
                }

                for (var i = 0; i < entries.length; i++) {
                    self.traverseFileTree(entries[i], path + item.name + "/", projectId);
                }
            });
        }
    },

    bindEvents: function ($) {
        var self = this;
        var ui = self._getUI();
        var $body = ui.$body;

        $body.off('.wsp_upload');

        $body.on('click.wsp_upload', '.trigger-upload', function (e) {
            e.preventDefault();

            if (!WSP.activeProjectId) {
                return WSP.ui.notify(WSP.lang('WSP_UPLOAD_NEED_PROJECT'), "warning");
            }

            if (!self._guardWrite()) return;

            if (!jQuery('#wsp-upload-input').length) {
                $body.append('<input type="file" id="wsp-upload-input" multiple>');
            }
            jQuery('#wsp-upload-input').trigger('click');
        });

        $body.on('change.wsp_upload', '#wsp-upload-input', function () {
            var files = this.files;

            if (!self._guardWrite()) {
                jQuery(this).val('');
                return;
            }

            if (files && files.length > 0) {
                WSP.ui.notify(WSP.lang('WSP_UPLOAD_SENDING_COUNT', { '%d': files.length }), "info");

                for (var i = 0; i < files.length; i++) {
                    self.performUpload(files[i], WSP.activeProjectId, null);
                }
            }

            jQuery(this).val('');
        });

        var $zone = jQuery('#sidebar-dropzone');
        if ($zone.length) {
            $zone.off('.wsp_upload');

            $zone.on('dragover.wsp_upload', function (e) {
                e.preventDefault();
                jQuery(this).addClass('sidebar-drag-active');
            });

            $zone.on('dragleave.wsp_upload', function (e) {
                e.preventDefault();
                jQuery(this).removeClass('sidebar-drag-active');
            });

            $zone.on('drop.wsp_upload', function (e) {
                e.preventDefault();
                jQuery(this).removeClass('sidebar-drag-active');

                if (!WSP.activeProjectId) {
                    return WSP.ui.notify(WSP.lang('WSP_UPLOAD_DROP_PROJECT'), "warning");
                }

                if (!self._guardWrite()) return;

                var dt = e.originalEvent && e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer : null;
                if (!dt) return;

                WSP.ui.notify(WSP.lang('WSP_UPLOAD_PROCESSING'), "info");

                var prefix = self._normalizePath(WSP.activeFolderPath || '');
                if (prefix) prefix += '/';

                var items = dt.items;
                if (items && items.length) {
                    for (var i = 0; i < items.length; i++) {
                        var entry = (items[i].webkitGetAsEntry) ? items[i].webkitGetAsEntry() : null;

                        if (entry) {
                            self.traverseFileTree(entry, prefix, WSP.activeProjectId);
                        } else {
                            var f = items[i].getAsFile ? items[i].getAsFile() : null;
                            if (f) {
                                self.performUpload(f, WSP.activeProjectId, prefix ? (prefix + f.name) : f.name);
                            }
                        }
                    }
                }
            });
        }
    }
};