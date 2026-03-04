/**
 * Mundo phpBB Workspace - File Tree Logic
 * Versão 4.2 (SSOT core): Lock-aware + i18n pura + reconstrução atómica
 */
WSP.tree = {
    activeFolderPath: '',
    _ui: null,

    _getUI: function () {
        if (this._ui) return this._ui;
        this._ui = {
            $activeFolderText: jQuery('#wsp-active-folder-text'),
            $mainContainer: jQuery('#wsp-main-container')
        };
        return this._ui;
    },

    _endsWith: function (str, suffix) {
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
    },

    _normalizePath: function (p) {
        if (p == null) return '';
        return String(p).trim().replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+$/, '');
    },

    _ensureTrailingSlash: function (p) {
        p = this._normalizePath(p);
        return (p && !this._endsWith(p, '/')) ? (p + '/') : p;
    },

    _escapeHtml: function (s) {
        // ✅ usa helper do core se existir
        if (window.WSP && typeof WSP._escapeHtml === 'function') {
            return WSP._escapeHtml(s);
        }
        // fallback
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    _isPlaceholder: function (name) {
        var s = (name || '').toLowerCase().trim();
        return (s === '.placeholder' || s.indexOf('/.placeholder') !== -1);
    },

    /**
     * ✅ SSOT: lock de escrita (true = travado)
     */
    _isWriteLocked: function () {
        if (!window.WSP || !WSP.activeProjectId) return false;

        if (typeof WSP.canWriteUI === 'function') {
            return !WSP.canWriteUI();
        }

        // fallback (core antigo)
        if (typeof WSP.canEditActiveProjectUI === 'function') {
            return !WSP.canEditActiveProjectUI();
        }
        if (WSP.activeProjectLocked && !WSP.canManageAll) return true;

        if (window.wspVars) {
            var locked = window.wspVars.activeLocked || window.wspVars.WSP_ACTIVE_LOCKED || 0;
            var canManageAll = window.wspVars.canManageAll || window.wspVars.WSP_CAN_MANAGE_ALL || 0;
            locked = (locked === true || locked === 1 || locked === '1');
            canManageAll = (canManageAll === true || canManageAll === 1 || canManageAll === '1');
            if (locked && !canManageAll) return true;
        }

        return false;
    },

    _lockedMsg: function () {
        var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_PROJECT_LOCKED_MSG') : '';
        if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') {
            msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PROJECT_LOCKED') : 'Projeto trancado.';
        }
        return msg;
    },

    _notifyLocked: function () {
        // ✅ centraliza se core já tiver
        if (window.WSP && typeof WSP.notifyLocked === 'function') {
            WSP.notifyLocked();
            return;
        }
        if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
            WSP.ui.notify(this._lockedMsg(), 'warning');
        }
    },

    _syncActiveFolderIndicator: function () {
        var ui = this._getUI();
        var path = (this.activeFolderPath || '').trim();
        var display = path ? path : WSP.lang('WSP_TREE_ROOT');

        if (ui.$activeFolderText.length) {
            ui.$activeFolderText.text(display);
        }

        if (ui.$mainContainer.length) {
            ui.$mainContainer.attr('data-active-folder', path);
        }
    },

    clearActiveFolder: function () {
        this.activeFolderPath = '';
        WSP.activeFolderPath = '';
        jQuery('.folder-title').removeClass('active-folder');
        this._syncActiveFolderIndicator();
    },

    _selectFolder: function ($folderTitle) {
        if (!$folderTitle || !$folderTitle.length) return;

        var path = this._ensureTrailingSlash($folderTitle.attr('data-path') || '');
        this.activeFolderPath = path;
        WSP.activeFolderPath = path;

        jQuery('.folder-title').removeClass('active-folder');
        $folderTitle.addClass('active-folder');

        this._syncActiveFolderIndicator();
    },

    render: function ($) {
        var self = this;

        var i18n = {
            newFile: WSP.lang('WSP_TREE_NEW_FILE'),
            newFolder: WSP.lang('WSP_TREE_NEW_FOLDER'),
            rename: WSP.lang('WSP_TREE_RENAME'),
            del: WSP.lang('WSP_TREE_DELETE'),
            move: WSP.lang('WSP_TREE_MOVE')
        };

        // ✅ lock via SSOT
        var writeLocked = self._isWriteLocked();
        var disabledAttrVal = writeLocked ? '1' : '0';
        var disabledClass = writeLocked ? ' wsp-disabled' : '';

        jQuery('.project-group').each(function () {
            var $project = jQuery(this);
            var $fileList = $project.find('.file-list');

            var files = [];
            $fileList.find('.load-file').each(function () {
                var $link = jQuery(this);
                var fileId = $link.data('id');
                if (!fileId) return;

                files.push({
                    id: fileId,
                    name: (String($link.attr('data-path') || $link.text() || '')).trim(),
                    type: $link.data('type') || 'php'
                });
            });

            $fileList.empty();

            var structure = {};
            for (var f = 0; f < files.length; f++) {
                var file = files[f];
                var name = self._normalizePath(file.name);
                var parts = name.split('/');
                var current = structure;

                for (var i = 0; i < parts.length - 1; i++) {
                    var part = parts[i];
                    if (!part) continue;
                    if (!current[part]) current[part] = { _isDir: true, _children: {} };
                    current = current[part]._children;
                }

                var leaf = parts[parts.length - 1];
                if (leaf) current[leaf] = file;
            }

            function buildHtml(obj, currentPath) {
                currentPath = currentPath || '';
                var keys = Object.keys(obj).sort(function (a, b) {
                    var aIsDir = obj[a] && obj[a]._isDir ? 1 : 0;
                    var bIsDir = obj[b] && obj[b]._isDir ? 1 : 0;
                    return (bIsDir - aIsDir) || a.localeCompare(b);
                });

                var out = [];
                for (var k = 0; k < keys.length; k++) {
                    var key = keys[k];
                    if (key === '_isDir' || key === '_children') continue;

                    if (obj[key] && obj[key]._isDir) {
                        var fullFolderPath = self._ensureTrailingSlash(currentPath + key);

                        out.push(
                            '<li class="folder-item">',
                                '<div class="folder-title" data-path="', self._escapeHtml(fullFolderPath), '">',
                                    '<i class="icon fa fa-folder fa-fw"></i> ',
                                    self._escapeHtml(key),
                                    '<span class="folder-context-actions">',
                                        '<i class="fa fa-plus-square ctx-add-file', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" title="', self._escapeHtml(i18n.newFile), '"></i>',
                                        '<i class="fa fa-plus-circle ctx-add-folder', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" title="', self._escapeHtml(i18n.newFolder), '"></i>',
                                        '<i class="fa fa-i-cursor ctx-rename-folder', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" title="', self._escapeHtml(i18n.rename), '"></i>',
                                        '<i class="fa fa-trash ctx-delete-folder', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" title="', self._escapeHtml(i18n.del), '"></i>',
                                    '</span>',
                                '</div>',
                                '<ul class="folder-content" style="display:none;">',
                                    buildHtml(obj[key]._children, fullFolderPath),
                                '</ul>',
                            '</li>'
                        );
                        continue;
                    }

                    if (self._isPlaceholder(key)) continue;

                    var fileObj = obj[key];
                    var fullFilePath = self._normalizePath(fileObj.name);

                    out.push(
                        '<li class="file-item">',
                            '<i class="icon fa fa-file-text-o fa-fw"></i> ',
                            '<a href="javascript:void(0);" class="load-file" ',
                                'data-id="', String(fileObj.id), '" ',
                                'data-path="', self._escapeHtml(fullFilePath), '" ',
                                'data-type="', self._escapeHtml(fileObj.type), '">',
                                self._escapeHtml(key),
                            '</a>',
                            '<span class="file-context-actions">',
                                '<i class="fa fa-arrows ctx-move-file', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" title="', self._escapeHtml(i18n.move), '"></i>',
                                '<i class="fa fa-pencil ctx-rename-file', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" data-id="', String(fileObj.id), '" data-name="', self._escapeHtml(key), '" title="', self._escapeHtml(i18n.rename), '"></i>',
                                '<i class="fa fa-trash-o ctx-delete-file', disabledClass, '" data-wsp-disabled="', disabledAttrVal, '" data-id="', String(fileObj.id), '" title="', self._escapeHtml(i18n.del), '"></i>',
                            '</span>',
                        '</li>'
                    );
                }

                return out.join('');
            }

            $fileList.html(buildHtml(structure, ''));

            $fileList.find('.folder-item').removeClass('is-open');
            $fileList.find('.folder-title i.icon').removeClass('fa-folder-open').addClass('fa-folder');
            $fileList.find('.folder-content').hide();
        });

        self._syncActiveFolderIndicator();
    },

    bindEvents: function ($) {
        var self = this;
        var $body = jQuery('body');

        $body.off('.wsp_tree');

        $body.on('click.wsp_tree', '.folder-title', function (e) {
            if (jQuery(e.target).closest('.folder-context-actions').length) return;

            var $title = jQuery(this);
            var $item = $title.closest('.folder-item');
            var $content = $title.next('.folder-content');
            var $icon = $title.find('i.icon');

            self._selectFolder($title);

            var isOpen = $item.hasClass('is-open');
            $content.stop(true, true);

            if (isOpen) {
                $item.removeClass('is-open');
                $content.slideUp(150);
                $icon.removeClass('fa-folder-open').addClass('fa-folder');
            } else {
                $item.addClass('is-open');
                $content.slideDown(150);
                $icon.removeClass('fa-folder').addClass('fa-folder-open');
            }
        });

        $body.on('click.wsp_tree', '.folder-context-actions i', function (e) {
            var disabled = (jQuery(this).attr('data-wsp-disabled') === '1');
            if (disabled || self._isWriteLocked()) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                self._notifyLocked();
                return false;
            }

            e.stopPropagation();
            self._selectFolder(jQuery(this).closest('.folder-title'));
        });

        $body.on('click.wsp_tree', '.file-context-actions i', function (e) {
            var disabled = (jQuery(this).attr('data-wsp-disabled') === '1');
            if (disabled || self._isWriteLocked()) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                self._notifyLocked();
                return false;
            }
        });

        $body.on('click.wsp_tree', '#wsp-active-folder-indicator', function (e) {
            e.preventDefault();
            self.clearActiveFolder();
        });
    }
};