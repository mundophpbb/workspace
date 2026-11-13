/**
 * Mundo phpBB Workspace - Tools (Diff, Search, Cache)
 * Version 4.3 (SSOT core): same logic, with SSOT lock enforcement for writes and editor protection
 */
WSP.tools = {
	_ui: null,
	_lastValidationReport: null,
	_validationProjectId: 0,
	_validationRunning: false,
	_wizardMode: false,
	_packageDownloadStarted: false,

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

			$refreshCacheBtn: jQuery('#refresh-phpbb-cache'),

			$validatorModal: jQuery('#release-validator-modal'),
			$validatorSummary: jQuery('#wsp-validator-summary'),
			$epvPanel: jQuery('#wsp-official-epv'),
			$validatorChecklist: jQuery('#wsp-validator-checklist'),
			$validatorIssues: jQuery('#wsp-validator-issues'),
			$validatorScope: jQuery('#wsp-validator-scope'),
			$wizard: jQuery('#wsp-release-wizard'),
			$wizardStatus: jQuery('#wsp-release-status'),
			$wizardMeta: jQuery('#wsp-release-metadata'),
			$wizardStart: jQuery('#wsp-release-start'),
			$validatorTitle: jQuery('#wsp-validator-modal-title'),
			$runValidatorBtn: jQuery('#run-release-validator'),
			$applyFixesBtn: jQuery('#apply-release-fixes'),
			$exportReportBtn: jQuery('#export-validation-report')
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
		if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PROJECT_LOCKED') : 'Project locked.';
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
		// Workspace UI logic.
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
			// The shared request helper shows failures. Its jqXHR also completes on
			// HTTP, permission and invalid-JSON errors, preventing stuck spinners.
			var request = WSP.ajaxPostJson(url, data, function (r) {
				cbOk && cbOk(r);
			}, 'WSP_ERROR_CRITICAL');
			if (cbAlways) {
				if (request && typeof request.always === 'function') request.always(cbAlways);
				else cbAlways();
			}
			return request;
		}

		return $.post(url, WSP.withCsrf(data), function (r) {
			if (r && r.success) cbOk && cbOk(r);
			else cbErr && cbErr(r);
		}, 'json').fail(function () {
			cbErr && cbErr(null);
		}).always(function () {
			cbAlways && cbAlways();
		});
	},




	_currentValidationProjectId: function () {
		return parseInt(String(WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || 0), 10) || 0;
	},

	_canDownloadValidatedPackage: function (summary) {
		summary = summary || {};
		return !!(this._lastValidationReport && !this._validationRunning &&
			this._validationProjectId === this._currentValidationProjectId() &&
			!this._hasUnsavedChanges() &&
			window.wspVars && window.wspVars.downloadSubmissionUrl &&
			parseInt(window.wspVars.activeCanDownload, 10) === 1 &&
			WSP.canEditActiveProjectUI && WSP.canEditActiveProjectUI() &&
			summary.ready && summary.scope === 'phpbb_ext_db' &&
			summary.epv_status === 'pass' && /^[0-9a-f]{24}$/.test(String(summary.fingerprint || '')));
	},

	_setWizardStep: function (step, state) {
		var ui = this._getUI();
		if (!ui.$wizard || !ui.$wizard.length) return;
		ui.$wizard.find('[data-wsp-release-step="' + step + '"]')
			.removeClass('is-pass is-warning is-error')
			.addClass(state ? 'is-' + state : '');
	},

	_updateReleaseWizard: function (report) {
		var ui = this._getUI();
		if (!this._wizardMode || !ui.$wizard || !ui.$wizard.length) return;
		var s = (report && report.summary) || {};
		var epv = (report && report.official_epv) || {};
		var hasResult = !!(report && report.summary);
		var ready = this._canDownloadValidatedPackage(s);
		var message = WSP.lang('WSP_RELEASE_BEGIN_HINT');
		var color = '';

		this._setWizardStep('structure', !hasResult ? '' : ((parseInt(s.errors, 10) || 0) === 0 ? 'pass' : 'error'));
		this._setWizardStep('epv', !hasResult ? '' : (epv.status === 'pass' ? 'pass' : (epv.status === 'unavailable' || epv.status === 'cooldown' || epv.status === 'busy' ? 'warning' : 'error')));
		this._setWizardStep('fixes', !hasResult ? '' : ((parseInt(s.errors, 10) || 0) === 0 && (parseInt(s.fixable, 10) || 0) === 0 ? 'pass' : 'warning'));
		this._setWizardStep('package', ready ? 'pass' : (hasResult ? 'warning' : ''));
		jQuery('#wsp-release-record').prop('disabled', !ready || !this._packageDownloadStarted ||
			!WSP.releaseHistory || !WSP.releaseHistory.allowed());

		if (this._validationRunning) {
			message = WSP.lang('WSP_RELEASE_RUNNING');
		} else if (hasResult) {
			if (s.scope !== 'phpbb_ext_db') message = WSP.lang('WSP_RELEASE_SCOPE_REQUIRED');
			else if (s.epv_status !== 'pass') message = WSP.lang('WSP_RELEASE_EPV_REQUIRED');
			else if (!s.ready) message = WSP.lang('WSP_RELEASE_REVIEW');
			else if (!window.wspVars || parseInt(window.wspVars.activeCanDownload, 10) !== 1) message = WSP.lang('WSP_RELEASE_NO_DOWNLOAD');
			else if (this._hasUnsavedChanges()) message = WSP.lang('WSP_RELEASE_UNSAVED');
			else message = WSP.lang('WSP_RELEASE_COMPLETE');
			color = ready ? 'is-pass' : 'is-warning';
		} else if (this._releaseReportOutdated) {
			message = WSP.lang('WSP_RELEASE_REPORT_STALE');
			color = 'is-warning';
		}
		ui.$wizardStatus.removeClass('is-pass is-warning is-error').addClass(color).text(message);
		if (hasResult) {
			var meta = [];
			if (s.extension_version) meta.push(WSP.lang('WSP_RELEASE_VERSION') + ': ' + s.extension_version);
			if (s.fingerprint) meta.push(WSP.lang('WSP_RELEASE_FINGERPRINT') + ': ' + s.fingerprint);
			ui.$wizardMeta.text(meta.join(' | ')).prop('hidden', !meta.length);
		} else {
			ui.$wizardMeta.empty().prop('hidden', true);
		}
		ui.$wizardStart.prop('disabled', this._validationRunning);
	},

	_setReleaseWizardMode: function (enabled) {
		this._wizardMode = !!enabled;
		var ui = this._getUI();
		ui.$wizard.prop('hidden', !this._wizardMode);
		ui.$validatorScope.prop('disabled', this._wizardMode);
		ui.$validatorTitle.html('<i class="fa ' + (this._wizardMode ? 'fa-rocket' : 'fa-check-square-o') + '"></i> ' +
			this._escape(WSP.lang(this._wizardMode ? 'WSP_RELEASE_ASSISTANT_TITLE' : 'WSP_VALIDATE_RELEASE')));
		if (this._wizardMode) {
			ui.$validatorScope.val('phpbb_ext_db');
			this._invalidateReleaseReport(false);
			ui.$validatorSummary.text(WSP.lang('WSP_VALIDATOR_WAITING'));
			ui.$validatorChecklist.empty();
			ui.$validatorIssues.empty();
			ui.$epvPanel.empty().prop('hidden', true);
		}
		this._updateReleaseWizard(this._lastValidationReport);
	},

	_invalidateReleaseReport: function (changed) {
		var hadReport = !!this._lastValidationReport;
		this._lastValidationReport = null;
		this._validationProjectId = 0;
		this._packageDownloadStarted = false;
		this._releaseReportOutdated = !!changed && (hadReport || this._releaseReportOutdated);
		var ui = this._getUI();
		this.renderPublicationAudit(null);
		ui.$exportReportBtn.prop('disabled', true);
		ui.$applyFixesBtn.prop('disabled', true);
		jQuery('#download-submission-package').prop('disabled', true).removeClass('is-active');
		this._updateReleaseWizard(null);
	},

	_syncValidationFixButton: function (summary) {
		var ui = this._getUI();
		if (!ui.$applyFixesBtn || !ui.$applyFixesBtn.length) return;

		summary = summary || {};
		var fixCount = parseInt(summary.fixable || 0, 10) || 0;
		var hasReport = !!this._lastValidationReport;
		var canFix = !!(hasReport && window.wspVars && window.wspVars.applyValidationFixesUrl && WSP.canEditActiveProjectUI && WSP.canEditActiveProjectUI());
		var label = WSP.lang('WSP_VALIDATOR_APPLY_SAFE_FIXES_COUNT', { '%d': fixCount });
		var hint = fixCount > 0
			? WSP.lang('WSP_VALIDATOR_SAFE_FIXES_HINT')
			: WSP.lang('WSP_VALIDATOR_NO_SAFE_FIXES_HINT');

		ui.$applyFixesBtn
			.prop('disabled', !canFix)
			.toggleClass('is-active', canFix)
			.attr('title', hint)
			.html('<i class="fa fa-magic"></i> ' + this._escape(label));
	},

	renderPublicationAudit: function (audit) {
		var $panel = jQuery('#wsp-publication-audit');
		if (!$panel.length) return;
		if (!audit || !Array.isArray(audit.items)) {
			$panel.empty().prop('hidden', true);
			return;
		}
		var keys = {
			composer: 'WSP_PUB_COMPOSER', version: 'WSP_PUB_VERSION', readme: 'WSP_PUB_README',
			changelog: 'WSP_PUB_CHANGELOG', changelog_version: 'WSP_PUB_CHANGELOG_VERSION',
			license: 'WSP_PUB_LICENSE', languages: 'WSP_PUB_LANGUAGES', workflow: 'WSP_PUB_WORKFLOW'
		};
		var html = '<h5>' + this._escape(WSP.lang('WSP_PUB_TITLE')) + '</h5>';
		html += '<p>' + this._escape(WSP.lang('WSP_PUB_ADVISORY')) + '</p>';
		html += '<p><strong>' + this._escape(String(audit.passed || 0) + '/' + String(audit.total || 0)) + '</strong> ' + this._escape(WSP.lang('WSP_PUB_PASSED')) + '</p>';
		for (var i = 0; i < audit.items.length; i++) {
			var item = audit.items[i] || {};
			var status = ['pass', 'warning', 'pending', 'info'].indexOf(item.status) >= 0 ? item.status : 'info';
			var title = WSP.lang(keys[item.key] || 'WSP_PUB_UNKNOWN');
			html += '<div class="wsp-pub-item is-' + status + '"><strong>' + this._escape(title) + '</strong><small>' + this._escape(item.file || '') + ' · ' + this._escape(item.detail || '') + '</small></div>';
		}
		$panel.html(html).prop('hidden', false);
	},

	renderReleaseValidation: function (r) {
		var ui = this._getUI();
		var summary = r.summary || {};
		this._lastValidationReport = r;
		this._validationProjectId = this._currentValidationProjectId();
		this._releaseReportOutdated = false;
		function pill(cls, icon, text) {
			return '<span class="wsp-validator-pill ' + cls + '"><i class="fa ' + icon + '"></i> ' + text + '</span>';
		}
		ui.$validatorSummary.html([
			pill(summary.ready ? 'is-pass' : 'is-error', summary.ready ? 'fa-check' : 'fa-times', summary.ready ? WSP.lang('WSP_VALIDATOR_READY') : WSP.lang('WSP_VALIDATOR_NOT_READY')),
			pill((summary.score || 0) >= 90 ? 'is-pass' : ((summary.score || 0) >= 70 ? 'is-warning' : 'is-error'), 'fa-tachometer', selfEscape(summary.score || 0) + '/100 ' + WSP.lang('WSP_VALIDATOR_SCORE')),
			pill('is-error', 'fa-times-circle', selfEscape(summary.errors || 0) + ' ' + WSP.lang('WSP_VALIDATOR_ERRORS')),
			pill('is-warning', 'fa-exclamation-triangle', selfEscape(summary.warnings || 0) + ' ' + WSP.lang('WSP_VALIDATOR_WARNINGS')),
			pill('is-info', 'fa-info-circle', selfEscape(summary.notices || 0) + ' ' + WSP.lang('WSP_VALIDATOR_NOTICES')),
			pill('is-pass', 'fa-check-circle', selfEscape(summary.passed || 0) + '/' + selfEscape(summary.checks || 0) + ' ' + WSP.lang('WSP_VALIDATOR_CHECKS')),
			pill('', 'fa-file-code-o', selfEscape(summary.files || 0) + ' ' + WSP.lang('WSP_VALIDATOR_FILES')),
			pill((summary.fixable || 0) > 0 ? 'is-warning' : '', 'fa-magic', selfEscape(summary.fixable || 0) + ' ' + WSP.lang('WSP_VALIDATOR_FIXABLE'))
		].join(''));

		var epv = r.official_epv || {};
		if (ui.$epvPanel && ui.$epvPanel.length) {
			if (!epv.required) {
				ui.$epvPanel.prop('hidden', true).empty();
			} else {
				var epvStatus = epv.status || 'unavailable';
				var epvClass = epvStatus === 'pass' ? 'is-pass' : (epvStatus === 'fail' ? 'is-error' : 'is-warning');
				var epvIcon = epvStatus === 'pass' ? 'fa-check-circle' : (epvStatus === 'fail' ? 'fa-times-circle' : 'fa-plug');
				var epvLabel = epvStatus === 'pass' ? WSP.lang('WSP_EPV_PASS') : (epvStatus === 'fail' ? WSP.lang('WSP_EPV_FAIL') : WSP.lang('WSP_EPV_UNAVAILABLE'));
				var counts = epv.counts || {};
				var eh = ['<h5><i class="fa fa-shield"></i> ' + this._escape(WSP.lang('WSP_EPV_OFFICIAL')) + '</h5>'];
				eh.push('<div class="wsp-validator-row ' + epvClass + '"><i class="fa ' + epvIcon + '"></i><span class="wsp-severity">' + this._escape(epvLabel) + '</span><span>' + this._escape(epv.summary || '') + (epv.version ? '<small>' + this._escape(WSP.lang('WSP_EPV_VERSION')) + ': ' + this._escape(epv.version) + '</small>' : '') + '</span></div>');
				if (epv.ran) {
					eh.push('<div class="wsp-epv-counts">' + this._escape(counts.fatal || 0) + ' ' + this._escape(WSP.lang('WSP_EPV_FATALS')) + ' · ' + this._escape(counts.errors || 0) + ' ' + this._escape(WSP.lang('WSP_VALIDATOR_ERRORS')) + ' · ' + this._escape(counts.warnings || 0) + ' ' + this._escape(WSP.lang('WSP_VALIDATOR_WARNINGS')) + ' · ' + this._escape(counts.notices || 0) + ' ' + this._escape(WSP.lang('WSP_VALIDATOR_NOTICES')) + '</div>');
				}
				var setup = epv.setup || {};
				if (!epv.available) {
					eh.push('<div class="wsp-epv-install"><strong>' + this._escape(WSP.lang('WSP_EPV_SETUP_TITLE')) + '</strong><small>' + this._escape(WSP.lang('WSP_EPV_SETUP_DESCRIPTION')) + '</small>');
					var setupChecks = setup.checks || {};
					var setupLabels = { proc_open: 'WSP_EPV_SETUP_PROC_OPEN', php_cli: 'WSP_EPV_SETUP_PHP_CLI' };
					eh.push('<div class="wsp-epv-setup-checks">');
					for (var setupKey in setupLabels) {
						if (!Object.prototype.hasOwnProperty.call(setupLabels, setupKey)) continue;
						var setupOk = !!setupChecks[setupKey];
						eh.push('<span class="' + (setupOk ? 'is-pass' : 'is-error') + '"><i class="fa ' + (setupOk ? 'fa-check' : 'fa-times') + '"></i> ' + this._escape(WSP.lang(setupLabels[setupKey])) + ': ' + this._escape(WSP.lang(setupOk ? 'WSP_EPV_SETUP_OK' : 'WSP_EPV_SETUP_MISSING')) + '</span>');
					}
					eh.push('</div><small>' + this._escape(WSP.lang('WSP_EPV_SETUP_SOURCE')) + '</small>');
					eh.push('<small>' + this._escape(WSP.lang('WSP_EPV_INSTALL_MANUAL_HINT')) + '</small>');
					eh.push('</div>');
				}
				var epvMessages = epv.messages || [];
				for (var em = 0; em < epvMessages.length; em++) {
					var epi = epvMessages[em] || {};
					eh.push('<div class="wsp-validator-row is-' + this._escape(epi.severity || 'info') + '"><i class="fa fa-angle-right"></i><span class="wsp-severity">EPV</span><span>' + this._escape(epi.message || '') + '</span></div>');
				}
				if (epv.raw_output) {
					eh.push('<details class="wsp-epv-raw"><summary>' + this._escape(WSP.lang('WSP_EPV_RAW_OUTPUT')) + '</summary><pre>' + this._escape(epv.raw_output) + '</pre></details>');
				}
				ui.$epvPanel.html(eh.join('')).prop('hidden', false);
			}
		}

		var checklist = r.checklist || [];
		if (!checklist.length) {
			ui.$validatorChecklist.html('<p class="wsp-muted">' + WSP.lang('WSP_VALIDATOR_NO_CHECKS') + '</p>');
		} else {
			var ch = ['<h5>' + WSP.lang('WSP_RELEASE_CHECKLIST') + '</h5>'];
			for (var i = 0; i < checklist.length; i++) {
				var it = checklist[i] || {};
				var st = it.status || 'warning';
				var icon = st === 'pass' ? 'fa-check' : (st === 'error' ? 'fa-times' : (st === 'info' ? 'fa-info-circle' : 'fa-exclamation'));
				ch.push('<div class="wsp-validator-row is-' + this._escape(st) + '"><i class="fa ' + icon + '"></i><span class="wsp-severity">' + this._escape(st) + '</span><span>' + this._escape(it.label || '') + '<small>' + this._escape(it.detail || '') + '</small></span></div>');
			}
			ui.$validatorChecklist.html(ch.join(''));
		}

		var issues = r.issues || [];
		if (!issues.length) {
			ui.$validatorIssues.html('<p class="wsp-muted">' + WSP.lang('WSP_VALIDATOR_NO_ISSUES') + '</p>');
		} else {
			var ih = ['<h5>' + WSP.lang('WSP_VALIDATOR_ISSUES') + '</h5>'];
			for (var j = 0; j < issues.length; j++) {
				var issue = issues[j] || {};
				var lineInfo = issue.line ? ' · ' + WSP.lang('WSP_VALIDATOR_LINE') + ' ' + this._escape(issue.line) : '';
				var detail = '<small>' + this._escape(issue.category || '') + (issue.file ? ' · ' + this._escape(issue.file) : '') + lineInfo + (issue.rule ? ' · ' + this._escape(issue.rule) : '') + '</small>';
				var action = issue.action ? '<em class="wsp-validator-action"><strong>' + this._escape(WSP.lang('WSP_VALIDATOR_ACTION')) + ':</strong> ' + this._escape(issue.action) + '</em>' : '';
				var fixable = issue.fixable ? '<em class="wsp-validator-fixable"><i class="fa fa-magic"></i> ' + this._escape(WSP.lang('WSP_VALIDATOR_SAFE_FIX_AVAILABLE')) + '</em>' : '';
				var excerpt = issue.excerpt ? '<code class="wsp-validator-excerpt"><strong>' + this._escape(WSP.lang('WSP_VALIDATOR_EXCERPT')) + ':</strong> ' + this._escape(issue.excerpt) + '</code>' : '';
				var example = issue.example ? '<code class="wsp-validator-example">' + this._escape(issue.example) + '</code>' : '';
				ih.push('<div class="wsp-validator-row is-' + this._escape(issue.severity || 'warning') + '"><i class="fa fa-dot-circle-o"></i><span class="wsp-severity">' + this._escape(issue.severity || '') + '</span><span>' + this._escape(issue.message || '') + detail + excerpt + action + fixable + example + '</span></div>');
			}
			ui.$validatorIssues.html(ih.join(''));
		}

		this.renderPublicationAudit(r.publication || null);
		this._syncValidationFixButton(summary);
		if (ui.$exportReportBtn && ui.$exportReportBtn.length) {
			ui.$exportReportBtn.prop('disabled', false);
		}
		var $submissionBtn = jQuery('#download-submission-package');
		if ($submissionBtn.length) {
			var canPackage = this._canDownloadValidatedPackage(summary);
			$submissionBtn.prop('disabled', !canPackage);
			$submissionBtn.toggleClass('is-active', canPackage);
		}

		this._updateReleaseWizard(r);
		function selfEscape(v) { return String(v == null ? '' : v); }
	},

	_reportLine: function (label, value) {
		return String(label) + ': ' + String(value == null ? '' : value);
	},

	buildValidationReportText: function (r) {
		r = r || {};
		var summary = r.summary || {};
		var epv = r.official_epv || {};
		var checklist = r.checklist || [];
		var issues = r.issues || [];
		var lines = [];
		var L = function (key, repl) { return WSP.lang(key, repl); };
		var projectName = (window.wspVars && (wspVars.activeProjectName || wspVars.projectName)) || '';
		var projectId = WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || '';
		var now = new Date();
		var stamp = now.toISOString ? now.toISOString() : String(now);
		var title = L('WSP_REPORT_TITLE');

		lines.push(title);
		lines.push(new Array(title.length + 1).join('='));
		lines.push(this._reportLine(L('WSP_REPORT_DATE'), stamp));
		lines.push(this._reportLine(L('WSP_REPORT_PROJECT'), projectName || ('#' + projectId)));
		lines.push(this._reportLine(L('WSP_REPORT_SCOPE'), summary.scope || ''));
		lines.push(this._reportLine(L('WSP_REPORT_READY'), summary.ready ? (L('WSP_REPORT_YES') + ((summary.epv_required === false) ? ' ' + L('WSP_REPORT_EPV_NOT_RUN') : '')) : L('WSP_REPORT_NO')));
		lines.push(this._reportLine(L('WSP_REPORT_SCORE'), (summary.score || 0) + '/100'));
		lines.push(this._reportLine(L('WSP_REPORT_ERRORS'), summary.errors || 0));
		lines.push(this._reportLine(L('WSP_REPORT_WARNINGS'), summary.warnings || 0));
		lines.push(this._reportLine(L('WSP_REPORT_NOTICES'), summary.notices || 0));
		lines.push(this._reportLine(L('WSP_REPORT_CHECKS'), (summary.passed || 0) + '/' + (summary.checks || 0)));
		lines.push(this._reportLine(L('WSP_REPORT_FILES'), summary.files || 0));
		if (summary.extension_version) lines.push(this._reportLine(L('WSP_REPORT_EXT_VERSION'), summary.extension_version));
		if (summary.fingerprint) lines.push(this._reportLine(L('WSP_REPORT_FINGERPRINT'), summary.fingerprint));
		lines.push('');
		var publication = r.publication || {};
		if (Array.isArray(publication.items)) {
			lines.push(L('WSP_PUB_TITLE') + ' (' + (publication.passed || 0) + '/' + (publication.total || 0) + ')');
			lines.push(L('WSP_PUB_ADVISORY'));
			publication.items.forEach(function (it) { lines.push('- [' + String(it.status || 'info') + '] ' + String(it.key || '') + ': ' + String(it.file || '') + ' - ' + String(it.detail || '')); });
			lines.push('');
		}
		lines.push(L('WSP_REPORT_EPV_TITLE'));
		lines.push(new Array(L('WSP_REPORT_EPV_TITLE').length + 1).join('-'));
		if (!epv.required) {
			lines.push(L('WSP_REPORT_EPV_NOT_REQUIRED'));
		} else {
			lines.push(this._reportLine(L('WSP_REPORT_STATUS'), epv.status || 'unavailable'));
			lines.push(this._reportLine(L('WSP_REPORT_VERSION'), epv.version || ''));
			lines.push(this._reportLine(L('WSP_REPORT_SUMMARY'), epv.summary || ''));
			var counts = epv.counts || {};
			lines.push(this._reportLine(L('WSP_REPORT_EPV_FATAL'), counts.fatal || 0));
			lines.push(this._reportLine(L('WSP_REPORT_EPV_ERRORS'), counts.errors || 0));
			lines.push(this._reportLine(L('WSP_REPORT_EPV_WARNINGS'), counts.warnings || 0));
			lines.push(this._reportLine(L('WSP_REPORT_EPV_NOTICES'), counts.notices || 0));
			var epvMessages = epv.messages || [];
			for (var em = 0; em < epvMessages.length; em++) {
				var epi = epvMessages[em] || {};
				lines.push('- [' + (epi.severity || 'info') + '] ' + (epi.message || ''));
			}
			if (epv.raw_output) {
				lines.push('');
				lines.push(L('WSP_REPORT_EPV_RAW'));
				lines.push(String(epv.raw_output));
			}
		}
		lines.push('');
		lines.push(L('WSP_REPORT_CHECKLIST'));
		lines.push(new Array(L('WSP_REPORT_CHECKLIST').length + 1).join('-'));
		if (!checklist.length) {
			lines.push(L('WSP_REPORT_EMPTY'));
		} else {
			for (var i = 0; i < checklist.length; i++) {
				var it = checklist[i] || {};
				lines.push('- [' + (it.status || '') + '] ' + (it.label || '') + (it.detail ? ' — ' + it.detail : ''));
			}
		}
		lines.push('');
		lines.push(L('WSP_REPORT_FINDINGS'));
		lines.push(new Array(L('WSP_REPORT_FINDINGS').length + 1).join('-'));
		if (!issues.length) {
			lines.push(L('WSP_REPORT_NONE'));
		} else {
			for (var j = 0; j < issues.length; j++) {
				var issue = issues[j] || {};
				var loc = [];
				if (issue.file) loc.push(issue.file);
				if (issue.line) loc.push(L('WSP_REPORT_LINE', { '%s': issue.line }));
				if (issue.rule) loc.push(issue.rule);
				lines.push((j + 1) + '. [' + (issue.severity || '') + '] ' + (issue.message || ''));
				if (loc.length) lines.push('   ' + loc.join(' · '));
				if (issue.excerpt) lines.push('   ' + L('WSP_REPORT_EXCERPT') + ' ' + issue.excerpt);
				if (issue.action) lines.push('   ' + L('WSP_REPORT_ACTION') + ' ' + issue.action);
				var details = issue.example_full || issue.example;
				if (details) lines.push('   ' + L('WSP_REPORT_DETAILS') + ' ' + details);
			}
		}
		lines.push('');
		return lines.join('\n');
	},

	exportValidationReport: function () {
		var r = this._lastValidationReport;
		if (!r || this._validationProjectId !== this._currentValidationProjectId()) {
			WSP.ui.notify(WSP.lang('WSP_EXPORT_VALIDATION_NO_REPORT'), 'warning');
			return;
		}
		var text = this.buildValidationReportText(r);
		var stamp = new Date();
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var name = (this._wizardMode ? 'workspace-lancamento-' : 'workspace-validacao-') + stamp.getFullYear() + pad(stamp.getMonth() + 1) + pad(stamp.getDate()) + '-' + pad(stamp.getHours()) + pad(stamp.getMinutes()) + '.txt';
		try {
			var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = name;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
			WSP.ui.notify(WSP.lang('WSP_EXPORT_VALIDATION_DONE'), 'success');
		} catch (e) {
			WSP.ui.notify(WSP.lang('WSP_ERROR_CRITICAL'), 'error');
		}
	},

	runReleaseValidation: function ($) {
		var self = this;
		var ui = self._getUI();
		var projectId = WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || 0;
		if (!projectId) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), 'warning');
		if (!WSP.canEditActiveProjectUI || !WSP.canEditActiveProjectUI()) {
			return WSP.ui.notify(WSP.lang('WSP_VALIDATOR_EDITOR_ONLY'), 'warning');
		}
		if (!window.wspVars || !window.wspVars.validateReleaseUrl) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), 'error');
		if (self._validationRunning) return WSP.ui.notify(WSP.lang('WSP_RELEASE_VALIDATING'), 'warning');
		if (self._hasUnsavedChanges()) return WSP.ui.notify(WSP.lang('WSP_RELEASE_UNSAVED'), 'warning');

		self._invalidateReleaseReport(false);
		self._validationRunning = true;
		ui.$validatorModal.fadeIn(200);
		self._updateReleaseWizard(null);
		ui.$validatorSummary.html('<span class="wsp-validator-pill"><i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_VALIDATOR_RUNNING') + '</span>');
		ui.$validatorChecklist.empty();
		ui.$validatorIssues.empty();
		if (ui.$epvPanel && ui.$epvPanel.length) ui.$epvPanel.prop('hidden', true).empty();
		ui.$runValidatorBtn.prop('disabled', true);
		if (ui.$applyFixesBtn && ui.$applyFixesBtn.length) ui.$applyFixesBtn.prop('disabled', true);
		if (ui.$exportReportBtn && ui.$exportReportBtn.length) ui.$exportReportBtn.prop('disabled', true);

		var scope = self._wizardMode ? 'phpbb_ext_db' : ((ui.$validatorScope && ui.$validatorScope.length) ? ui.$validatorScope.val() : 'normal');
		self._postJson($, window.wspVars.validateReleaseUrl, { project_id: projectId, scope: scope }, function (r) {
			self.renderReleaseValidation(r);
			if (r.summary && r.summary.ready) WSP.ui.notify(WSP.lang('WSP_VALIDATOR_READY'), 'success');
			else WSP.ui.notify(WSP.lang('WSP_VALIDATOR_NOT_READY'), 'warning');
		}, function (r) {
			WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), 'error');
		}, function () {
			self._validationRunning = false;
			ui.$runValidatorBtn.prop('disabled', false);
			var summary = self._lastValidationReport && self._lastValidationReport.summary;
			jQuery('#download-submission-package').prop('disabled', !self._canDownloadValidatedPackage(summary));
			self._updateReleaseWizard(self._lastValidationReport);
		});
	},
	applyValidationFixes: function ($) {
		var self = this;
		var ui = self._getUI();
		var projectId = WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || 0;
		if (!projectId) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), 'warning');
		if (!window.wspVars || !window.wspVars.applyValidationFixesUrl) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), 'error');
		if (!WSP.canEditActiveProjectUI || !WSP.canEditActiveProjectUI()) {
			return WSP.ui.notify(WSP.lang('WSP_VALIDATOR_EDITOR_ONLY'), 'warning');
		}
		if (!self._confirmLoseChanges()) return;

		WSP.ui.confirm(WSP.lang('WSP_VALIDATOR_APPLY_SAFE_FIXES_CONFIRM'), function () {
			ui.$applyFixesBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_PROCESSING'));
			var scope = (ui.$validatorScope && ui.$validatorScope.length) ? ui.$validatorScope.val() : 'normal';
			self._postJson($, window.wspVars.applyValidationFixesUrl, { project_id: projectId, scope: scope }, function (r) {
				var applied = r.applied || 0;
				WSP.ui.notify(WSP.lang('WSP_VALIDATOR_FIXES_APPLIED', { '%d': applied }), applied > 0 ? 'success' : 'info');
				if (r.report) self.renderReleaseValidation(r.report);
				if (applied > 0) self._invalidateReleaseReport(true);
				if (typeof WSP.ui.seamlessRefresh === 'function') WSP.ui.seamlessRefresh();
				if (WSP.activeFileId) jQuery('.active-file .load-file').trigger('click');
			}, function (r) {
				WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), 'error');
			}, function () {
				self._syncValidationFixButton((self._lastValidationReport && self._lastValidationReport.summary) ? self._lastValidationReport.summary : {});
			});
		});
	},

	bindEvents: function ($) {
		var self = this;
		var ui = self._getUI();
		var $body = ui.$body;

		$body.off('.wsp_tools');
		ui.$doc.off('keydown.wsp_tools');

		// Workspace UI logic.
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

			// Workspace UI logic.
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

			// Workspace UI logic.
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

				// Workspace UI logic.
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

		// 3) VALIDADOR phpBB / CHECKLIST / ASSISTENTE DE LANCAMENTO
		$body.on('click.wsp_tools', '#open-release-assistant', function (e) {
			e.preventDefault();
			if (!WSP.canEditActiveProjectUI || !WSP.canEditActiveProjectUI()) {
				return WSP.ui.notify(WSP.lang('WSP_VALIDATOR_EDITOR_ONLY'), 'warning');
			}
			self._setReleaseWizardMode(true);
			ui.$validatorModal.fadeIn(200);
		});

		$body.on('click.wsp_tools', '#wsp-release-start', function (e) {
			e.preventDefault();
			self.runReleaseValidation($);
		});

		$body.on('click.wsp_tools', '#wsp-release-record', function (e) {
			e.preventDefault();
			var summary = (self._lastValidationReport || {}).summary || {};
			if (!self._wizardMode || !self._packageDownloadStarted || !self._canDownloadValidatedPackage(summary) ||
				!WSP.releaseHistory || !WSP.releaseHistory.allowed()) return;
			self._postJson($, window.wspVars.verifyReleaseSnapshotUrl,
				{project_id:self._currentValidationProjectId(), fingerprint:String(summary.fingerprint)}, function (result) {
				if (!result.matches || !self._canDownloadValidatedPackage(summary)) {
					self._invalidateReleaseReport(true);
					WSP.ui.notify(WSP.lang('WSP_RELEASE_SNAPSHOT_CHANGED'), 'warning');
					return;
				}
				var packageName = String(summary.extension_package || '');
				var version = String(summary.extension_version || '');
				if (!/^[A-Za-z][A-Za-z0-9_]*\/[A-Za-z][A-Za-z0-9_]*$/.test(packageName) ||
					!/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/.test(version)) {
					WSP.ui.notify(WSP.lang('WSP_RH_INVALID'), 'warning'); return;
				}
				var fileName = (packageName.replace('/', '_') + '_' + version.replace(/[^A-Za-z0-9._-]/g, '_') + '.zip');
				jQuery('#release-validator-modal').hide();
				WSP.releaseHistory.openPrepared({projectId:self._currentValidationProjectId(),
					version:version, packageName:fileName, fingerprint:String(summary.fingerprint)});
			});
		});

		$body.on('click.wsp_tools', '#validate-release-project', function (e) {
			e.preventDefault();
			self._setReleaseWizardMode(false);
			self.runReleaseValidation($);
		});

		$body.on('click.wsp_tools', '#run-release-validator', function (e) {
			e.preventDefault();
			self.runReleaseValidation($);
		});

		$body.on('click.wsp_tools', '#apply-release-fixes', function (e) {
			e.preventDefault();
			self.applyValidationFixes($);
		});

		$body.on('click.wsp_tools', '#export-validation-report', function (e) {
			e.preventDefault();
			self.exportValidationReport();
		});

		$body.on('click.wsp_tools', '#download-submission-package', function (e) {
			e.preventDefault();
			var report = self._lastValidationReport || {};
			var summary = report.summary || {};
			if (!self._canDownloadValidatedPackage(summary)) {
				WSP.ui.notify(self._hasUnsavedChanges() ? WSP.lang('WSP_RELEASE_UNSAVED') : WSP.lang('WSP_VALIDATOR_RUN_EXTDB_FIRST'), 'warning');
				return;
			}
			var pid = self._currentValidationProjectId();
			var u = String((window.wspVars && window.wspVars.downloadSubmissionUrl) || '');
			if (!pid || !u || !window.wspVars.verifyReleaseSnapshotUrl) return;
			if (u.indexOf('project_id=0') !== -1) u = u.replace('project_id=0', 'project_id=' + pid);
			else u = u.replace(/\/0(\b|\/)?/, '/' + pid);
			var fingerprint = String(summary.fingerprint);
			var $button = jQuery(this).prop('disabled', true);
			self._postJson($, window.wspVars.verifyReleaseSnapshotUrl, { project_id: pid, fingerprint: fingerprint }, function (r) {
				if (!r.matches || !self._canDownloadValidatedPackage(summary)) {
					self._invalidateReleaseReport(true);
					WSP.ui.notify(WSP.lang('WSP_RELEASE_SNAPSHOT_CHANGED'), 'warning');
					return;
				}
				// Server checks the fingerprint again during ZIP creation (TOCTOU).
				window.location.href = u + (u.indexOf('?') === -1 ? '?' : '&') + 'fingerprint=' + encodeURIComponent(fingerprint);
				// A browser navigation cannot confirm the ZIP actually arrived.
				// Explicitly ask the developer to verify the download before recording.
				self._packageDownloadStarted = true;
				self._updateReleaseWizard(self._lastValidationReport);
			}, function (r) {
				WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), 'error');
			}, function () {
				$button.prop('disabled', !self._canDownloadValidatedPackage(summary));
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

		// Any successful file mutation invalidates an earlier validation report.
		// The server still checks the exact snapshot on every wizard ZIP download.
		var mutationRoutes = [];
		var mutationKeys = [
			'saveUrl', 'addFileUrl', 'uploadUrl', 'uploadZipUrl', 'renameUrl',
			'moveFileUrl', 'deleteFileUrl', 'renameFolderUrl', 'deleteFolderUrl',
			'replaceUrl', 'applyValidationFixesUrl', 'fileVersionRestoreUrl',
			'changelogUrl', 'clearChangelogUrl', 'generatorApplyUrl'
		];
		for (var k = 0; k < mutationKeys.length; k++) {
			if (window.wspVars && window.wspVars[mutationKeys[k]]) {
				mutationRoutes.push(String(window.wspVars[mutationKeys[k]]).split('?')[0]);
			}
		}
		ui.$doc.off('ajaxSuccess.wsp_tools').on('ajaxSuccess.wsp_tools', function (event, xhr, settings) {
			if (!self._lastValidationReport || !settings || String(settings.type || '').toUpperCase() !== 'POST') return;
			var requestPath = String(settings.url || '').split('?')[0];
			if (mutationRoutes.indexOf(requestPath) === -1) return;
			var data = xhr && xhr.responseJSON;
			if (data && data.success === false) return;
			self._invalidateReleaseReport(true);
		});

		// 4) ESC fecha modais
		ui.$doc.on('keydown.wsp_tools', function (e) {
			var isEsc = (e.key === "Escape" || e.keyCode === 27);
			if (isEsc) {
				ui.$searchModal.fadeOut(150);
				ui.$diffModal.fadeOut(150);
				ui.$validatorModal.fadeOut(150);
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