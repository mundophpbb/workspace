/** Workspace IDE 1.20.0 - bounded, read-only security review of saved project sources. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function asId(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function list(value) { return Array.isArray(value) ? value : []; }
	function key(value) { return String(value || '').replace(/[^a-z_]/g, ''); }
	var SEVERITIES = ['high', 'medium', 'low'];
	var CATEGORIES = ['sql', 'xss', 'csrf', 'authorization', 'upload', 'execution', 'input', 'secrets', 'transport'];

	WSP.securityAudit = {
		result: null, project: 0, serial: 0, busy: false, pendingProject: 0,
		projectId: function () { return asId(WSP.activeProjectId); },
		lang: function (code) { return String(WSP.lang(code) || code); },
		allowed: function () { return !!(this.projectId() && window.wspVars &&
			window.wspVars.activeCanEdit == 1 && window.wspVars.securityAuditUrl); },
		status: function (value) { $('#wsp-sec-status').text(String(value || '')); },
		open: function () {
			if (!this.allowed()) return;
			$('#wsp-sec-modal').fadeIn(120);
			// Re-scan on each open: a saved project may have changed since the previous review.
			this.scan();
		},
		scan: function () {
			if (!this.allowed() || (this.busy && this.pendingProject === this.projectId())) return;
			var self = this, project = this.projectId(), seq = ++this.serial;
			this.result = null; this.project = 0; this.busy = true; this.pendingProject = project;
			$('#wsp-sec-scan').prop('disabled', true);
			$('#wsp-sec-report').prop('disabled', true);
			$('#wsp-sec-summary,#wsp-sec-results').empty();
			this.status(this.lang('WSP_SEC_LOADING'));
			$.ajax({
				url: WSP.normalizeUrl(window.wspVars.securityAuditUrl), type: 'POST',
				dataType: 'json', cache: false, timeout: 30000,
				data: WSP.withCsrf({project_id: project})
			}).done(function (data) {
				if (seq !== self.serial || project !== self.projectId()) return;
				if (data && data.success && data.project_id == project && data.audit &&
					data.audit.static_only === true && Array.isArray(data.audit.findings)) {
					self.result = data; self.project = project;
					self.render();
					$('#wsp-sec-report').prop('disabled', false);
				} else self.status((data && data.error) || self.lang('WSP_SEC_FAILED'));
			}).fail(function () {
				if (seq === self.serial && project === self.projectId()) self.status(self.lang('WSP_SEC_FAILED'));
			}).always(function () {
				if (seq !== self.serial) return;
				self.busy = false; self.pendingProject = 0;
				$('#wsp-sec-scan').prop('disabled', false);
			});
		},
		selection: function () {
			var severity = String($('#wsp-sec-severity').val() || 'all');
			var category = String($('#wsp-sec-category').val() || 'all');
			if (SEVERITIES.indexOf(severity) < 0) severity = 'all';
			if (CATEGORIES.indexOf(category) < 0) category = 'all';
			return {severity: severity, category: category};
		},
		eligible: function (finding, filters) {
			return (filters.severity === 'all' || finding.severity === filters.severity) &&
				(filters.category === 'all' || finding.category === filters.category);
		},
		label: function (finding) {
			var code = String(finding.code || '').replace(/[^A-Z_]/g, '');
			return this.lang('WSP_SEC_RULE_' + code);
		},
		advice: function (finding) {
			var code = String(finding.code || '').replace(/[^A-Z_]/g, '');
			return this.lang('WSP_SEC_ADVICE_' + code);
		},
		render: function () {
			if (!this.result || this.project !== this.projectId()) return;
			var self = this, audit = this.result.audit || {}, findings = list(audit.findings);
			var summary = audit.summary || {}, counts = summary.by_severity || {};
			var coverage = this.result.coverage || {}, secondary = audit.coverage || {};
			var incomplete = coverage.complete === false || secondary.complete === false;
			var summaryBox = $('#wsp-sec-summary').empty();
			[['high','WSP_SEC_HIGH'], ['medium','WSP_SEC_MEDIUM'], ['low','WSP_SEC_LOW']].forEach(function (item) {
				$('<span/>',{'class':'wsp-sec-stat wsp-sec-stat-' + item[0]})
					.text((asId(counts[item[0]])) + ' ' + self.lang(item[1])).appendTo(summaryBox);
			});
			$('<span/>',{'class':'wsp-sec-stat'}).text(asId(summary.analyzed_files) + ' ' + this.lang('WSP_SEC_FILES')).appendTo(summaryBox);
			var filters = this.selection();
			var output = $('#wsp-sec-results').empty(), visible = 0;
			findings.forEach(function (finding) {
				if (!self.eligible(finding, filters)) return;
				visible++;
				var level = SEVERITIES.indexOf(finding.severity) >= 0 ? finding.severity : 'low';
				var category = CATEGORIES.indexOf(finding.category) >= 0 ? finding.category : 'input';
				var row = $('<article/>', {'class': 'wsp-sec-item wsp-sec-' + level}).appendTo(output);
				var headline = $('<div/>', {'class':'wsp-sec-item-heading'}).appendTo(row);
				$('<strong/>').text(self.label(finding)).appendTo(headline);
				$('<span/>',{'class':'wsp-sec-level'}).text(self.lang('WSP_SEC_' + level.toUpperCase())).appendTo(headline);
				$('<p/>', {'class':'wsp-sec-description'}).text(self.advice(finding)).appendTo(row);
				var meta = $('<div/>', {'class':'wsp-sec-item-meta'}).appendTo(row);
				$('<span/>').text(self.lang('WSP_SEC_CATEGORY_' + category.toUpperCase()) + ' · ' +
					self.lang('WSP_SEC_CONFIDENCE') + ': ' + self.lang('WSP_SEC_CONFIDENCE_' +
					key(finding.confidence).toUpperCase())).appendTo(meta);
				var fileId = asId(finding.file_id), line = Math.max(1, asId(finding.line));
				var location = String(finding.file || '?') + ':' + line;
				if (fileId) {
					$('<button/>',{type:'button','class':'wsp-sec-open-source'})
						.attr('data-id',fileId).attr('data-line',line)
						.text(location).appendTo(meta);
				} else $('<code/>').text(location).appendTo(meta);
			});
			if (!visible) $('<p/>',{'class':'wsp-sec-empty'}).text(findings.length ?
				this.lang('WSP_SEC_NO_MATCHES') : this.lang('WSP_SEC_NOT_DETECTED')).appendTo(output);
			this.status((incomplete ? this.lang('WSP_SEC_PARTIAL') + ' ' : '') +
				this.lang('WSP_SEC_FOUND') + ': ' + findings.length + ' — ' +
				this.lang('WSP_SEC_VISIBLE') + ': ' + visible);
		},
		report: function () {
			if (!this.result || this.project !== this.projectId()) return '';
			var self = this, audit = this.result.audit, summary = audit.summary || {};
			var coverage = this.result.coverage || {}, local = audit.coverage || {};
			var out = [this.lang('WSP_SEC_TITLE'), 'Projeto ID: ' + this.project,
				this.lang('WSP_SEC_STATIC_WARNING'),
				this.lang('WSP_SEC_FOUND') + ': ' + list(audit.findings).length,
				this.lang('WSP_SEC_FILES') + ': ' + (summary.analyzed_files || 0),
				this.lang('WSP_SEC_PARTIAL') + ': ' + (coverage.complete === false || local.complete === false ? 'SIM' : 'NAO'), ''];
			list(audit.findings).forEach(function (f) {
				out.push('[' + String(f.severity || '?').toUpperCase() + ' / ' + String(f.confidence || '?') +
					'] ' + self.label(f) + ' (' + String(f.file || '') + ':' + asId(f.line) + ')');
				out.push('  ' + self.advice(f));
			});
			return out.join('\n') + '\n';
		},
		downloadReport: function () {
			var content = this.report();
			if (!content) return false;
			var blob = new window.Blob([content],{type:'text/plain;charset=utf-8'});
			var uri = window.URL.createObjectURL(blob);
			var a = window.document.createElement('a');
			a.href = uri; a.download = 'workspace-auditoria-seguranca.txt';
			window.document.body.appendChild(a); a.click(); window.document.body.removeChild(a);
			window.setTimeout(function () { window.URL.revokeObjectURL(uri); }, 1000);
			return true;
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		openSource: function (fileId, line) {
			if (!this.allowed() || this.project !== this.projectId()) return;
			if (this.unsaved()) {this.status(this.lang('WSP_SEC_UNSAVED')); return;}
			var self=this, project=this.projectId();
			function gotoLine() {
				if (WSP.editor && WSP.editor.gotoLine) {
					WSP.editor.gotoLine(Math.max(1,line),0,true);
					if (WSP.editor.focus) WSP.editor.focus();
				}
			}
			if (asId(WSP.activeFileId) === fileId) {$('#wsp-sec-modal').fadeOut(120);gotoLine();return;}
			var link = $('.load-file[data-id]').filter(function () {return asId(this.getAttribute('data-id')) === fileId;}).first();
			if (!link.length) {this.status(this.lang('WSP_SEC_NOT_IN_TREE'));return;}
			$(document).off('wsp:file-loaded.wsp_sec').on('wsp:file-loaded.wsp_sec',function (_event, opened) {
				if (project !== self.projectId() || asId(opened) !== fileId) return;
				$(document).off('wsp:file-loaded.wsp_sec');
				$('#wsp-sec-modal').fadeOut(120);gotoLine();
			});
			window.setTimeout(function () {$(document).off('wsp:file-loaded.wsp_sec');},15000);
			link.trigger('click');
		},
		bindEvents: function () {
			if (!$('#wsp-sec-modal').length) return;
			var self=this;
			$('body').on('click.wsp_sec','#wsp-sec-open',function () {self.open();});
			$('body').on('click.wsp_sec','#wsp-sec-close',function () {$('#wsp-sec-modal').fadeOut(120);});
			$('body').on('click.wsp_sec','#wsp-sec-scan',function () {self.scan();});
			$('body').on('change.wsp_sec','#wsp-sec-severity,#wsp-sec-category',function () {self.render();});
			$('body').on('click.wsp_sec','#wsp-sec-report',function () {self.downloadReport();});
			$('body').on('click.wsp_sec','.wsp-sec-open-source',function () {
				self.openSource(asId(this.getAttribute('data-id')),asId(this.getAttribute('data-line')));
			});
			$(document).on('keydown.wsp_sec',function (e) {
				if (e.key === 'Escape' && $('#wsp-sec-modal').is(':visible')) $('#wsp-sec-modal').fadeOut(120);
			});
		}
	};
})(window, window.jQuery);
