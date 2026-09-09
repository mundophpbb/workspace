/**
 * Mundo phpBB Workspace - Tools (Diff, Search, Cache)
 * Version 4.3 (SSOT core): same logic, with SSOT lock enforcement for writes and editor protection
 */
WSP.tools = {
	_ui: null,
	_lastValidationReport: null,

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
			WSP.ajaxPostJson(url, data, function (r) {
				cbOk && cbOk(r);
				cbAlways && cbAlways();
			}, 'WSP_ERROR_CRITICAL');
			return;
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



	_syncValidationFixButton: function (summary) {
		var ui = this._getUI();
		if (!ui.$applyFixesBtn || !ui.$applyFixesBtn.length) return;

		summary = summary || {};
		var fixCount = parseInt(summary.fixable || 0, 10) || 0;
		var hasReport = !!this._lastValidationReport;
		var canFix = !!(hasReport && window.wspVars && window.wspVars.applyValidationFixesUrl && this._canWrite());
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

	renderReleaseValidation: function (r) {
		var ui = this._getUI();
		var summary = r.summary || {};
		this._lastValidationReport = r;
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
					var setupLabels = { admin: 'WSP_EPV_SETUP_ADMIN', proc_open: 'WSP_EPV_SETUP_PROC_OPEN', php_cli: 'WSP_EPV_SETUP_PHP_CLI', zip: 'WSP_EPV_SETUP_ZIP', download: 'WSP_EPV_SETUP_DOWNLOAD', writable: 'WSP_EPV_SETUP_WRITABLE' };
					eh.push('<div class="wsp-epv-setup-checks">');
					for (var setupKey in setupLabels) {
						if (!Object.prototype.hasOwnProperty.call(setupLabels, setupKey)) continue;
						var setupOk = !!setupChecks[setupKey];
						eh.push('<span class="' + (setupOk ? 'is-pass' : 'is-error') + '"><i class="fa ' + (setupOk ? 'fa-check' : 'fa-times') + '"></i> ' + this._escape(WSP.lang(setupLabels[setupKey])) + ': ' + this._escape(WSP.lang(setupOk ? 'WSP_EPV_SETUP_OK' : 'WSP_EPV_SETUP_MISSING')) + '</span>');
					}
					eh.push('</div><small>' + this._escape(WSP.lang('WSP_EPV_SETUP_SOURCE')) + '</small>');
					if (epv.can_install && window.wspVars && window.wspVars.epvInstallUrl) {
						eh.push('<button type="button" class="btn-save wsp-epv-install-btn"><i class="fa fa-download"></i> ' + this._escape(WSP.lang('WSP_EPV_INSTALL_BUTTON')) + '</button><small>' + this._escape(WSP.lang('WSP_EPV_SETUP_RETRY')) + '</small>');
					}
					eh.push('</div>');
				} else if (epv.managed && epv.can_install && window.wspVars && window.wspVars.epvInstallUrl) {
					eh.push('<div class="wsp-epv-install wsp-epv-installed-actions"><button type="button" class="btn-main wsp-epv-install-btn"><i class="fa fa-refresh"></i> ' + this._escape(WSP.lang('WSP_EPV_UPDATE_BUTTON')) + '</button></div>');
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

		this._syncValidationFixButton(summary);
		if (ui.$exportReportBtn && ui.$exportReportBtn.length) {
			ui.$exportReportBtn.prop('disabled', false);
		}
		var $submissionBtn = jQuery('#download-submission-package');
		if ($submissionBtn.length) {
			var canPackage = !!(summary.ready && summary.scope === 'phpbb_ext_db' && summary.epv_status === 'pass' && window.wspVars && window.wspVars.downloadSubmissionUrl);
			$submissionBtn.prop('disabled', !canPackage);
			$submissionBtn.toggleClass('is-active', canPackage);
		}

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
		var projectName = (window.wspVars && (wspVars.activeProjectName || wspVars.projectName)) || '';
		var projectId = WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || '';
		var now = new Date();
		var stamp = now.toISOString ? now.toISOString() : String(now);

		lines.push('phpBB Workspace - Relatorio de validacao');
		lines.push('=======================================');
		lines.push(this._reportLine('Data', stamp));
		lines.push(this._reportLine('Projeto', projectName || ('#' + projectId)));
		lines.push(this._reportLine('Escopo', summary.scope || ''));
		lines.push(this._reportLine('Pronto para envio', summary.ready ? 'sim' : 'nao'));
		lines.push(this._reportLine('Pontuacao', (summary.score || 0) + '/100'));
		lines.push(this._reportLine('Erros', summary.errors || 0));
		lines.push(this._reportLine('Avisos', summary.warnings || 0));
		lines.push(this._reportLine('Informacoes', summary.notices || 0));
		lines.push(this._reportLine('Checks', (summary.passed || 0) + '/' + (summary.checks || 0)));
		lines.push(this._reportLine('Arquivos', summary.files || 0));
		lines.push('');
		lines.push('EPV oficial do phpBB');
		lines.push('--------------------');
		if (!epv.required) {
			lines.push('Nao exigido neste escopo.');
		} else {
			lines.push(this._reportLine('Status', epv.status || 'unavailable'));
			lines.push(this._reportLine('Versao', epv.version || ''));
			lines.push(this._reportLine('Resumo', epv.summary || ''));
			var counts = epv.counts || {};
			lines.push(this._reportLine('Fatais', counts.fatal || 0));
			lines.push(this._reportLine('Erros EPV', counts.errors || 0));
			lines.push(this._reportLine('Avisos EPV', counts.warnings || 0));
			lines.push(this._reportLine('Informacoes EPV', counts.notices || 0));
			var epvMessages = epv.messages || [];
			for (var em = 0; em < epvMessages.length; em++) {
				var epi = epvMessages[em] || {};
				lines.push('- [' + (epi.severity || 'info') + '] ' + (epi.message || ''));
			}
			if (epv.raw_output) {
				lines.push('');
				lines.push('Saida bruta do EPV:');
				lines.push(String(epv.raw_output));
			}
		}
		lines.push('');
		lines.push('Checklist');
		lines.push('---------');
		if (!checklist.length) {
			lines.push('(vazio)');
		} else {
			for (var i = 0; i < checklist.length; i++) {
				var it = checklist[i] || {};
				lines.push('- [' + (it.status || '') + '] ' + (it.label || '') + (it.detail ? ' — ' + it.detail : ''));
			}
		}
		lines.push('');
		lines.push('Ocorrencias');
		lines.push('-----------');
		if (!issues.length) {
			lines.push('(nenhuma)');
		} else {
			for (var j = 0; j < issues.length; j++) {
				var issue = issues[j] || {};
				var loc = [];
				if (issue.file) loc.push(issue.file);
				if (issue.line) loc.push('linha ' + issue.line);
				if (issue.rule) loc.push(issue.rule);
				lines.push((j + 1) + '. [' + (issue.severity || '') + '] ' + (issue.message || ''));
				if (loc.length) lines.push('   ' + loc.join(' · '));
				if (issue.excerpt) lines.push('   Trecho: ' + issue.excerpt);
				if (issue.action) lines.push('   Acao: ' + issue.action);
			}
		}
		lines.push('');
		return lines.join('\n');
	},

	exportValidationReport: function () {
		var r = this._lastValidationReport;
		if (!r) {
			WSP.ui.notify(WSP.lang('WSP_EXPORT_VALIDATION_NO_REPORT'), 'warning');
			return;
		}
		var text = this.buildValidationReportText(r);
		var stamp = new Date();
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var name = 'workspace-validacao-' + stamp.getFullYear() + pad(stamp.getMonth() + 1) + pad(stamp.getDate()) + '-' + pad(stamp.getHours()) + pad(stamp.getMinutes()) + '.txt';
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
		if (!window.wspVars || !window.wspVars.validateReleaseUrl) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), 'error');

		ui.$validatorModal.fadeIn(200);
		ui.$validatorSummary.html('<span class="wsp-validator-pill"><i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_VALIDATOR_RUNNING') + '</span>');
		ui.$validatorChecklist.empty();
		ui.$validatorIssues.empty();
		if (ui.$epvPanel && ui.$epvPanel.length) ui.$epvPanel.prop('hidden', true).empty();
		ui.$runValidatorBtn.prop('disabled', true);
		if (ui.$applyFixesBtn && ui.$applyFixesBtn.length) ui.$applyFixesBtn.prop('disabled', true);
		if (ui.$exportReportBtn && ui.$exportReportBtn.length) ui.$exportReportBtn.prop('disabled', true);

		var scope = (ui.$validatorScope && ui.$validatorScope.length) ? ui.$validatorScope.val() : 'normal';
		self._postJson($, window.wspVars.validateReleaseUrl, { project_id: projectId, scope: scope }, function (r) {
			self.renderReleaseValidation(r);
			if (r.summary && r.summary.ready) WSP.ui.notify(WSP.lang('WSP_VALIDATOR_READY'), 'success');
			else WSP.ui.notify(WSP.lang('WSP_VALIDATOR_NOT_READY'), 'warning');
		}, function (r) {
			WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), 'error');
		}, function () {
			ui.$runValidatorBtn.prop('disabled', false);
		});
	},
	applyValidationFixes: function ($) {
		var self = this;
		var ui = self._getUI();
		var projectId = WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || 0;
		if (!projectId) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_NEED_PROJECT'), 'warning');
		if (!window.wspVars || !window.wspVars.applyValidationFixesUrl) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), 'error');
		if (!self._canWrite()) return self._notifyLocked();
		if (!self._confirmLoseChanges()) return;

		WSP.ui.confirm(WSP.lang('WSP_VALIDATOR_APPLY_SAFE_FIXES_CONFIRM'), function () {
			ui.$applyFixesBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + WSP.lang('WSP_PROCESSING'));
			var scope = (ui.$validatorScope && ui.$validatorScope.length) ? ui.$validatorScope.val() : 'normal';
			self._postJson($, window.wspVars.applyValidationFixesUrl, { project_id: projectId, scope: scope }, function (r) {
				var applied = r.applied || 0;
				WSP.ui.notify(WSP.lang('WSP_VALIDATOR_FIXES_APPLIED', { '%d': applied }), applied > 0 ? 'success' : 'info');
				if (r.report) self.renderReleaseValidation(r.report);
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

		// 3) VALIDADOR phpBB / CHECKLIST
		$body.on('click.wsp_tools', '#validate-release-project, #run-release-validator', function (e) {
			e.preventDefault();
			self.runReleaseValidation($);
		});

		$body.on('click.wsp_tools', '#apply-release-fixes', function (e) {
			e.preventDefault();
			self.applyValidationFixes($);
		});

		$body.on('click.wsp_tools', '.wsp-epv-install-btn', function (e) {
			e.preventDefault();
			if (!window.wspVars || !window.wspVars.epvInstallUrl) return WSP.ui.notify(WSP.lang('WSP_TOOLS_SEARCH_INTERFACE_ERROR'), 'error');
			var $btn = jQuery(this);
			WSP.ui.confirm(WSP.lang('WSP_EPV_INSTALL_CONFIRM'), function () {
				$btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + self._escape(WSP.lang('WSP_EPV_INSTALLING')));
				WSP.ui.notify(WSP.lang('WSP_EPV_INSTALLING'), 'info');
				self._postJson($, window.wspVars.epvInstallUrl, {}, function (r) {
					WSP.ui.notify((r && r.message) ? r.message : WSP.lang('WSP_EPV_INSTALL_SUCCESS'), 'success');
					self.runReleaseValidation($);
				}, function (r) {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_CRITICAL'), 'error');
					$btn.prop('disabled', false);
				});
			});
		});

		$body.on('click.wsp_tools', '#export-validation-report', function (e) {
			e.preventDefault();
			self.exportValidationReport();
		});

		$body.on('click.wsp_tools', '#download-submission-package', function (e) {
			e.preventDefault();
			var report = self._lastValidationReport || {};
			var summary = report.summary || {};
			if (!summary.ready || summary.scope !== 'phpbb_ext_db') {
				WSP.ui.notify(WSP.lang('WSP_VALIDATOR_RUN_EXTDB_FIRST'), 'warning');
				return;
			}
			var pid = parseInt(String(WSP.activeProjectId || (window.wspVars && window.wspVars.activeProjectId) || 0), 10) || 0;
			var u = String((window.wspVars && window.wspVars.downloadSubmissionUrl) || '');
			if (!pid || !u) return;
			if (u.indexOf('project_id=0') !== -1) u = u.replace('project_id=0', 'project_id=' + pid);
			else u = u.replace(/\/0(\b|\/)?/, '/' + pid);
			window.location.href = u;
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