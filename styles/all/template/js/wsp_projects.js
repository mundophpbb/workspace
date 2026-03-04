/**
 * Mundo phpBB Workspace - Project Operations
 * Versão 4.5 (SSOT + ACL granular + lock-aware + modal open-project respeita CAN_OPEN)
 */
WSP.projects = {
    // Estado interno para ações do modal de mover arquivo
    _pendingMove: null,

    // -----------------------------
    // Helpers
    // -----------------------------
    _normalizePath: function (p) {
        if (!p) return '';
        return String(p).trim().replace(/\\/g, '/').replace(/^\/+/, '').replace(/\/+$/, '');
    },

    _joinPath: function (base, name) {
        base = this._normalizePath(base);
        name = this._normalizePath(name);
        return base ? base + '/' + name : name;
    },

    _endsWith: function (str, suffix) {
        if (window.WSP && typeof WSP._endsWith === 'function') {
            return WSP._endsWith(str, suffix);
        }
        str = String(str || '');
        suffix = String(suffix || '');
        if (!suffix) return true;
        if (typeof str.endsWith === 'function') return str.endsWith(suffix);
        return str.indexOf(suffix, str.length - suffix.length) !== -1;
    },

    _escape: function (s) {
        if (window.WSP && typeof WSP._escapeHtml === 'function') {
            return WSP._escapeHtml(s);
        }
        s = String(s == null ? '' : s);
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    /**
     * Notifica LOCK (i18n)
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

    /**
     * Notifica PERMISSÃO NEGADA (i18n)
     */
    _notifyDenied: function () {
        var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PERMISSION') : '';
        if (!msg || msg === '[WSP_ERR_PERMISSION]') msg = 'Sem permissão.';
        if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
            WSP.ui.notify(msg, 'error');
        }
    },

    /**
     * ✅ Guard granular por ação (SSOT)
     * cap: edit | upload | rename_move | delete | manage | replace | lock | write
     */
    _can: function (cap) {
        if (!window.WSP || !WSP.activeProjectId) return false;

        // Preferência: helpers do core
        if (cap === 'edit' && typeof WSP.canEditActiveProjectUI === 'function') return !!WSP.canEditActiveProjectUI();
        if (cap === 'upload' && typeof WSP.canUploadUI === 'function') return !!WSP.canUploadUI();
        if (cap === 'rename_move' && typeof WSP.canRenameMoveUI === 'function') return !!WSP.canRenameMoveUI();
        if (cap === 'delete' && typeof WSP.canDeleteUI === 'function') return !!WSP.canDeleteUI();
        if (cap === 'manage' && typeof WSP.canManageProjectUI === 'function') return !!WSP.canManageProjectUI();
        if (cap === 'replace' && typeof WSP.canReplaceUI === 'function') return !!WSP.canReplaceUI();
        if (cap === 'lock' && typeof WSP.canLockUI === 'function') return !!WSP.canLockUI();

        // write genérico
        if (typeof WSP.canWriteUI === 'function') return !!WSP.canWriteUI();

        // fallback legacy
        if (WSP.activeProjectLocked && !WSP.canManageAll) return false;
        return true;
    },

    /**
     * Compat: algumas rotas antigas chamam _canWrite()
     */
    _canWrite: function () {
        return this._can('write');
    },

    _isChangelogOpen: function () {
        var $a = jQuery('.active-file .load-file');
        if (!$a.length) return false;

        var p = (String($a.attr('data-path') || '')).trim().toLowerCase();
        if (p) {
            return (p === 'changelog.txt' || this._endsWith(p, '/changelog.txt'));
        }

        var t = (String($a.text() || '')).trim().replace(/^\* /, '').toLowerCase();
        return t === 'changelog.txt';
    },

    _reloadActiveIfChangelog: function () {
        if (!this._isChangelogOpen()) return;
        var $a = jQuery('.active-file .load-file');
        if ($a.length) $a.trigger('click');
    },

    _getLockUrl: function (type) {
        if (!window.wspVars) return '';
        if (type === 'lock') {
            return window.wspVars.lockProjectUrl ||
                window.wspVars.lockUrl ||
                window.wspVars.U_WORKSPACE_LOCK_PROJECT ||
                '';
        }
        return window.wspVars.unlockProjectUrl ||
            window.wspVars.unlockUrl ||
            window.wspVars.U_WORKSPACE_UNLOCK_PROJECT ||
            '';
    },

    _postJson: function ($, url, data, onOk, onErrKey) {
        url = (window.WSP && typeof WSP.normalizeUrl === 'function') ? WSP.normalizeUrl(url) : url;

        if (!url) {
            if (WSP && WSP.ui) WSP.ui.notify(WSP.lang('WSP_ERR_INVALID_DATA'), "error");
            return;
        }

        // Preferir helper robusto do core (evita "WSP_ERROR_CRITICAL" cego)
        if (window.WSP && typeof WSP.ajaxPostJson === 'function') {
            WSP.ajaxPostJson(url, data, onOk, onErrKey || 'WSP_ERROR_CRITICAL');
            return;
        }

        data = data || {};
        if (typeof data._nocache === 'undefined') data._nocache = Date.now();

        $.post(url, data, function (r) {
            if (r && r.success) {
                onOk && onOk(r);
            } else {
                WSP.ui.notify((r && r.error) ? r.error : WSP.lang(onErrKey || 'WSP_ERROR_CRITICAL'), "error");
            }
        }, 'json').fail(function () {
            WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), "error");
        });
    },

    /**
     * Toggle imediato do toolbar (lock/unlock) após resposta do backend.
     * Preferência: função global do template (WSP_toggleLockButtons).
     * Fallback: toggle manual.
     */
    _toggleLockButtonsUI: function (isLocked) {
        if (typeof window.WSP_toggleLockButtons === 'function') {
            window.WSP_toggleLockButtons();
            return;
        }

        var lockBtn = document.getElementById('lock-project');
        var unlockBtn = document.getElementById('unlock-project');
        if (!lockBtn || !unlockBtn) return;

        lockBtn.style.display = isLocked ? 'none' : '';
        unlockBtn.style.display = isLocked ? '' : 'none';
    },

    _setProjectLock: function ($, wantLock) {
        if (!WSP.activeProjectId) return;

        // ✅ lock/unlock é permissão própria
        if (!this._can('lock')) {
            return this._notifyDenied();
        }

        var url = this._getLockUrl(wantLock ? 'lock' : 'unlock');
        if (!url) {
            WSP.ui.notify(WSP.lang('WSP_ERR_INVALID_DATA'), "error");
            return;
        }

        WSP.ui.notify(WSP.lang('WSP_LOADING'), "info");

        var self = this;

        this._postJson($, url, { project_id: WSP.activeProjectId }, function (r) {
            var isLocked = !!(r.is_locked === 1 || r.is_locked === '1' || r.is_locked === true);

            // Atualiza estado global
            WSP.activeProjectLocked = isLocked;
            WSP.activeProjectLockedBy = parseInt(r.locked_by, 10) || 0;
            WSP.activeProjectLockedTime = parseInt(r.locked_time, 10) || 0;

            // Atualiza wspVars (SSOT do frontend)
            window.wspVars = window.wspVars || {};
            window.wspVars.activeLocked = isLocked ? 1 : 0;
            window.wspVars.activeLockedBy = WSP.activeProjectLockedBy;
            window.wspVars.activeLockedTime = WSP.activeProjectLockedTime;

            // Core UI + árvore (se existir)
            if (typeof WSP.updateUIState === 'function') WSP.updateUIState();
            if (WSP.ui && typeof WSP.ui.applyLockUIState === 'function') WSP.ui.applyLockUIState();

            // ✅ Toggle imediato lock/unlock no toolbar
            self._toggleLockButtonsUI(isLocked);

            // ✅ Evento global
            if (window.jQuery) {
                jQuery(document).trigger('wsp:lockChanged', [{
                    is_locked: isLocked ? 1 : 0,
                    locked_by: WSP.activeProjectLockedBy,
                    locked_time: WSP.activeProjectLockedTime
                }]);
            }

            WSP.ui.notify(
                (isLocked ? (WSP.lang('WSP_LOG_PROJECT_LOCKED') || WSP.lang('WSP_SAVED'))
                          : (WSP.lang('WSP_LOG_PROJECT_UNLOCKED') || WSP.lang('WSP_SAVED'))),
                "success"
            );
        }, 'WSP_ERROR_CRITICAL');
    },

    _buildFolderTreeFromDom: function ($) {
        var folderTree = {};

        $('.folder-title').each(function () {
            var path = $(this).attr('data-path');
            if (!path) return;

            var parts = String(path).split('/');
            var current = folderTree;

            for (var i = 0; i < parts.length; i++) {
                var part = parts[i];
                if (!current[part]) {
                    current[part] = { _path: parts.slice(0, i + 1).join('/'), _children: {} };
                }
                current = current[part]._children;
            }
        });

        return folderTree;
    },

    _renderFolderTreeModalHtml: function (folderTree) {
        var self = this;

        function build(obj) {
            var keys = Object.keys(obj).sort();
            if (!keys.length) return '';

            var out = ['<ul class="modal-tree-ul">'];
            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                out.push(
                    '<li>',
                        '<div class="folder-option" data-path="', self._escape(obj[key]._path), '">',
                            '<i class="fa fa-folder" style="color:var(--wsp-folder); margin-right:6px;"></i>',
                            '<span style="font-size:12px; color:#eee;">', self._escape(key), '</span>',
                        '</div>',
                        build(obj[key]._children),
                    '</li>'
                );
            }
            out.push('</ul>');
            return out.join('');
        }

        var out = ['<div class="modal-folder-tree-selector">'];
        out.push(
            '<div class="folder-option is-root" data-path="">',
                '<i class="fa fa-home" style="color:var(--wsp-accent); margin-right:8px;"></i>',
                '<span style="font-weight:bold; color:#fff;">', self._escape(WSP.lang('WSP_LABEL_MOVE_ROOT')), '</span>',
            '</div>'
        );
        out.push(build(folderTree));
        out.push('</div>');
        return out.join('');
    },

    // -----------------------------
    // Bind events
    // -----------------------------
    bindEvents: function ($) {
        var self = this;
        var $body = $('body');
        var $modalBody = $('#wsp-modal-body-custom');

        // higiene
        $body.off('.wsp_projects');

        // =====================================================
        // 0) LOCK / UNLOCK
        // =====================================================
        $body.on('click.wsp_projects', '#lock-project, .lock-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            self._setProjectLock($, true);
        });

        $body.on('click.wsp_projects', '#unlock-project, .unlock-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;
            self._setProjectLock($, false);
        });

        $body.on('click.wsp_projects', '.toggle-project-lock', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            var wantLock = $(this).attr('data-lock');
            if (wantLock === '1' || wantLock === 1) self._setProjectLock($, true);
            else if (wantLock === '0' || wantLock === 0) self._setProjectLock($, false);
            else self._setProjectLock($, !WSP.activeProjectLocked);
        });

        // =====================================================
        // 1) PROJETOS (TOOLBAR)
        // =====================================================
        $body.on('click.wsp_projects', '#add-project', function (e) {
            e.preventDefault();

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_PROJECT_NAME'), '', function (name) {
                if (!name) return;

                self._postJson($, window.wspVars.addUrl, { name: name }, function (r) {
                    window.location.href = window.location.href.split('?')[0] + '?p=' + r.project_id;
                }, 'WSP_ERROR_PROJECT_CREATE');
            });
        });

        // Abrir projeto (switcher modal) — respeita CAN_OPEN/LOCKED via data-attrs
        $body.on('click.wsp_projects', '#open-project', function (e) {
            e.preventDefault();

            var $projects = $('.project-group');
            if ($projects.length === 0) {
                return WSP.ui.notify(WSP.lang('WSP_PROJECT_NOT_FOUND'), "warning");
            }

            var out = ['<div class="project-switcher-box">'];

            $projects.each(function () {
                var $p = $(this);

                var pid = $p.data('project-id');
                var pname = $p.find('.project-title-simple').text().trim();
                var isActive = (String(pid) === String(WSP.activeProjectId));

                // ✅ novos data-attrs vindos do template/main.php
                var canOpen = String($p.data('can-open') || $p.attr('data-can-open') || '1') === '1';
                var isLocked = String($p.data('locked') || $p.attr('data-locked') || '0') === '1';

                var disabledClass = (!canOpen ? ' disabled' : '');
                var hint = '';

                if (!canOpen) {
                    hint = isLocked ? (WSP.lang('WSP_ERR_PROJECT_LOCKED') || '') : (WSP.lang('WSP_ERR_PERMISSION') || '');
                } else {
                    hint = isActive ? WSP.lang('WSP_LABEL_ACTIVE_PROJECT') : WSP.lang('WSP_LABEL_CLICK_OPEN');
                }

                out.push(
                    '<div class="switcher-item ', (isActive ? 'active' : ''), disabledClass,
                        '" data-pid="', String(pid),
                        '" data-can-open="', (canOpen ? '1' : '0'),
                        '" data-locked="', (isLocked ? '1' : '0'),
                    '">',
                        '<i class="fa ', (isActive ? 'fa-folder-open' : 'fa-folder'),
                            '" style="color:var(--wsp-folder); font-size:18px;"></i>',
                        '<div class="switcher-info">',
                            '<span style="color:#fff; font-weight:bold; font-size:14px;">', self._escape(pname), '</span>',
                            '<small style="color:', (!canOpen ? '#ff6b6b' : (isActive ? 'var(--wsp-accent)' : '#888')), '">',
                                self._escape(hint),
                            '</small>',
                        '</div>',
                    '</div>'
                );
            });

            out.push('</div>');

            WSP.ui.prompt(WSP.lang('WSP_MODAL_TITLE_SELECT'), 'LIST_MODE');
            $modalBody.html(out.join('')).show();
        });

        // Clique no item do switcher — bloqueia se não puder abrir
        $body.on('click.wsp_projects', '.project-switcher-box .switcher-item', function (e) {
            e.preventDefault();

            var $it = $(this);
            var canOpen = String($it.attr('data-can-open') || '1') === '1';
            var isLocked = String($it.attr('data-locked') || '0') === '1';

            if (!canOpen) {
                if (isLocked) return self._notifyLocked();
                return self._notifyDenied();
            }

            var pid = $it.attr('data-pid');
            if (!pid) return;
            window.location.href = '?p=' + pid;
        });

        // Renomear projeto ativo (✅ manage)
        $body.on('click.wsp_projects', '.rename-active-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('manage')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var oldName = $('.project-group.active-focus .project-title-simple').text().trim();

            WSP.ui.prompt(WSP.lang('WSP_RENAME_PROJECT_TITLE'), oldName, function (newName) {
                if (!newName || newName === oldName) return;

                self._postJson($, window.wspVars.renameProjectUrl, {
                    project_id: WSP.activeProjectId,
                    new_name: newName
                }, function () {
                    window.location.reload();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Excluir projeto ativo (✅ manage)
        $body.on('click.wsp_projects', '.delete-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('manage')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_DELETE_PROJ'), function () {
                self._postJson($, window.wspVars.deleteUrl, { project_id: WSP.activeProjectId }, function () {
                    window.location.href = window.location.href.split('?')[0];
                }, 'WSP_ERR_DELETE_FAILED');
            });
        });

        // =====================================================
        // 2) ARQUIVOS (SIDEBAR / ÁRVORE)
        // =====================================================

        // Mover arquivo (✅ rename_move)
        $body.on('click.wsp_projects', '.ctx-move-file', function (e) {
            e.stopPropagation();

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var $link = $(this).closest('.file-item').find('.load-file');
            var id = $(this).data('id') || $link.data('id');
            var fullPath = $link.attr('data-path') || $link.text();
            var fileName = String(fullPath).split('/').pop();

            self._pendingMove = { file_id: id, file_name: fileName };

            var folderTree = self._buildFolderTreeFromDom($);
            var modalHtml = self._renderFolderTreeModalHtml(folderTree);

            WSP.ui.prompt(WSP.lang('WSP_MODAL_TITLE_MOVE'), 'LIST_MODE');
            $modalBody.html(modalHtml).show();
        });

        // Clique em pasta no modal de mover (✅ rename_move)
        $body.on('click.wsp_projects', '#wsp-modal-body-custom .folder-option', function () {
            if (!self._pendingMove) return;

            if (!self._can('rename_move')) {
                self._pendingMove = null;
                $('#wsp-custom-modal').hide();
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var targetDir = $(this).attr('data-path') || '';
            var fileName = self._pendingMove.file_name;
            var id = self._pendingMove.file_id;

            var newFullPath = targetDir
                ? (String(targetDir).replace(/\/$/, '') + '/' + fileName)
                : fileName;

            $('#wsp-custom-modal').hide();
            WSP.ui.notify(WSP.lang('WSP_LOADING'), "info");

            self._postJson($, window.wspVars.moveFileUrl, { file_id: id, new_path: newFullPath }, function () {
                self._pendingMove = null;
                WSP.ui.notify(WSP.lang('WSP_SAVED'));
                WSP.ui.seamlessRefresh();
            }, 'WSP_ERR_INVALID_DATA');
        });

        // Renomear arquivo (✅ rename_move)
        $body.on('click.wsp_projects', '.ctx-rename-file', function (e) {
            e.stopPropagation();

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var $link = $(this).closest('.file-item').find('.load-file');
            var id = $link.data('id');
            var fullPath = $link.attr('data-path') || $link.text();

            var parts = String(fullPath).split('/');
            var oldFileName = parts.pop();
            var folderPrefix = parts.join('/');

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_RENAME_FILE'), oldFileName, function (newName) {
                var cleanName = String(newName || '').replace(/[\/\\?%*:|"<>]/g, '').trim();
                if (!cleanName || cleanName === oldFileName) return;

                var newFullPath = folderPrefix ? (folderPrefix + '/' + cleanName) : cleanName;

                self._postJson($, window.wspVars.renameUrl, { file_id: id, new_name: newFullPath }, function () {
                    WSP.ui.notify(WSP.lang('WSP_SAVED'));
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Excluir arquivo (✅ delete)
        $body.on('click.wsp_projects', '.ctx-delete-file', function (e) {
            e.stopPropagation();

            if (!self._can('delete')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var id = $(this).data('id') || $(this).closest('.file-item').find('.load-file').data('id');

            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_DELETE_FILE'), function () {
                self._postJson($, window.wspVars.deleteFileUrl, { file_id: id }, function () {
                    if (String(WSP.activeFileId) === String(id)) {
                        WSP.activeFileId = null;
                        if (WSP.editor && typeof WSP.editor.setValue === 'function') {
                            WSP.editor.setValue(WSP.lang('WSP_WELCOME_MSG'), -1);
                            WSP.editor.setReadOnly(true);
                        }
                    }
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_DELETE_FAILED');
            });
        });

        // =====================================================
        // 3) PASTAS (CONTEXTO)
        // =====================================================
        // Renomear pasta (✅ rename_move)
        $body.on('click.wsp_projects', '.ctx-rename-folder', function (e) {
            e.stopPropagation();

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var oldPath = self._normalizePath(WSP.activeFolderPath);
            if (!oldPath) return;

            var parts = oldPath.split('/');
            var oldName = parts.pop();
            var parentPath = parts.join('/');

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_RENAME_FOLDER'), oldName, function (newName) {
                var clean = String(newName || '').replace(/[\/\\?%*:|"<>]/g, '').trim();
                if (!clean || clean === oldName) return;

                var newPath = parentPath ? (parentPath + '/' + clean) : clean;

                self._postJson($, window.wspVars.renameFolderUrl, {
                    project_id: WSP.activeProjectId,
                    old_path: oldPath,
                    new_path: newPath
                }, function () {
                    WSP.activeFolderPath = newPath;
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Criar arquivo (backend exige rename_move no seu file_controller add_file)
        $body.on('click.wsp_projects', '.ctx-add-file', function (e) {
            e.stopPropagation();

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var title = WSP.lang('WSP_PROMPT_NEW_FILE', { '%s': WSP.activeFolderPath });

            WSP.ui.prompt(title, '', function (name) {
                if (!name) return;

                var full = self._joinPath(WSP.activeFolderPath, name);

                self._postJson($, window.wspVars.addFileUrl, {
                    project_id: WSP.activeProjectId,
                    name: full
                }, function (r) {
                    WSP.ui.seamlessRefresh(r.file_id);
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Criar pasta (via .placeholder) (backend exige rename_move no seu file_controller add_file)
        $body.on('click.wsp_projects', '.ctx-add-folder', function (e) {
            e.stopPropagation();

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var title = WSP.lang('WSP_PROMPT_NEW_FOLDER', { '%s': WSP.activeFolderPath });

            WSP.ui.prompt(title, '', function (name) {
                if (!name) return;

                var full = self._joinPath(WSP.activeFolderPath, name) + '/.placeholder';

                self._postJson($, window.wspVars.addFileUrl, {
                    project_id: WSP.activeProjectId,
                    name: full
                }, function () {
                    WSP.ui.notify(WSP.lang('WSP_READY'));
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Excluir pasta (✅ delete)
        $body.on('click.wsp_projects', '.ctx-delete-folder', function (e) {
            e.stopPropagation();

            if (!self._can('delete')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            var path = self._normalizePath(WSP.activeFolderPath);
            var msg = WSP.lang('WSP_CONFIRM_DELETE_FOLDER', { '%s': path });

            WSP.ui.confirm(msg, function () {
                self._postJson($, window.wspVars.deleteFolderUrl, {
                    project_id: WSP.activeProjectId,
                    path: path
                }, function () {
                    WSP.activeFolderPath = '';
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // =====================================================
        // 4) AÇÕES NA RAIZ
        // =====================================================
        // Novo arquivo na raiz (backend exige rename_move)
        $body.on('click.wsp_projects', '.add-file-to-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_ROOT_FILE'), '', function (name) {
                if (!name) return;

                self._postJson($, window.wspVars.addFileUrl, {
                    project_id: WSP.activeProjectId,
                    name: name
                }, function (r) {
                    WSP.ui.seamlessRefresh(r.file_id);
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // Nova pasta na raiz (backend exige rename_move)
        $body.on('click.wsp_projects', '.add-folder-to-project', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('rename_move')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            WSP.ui.prompt(WSP.lang('WSP_PROMPT_ROOT_FOLDER'), '', function (name) {
                if (!name) return;

                var placeholder = name + '/.placeholder';

                self._postJson($, window.wspVars.addFileUrl, {
                    project_id: WSP.activeProjectId,
                    name: placeholder
                }, function () {
                    WSP.ui.notify(WSP.lang('WSP_READY'));
                    WSP.ui.seamlessRefresh();
                }, 'WSP_ERR_INVALID_DATA');
            });
        });

        // =====================================================
        // 5) CHANGELOG (✅ edit)
        // =====================================================
        $body.on('click.wsp_projects', '.generate-project-changelog', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('edit')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            self._postJson($, window.wspVars.changelogUrl, { project_id: WSP.activeProjectId }, function () {
                WSP.ui.notify(WSP.lang('WSP_NOTIFY_CHANGELOG_OK'), "success");
                self._reloadActiveIfChangelog();
            }, 'WSP_ERROR_CRITICAL');
        });

        $body.on('click.wsp_projects', '.clear-project-changelog', function (e) {
            e.preventDefault();
            if (!WSP.activeProjectId) return;

            if (!self._can('edit')) {
                if (WSP.activeProjectLocked && !WSP.canManageAll) return self._notifyLocked();
                return self._notifyDenied();
            }

            WSP.ui.confirm(WSP.lang('WSP_CONFIRM_CLEAR_CHANGE'), function () {
                self._postJson($, window.wspVars.clearChangelogUrl, { project_id: WSP.activeProjectId }, function () {
                    WSP.ui.notify(WSP.lang('WSP_HISTORY_CLEANED'), "info");
                    self._reloadActiveIfChangelog();
                }, 'WSP_ERROR_CRITICAL');
            });
        });
    }
};