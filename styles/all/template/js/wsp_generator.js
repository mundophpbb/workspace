/** Workspace IDE 1.13.0 - visual phpBB scaffolds. No eval, HTML injection or auto writes. */
(function (window, $) {
	'use strict';
	if (!window.WSP || !$) return;

	WSP.generator = {
		_token: '',
		_previewProject: 0,
		_files: [],
		_busy: false,
		_applying: false,
		_serial: 0,

		_lang: function (key) { return WSP.lang(key); },
		_project: function () { return parseInt(WSP.activeProjectId || 0, 10) || 0; },
		_allowed: function () {
			return this._project() > 0 && window.wspVars &&
				!!window.wspVars.generatorPreviewUrl && !!window.wspVars.generatorApplyUrl &&
				window.wspVars.activeCanEdit == 1 && window.wspVars.activeCanRenameMove == 1 &&
				(!WSP.canEditActiveProjectUI || WSP.canEditActiveProjectUI());
		},
		_unsaved: function () {
			var ed = WSP.editor;
			return !!(ed && typeof ed.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && ed.getValue() !== WSP.originalContent);
		},
		_status: function (message) { $('#wsp-gen-status').text(message); },
		_invalidate: function () {
			this._serial++;
			this._busy = false;
			this._token = '';
			this._files = [];
			this._previewProject = 0;
			$('#wsp-gen-apply').prop('disabled', true);
			$('#wsp-gen-results').prop('hidden', true);
			$('#wsp-gen-files').empty();
			$('#wsp-gen-code').empty();
			$('#wsp-gen-extension').text('');
			$('#wsp-gen-refresh').prop('hidden', true);
		},
		_testKind: function () {
			return /^test_(?:service|controller|permission)$/.test(String($('#wsp-gen-kind').val() || ''));
		},
		_updateGuide: function () {
			var test = this._testKind();
			$('#wsp-gen-test-hint').prop('hidden', !test);
			$('#wsp-gen-feature-label').text(this._lang(test ? 'WSP_TEST_FEATURE' : 'WSP_GEN_FEATURE'));
			$('#wsp-gen-feature').attr('placeholder',
				test && $('#wsp-gen-kind').val() === 'test_permission' ? 'u_exemplo_permissao' :
				(test ? 'nome_da_classe' : 'minha_funcionalidade'));
		},
		_form: function () {
			return {
				project_id: this._project(),
				kind: String($('#wsp-gen-kind').val() || ''),
				feature: String($('#wsp-gen-feature').val() || '').trim(),
				event: String($('#wsp-gen-event').val() || '')
			};
		},
		_error: function (error) {
			var message = String(error || this._lang('WSP_ERROR_CRITICAL'));
			this._status(message);
			if (WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(message, 'error');
		},
		_request: function (url, data, success, serial) {
			var self = this;
			var request = $.ajax({
				url: WSP.normalizeUrl(url), type: 'POST', data: WSP.withCsrf(data),
				dataType: 'json', timeout: 30000, cache: false
			});
			request.done(function (r) {
				if (serial !== self._serial) return;
				self._busy = false;
				self._applying = false;
				$('#wsp-gen-kind, #wsp-gen-feature, #wsp-gen-event, #wsp-gen-preview').prop('disabled', false);
				if (r && r.success) success(r);
				else self._error(r && r.error);
			}).fail(function (xhr) {
				if (serial !== self._serial) return;
				self._busy = false;
				self._applying = false;
				$('#wsp-gen-kind, #wsp-gen-feature, #wsp-gen-event, #wsp-gen-preview').prop('disabled', false);
				self._error(self._lang('WSP_ERROR_CRITICAL') + (xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : ''));
			});
		},
		preview: function () {
			if (!this._allowed() || this._busy) return;
			if (this._unsaved()) { this._status(this._lang('WSP_GEN_UNSAVED')); return; }
			var form = this._form();
			this._invalidate();
			if (!/^[a-z][a-z0-9_]{1,31}$/.test(form.feature)) {
				this._status(this._lang('WSP_GEN_INVALID')); return;
			}
			var self = this, serial = this._serial;
			this._busy = true;
			$('#wsp-gen-preview').prop('disabled', true);
			this._status(this._lang('WSP_GEN_WORKING'));
			this._request(window.wspVars.generatorPreviewUrl, form, function (r) {
				if (!Array.isArray(r.files) || !r.files.length || !/^[a-f0-9]{64}$/.test(String(r.preview_token || ''))) {
					self._error(self._lang('WSP_ERROR_CRITICAL')); return;
				}
				self._token = String(r.preview_token);
				self._files = r.files;
				self._previewProject = form.project_id;
				$('#wsp-gen-extension').text(String(r.extension || ''));
				var $files = $('#wsp-gen-files').empty();
				r.files.forEach(function (file, index) {
					var label = self._lang(file.action === 'modify' ? 'WSP_GEN_MODIFY' : 'WSP_GEN_NEW');
					$('<button/>', { type: 'button', 'class': 'wsp-generator-file' })
						.attr('data-index', index).text(label + ' - ' + String(file.path || ''))
						.appendTo($files);
				});
				$('#wsp-gen-results').prop('hidden', false);
				self.showFile(0);
				$('#wsp-gen-apply').prop('disabled', false);
				self._status(self._lang('WSP_GEN_READY'));
			}, serial);
		},
		showFile: function (index) {
			var file = this._files[index];
			if (!file) return;
			$('#wsp-gen-files .wsp-generator-file').removeClass('is-active').filter(function () {
				return parseInt(this.getAttribute('data-index'), 10) === index;
			}).addClass('is-active');
			$('#wsp-gen-code').text(String(file.content || ''));
		},
		apply: function () {
			if (!this._allowed() || this._busy || !this._token ||
				this._previewProject !== this._project()) return;
			if (this._unsaved()) { this._status(this._lang('WSP_GEN_UNSAVED')); return; }
			var form = this._form();
			if (!/^[a-z][a-z0-9_]{1,31}$/.test(form.feature)) return;
			form.preview_token = this._token;
			var self = this, serial = this._serial;
			this._busy = true;
			this._applying = true;
			$('#wsp-gen-kind, #wsp-gen-feature, #wsp-gen-event, #wsp-gen-preview').prop('disabled', true);
			$('#wsp-gen-apply').prop('disabled', true);
			this._status(this._lang('WSP_GEN_SAVING'));
			this._request(window.wspVars.generatorApplyUrl, form, function () {
				self._invalidate();
				self._status(self._lang('WSP_GEN_DONE'));
				$('#wsp-gen-preview').prop('disabled', true);
				$('#wsp-gen-refresh').prop('hidden', false);
			}, serial);
		},
		bindEvents: function () {
			var self = this;
			if (!$('#open-generator').length) return;
			$('body').on('click.wsp_generator', '#open-generator, #open-phpunit-generator', function () {
				if (!self._allowed()) return;
				if (this.id === 'open-phpunit-generator') {
					$('#wsp-gen-kind').val('test_service');
					self._invalidate();
				}
				self._updateGuide();
				$('#wsp-generator-modal').fadeIn(150);
			});
			$('body').on('click.wsp_generator', '#wsp-gen-close', function () {
				$('#wsp-generator-modal').fadeOut(150);
			});
			$('body').on('change.wsp_generator input.wsp_generator', '#wsp-gen-kind, #wsp-gen-feature, #wsp-gen-event', function () {
				if (self._applying) return;
				self._invalidate();
				$('#wsp-gen-event-field').prop('hidden', $('#wsp-gen-kind').val() !== 'listener');
				self._updateGuide();
				self._status(self._lang('WSP_GEN_START'));
			});
			$('body').on('click.wsp_generator', '#wsp-gen-preview', function () { self.preview(); });
			$('body').on('click.wsp_generator', '#wsp-gen-apply', function () { self.apply(); });
			$('body').on('click.wsp_generator', '.wsp-generator-file', function () {
				self.showFile(parseInt($(this).attr('data-index'), 10) || 0);
			});
			$('body').on('click.wsp_generator', '#wsp-gen-refresh', function () { window.location.reload(); });
			this._updateGuide();
		}
	};
})(window, window.jQuery);
