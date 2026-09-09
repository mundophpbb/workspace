/**
 * Mundo phpBB Workspace - Upload & DragDrop
 * Version 4.3: serialized upload queue + hosting/WAF backoff
 */
WSP.upload = {
	_refreshTimer: null,
	_pendingUploads: 0,
	_activeUploads: 0,
	_queue: [],
	_queueDelay: 250,
	_maxAttempts: 3,
	_batchFailures: 0,
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

	_scheduleRefresh: function () {
		var self = this;
		clearTimeout(self._refreshTimer);
		self._refreshTimer = setTimeout(function () {
			if (self._pendingUploads !== 0 || self._activeUploads !== 0 || self._queue.length !== 0) {
				return;
			}
			WSP.ui.seamlessRefresh();
			if (self._batchFailures > 0) {
				WSP.ui.notify(WSP.lang('WSP_UPLOAD_BATCH_FINISHED_ERRORS', { '%d': self._batchFailures }), 'warning');
			} else {
				WSP.ui.notify(WSP.lang('WSP_UPLOAD_LIST_UPDATED'), 'success');
			}
			self._batchFailures = 0;
		}, 700);
	},

	_buildFormData: function (job) {
		var formData = new FormData();
		formData.append('file_content', job.encodedContent);
		formData.append('project_id', job.projectId);
		formData.append('full_path', job.path);
		formData.append('is_encoded', '1');
		return WSP.withCsrf(formData);
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

		WSP.ui.notify(msg, 'error');
	},

	_completeJob: function (success) {
		var self = this;
		if (!success) self._batchFailures++;
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
				self._completeJob(true);
				return;
			}

			if (self._shouldRetry(xhr, txt, attempt)) {
				var delay = 1200 * attempt;
				setTimeout(function () {
					self._sendJob(job, attempt + 1);
				}, delay);
				return;
			}

			self._notifyFinalFailure(job, xhr, txt, r);
			self._completeJob(false);
		}).fail(function (xhr) {
			var body = xhr && xhr.responseText ? String(xhr.responseText) : '';
			if (self._shouldRetry(xhr, body, attempt)) {
				var delay = 1200 * attempt;
				setTimeout(function () {
					self._sendJob(job, attempt + 1);
				}, delay);
				return;
			}

			self._notifyFinalFailure(job, xhr, body, null);
			self._completeJob(false);
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
			WSP.ui.notify(WSP.lang('WSP_UPLOAD_FAILED', { '%s': job.path }), 'error');
			self._completeJob(false);
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

		if (self._pendingUploads === 0 && self._activeUploads === 0 && self._queue.length === 0) {
			self._batchFailures = 0;
		}

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

		if (item.isFile) {
			item.file(function (file) {
				self.performUpload(file, projectId, path + file.name);
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
					return;
				}

				for (var i = 0; i < entries.length; i++) {
					self.traverseFileTree(entries[i], path + item.name + '/', projectId);
				}
			});
		}
	},

	bindEvents: function ($) {
		var self = this;
		var ui = self._getUI();
		var $body = ui.$body;

		$body.off('.wsp_upload');

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
					self.performUpload(files[j], WSP.activeProjectId, prefix ? (prefix + files[j].name) : files[j].name);
				}
			});
		}
	}
};
