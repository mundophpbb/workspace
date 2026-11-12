/**
 * Mundo phpBB Workspace - Upload & DragDrop
 * Version 4.4: serialized upload queue + hosting/WAF backoff + persistent batch report with retry
 */
WSP.upload = {
	_refreshTimer: null,
	_pendingUploads: 0,
	_activeUploads: 0,
	_queue: [],
	_queueDelay: 250,
	_minDelay: 250,
	_maxDelay: 4000,
	_retryBase: 1500,
	_maxAttempts: 6,
	_slowNotified: false,
	_batchFailures: 0,
	_scanning: 0,
	_scanWatchdog: null,
	_batch: { total: 0, ok: 0, failed: [] },
	_ui: null,

	_getUI: function () {
		if (this._ui) return this._ui;
		this._ui = {
			$body: jQuery('body'),
			$zone: jQuery('#sidebar-dropzone'),
			$uploadInput: jQuery('#wsp-upload-input')
		};
		return this._ui;
	},

	_endsWith: function (str, suffix) {
		if (window.WSP && typeof WSP._endsWith === 'function') {
			return WSP._endsWith(str, suffix);
		}
		str = String(str || '');
		suffix = String(suffix || '');
		if (!suffix) return true;
		if (typeof str.endsWith === 'function') return str.endsWith(suffix);
		return str.indexOf(suffix, str.length - suffix.length) !== -1;
	},

	_canWrite: function () {
		if (window.WSP && typeof WSP.canWriteUI === 'function') {
			return !!WSP.canWriteUI();
		}
		if (!window.WSP || !WSP.activeProjectId) return false;
		if (typeof WSP.canEditActiveProjectUI === 'function') return !!WSP.canEditActiveProjectUI();
		if (WSP.activeProjectLocked && !WSP.canManageAll) return false;

		if (window.wspVars) {
			var locked = window.wspVars.activeLocked || window.wspVars.WSP_ACTIVE_LOCKED || 0;
			var canManageAll = window.wspVars.canManageAll || window.wspVars.WSP_CAN_MANAGE_ALL || 0;
			locked = (locked === true || locked === 1 || locked === '1');
			canManageAll = (canManageAll === true || canManageAll === 1 || canManageAll === '1');
			if (locked && !canManageAll) return false;
		}
		return true;
	},

	_notifyLocked: function () {
		if (window.WSP && typeof WSP.notifyLocked === 'function') {
			WSP.notifyLocked();
			return;
		}
		var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_PROJECT_LOCKED_MSG') : '';
		if (!msg || msg === '[WSP_PROJECT_LOCKED_MSG]') {
			msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PROJECT_LOCKED') : 'Project locked.';
		}
		if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
			WSP.ui.notify(msg, 'warning');
		}
	},

	_guardWrite: function () {
		if (!this._canWrite()) {
			this._notifyLocked();
			return false;
		}
		return true;
	},

	_normalizePath: function (p) {
		if (!p) return '';
		p = String(p).trim().replace(/\\/g, '/');
		p = p.replace(/^\/+/, '');
		p = p.replace(/(\.\.\/)+/g, '');
		p = p.replace(/(^|\/)\.\//g, '$1');
		p = p.replace(/\/{2,}/g, '/');
		return p;
	},

	_isIgnoredFile: function (name) {
		var ignored = ['thumbs.db', '.ds_store', 'desktop.ini', '__macosx'];
		var base = String(name || '').toLowerCase();
		for (var i = 0; i < ignored.length; i++) {
			if (base.indexOf(ignored[i]) !== -1) return true;
		}
		return false;
	},

	_isSecurityChallenge: function (xhr, body) {
		if (window.WSP && typeof WSP._isSecurityChallenge === 'function') {
			return WSP._isSecurityChallenge(xhr, body);
		}
		var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
		var text = String(body || '').toLowerCase();
		if ([403, 429, 503].indexOf(status) === -1) return false;
		return text.indexOf('checking your browser') !== -1
			|| text.indexOf('just a moment') !== -1
			|| text.indexOf('browser verification') !== -1
			|| text.indexOf('challenge-platform') !== -1;
	},

	_parseJson: function (txt) {
		if (window.WSP && typeof WSP._parseJsonResponse === 'function') {
			return WSP._parseJsonResponse(txt);
		}
		try {
			return JSON.parse(String(txt || ''));
		} catch (e) {
			return null;
		}
	},

	_escape: function (text) {
		return jQuery('<div>').text(String(text === null || typeof text === 'undefined' ? '' : text)).html();
	},

	_isIdle: function () {
		return this._pendingUploads === 0 && this._activeUploads === 0 && this._queue.length === 0 && this._scanning === 0;
	},

	_scheduleRefresh: function () {
		var self = this;
		clearTimeout(self._refreshTimer);
		self._refreshTimer = setTimeout(function () {
			if (!self._isIdle()) {
				return;
			}
			WSP.ui.seamlessRefresh();
			self._showReport();
		}, 700);
	},

	_showReport: function () {
		var self = this;
		var batch = self._batch;
		var failed = batch.failed || [];

		if (!failed.length) {
			WSP.ui.notify(WSP.lang('WSP_UPLOAD_LIST_UPDATED'), 'success');
			self._batch = { total: 0, ok: 0, failed: [] };
			self._batchFailures = 0;
			return;
		}

		var rows = '';
		for (var i = 0; i < failed.length && i < 50; i++) {
			rows += '<li><code>' + self._escape(failed[i].path) + '</code> &mdash; ' + self._escape(failed[i].reason) + '</li>';
		}
		if (failed.length > 50) {
			rows += '<li>&hellip; +' + (failed.length - 50) + '</li>';
		}

		var retryable = 0;
		for (var j = 0; j < failed.length; j++) {
			if (failed[j].job) retryable++;
		}

		var html = '<div class="wsp-upload-report">' +
			'<strong>' + self._escape(WSP.lang('WSP_UPLOAD_REPORT_PARTIAL', { '{ok}': batch.ok, '{total}': batch.total })) + '</strong>' +
			'<ul>' + rows + '</ul>' +
			(retryable ? '<button type="button" class="wsp-upload-retry">' + self._escape(WSP.lang('WSP_UPLOAD_RETRY_FAILED')) + '</button>' : '') +
			'</div>';

		jQuery('.wsp-upload-report').closest('.wsp-notification').remove();
		WSP.ui.notify(html, 'warning', true);
		self._batchFailures = failed.length;
	},

	_retryFailed: function () {
		var self = this;
		var failed = (self._batch && self._batch.failed) ? self._batch.failed : [];
		var jobs = [];
		for (var i = 0; i < failed.length; i++) {
			if (failed[i].job) jobs.push(failed[i].job);
		}
		jQuery('.wsp-upload-report').closest('.wsp-notification').remove();
		if (!jobs.length) return;
		if (!self._guardWrite()) return;

		self._batch = { total: jobs.length, ok: 0, failed: [] };
		for (var j = 0; j < jobs.length; j++) {
			jobs[j].encodedContent = null;
			self._queue.push(jobs[j]);
			self._pendingUploads++;
		}
		self._drainQueue();
	},

	_isZip: function (file) {
		return !!(file && /\.zip$/i.test(String(file.name || '')));
	},

	// Imports a whole ZIP archive with ONE request (no per-file queue).
	importZip: function (file) {
		var self = this;
		if (!file || !WSP.activeProjectId) return;
		if (!self._guardWrite()) return;

		if (!self._isZip(file)) {
			WSP.ui.notify(WSP.lang('WSP_ZIP_FILE_REQUIRED'), 'warning');
			return;
		}

		var formData = new FormData();
		formData.append('zip_file', file, file.name);
		formData.append('project_id', WSP.activeProjectId);
		formData = WSP.withCsrf(formData);

		WSP.ui.notify(WSP.lang('WSP_ZIP_IMPORTING'), 'info');

		jQuery.ajax({
			url: window.wspVars.uploadZipUrl,
			type: 'POST',
			data: formData,
			contentType: false,
			processData: false,
			dataType: 'text',
			cache: false,
			timeout: 180000
		}).done(function (txt) {
			var r = self._parseJson(txt);
			if (r && r.success) {
				self._showZipReport(r);
				WSP.ui.seamlessRefresh();
				return;
			}
			WSP.ui.notify(self._escape((r && r.error) ? r.error : WSP.lang('WSP_ERR_ZIP_UPLOAD')), 'error', true);
		}).fail(function (xhr) {
			var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
			WSP.ui.notify(self._escape(WSP.lang('WSP_UPLOAD_HTTP_ERROR', { '%s': file.name, '%d': String(status) })), 'error', true);
		});
	},

	_showZipReport: function (r) {
		var self = this;
		var skipped = (r.skipped || []).concat(r.failed || []);
		var html = '<div class="wsp-upload-report"><strong>' + self._escape(WSP.lang('WSP_ZIP_REPORT', {
			'{created}': r.created || 0,
			'{updated}': r.updated || 0,
			'{unchanged}': r.unchanged || 0
		})) + '</strong>';

		if (skipped.length) {
			html += '<div>' + self._escape(WSP.lang('WSP_ZIP_SKIPPED_HEAD')) + '</div><ul>';
			for (var i = 0; i < skipped.length && i < 50; i++) {
				html += '<li><code>' + self._escape(skipped[i][0]) + '</code> &mdash; ' + self._escape(WSP.lang('WSP_ZIP_REASON_' + skipped[i][1])) + '</li>';
			}
			if (skipped.length > 50) html += '<li>&hellip; +' + (skipped.length - 50) + '</li>';
			html += '</ul>';
		}
		html += '</div>';

		jQuery('.wsp-upload-report').closest('.wsp-notification').remove();
		WSP.ui.notify(html, skipped.length ? 'warning' : 'success', skipped.length > 0);
	},

	_scanFailed: function (path, reason) {
		if (this._isIdle()) {
			this._batch = { total: 0, ok: 0, failed: [] };
		}
		this._batch.total++;
		this._batch.failed.push({ path: path, reason: reason, job: null });
	},

	_scanDone: function () {
		this._scanning = Math.max(0, this._scanning - 1);
		this._touchScan();
		if (this._scanning === 0) {
			this._scheduleRefresh();
		}
	},

	// Safety net: if the browser never answers for an entry (for example an
	// unreadable folder), stop waiting after 20 s so the report is still shown.
	_touchScan: function () {
		var self = this;
		clearTimeout(self._scanWatchdog);
		if (self._scanning > 0) {
			self._scanWatchdog = setTimeout(function () {
				self._scanning = 0;
				self._scheduleRefresh();
			}, 20000);
		}
	},

	_buildFormData: function (job) {
		var formData = new FormData();
		formData.append('file_content', job.encodedContent);
		formData.append('project_id', job.projectId);
		formData.append('full_path', job.path);
		formData.append('is_encoded', '1');
		return WSP.withCsrf(formData);
	},

	// The host answers 503/429 when it is asked for too much at once (resource or
	// rate limits). Slow the queue down while that lasts and speed up again after.
	_slowDown: function () {
		this._queueDelay = Math.min(this._maxDelay, Math.max(this._queueDelay * 2, 1000));
		if (!this._slowNotified && window.WSP && WSP.ui && typeof WSP.ui.notify === 'function') {
			this._slowNotified = true;
			WSP.ui.notify(WSP.lang('WSP_UPLOAD_SERVER_BUSY'), 'warning');
		}
	},

	_speedUp: function () {
		this._queueDelay = Math.max(this._minDelay, Math.floor(this._queueDelay * 0.85));
	},

	_retryDelay: function (attempt) {
		return Math.min(20000, this._retryBase * Math.pow(2, attempt - 1));
	},

	_shouldRetry: function (xhr, body, attempt) {
		if (attempt >= this._maxAttempts) return false;
		var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
		if (this._isSecurityChallenge(xhr, body)) return true;
		return status === 0 || status === 429 || status === 502 || status === 503 || status === 504;
	},

	_notifyFinalFailure: function (job, xhr, body, response) {
		var status = xhr ? parseInt(xhr.status || 0, 10) : 0;
		var msg = '';

		if (response && response.error) {
			msg = response.error;
		} else if (this._isSecurityChallenge(xhr, body)) {
			msg = WSP.lang('WSP_UPLOAD_WAF_BLOCKED', {
				'%s': job.path,
				'%d': String(status || 403)
			});
		} else if (status === 413) {
			msg = WSP.lang('WSP_UPLOAD_TOO_LARGE', { '%s': job.path });
		} else {
			msg = WSP.lang('WSP_UPLOAD_HTTP_ERROR', {
				'%s': job.path,
				'%d': String(status || 0)
			});
		}

		return String(msg).replace(/<[^>]*>/g, '');
	},

	_completeJob: function (success, job, reason) {
		var self = this;
		if (success) {
			self._batch.ok++;
		} else {
			self._batchFailures++;
			self._batch.failed.push({ path: job ? job.path : '', reason: reason || '', job: job || null });
		}
		self._activeUploads = Math.max(0, self._activeUploads - 1);
		self._pendingUploads = Math.max(0, self._pendingUploads - 1);

		if (self._queue.length > 0) {
			setTimeout(function () {
				self._drainQueue();
			}, self._queueDelay);
		} else if (self._activeUploads === 0) {
			self._scheduleRefresh();
		}
	},

	_sendJob: function (job, attempt) {
		var self = this;
		attempt = attempt || 1;

		var formData = self._buildFormData(job);
		jQuery.ajax({
			url: window.wspVars.uploadUrl,
			type: 'POST',
			data: formData,
			contentType: false,
			processData: false,
			dataType: 'text',
			cache: false,
			timeout: 45000
		}).done(function (txt, _status, xhr) {
			var r = self._parseJson(txt);
			if (r && r.success) {
				self._speedUp();
				self._completeJob(true, job);
				return;
			}

			if (self._shouldRetry(xhr, txt, attempt)) {
				self._slowDown();
				var delay = self._retryDelay(attempt);
				setTimeout(function () {
					self._sendJob(job, attempt + 1);
				}, delay);
				return;
			}

			self._completeJob(false, job, self._notifyFinalFailure(job, xhr, txt, r));
		}).fail(function (xhr) {
			var body = xhr && xhr.responseText ? String(xhr.responseText) : '';
			if (self._shouldRetry(xhr, body, attempt)) {
				self._slowDown();
				var delay = self._retryDelay(attempt);
				setTimeout(function () {
					self._sendJob(job, attempt + 1);
				}, delay);
				return;
			}

			self._completeJob(false, job, self._notifyFinalFailure(job, xhr, body, null));
		});
	},

	_readAndSend: function (job) {
		var self = this;
		var reader = new FileReader();

		reader.onload = function (e) {
			job.encodedContent = e.target.result;
			self._sendJob(job, 1);
		};

		reader.onerror = function () {
			self._completeJob(false, job, WSP.lang('WSP_UPLOAD_READ_ERROR'));
		};

		reader.readAsDataURL(job.file);
	},

	_drainQueue: function () {
		if (this._activeUploads > 0 || this._queue.length === 0) return;
		var job = this._queue.shift();
		this._activeUploads = 1;
		this._readAndSend(job);
	},

	performUpload: function (file, projectId, customPath) {
		var self = this;
		if (!file || !projectId) return;
		if (!self._guardWrite()) return;

		var finalPath = self._normalizePath(customPath || file.name);
		if (!finalPath || self._isIgnoredFile(finalPath)) return;

		if (self._isIdle()) {
			self._batchFailures = 0;
			self._slowNotified = false;
			self._batch = { total: 0, ok: 0, failed: [] };
		}
		self._batch.total++;

		self._queue.push({
			file: file,
			projectId: projectId,
			path: finalPath,
			encodedContent: null
		});
		self._pendingUploads++;
		self._drainQueue();
	},

	_readAllDirectoryEntries: function (reader, callback) {
		var self = this;
		var all = [];
		var readBatch = function () {
			reader.readEntries(function (entries) {
				entries = entries || [];
				if (!entries.length) {
					callback(all);
					return;
				}
				for (var i = 0; i < entries.length; i++) all.push(entries[i]);
				readBatch();
			}, function () {
				self._scanFailed('', WSP.lang('WSP_UPLOAD_READ_ERROR'));
				callback(all);
			});
		};
		readBatch();
	},

	traverseFileTree: function (item, path, projectId) {
		var self = this;
		if (!self._guardWrite()) return;

		path = self._normalizePath(path || '');
		if (path && !self._endsWith(path, '/')) path += '/';
		if (!item) return;

		// Every entry being read counts as "scanning", so the batch is only
		// reported after the whole folder tree has been read and uploaded.
		self._scanning++;
		self._touchScan();

		if (item.isFile) {
			item.file(function (file) {
				try {
					self.performUpload(file, projectId, path + file.name);
				} finally {
					self._scanDone();
				}
			}, function () {
				self._scanFailed(path + item.name, WSP.lang('WSP_UPLOAD_READ_ERROR'));
				self._scanDone();
			});
			return;
		}

		if (item.isDirectory) {
			var dirReader = item.createReader();
			self._readAllDirectoryEntries(dirReader, function (entries) {
				if (!entries || entries.length === 0) {
					var blob = new Blob([''], { type: 'text/plain' });
					var placeholder = new File([blob], '.placeholder');
					self.performUpload(placeholder, projectId, path + item.name + '/.placeholder');
					self._scanDone();
					return;
				}

				try {
					for (var i = 0; i < entries.length; i++) {
						self.traverseFileTree(entries[i], path + item.name + '/', projectId);
					}
				} finally {
					self._scanDone();
				}
			});
			return;
		}

		self._scanDone();
	},

	bindEvents: function ($) {
		var self = this;
		var ui = self._getUI();
		var $body = ui.$body;

		$body.off('.wsp_upload');

		$body.on('click.wsp_upload', '.wsp-upload-retry', function (e) {
			e.preventDefault();
			self._retryFailed();
		});

		$body.on('click.wsp_upload', '.wsp-notification-close', function (e) {
			e.preventDefault();
			jQuery(this).closest('.wsp-notification').remove();
		});

		$body.on('click.wsp_upload', '.trigger-import-zip', function (e) {
			e.preventDefault();
			if (!WSP.activeProjectId) {
				return WSP.ui.notify(WSP.lang('WSP_UPLOAD_NEED_PROJECT'), 'warning');
			}
			if (!self._guardWrite()) return;

			if (!jQuery('#wsp-zip-input').length) {
				$body.append('<input type="file" id="wsp-zip-input" accept=".zip,application/zip" style="display:none">');
			}
			jQuery('#wsp-zip-input').trigger('click');
		});

		$body.on('change.wsp_upload', '#wsp-zip-input', function () {
			var file = this.files && this.files.length ? this.files[0] : null;
			jQuery(this).val('');
			if (file) self.importZip(file);
		});

		$body.on('click.wsp_upload', '.trigger-upload', function (e) {
			e.preventDefault();
			if (!WSP.activeProjectId) {
				return WSP.ui.notify(WSP.lang('WSP_UPLOAD_NEED_PROJECT'), 'warning');
			}
			if (!self._guardWrite()) return;

			if (!jQuery('#wsp-upload-input').length) {
				$body.append('<input type="file" id="wsp-upload-input" multiple>');
			}
			jQuery('#wsp-upload-input').trigger('click');
		});

		$body.on('change.wsp_upload', '#wsp-upload-input', function () {
			var files = this.files;
			if (!self._guardWrite()) {
				jQuery(this).val('');
				return;
			}

			if (files && files.length > 0) {
				WSP.ui.notify(WSP.lang('WSP_UPLOAD_SENDING_COUNT', { '%d': files.length }), 'info');
				for (var i = 0; i < files.length; i++) {
					if (self._isZip(files[i])) {
						self.importZip(files[i]);
						continue;
					}
					self.performUpload(files[i], WSP.activeProjectId, null);
				}
			}
			jQuery(this).val('');
		});

		var $zone = jQuery('#sidebar-dropzone');
		if ($zone.length) {
			$zone.off('.wsp_upload');

			$zone.on('dragover.wsp_upload', function (e) {
				e.preventDefault();
				jQuery(this).addClass('sidebar-drag-active');
			});

			$zone.on('dragleave.wsp_upload', function (e) {
				e.preventDefault();
				jQuery(this).removeClass('sidebar-drag-active');
			});

			$zone.on('drop.wsp_upload', function (e) {
				e.preventDefault();
				jQuery(this).removeClass('sidebar-drag-active');

				if (!WSP.activeProjectId) {
					return WSP.ui.notify(WSP.lang('WSP_UPLOAD_DROP_PROJECT'), 'warning');
				}
				if (!self._guardWrite()) return;

				var dt = e.originalEvent && e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer : null;
				if (!dt) return;

				WSP.ui.notify(WSP.lang('WSP_UPLOAD_PROCESSING'), 'info');

				var prefix = self._normalizePath(WSP.activeFolderPath || '');
				if (prefix) prefix += '/';

				var items = dt.items;
				if (items && items.length) {
					for (var i = 0; i < items.length; i++) {
						var zipFile = (items[i].kind === 'file' && items[i].getAsFile) ? items[i].getAsFile() : null;
						if (zipFile && self._isZip(zipFile)) {
							self.importZip(zipFile);
							continue;
						}
						var entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
						if (entry) {
							self.traverseFileTree(entry, prefix, WSP.activeProjectId);
						} else {
							var f = items[i].getAsFile ? items[i].getAsFile() : null;
							if (f) self.performUpload(f, WSP.activeProjectId, prefix ? (prefix + f.name) : f.name);
						}
					}
					return;
				}

				var files = dt.files || [];
				for (var j = 0; j < files.length; j++) {
					if (self._isZip(files[j])) {
						self.importZip(files[j]);
						continue;
					}
					self.performUpload(files[j], WSP.activeProjectId, prefix ? (prefix + files[j].name) : files[j].name);
				}
			});
		}
	}
};
