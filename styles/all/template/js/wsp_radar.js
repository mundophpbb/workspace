/** Workspace IDE 1.12.0 — Radar: bounded static diagnostics, no code execution. */
(function (window, $) {
	'use strict';
	if (!window.WSP || !$) return;

	WSP.radar = {
		_running: false,
		_queued: false,
		_timer: null,
		_lastReport: null,
		_lastProject: 0,
		_filter: 'all',
		_stale: false,

		_lang: function (key) { return WSP.lang(key); },
		_escape: function (text) { return WSP._escapeHtml(text); },
		_project: function () { return parseInt(WSP.activeProjectId || 0, 10) || 0; },
		_allowed: function () {
			return !!(this._project() > 0 && window.wspVars && window.wspVars.radarUrl &&
				window.wspVars.activeCanEdit == 1 && WSP.canEditActiveProjectUI && WSP.canEditActiveProjectUI());
		},
		_status: function (text) {
			$('#wsp-radar-status').text(text);
		},
		_isDirty: function () {
			var ed = WSP.editor;
			return !!(ed && typeof ed.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && ed.getValue() !== WSP.originalContent);
		},
		_markStale: function () {
			this._stale = true;
			$('#wsp-radar-status').text(this._lang('WSP_RADAR_STALE'));
			$('#wsp-radar-count').attr('title', this._lang('WSP_RADAR_STALE'));
		},
		schedule: function (delay) {
			if (!this._allowed()) return;
			var self = this;
			if (this._timer) clearTimeout(this._timer);
			this._markStale();
			this._timer = setTimeout(function () {
				self._timer = null;
				if (self._isDirty()) {
					self._status(self._lang('WSP_RADAR_SAVE_FIRST'));
					return;
				}
				self.scan();
			}, Math.max(300, delay || 1100));
		},
		scan: function () {
			var self = this;
			if (!self._allowed()) return;
			if (self._isDirty()) {
				self._status(self._lang('WSP_RADAR_SAVE_FIRST'));
				return;
			}
			if (self._running) { self._queued = true; return; }
			var projectId = self._project();
			self._running = true;
			self._queued = false;
			$('#wsp-radar-scan').prop('disabled', true);
			self._status(self._lang('WSP_RADAR_RUNNING'));
			var completed = false;
			var request = WSP.ajaxPostJson(window.wspVars.radarUrl, { project_id: projectId }, function (response) {
				if (self._project() !== projectId) return;
				completed = true;
				self._lastReport = response;
				self._lastProject = projectId;
				self._stale = false;
				self.render();
			}, 'WSP_RADAR_FAILED');
			if (request && typeof request.always === 'function') {
				request.always(function () {
					self._running = false;
					$('#wsp-radar-scan').prop('disabled', false);
					if (self._queued) { self._queued = false; self.schedule(1000); }
					else if (!completed) self._status(self._lang('WSP_RADAR_FAILED'));
				});
			} else {
				self._running = false;
				$('#wsp-radar-scan').prop('disabled', false);
			}
		},
		render: function () {
			if (!this._lastReport || this._lastProject !== this._project()) return;
			var report = this._lastReport;
			var counts = report.summary || {};
			var issues = report.issues || [];
			var count = (parseInt(counts.errors, 10) || 0) + (parseInt(counts.warnings, 10) || 0);
			var $count = $('#wsp-radar-count');
			$count.text(count).prop('hidden', !count);
			var parts = [
				'<span class="wsp-radar-summary-number is-error"><i class="fa fa-times-circle"></i> ' + (parseInt(counts.errors, 10) || 0) + ' ' + this._escape(this._lang('WSP_RADAR_ERRORS')) + '</span>',
				'<span class="wsp-radar-summary-number is-warning"><i class="fa fa-exclamation-triangle"></i> ' + (parseInt(counts.warnings, 10) || 0) + ' ' + this._escape(this._lang('WSP_RADAR_WARNINGS')) + '</span>',
				'<span class="wsp-radar-summary-number"><i class="fa fa-info-circle"></i> ' + (parseInt(counts.notices, 10) || 0) + ' ' + this._escape(this._lang('WSP_RADAR_NOTICES')) + '</span>'
			];
			$('#wsp-radar-summary').html(parts.join(''));
			var incomplete = !!counts.truncated || !!counts.skipped || !!counts.omitted;
			$('#wsp-radar-limits').text(incomplete ? this._lang('WSP_RADAR_LIMITS') + ' (' +
				(parseInt(counts.skipped, 10) || 0) + ' / ' +
				(parseInt(counts.omitted, 10) || 0) + ')' : '').prop('hidden', !incomplete);
			this._status(this._stale ? this._lang('WSP_RADAR_STALE') : this._lang('WSP_RADAR_COMPLETE'));
			var html = [];
			for (var i = 0; i < issues.length; i++) {
				var issue = issues[i] || {};
				var severity = ['error', 'warning', 'info'].indexOf(issue.severity) !== -1 ? issue.severity : 'info';
				if (this._filter !== 'all' && this._filter !== severity) continue;
				var canJump = parseInt(issue.file_id, 10) > 0;
				var file = this._escape(issue.file || '');
				var line = Math.max(1, parseInt(issue.line, 10) || 1);
				html.push('<li class="wsp-radar-item is-' + severity + '"><button type="button" class="wsp-radar-issue" data-issue-index="' + i + '"' + (canJump ? '' : ' disabled') + '>');
				html.push('<span class="wsp-radar-label"><span class="wsp-radar-severity">' + this._escape(this._lang('WSP_RADAR_LEVEL_' + severity.toUpperCase())) + '</span><code>' + this._escape(issue.rule || '') + '</code></span>');
				html.push('<strong>' + this._escape(issue.message || '') + '</strong>');
				html.push('<small>' + file + (file ? ':' + line : '') + '</small>');
				html.push('<span class="wsp-radar-hint">' + this._escape(issue.suggestion || '') + '</span>');
				html.push('</button></li>');
			}
			$('#wsp-radar-list').html(html.length ? html.join('') : '<li class="wsp-radar-empty">' + this._escape(this._lang('WSP_RADAR_NO_ISSUES')) + '</li>');
		},
		_goto: function (line) {
			var editor = WSP.editor && typeof WSP.editor.gotoLine === 'function' ? WSP.editor : null;
			if (!editor) return;
			editor.gotoLine(line, 0, true);
			if (editor.selection && editor.selection.clearSelection) editor.selection.clearSelection();
			editor.focus();
		},
		openIssue: function (index) {
			if (!this._lastReport || this._lastProject !== this._project()) return;
			var issue = (this._lastReport.issues || [])[index];
			if (!issue) return;
			var fileId = parseInt(issue.file_id, 10) || 0;
			var line = Math.max(1, parseInt(issue.line, 10) || 1);
			if (!fileId) return;
			if (this._isDirty()) {
				this._status(this._lang('WSP_RADAR_NAV_UNSAVED'));
				return;
			}
			if (String(WSP.activeFileId || '') === String(fileId)) {
				$('#wsp-radar-modal').fadeOut(150);
				this._goto(line);
				return;
			}
			var $link = $('.load-file').filter(function () { return parseInt(this.getAttribute('data-id'), 10) === fileId; }).first();
			if (!$link.length) { this._status(this._lang('WSP_RADAR_FILE_NOT_FOUND')); return; }
			var self = this;
			$(document).off('wsp:file-loaded.wsp_radar').on('wsp:file-loaded.wsp_radar', function (_event, openedId) {
				if (String(openedId) !== String(fileId)) return;
				$(document).off('wsp:file-loaded.wsp_radar');
				$('#wsp-radar-modal').fadeOut(150);
				self._goto(line);
			});
			// Clear a pending jump if loading fails, is cancelled, or becomes stale.
			setTimeout(function () { $(document).off('wsp:file-loaded.wsp_radar'); }, 15000);
			$link.trigger('click');
		},

		exportReport: function () {
			if (!this._lastReport || !this._lastReport.issues) {
				this._status(this._lang('WSP_RADAR_EXPORT_EMPTY'));
				return;
			}
			var issues = this._lastReport.issues;
			var lines = [this._lang('WSP_RADAR_EXPORT_TITLE'), '============================', ''];
			for (var i = 0; i < issues.length; i++) {
				var issue = issues[i] || {};
				var severity = (issue.severity || 'info').toUpperCase();
				var file = issue.file || '';
				var line = issue.line || '';
				var msg = issue.message || '';
				lines.push('[' + severity + '] ' + file + (line ? ':' + line : ''));
				lines.push(msg);
				if (issue.rule) lines.push(this._lang('WSP_RADAR_EXPORT_RULE') + ': ' + issue.rule);
				lines.push('');
			}
			var text = lines.join('\n');
			var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'workspace-radar-report.txt';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(a.href);
		},

				bindEvents: function () {
			var self = this;
			if (!self._allowed()) return;
			$('body').on('click.wsp_radar', '#open-radar', function () {
				$('#wsp-radar-modal').fadeIn(150);
				if (!self._lastReport || self._lastProject !== self._project()) self.schedule(350);
				else self.render();
			});
			$('body').on('click.wsp_radar', '#wsp-radar-scan', function () { self.schedule(350); });
			$('body').on('click.wsp_radar', '#wsp-radar-export', function () { self.exportReport(); });
			$('body').on('change.wsp_radar', '#wsp-radar-filter', function () {
				self._filter = String($(this).val() || 'all');
				self.render();
			});
			$('body').on('click.wsp_radar', '.wsp-radar-issue', function () { self.openIssue(parseInt($(this).attr('data-issue-index'), 10) || 0); });
			// A successful mutation (not a failed HTTP response) invalidates the cached report.
			$(document).on('ajaxSuccess.wsp_radar', function (_event, xhr, settings) {
				var url = String((settings && settings.url) || '');
				if (!/\/workspace\/(save|add_file|upload|upload_zip|rename_file|move_file|delete_file|delete_folder|rename_folder|replace|validation_fix|file_version_restore|generator\/apply|refactor\/apply)(?:[?&#/]|$)/.test(url)) return;
				var response = WSP._parseJsonResponse ? WSP._parseJsonResponse(xhr.responseText) : null;
				if (!response || !response.success) return;
				self.schedule(1200);
			});
			// Indicate that findings refer to the saved source, not unsaved ACE edits.
			var ed = WSP.editor;
			if (ed && ed.session && ed.session.on) {
				ed.session.on('change', function () {
					setTimeout(function () {
						if (self._isDirty() && self._lastReport) self._status(self._lang('WSP_RADAR_SAVE_FIRST'));
					}, 250);
				});
			}
			self.schedule(1400);
		}
	};
})(window, window.jQuery);
