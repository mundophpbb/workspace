/** Workspace IDE 1.18.0 - guarded, literal project-wide search and refactoring. */
(function (window, $) {
	'use strict';
	if (!window.WSP || !$) return;
	function id(value) { return Math.max(0, parseInt(value, 10) || 0); }
	WSP.refactor = {
		previewData: null, busy: false, sequence: 0,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return id(WSP.activeProjectId); },
		allowed: function () { return !!(this.projectId() && window.wspVars &&
			id(window.wspVars.activeCanEdit) && window.wspVars.refactorPreviewUrl); },
		dirty: function () { return !!(WSP.editor && typeof WSP.editor.getValue === 'function' &&
			WSP.activeFileId && typeof WSP.originalContent === 'string' &&
			WSP.editor.getValue() !== WSP.originalContent); },
		status: function (message) { $('#wsp-rf-status').text(String(message || '')); },
		reset: function () {
			this.previewData = null;
			$('#wsp-rf-results').prop('hidden', true);
			$('#wsp-rf-rows,#wsp-rf-summary').empty();
			$('#wsp-rf-apply').prop('disabled', true);
		},
		options: function () {
			return {
				project_id: this.projectId(), search: String($('#wsp-rf-search').val() || ''),
				replace: String($('#wsp-rf-replace').val() || ''),
				path_filter: String($('#wsp-rf-filter').val() || ''),
				case_sensitive: $('#wsp-rf-case').prop('checked') ? 1 : 0,
				whole_word: $('#wsp-rf-whole').prop('checked') ? 1 : 0
			};
		},
		open: function () {
			if (!this.allowed()) return;
			this.reset(); this.status(this.lang('WSP_RF_READY'));
			$('#wsp-rf-modal').fadeIn(150);
			$('#wsp-rf-search').trigger('focus');
		},
		request: function (url, data, done) {
			if (this.busy || !url) return;
			var self = this, project = this.projectId(), serial = ++this.sequence;
			this.busy = true;
			$('#wsp-rf-preview,#wsp-rf-apply').prop('disabled', true);
			$.ajax({url: WSP.normalizeUrl(url), type: 'POST', dataType: 'json',
				data: WSP.withCsrf(data), cache: false, timeout: 60000})
			.done(function (response) {
				if (serial !== self.sequence || self.projectId() !== project) return;
				if (response && response.success && id(response.project_id) === project) done(response);
				else self.status(response && response.error || self.lang('WSP_RF_FAILED'));
			}).fail(function () {
				if (serial === self.sequence && self.projectId() === project)
					self.status(self.lang('WSP_RF_FAILED'));
			}).always(function () {
				if (serial !== self.sequence) return;
				self.busy = false;
				$('#wsp-rf-preview').prop('disabled', false);
				self.syncApply();
			});
		},
		search: function () {
			if (!this.allowed() || this.busy) return;
			if (this.dirty()) { this.status(this.lang('WSP_RF_UNSAVED')); return; }
			var data = this.options();
			this.reset();
			if (data.search.length < 2) { this.status(this.lang('WSP_RF_INVALID')); return; }
			this.status(this.lang('WSP_RF_RUNNING'));
			var self = this;
			this.request(window.wspVars.refactorPreviewUrl, data, function (response) {
				self.previewData = response;
				self.render();
			});
		},
		render: function () {
			var report = this.previewData;
			if (!report || id(report.project_id) !== this.projectId()) return;
			var rows = Array.isArray(report.files) ? report.files : [];
			var summary = report.summary || {};
			$('#wsp-rf-results').prop('hidden', false);
			$('#wsp-rf-summary').text(id(summary.matches) + ' ' + this.lang('WSP_RF_OCCURRENCES') +
				' · ' + id(summary.matching_files) + ' ' + this.lang('WSP_RF_FILES'));
			if (!summary.complete) this.status(this.lang('WSP_RF_PARTIAL'));
			else this.status(rows.length ? this.lang('WSP_RF_PREVIEW_READY') : this.lang('WSP_RF_EMPTY'));
			var host = $('#wsp-rf-rows').empty(), self = this;
			rows.forEach(function (file) {
				var item = $('<div/>', {'class':'wsp-rf-file'}).appendTo(host);
				var header = $('<div/>', {'class':'wsp-rf-file-heading'}).appendTo(item);
				$('<input/>', {type:'checkbox', 'class':'wsp-rf-select'})
					.attr('data-file-id', id(file.file_id))
					.prop('checked', !!(summary.complete && report.can_replace && file.changed))
					.prop('disabled', !(summary.complete && report.can_replace && file.changed))
					.appendTo(header);
				$('<strong/>').text(String(file.path || '')).appendTo(header);
				$('<small/>').text(id(file.matches) + ' ' + self.lang('WSP_RF_OCCURRENCES')).appendTo(header);
				(Array.isArray(file.samples) ? file.samples : []).forEach(function (sample) {
					var jump = $('<button/>', {type:'button','class':'wsp-rf-line'})
						.attr('data-file-id', id(file.file_id))
						.attr('data-line', id(sample.line)).appendTo(item);
					$('<span/>', {'class':'wsp-rf-line-number'}).text('L' + id(sample.line)).appendTo(jump);
					$('<code/>').text(String(sample.text || '')).appendTo(jump);
				});
			});
			this.syncApply();
		},
		syncApply: function () {
			var report = this.previewData;
			var enabled = !!(report && report.can_replace && report.summary &&
				report.summary.complete && !this.busy && !this.dirty() && this.projectId() === id(report.project_id) &&
				$('#wsp-rf-rows .wsp-rf-select:checked:not(:disabled)').length);
			$('#wsp-rf-apply').prop('disabled', !enabled);
		},
		jump: function (fileId, line) {
			if (this.dirty()) { this.status(this.lang('WSP_RF_UNSAVED')); return; }
			var editor = WSP.editor;
			function move() {
				if (editor && typeof editor.gotoLine === 'function') {
					editor.gotoLine(Math.max(1, line), 0, true);
					if (typeof editor.focus === 'function') editor.focus();
				}
				$('#wsp-rf-modal').fadeOut(130);
			}
			if (id(WSP.activeFileId) === fileId) { move(); return; }
			var link = $('.load-file').filter(function () {
				return id(this.getAttribute('data-id')) === fileId;
			}).first();
			if (!link.length) { this.status(this.lang('WSP_RF_NOT_FOUND')); return; }
			$(document).off('wsp:file-loaded.wsp_rf').on('wsp:file-loaded.wsp_rf', function (_ev, openedId) {
				if (id(openedId) !== fileId) return;
				$(document).off('wsp:file-loaded.wsp_rf'); move();
			});
			window.setTimeout(function () { $(document).off('wsp:file-loaded.wsp_rf'); }, 15000);
			link.trigger('click');
		},
		apply: function () {
			var report = this.previewData;
			if (!report || this.busy || !report.can_replace || !report.summary.complete || this.dirty()) {
				this.status(this.lang('WSP_RF_UNSAVED')); return;
			}
			var files = [];
			$('#wsp-rf-rows .wsp-rf-select:checked:not(:disabled)').each(function () {
				files.push(id(this.getAttribute('data-file-id')));
			});
			if (!files.length || !window.confirm(this.lang('WSP_RF_CONFIRM'))) return;
			var data = this.options();
			data.preview_token = String(report.preview_token || '');
			data.file_ids = JSON.stringify(files);
			var self = this;
			this.status(this.lang('WSP_RF_APPLYING'));
			this.request(window.wspVars.refactorApplyUrl, data, function (response) {
				self.reset();
				self.status(self.lang(response.checkpoint_id ? 'WSP_RF_APPLIED_CHECKPOINT' : 'WSP_RF_APPLIED_HISTORY'));
				if (WSP.ui && typeof WSP.ui.notify === 'function')
					WSP.ui.notify(self.lang('WSP_RF_APPLIED'), 'success');
				// Invalidate ACE's original content and the tree after changes across files.
				window.location.reload();
			});
		},
		init: function () {},
		bindEvents: function () {
			var self = this;
			$('body').off('.wsp_rf');
			$('body').on('click.wsp_rf', '#wsp-rf-open', function () { self.open(); });
			$('body').on('click.wsp_rf', '#wsp-rf-close', function () { $('#wsp-rf-modal').fadeOut(150); });
			$('body').on('click.wsp_rf', '#wsp-rf-preview', function () { self.search(); });
			$('body').on('click.wsp_rf', '#wsp-rf-apply', function () { self.apply(); });
			$('body').on('change.wsp_rf', '#wsp-rf-rows .wsp-rf-select', function () { self.syncApply(); });
			$('body').on('click.wsp_rf', '#wsp-rf-select-all', function () {
				$('#wsp-rf-rows .wsp-rf-select:not(:disabled)').prop('checked', true); self.syncApply();
			});
			$('body').on('click.wsp_rf', '#wsp-rf-unselect-all', function () {
				$('#wsp-rf-rows .wsp-rf-select').prop('checked', false); self.syncApply();
			});
			$('body').on('click.wsp_rf', '#wsp-rf-rows .wsp-rf-line', function () {
				self.jump(id(this.getAttribute('data-file-id')), id(this.getAttribute('data-line')));
			});
			$('body').on('input.wsp_rf change.wsp_rf', '#wsp-rf-search,#wsp-rf-replace,#wsp-rf-filter,#wsp-rf-case,#wsp-rf-whole',
				function () { if (self.previewData) { self.reset(); self.status(self.lang('WSP_RF_STALE')); } });
			// Any successful write in this tab invalidates a preview, even when another tool makes it.
			$(document).off('ajaxSuccess.wsp_rf').on('ajaxSuccess.wsp_rf', function (_event, xhr, settings) {
				if (!self.previewData) return;
				if (!/\/workspace\/(save|add_file|upload|upload_zip|rename_file|move_file|delete_file|replace|validation_fix|generator\/apply|checkpoints\/restore)(?:[?&#/]|$)/.test(String(settings && settings.url || ''))) return;
				var result = WSP._parseJsonResponse ? WSP._parseJsonResponse(xhr.responseText) : null;
				if (result && result.success) { self.reset(); self.status(self.lang('WSP_RF_STALE')); }
			});
			$(document).off('keydown.wsp_rf').on('keydown.wsp_rf', function (event) {
				if (event.key === 'Escape' && $('#wsp-rf-modal').is(':visible')) $('#wsp-rf-modal').fadeOut(130);
			});
		}
	};
})(window, window.jQuery);
