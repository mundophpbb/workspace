/**
 * Mundo phpBB Workspace - Inicializador Blindado
 * Version 4.2 (SSOT core): uses WSP.canWriteUI() from wsp_core.js
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
 * Workspace module.
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

	// 1.55.0: load optional feature scripts on the first explicit tool click.
	// Keep the editor and collaboration modules in the initial page request.
	var optionalTools = [
		{ name: 'Generator', property: 'generator', file: 'wsp_generator.js', selector: '#open-generator, #open-phpunit-generator' },
		{ name: 'Architecture', property: 'architecture', file: 'wsp_architecture.js', selector: '#wsp-arch-open-compare, #wsp-arch-open-map' },
		{ name: 'Permissions', property: 'permissions', file: 'wsp_permissions.js', selector: '#wsp-perm-open' },
		{ name: 'Test laboratory', property: 'testLab', file: 'wsp_test_lab.js', selector: '#wsp-lab-open' },
		{ name: 'Security audit', property: 'securityAudit', file: 'wsp_security_audit.js', selector: '#wsp-sec-open' },
		{ name: 'Upgrade advisor', property: 'upgradeAdvisor', file: 'wsp_upgrade_advisor.js', selector: '#wsp-up-open' },
		{ name: 'Quality dashboard', property: 'qualityDashboard', file: 'wsp_quality_dashboard.js', selector: '#wsp-ql-open' },
		{ name: 'Template preview', property: 'templatePreview', file: 'wsp_template_preview.js', selector: '#wsp-tp-open' },
		{ name: 'Release notes', property: 'releaseNotes', file: 'wsp_release_notes.js', selector: '#wsp-rn-open' },
		{ name: 'Documentation', property: 'documentation', file: 'wsp_documentation.js', selector: '#wsp-doc-open' },
		{ name: 'GitHub repositories', property: 'githubRepositories', file: 'wsp_github.js', selector: '#wsp-gh-open' }
	];
	var scriptBase = '';
	var scriptQuery = '';
	(function resolveScriptBase() {
		// phpBB's INCLUDEJS script has an absolute same-origin URL, even under subdirectories.
		var current = document.currentScript;
		var source = current && current.src || '';
		if (!/\/wsp_init\.js(?:\?.*)?$/.test(source)) {
			var fallback = document.querySelector('script[src*="/wsp_init.js"]');
			source = fallback ? fallback.src : '';
		}
		var match = /^(https?:\/\/[^?#]+\/)(?:wsp_init\.js)(\?[^#]*)?$/.exec(source);
		if (!match) return;
		var anchor = document.createElement('a');
		anchor.href = match[1];
		if (anchor.protocol !== window.location.protocol || anchor.host !== window.location.host) return;
		scriptBase = match[1];
		scriptQuery = match[2] || '';
	})();

	function activateModule(m) {
		if (!m.ref) return;
		if (typeof m.ref.init === 'function') m.ref.init($);
		if (typeof m.ref.render === 'function') m.ref.render($);
		if (typeof m.ref.bindEvents === 'function') m.ref.bindEvents($);
	}
	function registerOptionalTool(spec) {
		var state = 'idle';
		var pendingTarget = null;
		var projectAtClick = null;
		function notifyFailure() {
			if (window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
				WSP.ui.notify(safeLang('WSP_ERROR_CRITICAL'), 'error');
			}
		}
		function onClick(event) {
			var target = event.target;
			var button = target && typeof target.closest === 'function' ? target.closest(spec.selector) : null;
			if (!button || state === 'ready') return;
			// Stop the first click before the toolbar closes its menu. Re-dispatch only
			// after the optional module has installed its delegated listeners.
			event.preventDefault();
			event.stopImmediatePropagation();
			if (state === 'loading') return;
			if (!scriptBase) {
				console.error('WSP: cannot locate optional script directory');
				notifyFailure();
				return;
			}
			state = 'loading';
			pendingTarget = button;
			projectAtClick = window.WSP ? WSP.activeProjectId : null;
			var script = document.createElement('script');
			script.src = scriptBase + spec.file + scriptQuery;
			script.async = true;
			script.onload = function () {
				try {
					var module = window.WSP && WSP[spec.property];
					if (!module) throw new Error('Optional module not registered: ' + spec.property);
					activateModule({ name: spec.name, ref: module });
					state = 'ready';
					document.removeEventListener('click', onClick, true);
					if (pendingTarget && pendingTarget.isConnected && WSP.activeProjectId === projectAtClick && !pendingTarget.disabled) pendingTarget.click();
				} catch (error) {
					state = 'failed';
					console.error('WSP: optional module initialization failed', spec.name, error);
					notifyFailure();
				}
				pendingTarget = null;
			};
			script.onerror = function () {
				state = 'idle'; // Retry on a later deliberate click.
				pendingTarget = null;
				if (script.parentNode) script.parentNode.removeChild(script);
				console.error('WSP: optional JavaScript could not load', spec.file);
				notifyFailure();
			};
			document.head.appendChild(script);
		}
		document.addEventListener('click', onClick, true);
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

		// Workspace UI logic.
		var modules = [
			{ name: 'UI', ref: WSP.ui },
			{ name: 'Tree', ref: WSP.tree },
			{ name: 'Files', ref: WSP.files },
			{ name: 'Projects', ref: WSP.projects },
			{ name: 'Upload', ref: WSP.upload },
			{ name: 'Tools', ref: WSP.tools },
			{ name: 'Radar', ref: WSP.radar },
			{ name: 'Project checkpoints', ref: WSP.checkpoints },
			{ name: 'Global refactoring', ref: WSP.refactor }
		];
		for (var i = 0; i < modules.length; i++) {
			try {
				activateModule(modules[i]);
			} catch (e) {
				console.error('WSP: module initialization error', modules[i].name, e);
			}
		}
		optionalTools.forEach(registerOptionalTool);

		// 3) Estado visual toolbar (Core)
		if (typeof WSP.updateUIState === 'function') {
			WSP.updateUIState();
		}

		// Workspace UI logic.
		var savedFileId = localStorage.getItem('wsp_active_file_id');
		var hasProject = !!WSP.activeProjectId;

		if (savedFileId && hasProject && window.wspVars && window.wspVars.loadUrl) {
			$currentFile
				.addClass('is-loading')
				.removeClass('is-empty')
				.text(safeLang('WSP_LOADING_FILE'));

			WSP.ajaxGetJson(window.wspVars.loadUrl, { file_id: savedFileId }, function (r) {
				if (r && r.success) {
					WSP.activeFileId = savedFileId;
					WSP.activeFileRev = (typeof r.file_rev === 'number') ? r.file_rev : null;

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

					// Workspace UI logic.
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
			}, 'WSP_ERROR_CRITICAL', function () {
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
		var modulesReady = (window.WSP && WSP.ui && WSP.tree && WSP.files && WSP.projects && WSP.upload && WSP.tools && WSP.radar);

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