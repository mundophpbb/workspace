/**
 * Workspace module.
 */
(function (window, $) {
	'use strict';

	// ==========================================================
	// Workspace UI logic.
	// ==========================================================
	if (typeof window.wspVars === 'undefined' || !window.wspVars) {
		window.wspVars = {
			basePath: '',
			allowedExt: '',
			activeProjectId: 0,
			lang: {}
		};
	}

	// Workspace UI logic.
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

		// Workspace UI logic.
		canManageAll: false,
		activeProjectLocked: false,
		activeProjectLockedBy: 0,
		activeProjectLockedTime: 0,

		// Workspace UI logic.
		activeCanEdit: false,
		activeCanUpload: false,
		activeCanRenameMove: false,
		activeCanDelete: false,
		activeCanManage: false,
		activeCanReplace: false,
		activeCanLock: false,

		// GLOBAL (does not depend on a project)
		canPurgeCache: false,

		// cache UI
		_ui: null,

		// throttle resize ACE
		_resizeQueued: false,

		// Workspace UI logic.
		_endsWith: endsWith,
		_escapeHtml: escapeHtml,

		/**
 * Workspace module.
 */
		normalizeUrl: function (url) {
			url = String(url || '').trim();
			if (!url) return '';
			// remove &amp; (for safety)
			url = url.replace(/&amp;/g, '&');
			// "./app.php/..." => "/app.php/..."
			url = url.replace(/^\.\//, '/');
			// "app.php/..." => "/app.php/..."
			if (url.charAt(0) !== '/' && url.indexOf('http') !== 0) {
				url = '/' + url;
			}
			// Workspace UI logic.
			url = url.replace(/\/app\.php\/app\.php\//g, '/app.php/');
			return url;
		},

		/**
		 * Attach the phpBB CSRF token to a plain object, query string or FormData.
		 */
		withCsrf: function (data) {
			var csrf = (window.wspVars && window.wspVars.csrf) ? window.wspVars.csrf : {};
			var creationTime = String(csrf.creationTime || '');
			var formToken = String(csrf.formToken || '');

			// phpBB 3.3 exposes add_form_key() tokens through S_FORM_TOKEN.
			// Read the native hidden fields instead of depending on a DI form-helper service.
			if (!creationTime || !formToken) {
				var holder = document.getElementById('wsp-csrf-fields');
				if (holder) {
					var creationInput = holder.querySelector('input[name="creation_time"]');
					var tokenInput = holder.querySelector('input[name="form_token"]');
					creationTime = creationInput ? String(creationInput.value || '') : '';
					formToken = tokenInput ? String(tokenInput.value || '') : '';
				}
			}

			if (!creationTime || !formToken) {
				return data || {};
			}

			if (window.FormData && data instanceof window.FormData) {
				if (typeof data.set === 'function') {
					data.set('creation_time', creationTime);
					data.set('form_token', formToken);
				} else {
					data.append('creation_time', creationTime);
					data.append('form_token', formToken);
				}
				return data;
			}

			if (typeof data === 'string') {
				var separator = data ? '&' : '';
				return data + separator + 'creation_time=' + encodeURIComponent(creationTime)
					+ '&form_token=' + encodeURIComponent(formToken);
			}

			data = data || {};
			data.creation_time = creationTime;
			data.form_token = formToken;
			return data;
		},

		/**
 * Workspace module.
 */
		_isSecurityChallenge: function (xhr, body) {
			var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
			var text = String(body || '').toLowerCase();
			if ([403, 429, 503].indexOf(status) === -1) return false;
			return text.indexOf('checking your browser') !== -1
				|| text.indexOf('just a moment') !== -1
				|| text.indexOf('cf-chl-') !== -1
				|| text.indexOf('challenge-platform') !== -1
				|| text.indexOf('browser verification') !== -1;
		},

		_parseJsonResponse: function (txt) {
			var r = null;
			try {
				r = JSON.parse(txt);
			} catch (e) {
				try {
					var s = String(txt || '');
					var a = s.lastIndexOf('{');
					var b = s.lastIndexOf('}');
					if (a !== -1 && b !== -1 && b > a) r = JSON.parse(s.slice(a, b + 1));
				} catch (e2) {}
			}
			return r;
		},

		ajaxGetJson: function (url, data, onOk, onFailKey, onFail) {
			var self = this;
			url = self.normalizeUrl(url);
			data = data || {};
			if (typeof data._nocache === 'undefined') data._nocache = Date.now();
			if (!window.jQuery) return;

			var attempts = 0;
			var maxAttempts = 2;
			var run = function () {
				attempts++;
				window.jQuery.ajax({
					url: url,
					type: 'GET',
					data: data,
					dataType: 'text',
					cache: false,
					timeout: 30000
				}).done(function (txt, _status, xhr) {
					var r = self._parseJsonResponse(txt);
					if (r && r.success) {
						onOk && onOk(r);
						return;
					}
					if (self._isSecurityChallenge(xhr, txt) && attempts < maxAttempts) {
						setTimeout(run, 1200);
						return;
					}
					if (self._isSecurityChallenge(xhr, txt)) {
						var wafMsg = self.lang('WSP_WAF_CHALLENGE', { '%s': String(xhr ? xhr.status : 403) });
						if (WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(wafMsg, 'error');
						onFail && onFail('waf', xhr);
						return;
					}
					var msg = (r && r.error) ? r.error : self.lang(onFailKey || 'WSP_ERROR_CRITICAL');
					if (WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(msg, 'error');
					onFail && onFail('response', xhr);
				}).fail(function (xhr) {
					var body = xhr && xhr.responseText ? String(xhr.responseText) : '';
					if (self._isSecurityChallenge(xhr, body) && attempts < maxAttempts) {
						setTimeout(run, 1200);
						return;
					}
					if (self._isSecurityChallenge(xhr, body)) {
						var wafMsg = self.lang('WSP_WAF_CHALLENGE', { '%s': String(xhr ? xhr.status : 403) });
						if (WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(wafMsg, 'error');
						onFail && onFail('waf', xhr);
						return;
					}
					if (attempts < maxAttempts && (!xhr || xhr.status === 0 || xhr.status >= 500)) {
						setTimeout(run, 900);
						return;
					}
					var msg2 = self.lang(onFailKey || 'WSP_ERROR_CRITICAL');
					if (xhr && xhr.status) msg2 += ' (HTTP ' + xhr.status + ')';
					if (WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(msg2, 'error');
					onFail && onFail('http', xhr);
				});
			};
			run();
		},

		ajaxPostJson: function (url, data, onOk, onFailKey) {
			var self = this;
			url = self.normalizeUrl(url);
			data = self.withCsrf(data || {});
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
					// Workspace UI logic.
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
 * Workspace module.
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
 * Workspace module.
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
 * Workspace module.
 */
		syncStateFromVars: function () {
			var vars = window.wspVars || {};

			// Workspace UI logic.
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

			// Workspace UI logic.
			// allows all when a project exists and it is unlocked or the user is an admin
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
 * Workspace module.
 */
		canEditActiveProjectUI: function () {
			if (!this.activeProjectId) return false;

			this.syncStateFromVars();

			if (this.canManageAll) return true;
			if (this.activeProjectLocked) return false;

			return !!this.activeCanEdit;
		},

		/**
 * Workspace module.
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

		// Workspace UI logic.
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
 * Workspace module.
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

		// Workspace UI logic.
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
			this.editor.setTheme("ace/theme/github_dark");

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

			// Workspace UI logic.
			this.allowedExtensions = this.parseAllowedExtensions(window.wspVars.allowedExt);
			this.allowedExtensionsMap = this.buildAllowedExtensionsMap(this.allowedExtensions);

			// Active project
			this.activeProjectId = this.normalizeProjectId(window.wspVars.activeProjectId);

			// Lock/perms
			this.syncStateFromVars();

			// Workspace UI logic.
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
		 * Frontend extension validation (whitelist)
		 */
		isExtensionAllowed: function (filename) {
			if (!filename) return false;

			var name = String(filename).trim();
			if (!name) return false;

			var lowerName = name.toLowerCase();

			// Workspace UI logic.
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
 * Workspace module.
 */
		getUI: function () {
			if (this._ui) return this._ui;

			// Workspace UI logic.
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

			// Workspace UI logic.
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
			// Workspace UI logic.
		}
	};

})(window, window.jQuery);