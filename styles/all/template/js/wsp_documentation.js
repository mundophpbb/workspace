/** Workspace 1.26.0: read-only preview, explicit approval, revision-protected documentation. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;

	function positive(value) { return Math.max(0, parseInt(value, 10) || 0); }
	WSP.documentation = {
		serial: 0, busy: false, previewData: null,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return positive(WSP.activeProjectId); },
		allowed: function () {
			return !!(window.wspVars && this.projectId() && positive(window.wspVars.activeCanEdit) &&
				window.wspVars.documentationPreviewUrl && window.wspVars.documentationApplyUrl &&
				(typeof WSP.canEditActiveProjectUI !== 'function' || WSP.canEditActiveProjectUI()));
		},
		dirtyEditor: function () {
			return !!(WSP.activeFileId && WSP.editor && typeof WSP.editor.getValue === 'function' &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		status: function (message) { $('#wsp-doc-status').text(String(message || '')); },
		details: function () {
			return {project_id: this.projectId(), locale: String($('#wsp-doc-locale').val() || 'pt_br'),
				target: String($('#wsp-doc-target').val() || 'generated'),
				replace_existing: $('#wsp-doc-replace').prop('checked') ? '1' : '0'};
		},
		clear: function () {
			this.serial++;
			this.previewData = null;
			$('#wsp-doc-result').prop('hidden', true);
			$('#wsp-doc-draft').val('');
			$('#wsp-doc-summary').text('');
			this.sync();
		},
		open: function () {
			if (!this.allowed()) return;
			$('#wsp-doc-replace').prop('checked', false);
			this.clear();
			this.status(this.lang('WSP_DOC_READY'));
			$('#wsp-doc-modal').fadeIn(120);
		},
		close: function () {
			this.clear();
			$('#wsp-doc-modal').fadeOut(120);
		},
		sync: function () {
			var data = this.details(), latest = this.previewData,
				good = !!(latest && latest.project_id === data.project_id &&
					latest.locale === data.locale && latest.target === data.target &&
					latest.replace_existing === data.replace_existing),
				draft = String($('#wsp-doc-draft').val() || ''),
				canText = !!(good && draft.trim() && draft.length <= 131072);
			$('#wsp-doc-preview').prop('disabled', !this.allowed() || this.busy || this.dirtyEditor());
			$('#wsp-doc-copy,#wsp-doc-download').prop('disabled', !canText);
			$('#wsp-doc-save').prop('disabled', !canText || !latest.can_write || this.busy || this.dirtyEditor());
		},
		request: function (url, data, callback) {
			if (!url || !this.allowed() || this.busy) return false;
			var self = this, start = this.projectId(), serial = ++this.serial;
			this.busy = true;
			this.sync();
			$.ajax({url: WSP.normalizeUrl(url), type:'POST', dataType:'json', cache:false, timeout:60000,
				data: WSP.withCsrf(data)}).done(function (r) {
					if (serial !== self.serial || start !== self.projectId()) return;
					if (r && r.success && positive(r.project_id) === start) callback(r);
					else self.status((r && r.error) || self.lang('WSP_DOC_FAILED'));
			}).fail(function () {
				if (serial === self.serial && start === self.projectId())
					self.status(self.lang('WSP_DOC_FAILED'));
			}).always(function () {
				// A changed selection invalidates the reply but must also free the busy state.
				self.busy = false;
				self.sync();
			});
			return true;
		},
		preview: function () {
			if (!this.allowed() || this.busy) return;
			if (this.dirtyEditor()) { this.status(this.lang('WSP_DOC_UNSAVED')); return; }
			var args = this.details(), self = this;
			this.clear();
			this.status(this.lang('WSP_DOC_SCANNING'));
			this.request(window.wspVars.documentationPreviewUrl, args, function (r) {
				self.previewData = {project_id: args.project_id, locale: args.locale, target: args.target,
					replace_existing: args.replace_existing, token: String(r.token || ''),
					can_write: !!r.can_write, exists: !!r.exists, path: String(r.path || '')};
				$('#wsp-doc-result').prop('hidden', false);
				$('#wsp-doc-draft').val(String(r.draft || ''));
				var facts = r.facts || {};
				$('#wsp-doc-summary').text(String(facts.package || '') + ' · ' +
					String(r.path || ''));
				self.status(r.locked ? self.lang('WSP_DOC_LOCKED') :
					(r.overwrite_required ? self.lang('WSP_DOC_OVERWRITE') :
					(!r.can_write ? self.lang('WSP_DOC_CANNOT_CREATE') : self.lang('WSP_DOC_PREVIEW_READY'))));
				self.sync();
			});
		},
		apply: function () {
			var args = this.details(), data = this.previewData;
			if (!data || this.busy || this.dirtyEditor() || !data.can_write ||
				data.project_id !== args.project_id || data.target !== args.target ||
				data.locale !== args.locale || data.replace_existing !== args.replace_existing) return;
			var draft = String($('#wsp-doc-draft').val() || '');
			if (!draft.trim() || draft.length > 131072) return;
			if (!window.confirm(this.lang(data.exists ? 'WSP_DOC_CONFIRM_REPLACE' : 'WSP_DOC_CONFIRM_NEW'))) return;
			args.preview_token = data.token;
			args.draft = draft;
			var self = this;
			this.status(this.lang('WSP_DOC_SAVING'));
			this.request(window.wspVars.documentationApplyUrl, args, function () {
				self.clear();
				self.status(self.lang('WSP_DOC_SAVED'));
				window.location.reload();
			});
		},
		copy: function () {
			this.sync();
			if ($('#wsp-doc-copy').prop('disabled')) return;
			var self = this, content = String($('#wsp-doc-draft').val() || '');
			if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
				window.navigator.clipboard.writeText(content).then(function () {
					self.status(self.lang('WSP_DOC_COPIED'));
				}, function () { self.status(self.lang('WSP_DOC_COPY_FAILED')); });
			} else {
				var el = $('#wsp-doc-draft')[0];
				if (el && typeof el.select === 'function' && window.document.execCommand) {
					el.select();
					self.status(window.document.execCommand('copy') ?
						self.lang('WSP_DOC_COPIED') : self.lang('WSP_DOC_COPY_FAILED'));
				} else self.status(self.lang('WSP_DOC_COPY_FAILED'));
			}
		},
		download: function () {
			this.sync();
			if ($('#wsp-doc-download').prop('disabled')) return;
			var doc = String($('#wsp-doc-draft').val() || '');
			var url = window.URL.createObjectURL(new window.Blob([doc], {type:'text/markdown;charset=utf-8'}));
			var anchor = window.document.createElement('a');
			anchor.href = url;
			anchor.download = this.details().target === 'readme' ? 'README.md' : 'README_GERADO.md';
			window.document.body.appendChild(anchor);
			anchor.click();
			window.document.body.removeChild(anchor);
			window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1500);
		},
		init: function () {},
		bindEvents: function () {
			var self = this;
			$('body').off('.wsp_documentation');
			$('body').on('click.wsp_documentation', '#wsp-doc-open', function () { self.open(); });
			$('body').on('click.wsp_documentation', '#wsp-doc-close', function () { self.close(); });
			$('body').on('click.wsp_documentation', '#wsp-doc-preview', function () { self.preview(); });
			$('body').on('click.wsp_documentation', '#wsp-doc-save', function () { self.apply(); });
			$('body').on('click.wsp_documentation', '#wsp-doc-copy', function () { self.copy(); });
			$('body').on('click.wsp_documentation', '#wsp-doc-download', function () { self.download(); });
			$('body').on('change.wsp_documentation', '#wsp-doc-locale,#wsp-doc-target,#wsp-doc-replace', function () {
				self.clear();
				self.status(self.lang('WSP_DOC_CHANGED'));
			});
			$('body').on('input.wsp_documentation', '#wsp-doc-draft', function () { self.sync(); });
		}
	};
})(window, window.jQuery);
