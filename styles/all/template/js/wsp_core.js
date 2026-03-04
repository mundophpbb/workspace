/**
 * Mundo phpBB Workspace - Core & State
 * Versão 4.4: SSOT canWriteUI + Permissões Granulares + Lock-aware + i18n puro
 * Centraliza estado global, editor, permissões e motor de internacionalização.
 */
(function (window, $) {
    'use strict';

    // ==========================================================
    // Fallback de segurança: evita quebras fora da página Workspace
    // ==========================================================
    if (typeof window.wspVars === 'undefined' || !window.wspVars) {
        window.wspVars = {
            basePath: '',
            allowedExt: '',
            activeProjectId: 0,
            lang: {}
        };
    }

    // Compat: se o template entregar lang como JSON string, normaliza para objeto
    if (window.wspVars && typeof window.wspVars.lang === 'string') {
        try {
            window.wspVars.lang = JSON.parse(window.wspVars.lang);
        } catch (e) {
            window.wspVars.lang = {};
        }
    }

    var hasOwn = Object.prototype.hasOwnProperty;

    function toBool(v) { return (v === true || v === 1 || v === '1'); }
    function toInt(v)  { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }

    function endsWith(str, suffix) {
        str = String(str || '');
        suffix = String(suffix || '');
        if (!suffix) return true;
        if (typeof str.endsWith === 'function') return str.endsWith(suffix);
        return str.indexOf(suffix, str.length - suffix.length) !== -1;
    }

    function escapeHtml(s) {
        s = String(s == null ? '' : s);
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ==========================================================
    // WSP Global
    // ==========================================================
    window.WSP = {
        activeFileId: null,
        activeProjectId: null,
        activeFolderPath: '',
        originalContent: "",
        editor: null,

        allowedExtensions: [],
        allowedExtensionsMap: null,

        // ====== LOCK / PERMISSÕES (estado vindo do PHP) ======
        canManageAll: false,
        activeProjectLocked: false,
        activeProjectLockedBy: 0,
        activeProjectLockedTime: 0,

        // ====== PERMISSÕES GRANULARES (vindas do PHP) ======
        activeCanEdit: false,
        activeCanUpload: false,
        activeCanRenameMove: false,
        activeCanDelete: false,
        activeCanManage: false,
        activeCanReplace: false,
        activeCanLock: false,

        // GLOBAL (não depende de projeto)
        canPurgeCache: false,

        // cache UI
        _ui: null,

        // throttle resize ACE
        _resizeQueued: false,

        // helpers expostos (opcional para módulos)
        _endsWith: endsWith,
        _escapeHtml: escapeHtml,

        /**
         * Normaliza URLs vindas do PHP para evitar rotas relativas quebradas.
         * Ex.: "app.php/workspace/load" pode virar "/app.php/app.php/workspace/load" dependendo da URL atual.
         */
        normalizeUrl: function (url) {
            url = String(url || '').trim();
            if (!url) return '';
            // remove &amp; (por segurança)
            url = url.replace(/&amp;/g, '&');
            // "./app.php/..." => "/app.php/..."
            url = url.replace(/^\.\//, '/');
            // "app.php/..." => "/app.php/..."
            if (url.charAt(0) !== '/' && url.indexOf('http') !== 0) {
                url = '/' + url;
            }
            // corrige duplicação comum
            url = url.replace(/\/app\.php\/app\.php\//g, '/app.php/');
            return url;
        },

        /**
         * POST JSON robusto: aceita resposta não-JSON e tenta extrair JSON.
         * Mostra erro útil no toast (status + trecho da resposta).
         */
        ajaxPostJson: function (url, data, onOk, onFailKey) {
            var self = this;
            url = self.normalizeUrl(url);
            data = data || {};
            if (typeof data._nocache === 'undefined') data._nocache = Date.now();

            if (!window.jQuery) return;
            window.jQuery.ajax({
                url: url,
                type: 'POST',
                data: data,
                dataType: 'text'
            }).done(function (txt, _status, xhr) {
                var r = null;
                try {
                    r = JSON.parse(txt);
                } catch (e) {
                    // tenta extrair o último {...} (comum quando tem HTML/Warning antes)
                    try {
                        var s = String(txt || '');
                        var a = s.lastIndexOf('{');
                        var b = s.lastIndexOf('}');
                        if (a !== -1 && b !== -1 && b > a) {
                            r = JSON.parse(s.slice(a, b + 1));
                        }
                    } catch (e2) {}

                    if (!r) {
                        var body = String(txt || '');
                        var preview = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
                        if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
                            WSP.ui.notify('AJAX retornou resposta NÃO-JSON (HTTP ' + (xhr ? xhr.status : '') + '): ' + preview, 'error');
                        }
                        return;
                    }
                }

                if (r && r.success) {
                    onOk && onOk(r);
                } else {
                    var msg = (r && r.error) ? r.error : self.lang(onFailKey || 'WSP_ERROR_CRITICAL');
                    if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
                        WSP.ui.notify(msg, 'error');
                    }
                }
            }).fail(function (xhr) {
                var body = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
                var preview = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
                var msg2 = self.lang(onFailKey || 'WSP_ERROR_CRITICAL');
                if (preview) msg2 += ' (HTTP ' + (xhr ? xhr.status : '') + '): ' + preview;
                if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
                    WSP.ui.notify(msg2, 'error');
                }
            });
        },

        /**
         * MOTOR DE TRADUÇÃO (i18n)
         */
        lang: function (key, replacements) {
            var dict = (window.wspVars && window.wspVars.lang) ? window.wspVars.lang : null;
            var str = (dict && typeof dict[key] !== 'undefined') ? dict[key] : '[' + key + ']';

            if (replacements && typeof replacements === 'object') {
                for (var placeholder in replacements) {
                    if (!hasOwn.call(replacements, placeholder)) continue;
                    str = str.split(placeholder).join(replacements[placeholder]);
                }
            }
            return str;
        },

        /**
         * Lê variável com fallback (compatibilidade).
         */
        getVar: function (keys, fallback) {
            var vars = window.wspVars;
            if (!vars) return fallback;

            for (var i = 0; i < keys.length; i++) {
                var k = keys[i];
                if (typeof vars[k] !== 'undefined') {
                    return vars[k];
                }
            }
            return fallback;
        },

        /**
         * Sincroniza lock + permissões (inclui granular quando disponível).
         * Se granular não existir (legacy), cai num fallback compatível.
         */
        syncStateFromVars: function () {
            var vars = window.wspVars || {};

            // Mantém projectId alinhado com o PHP se vier (útil quando módulo chama WSP.canWriteUI cedo)
            if (typeof vars.activeProjectId !== 'undefined') {
                this.activeProjectId = this.normalizeProjectId(vars.activeProjectId);
            }

            // canManageAll
            var canManageAll = (typeof vars.canManageAll !== 'undefined')
                ? vars.canManageAll
                : (typeof vars.WSP_CAN_MANAGE_ALL !== 'undefined' ? vars.WSP_CAN_MANAGE_ALL : 0);
            this.canManageAll = toBool(canManageAll);

            // lock
            var locked = (typeof vars.activeLocked !== 'undefined')
                ? vars.activeLocked
                : (typeof vars.WSP_ACTIVE_LOCKED !== 'undefined' ? vars.WSP_ACTIVE_LOCKED
                    : (typeof vars.activeProjectLocked !== 'undefined' ? vars.activeProjectLocked : 0));
            this.activeProjectLocked = toBool(locked);

            var lockedBy = (typeof vars.activeLockedBy !== 'undefined')
                ? vars.activeLockedBy
                : (typeof vars.WSP_ACTIVE_LOCKED_BY !== 'undefined' ? vars.WSP_ACTIVE_LOCKED_BY
                    : (typeof vars.activeProjectLockedBy !== 'undefined' ? vars.activeProjectLockedBy : 0));
            this.activeProjectLockedBy = toInt(lockedBy);

            var lockedTime = (typeof vars.activeLockedTime !== 'undefined')
                ? vars.activeLockedTime
                : (typeof vars.WSP_ACTIVE_LOCKED_TIME !== 'undefined' ? vars.WSP_ACTIVE_LOCKED_TIME
                    : (typeof vars.activeProjectLockedTime !== 'undefined' ? vars.activeProjectLockedTime : 0));
            this.activeProjectLockedTime = toInt(lockedTime);

            // granular: se qualquer chave existir, usa tudo
            var hasGranular =
                (typeof vars.activeCanEdit !== 'undefined') ||
                (typeof vars.activeCanUpload !== 'undefined') ||
                (typeof vars.activeCanRenameMove !== 'undefined') ||
                (typeof vars.activeCanDelete !== 'undefined') ||
                (typeof vars.activeCanManage !== 'undefined') ||
                (typeof vars.activeCanReplace !== 'undefined') ||
                (typeof vars.activeCanLock !== 'undefined') ||
                (typeof vars.canPurgeCache !== 'undefined');

            if (hasGranular) {
                this.activeCanEdit       = toBool(vars.activeCanEdit);
                this.activeCanUpload     = toBool(vars.activeCanUpload);
                this.activeCanRenameMove = toBool(vars.activeCanRenameMove);
                this.activeCanDelete     = toBool(vars.activeCanDelete);
                this.activeCanManage     = toBool(vars.activeCanManage);
                this.activeCanReplace    = toBool(vars.activeCanReplace);
                this.activeCanLock       = toBool(vars.activeCanLock);

                this.canPurgeCache       = toBool(vars.canPurgeCache);
                return;
            }

            // fallback legacy (sem granular):
            // permite tudo se tem projeto e (não lockado ou é admin)
            var legacyWrite = (!!this.activeProjectId && (!this.activeProjectLocked || this.canManageAll));

            this.activeCanEdit       = legacyWrite;
            this.activeCanUpload     = legacyWrite;
            this.activeCanRenameMove = legacyWrite;
            this.activeCanDelete     = legacyWrite;
            this.activeCanManage     = legacyWrite;
            this.activeCanReplace    = legacyWrite;

            this.activeCanLock  = !!this.canManageAll;
            this.canPurgeCache  = !!this.canManageAll;
        },

        /**
         * Editor (ACE/Save) depende de EDIT.
         * - admin global sempre pode
         * - lock bloqueia não-admin
         * - senão: activeCanEdit
         */
        canEditActiveProjectUI: function () {
            if (!this.activeProjectId) return false;

            this.syncStateFromVars();

            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;

            return !!this.activeCanEdit;
        },

        /**
         * ✅ SSOT: "pode escrever na UI?" (qualquer ação de escrita)
         * - admin global => true
         * - lock => false (para não-admin)
         * - senão => qualquer granular true
         */
        canWriteUI: function () {
            if (!this.activeProjectId) return false;

            this.syncStateFromVars();

            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;

            return !!(
                this.activeCanEdit ||
                this.activeCanUpload ||
                this.activeCanRenameMove ||
                this.activeCanDelete ||
                this.activeCanManage ||
                this.activeCanReplace ||
                this.activeCanLock
            );
        },

        // Helpers granulares (recomendado para módulos)
        canUploadUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;
            return !!this.activeCanUpload;
        },

        canRenameMoveUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;
            return !!this.activeCanRenameMove;
        },

        canDeleteUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;
            return !!this.activeCanDelete;
        },

        canManageProjectUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;
            return !!this.activeCanManage;
        },

        canReplaceUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            if (this.canManageAll) return true;
            if (this.activeProjectLocked) return false;
            return !!this.activeCanReplace;
        },

        canLockUI: function () {
            if (!this.activeProjectId) return false;
            this.syncStateFromVars();
            return !!(this.canManageAll || this.activeCanLock);
        },

        canPurgeCacheUI: function () {
            this.syncStateFromVars();
            return !!this.canPurgeCache;
        },

        /**
         * Notificação padrão de lock (centralizada)
         */
        notifyLocked: function () {
            var msg = this.lang('WSP_PROJECT_LOCKED_MSG');
            if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') {
                msg = this.lang('WSP_ERR_PROJECT_LOCKED');
            }
            if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
                WSP.ui.notify(msg || 'Projeto trancado.', "warning");
            }
        },

        // MAPA UNIVERSAL DE LINGUAGENS (Extensão -> Modo ACE)
        modes: {
            'php': 'ace/mode/php',
            'js': 'ace/mode/javascript',
            'ts': 'ace/mode/typescript',
            'css': 'ace/mode/css',
            'scss': 'ace/mode/scss',
            'sass': 'ace/mode/sass',
            'less': 'ace/mode/less',
            'html': 'ace/mode/html',
            'htm': 'ace/mode/html',
            'twig': 'ace/mode/twig',
            'svg': 'ace/mode/svg',
            'json': 'ace/mode/json',
            'xml': 'ace/mode/xml',
            'yml': 'ace/mode/yaml',
            'yaml': 'ace/mode/yaml',
            'sql': 'ace/mode/sql',
            'md': 'ace/mode/markdown',
            'csv': 'ace/mode/text',
            'c': 'ace/mode/c_cpp',
            'cpp': 'ace/mode/c_cpp',
            'h': 'ace/mode/c_cpp',
            'hpp': 'ace/mode/c_cpp',
            'cs': 'ace/mode/csharp',
            'java': 'ace/mode/java',
            'py': 'ace/mode/python',
            'rb': 'ace/mode/ruby',
            'lua': 'ace/mode/lua',
            'go': 'ace/mode/golang',
            'rs': 'ace/mode/rust',
            'kt': 'ace/mode/kotlin',
            'swift': 'ace/mode/swift',
            'dart': 'ace/mode/dart',
            'pl': 'ace/mode/perl',
            'r': 'ace/mode/r',
            'scala': 'ace/mode/scala',
            'sh': 'ace/mode/sh',
            'bash': 'ace/mode/sh',
            'ini': 'ace/mode/ini',
            'htaccess': 'ace/mode/apache_conf',
            'conf': 'ace/mode/text',
            'bat': 'ace/mode/batchfile',
            'ps1': 'ace/mode/powershell',
            'dockerfile': 'ace/mode/dockerfile',
            'makefile': 'ace/mode/makefile',
            'txt': 'ace/mode/text',
            'log': 'ace/mode/text',
            'diff': 'ace/mode/diff'
        },

        /**
         * Inicializa o ACE editor
         */
        initEditor: function () {
            if (typeof window.ace === 'undefined') return false;
            if (!document.getElementById('editor')) return false;

            if (this.editor) return true;

            if (window.wspVars.basePath) {
                ace.config.set("basePath", window.wspVars.basePath);
                ace.config.set("modePath", window.wspVars.basePath);
                ace.config.set("themePath", window.wspVars.basePath);
            }

            if (typeof ace.require !== 'undefined') {
                try {
                    ace.require("ace/ext/language_tools");
                } catch (e) {
                    if (window.console && console.warn) console.warn("WSP: Language tools extension not found.");
                }
            }

            this.editor = ace.edit("editor");
            this.editor.setTheme("ace/theme/monokai");

            this.editor.setOptions({
                fontSize: "14px",
                fontFamily: "Consolas, 'Courier New', monospace",
                showPrintMargin: false,
                displayIndentGuides: true,
                highlightActiveLine: true,
                behavioursEnabled: true,
                wrap: true,
                tabSize: 4,
                useSoftTabs: true,
                scrollPastEnd: 0.5,
                readOnly: true,
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                enableSnippets: true
            });

            this.editor.session.setUseWorker(false);

            // whitelist dinâmica
            this.allowedExtensions = this.parseAllowedExtensions(window.wspVars.allowedExt);
            this.allowedExtensionsMap = this.buildAllowedExtensionsMap(this.allowedExtensions);

            // Projeto ativo
            this.activeProjectId = this.normalizeProjectId(window.wspVars.activeProjectId);

            // Lock/perms
            this.syncStateFromVars();

            // resize com throttle
            var self = this;
            window.addEventListener('resize', function () {
                self.queueEditorResize();
            });

            this.updateUIState();
            return true;
        },

        queueEditorResize: function () {
            var self = this;
            if (!self.editor) return;
            if (self._resizeQueued) return;

            self._resizeQueued = true;
            (window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); })(function () {
                self._resizeQueued = false;
                if (self.editor) self.editor.resize();
            });
        },

        normalizeProjectId: function (value) {
            if (!value || value === '0' || value === 0) return null;
            return parseInt(value, 10);
        },

        parseAllowedExtensions: function (str) {
            if (!str || typeof str !== 'string') return [];
            return str.split(',')
                .map(function (s) { return s.trim().toLowerCase(); })
                .filter(function (s) { return s.length > 0; });
        },

        buildAllowedExtensionsMap: function (arr) {
            var map = {};
            if (!arr || !arr.length) return map;
            for (var i = 0; i < arr.length; i++) map[arr[i]] = 1;
            return map;
        },

        /**
         * Validação de extensão no frontend (whitelist)
         */
        isExtensionAllowed: function (filename) {
            if (!filename) return false;

            var name = String(filename).trim();
            if (!name) return false;

            var lowerName = name.toLowerCase();

            // Técnicos sempre permitidos
            if (lowerName === '.placeholder' || lowerName === '.htaccess' || lowerName === 'changelog.txt' ||
                name === 'Dockerfile' || name === 'Makefile' || lowerName === 'dockerfile' || lowerName === 'makefile') {
                return true;
            }

            var parts = name.split('.');
            if (parts.length === 1) return true; // README, LICENSE, etc.

            var ext = parts.pop().toLowerCase();
            if (this.allowedExtensionsMap) return !!this.allowedExtensionsMap[ext];
            return this.allowedExtensions.indexOf(ext) !== -1;
        },

        /**
         * Cache de seletores UI (evita re-query)
         */
        getUI: function () {
            if (this._ui) return this._ui;

            // Se jQuery não estiver disponível, evita quebrar
            if (typeof $ === 'undefined') {
                this._ui = {
                    $toolbarActions: null,
                    $saveBtn: null,
                    $bbcodeBtn: null,
                    $currentFileLabel: null
                };
                return this._ui;
            }

            this._ui = {
                $toolbarActions: $('.actions-active'),
                $saveBtn: $('#save-file'),
                $bbcodeBtn: $('#copy-bbcode'),
                $currentFileLabel: $('#current-file')
            };
            return this._ui;
        },

        /**
         * Toolbar + Editor state
         * - Editor usa canEditActiveProjectUI()
         */
        updateUIState: function () {
            this.syncStateFromVars();

            var hasProject = !!this.activeProjectId;
            var hasFile = !!this.activeFileId;

            var ui = this.getUI();
            var $toolbarActions = ui.$toolbarActions;
            var $saveBtn = ui.$saveBtn;
            var $bbcodeBtn = ui.$bbcodeBtn;
            var $currentFileLabel = ui.$currentFileLabel;

            // Se jQuery não existe, só controla o ACE com segurança
            if (typeof $toolbarActions === 'undefined' || $toolbarActions === null) {
                if (!hasProject && this.editor) {
                    this.editor.setReadOnly(true);
                    this.editor.setValue(this.lang('WSP_WELCOME_MSG'), -1);
                }
                if (this.editor && hasProject) {
                    this.editor.setReadOnly(!this.canEditActiveProjectUI());
                }
                return;
            }

            if (!hasProject) {
                $toolbarActions.removeClass('is-enabled').addClass('is-disabled');

                if (this.editor) {
                    this.editor.setReadOnly(true);
                    this.editor.setValue(this.lang('WSP_WELCOME_MSG'), -1);
                }

                if ($saveBtn) $saveBtn.hide();
                if ($bbcodeBtn) $bbcodeBtn.hide();
                if ($currentFileLabel) $currentFileLabel.text(this.lang('WSP_SELECT_FILE'));
                return;
            }

            $toolbarActions.removeClass('is-disabled').addClass('is-enabled');

            var canEditUI = this.canEditActiveProjectUI(); // ✅ editor depende de edit

            if (!hasFile) {
                if (this.editor) {
                    this.editor.setReadOnly(true);

                    if (this.activeProjectLocked && !this.canManageAll) {
                        var msg = this.lang('WSP_PROJECT_LOCKED_MSG');
                        if (msg === '[WSP_PROJECT_LOCKED_MSG]') msg = this.lang('WSP_ERR_PROJECT_LOCKED');
                        this.editor.setValue(msg, -1);
                    } else {
                        this.editor.setValue(this.lang('WSP_EDITOR_START_MSG'), -1);
                    }
                }

                if ($saveBtn) $saveBtn.hide();
                if ($bbcodeBtn) $bbcodeBtn.hide();
                return;
            }

            if (this.editor) {
                this.editor.setReadOnly(!canEditUI);
            }

            if (!canEditUI) {
                if ($saveBtn) $saveBtn.hide();
            }
            // bbcode não é forçado aqui (mantém sua lógica atual)
        }
    };

})(window, window.jQuery);