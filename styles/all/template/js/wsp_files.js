/**
 * Workspace module.
 */
WSP.files = {
	autoSaveInterval: null,
	_dirtyBound: false,
	_saveCommandBound: false,
	_toolbarI18nApplied: false,

	_ui: null,
	_dirtyRafQueued: false,

	_getUI: function () {
		if (this._ui) return this._ui;

		this._ui = {
			$body: jQuery('body'),
			$currentFile: jQuery('#current-file'),
			$saveBtn: jQuery('#save-file'),
			$lockBtn: jQuery('#lock-file'),
			$unlockBtn: jQuery('#unlock-file'),
			$bbcodeBtn: jQuery('#copy-bbcode'),
			$modalBody: jQuery('#wsp-modal-body-custom')
		};
		return this._ui;
	},

	_endsWith: function (str, suffix) {
		if (window.WSP && typeof WSP._endsWith === 'function') return WSP._endsWith(str, suffix);

		str = String(str || '');
		suffix = String(suffix || '');
		if (!suffix) return true;
		if (typeof str.endsWith === 'function') return str.endsWith(suffix);
		return str.indexOf(suffix, str.length - suffix.length) !== -1;
	},

	_escape: function (s) {
		s = String(s == null ? '' : s);
		return s
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	},

	_getEditor: function () {
		if (WSP && WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.editor.session) return WSP.editor;
		if (WSP && WSP.editor && WSP.editor.ace && typeof WSP.editor.ace.getValue === 'function' && WSP.editor.ace.session) return WSP.editor.ace;
		return null;
	},

	/**
	 * ✅ Edit real (granular): preferir WSP.canEditUI()
	 */
	_canEdit: function () {
		if (window.WSP && typeof WSP.canEditUI === 'function') return !!WSP.canEditUI();
		if (window.WSP && typeof WSP.canWriteUI === 'function') return !!WSP.canWriteUI();

		// fallback antigo
		if (!WSP || !WSP.activeProjectId) return false;
		if (WSP.activeProjectLocked && !WSP.canManageAll) return false;
		return true;
	},

	_notifyLockedOrDenied: function () {
		// Se existir notifyLocked no core, usa
		if (window.WSP && typeof WSP.notifyLocked === 'function') return WSP.notifyLocked();

		var msg = (typeof WSP.lang === 'function') ? WSP.lang('WSP_ERR_PERMISSION') : '';
		if (!msg || msg === '[WSP_ERR_PERMISSION]') msg = 'Sem permissão.';
		if (WSP && WSP.ui && typeof WSP.ui.notify === 'function') WSP.ui.notify(msg, 'error');
	},

	_postJson: function ($, url, data, onDone, onFailMsgKey) {
		url = (window.WSP && typeof WSP.normalizeUrl === 'function') ? WSP.normalizeUrl(url) : url;

		if (!url) {
			if (WSP && WSP.ui) WSP.ui.notify(WSP.lang('WSP_ERR_INVALID_DATA'), 'error');
			return;
		}

		if (window.WSP && typeof WSP.ajaxPostJson === 'function') {
			WSP.ajaxPostJson(url, data, function (r) { onDone(r); }, onFailMsgKey || 'WSP_ERROR_CRITICAL');
			return;
		}

		data = data || {};
		if (typeof data._nocache === 'undefined') data._nocache = Date.now();

		$.post(url, WSP.withCsrf(data), function (r) {
			onDone(r);
		}, 'json').fail(function () {
			WSP.ui.notify(WSP.lang(onFailMsgKey || 'WSP_ERROR_CRITICAL'), 'error');
		});
	},



	_formatCommentTime: function (ts) {
		ts = parseInt(String(ts || 0), 10) || 0;
		if (!ts) return '';
		try {
			return new Date(ts * 1000).toLocaleString();
		} catch (e) {
			return '';
		}
	},

	_commentLineLabel: function (line) {
		line = parseInt(String(line || 0), 10) || 0;
		return line > 0 ? (WSP.lang('WSP_COMMENT_LINE') + ' ' + line) : WSP.lang('WSP_COMMENT_GENERAL');
	},

	renderComments: function (comments) {
		var $list = jQuery('#wsp-comments-list');
		if (!$list.length) return;

		comments = comments || [];
		if (!comments.length) {
			$list.html('<p class="wsp-muted">' + this._escape(WSP.lang('WSP_NO_COMMENTS')) + '</p>');
			return;
		}

		var html = [];
		for (var i = 0; i < comments.length; i++) {
			var c = comments[i] || {};
			var resolved = String(c.resolved || 0) === '1';
			var colour = String(c.user_colour || '').replace(/[^a-fA-F0-9]/g, '');
			var nameStyle = colour ? ' style="color:#' + colour + '"' : '';
			var msg = this._escape(c.message || '').replace(/\n/g, '<br>');
			var author = this._escape(c.username || WSP.lang('WSP_SYSTEM_USER'));

			html.push('<div class="wsp-comment-item' + (resolved ? ' is-resolved' : '') + '" data-comment-id="' + parseInt(c.comment_id || 0, 10) + '">');
			html.push('<div class="wsp-comment-meta">');
			html.push('<span class="wsp-comment-line"><i class="fa fa-code"></i> ' + this._escape(this._commentLineLabel(c.line_number)) + '</span>');
			html.push('<strong' + nameStyle + '>' + author + '</strong>');
			html.push('<small>' + this._escape(this._formatCommentTime(c.created_time)) + '</small>');
			if (resolved) html.push('<span class="wsp-comment-status"><i class="fa fa-check"></i> ' + this._escape(WSP.lang('WSP_COMMENT_RESOLVED')) + '</span>');
			html.push('</div>');
			html.push('<div class="wsp-comment-message">' + msg + '</div>');
			html.push('<div class="wsp-comment-actions">');
			html.push('<button type="button" class="wsp-resolve-comment" data-resolved="' + (resolved ? '0' : '1') + '">' + this._escape(resolved ? WSP.lang('WSP_REOPEN_COMMENT') : WSP.lang('WSP_RESOLVE_COMMENT')) + '</button>');
			html.push('<button type="button" class="wsp-delete-comment"><i class="fa fa-trash-o"></i> ' + this._escape(WSP.lang('WSP_DELETE_COMMENT')) + '</button>');
			html.push('</div>');
			html.push('</div>');
		}
		$list.html(html.join(''));
	},

	loadComments: function (fileId, fileName) {
		var self = this;
		var $panel = jQuery('#wsp-review-panel');
		if (!$panel.length || !fileId) return;

		$panel.show();
		jQuery('#wsp-review-current-file').text(fileName || WSP.lang('WSP_SELECT_FILE_REVIEW'));
		jQuery('#wsp-comments-list').html('<p class="wsp-muted">' + self._escape(WSP.lang('WSP_LOADING_COMMENTS')) + '</p>');

		self._postJson(jQuery, window.wspVars.commentsUrl, { file_id: fileId, include_resolved: 1 }, function (r) {
			if (r && r.success) {
				self.renderComments(r.comments || []);
				return;
			}
			jQuery('#wsp-comments-list').html('<p class="wsp-muted">' + self._escape((r && r.error) ? r.error : WSP.lang('WSP_ERROR_COMMENTS')) + '</p>');
		}, 'WSP_ERROR_CRITICAL');
	},



	renderVersions: function (versions) {
		var $list = jQuery('#wsp-versions-list');
		if (!$list.length) return;

		versions = versions || [];
		if (!versions.length) {
			$list.html('<p class="wsp-muted">' + this._escape(WSP.lang('WSP_NO_VERSIONS')) + '</p>');
			return;
		}

		var html = [];
		for (var i = 0; i < versions.length; i++) {
			var v = versions[i] || {};
			var colour = String(v.user_colour || '').replace(/[^a-fA-F0-9]/g, '');
			var nameStyle = colour ? ' style="color:#' + colour + '"' : '';
			var author = this._escape(v.username || WSP.lang('WSP_SYSTEM_USER'));
			var note = this._escape(v.change_note || '');
			var source = this._escape(WSP.lang('WSP_VERSION_SOURCE_' + String(v.source || 'save').toUpperCase()));
			if (source.indexOf('[WSP_VERSION_SOURCE_') === 0) source = this._escape(v.source || 'save');

			html.push('<div class="wsp-version-item" data-version-id="' + parseInt(v.version_id || 0, 10) + '">');
			html.push('<div class="wsp-version-meta"><strong' + nameStyle + '>' + author + '</strong>');
			html.push('<small>' + this._escape(this._formatCommentTime(v.created_time)) + '</small>');
			html.push('<span>' + source + '</span></div>');
			if (note) html.push('<div class="wsp-version-note">' + note + '</div>');
			html.push('<div class="wsp-version-actions">');
			html.push('<button type="button" class="wsp-view-version"><i class="fa fa-eye"></i> ' + this._escape(WSP.lang('WSP_VIEW_VERSION')) + '</button>');
			if (this._canEdit()) html.push('<button type="button" class="wsp-restore-version"><i class="fa fa-undo"></i> ' + this._escape(WSP.lang('WSP_RESTORE_VERSION')) + '</button>');
			html.push('</div></div>');
		}
		$list.html(html.join(''));
	},

	loadVersions: function (fileId) {
		var self = this;
		var $list = jQuery('#wsp-versions-list');
		if (!$list.length || !fileId || !window.wspVars || !window.wspVars.fileVersionsUrl) return;

		$list.html('<p class="wsp-muted">' + self._escape(WSP.lang('WSP_LOADING_VERSIONS')) + '</p>');
		self._postJson(jQuery, window.wspVars.fileVersionsUrl, { file_id: fileId }, function (r) {
			if (r && r.success) {
				self.renderVersions(r.versions || []);
				return;
			}
			$list.html('<p class="wsp-muted">' + self._escape((r && r.error) ? r.error : WSP.lang('WSP_ERROR_VERSIONS')) + '</p>');
		}, 'WSP_ERROR_CRITICAL');
	},

	_reviewStatusLabel: function (status) {
		status = String(status || 'none');
		if (status === 'pending') return WSP.lang('WSP_REVIEW_STATUS_PENDING');
		if (status === 'approved') return WSP.lang('WSP_REVIEW_STATUS_APPROVED');
		if (status === 'changes_requested') return WSP.lang('WSP_REVIEW_STATUS_CHANGES');
		return WSP.lang('WSP_REVIEW_STATUS_NONE');
	},

	renderReviewStatus: function (review) {
		var $box = jQuery('#wsp-review-status');
		if (!$box.length) return;

		review = review || {};
		var status = String(review.status || 'none');
		var note = String(review.review_note || '');
		var who = '';
		if (status === 'pending') who = String(review.requested_username || '');
		else if (status !== 'none') who = String(review.reviewed_username || '');

		$box.removeClass('is-none is-pending is-approved is-changes-requested')
			.addClass('is-' + status.replace('_', '-'));

		var text = this._reviewStatusLabel(status);
		if (who) text += ' - ' + who;
		if (note) text += ': ' + note;
		$box.find('.wsp-review-status-label').text(text);
	},

	loadReviewStatus: function (fileId) {
		var self = this;
		var $box = jQuery('#wsp-review-status');
		if (!$box.length || !fileId || !window.wspVars || !window.wspVars.fileReviewUrl) return;

		$box.find('.wsp-review-status-label').text(WSP.lang('WSP_LOADING_REVIEW_STATUS'));
		self._postJson(jQuery, window.wspVars.fileReviewUrl, { file_id: fileId }, function (r) {
			if (r && r.success) {
				self.renderReviewStatus(r.review || {});
				return;
			}
			self.renderReviewStatus({ status: 'none' });
		}, 'WSP_ERROR_CRITICAL');
	},



	renderFileLock: function (lock) {
		var ui = this._getUI();
		var $status = jQuery('#wsp-file-lock-status');
		lock = lock || {};
		WSP.activeFileLock = lock;

		if (!WSP.activeFileId) {
			ui.$lockBtn.hide();
			ui.$unlockBtn.hide();
			$status.hide();
			return;
		}

		var locked = String(lock.locked || 0) === '1';
		var mine = String(lock.mine || 0) === '1';
		var canEdit = this._canEdit();
		var ed = this._getEditor();

		if (locked) {
			var who = this._escape(lock.username || WSP.lang('WSP_SYSTEM_USER'));
			var text = mine ? WSP.lang('WSP_FILE_LOCKED_BY_YOU') : WSP.lang('WSP_FILE_LOCKED_BY_USER').replace('%s', who);
			$status.show().removeClass('is-open').addClass(mine ? 'is-mine' : 'is-locked')
				.find('.wsp-file-lock-label').html('<i class="fa fa-lock"></i> ' + this._escape(text));
			ui.$lockBtn.hide();
			ui.$unlockBtn.toggle(mine || !!(window.wspVars && window.wspVars.WSP_CAN_MANAGE_ALL));
			if (ed && !mine && !window.wspVars.WSP_CAN_MANAGE_ALL) ed.setReadOnly(true);
		} else {
			$status.show().removeClass('is-locked is-mine').addClass('is-open')
				.find('.wsp-file-lock-label').html('<i class="fa fa-unlock"></i> ' + this._escape(WSP.lang('WSP_FILE_UNLOCKED')));
			ui.$lockBtn.toggle(canEdit);
			ui.$unlockBtn.hide();
			if (ed) ed.setReadOnly(!canEdit);
		}
	},

	loadFileLock: function (fileId) {
		var self = this;
		if (!fileId || !window.wspVars || !window.wspVars.fileLockUrl) return;
		self._postJson(jQuery, window.wspVars.fileLockUrl, { file_id: fileId }, function (r) {
			if (r && r.success) self.renderFileLock(r.lock || {});
		}, 'WSP_ERROR_CRITICAL');
	},

	/**
 * Workspace module.
 */
	applyToolbarTranslations: function () {
		if (this._toolbarI18nApplied) return;
		this._toolbarI18nApplied = true;

		var ui = this._getUI();
		var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

		// Workspace UI logic.
		if (ui.$saveBtn.length) {
			ui.$saveBtn.attr('title', saveTitle);
			ui.$saveBtn.attr('aria-label', saveTitle);

			// Workspace UI logic.
			if (!ui.$saveBtn.find('i.fa').length) {
				ui.$saveBtn.html('<i class="fa fa-save fa-fw"></i>');
			}
		}

		// Workspace UI logic.
		jQuery('.generate-project-changelog').each(function () {
			var $el = jQuery(this);
			$el.attr('title', WSP.lang('WSP_GENERATE_CHANGELOG'));
			$el.attr('aria-label', WSP.lang('WSP_GENERATE_CHANGELOG'));
		});

		jQuery('.clear-project-changelog').each(function () {
			var $el = jQuery(this);
			$el.attr('title', WSP.lang('WSP_CLEAR_CHANGELOG'));
			$el.attr('aria-label', WSP.lang('WSP_CLEAR_CHANGELOG'));
		});

		// Workspace UI logic.
		jQuery('[data-wsp-i18n]').each(function () {
			var $el = jQuery(this);
			var key = ($el.attr('data-wsp-i18n') || '').trim();
			if (key) $el.text(WSP.lang(key));
		});
	},

	renderBreadcrumbs: function (path) {
		var ui = this._getUI();
		path = (path || '').trim();

		if (!path) {
			ui.$currentFile.text(WSP.lang('WSP_SELECT_FILE'));
			return;
		}

		var parts = path.split('/');
		var out = ['<i class="fa fa-folder-open-o breadcrumb-folder-icon"></i> '];

		for (var i = 0; i < parts.length; i++) {
			out.push('<span class="breadcrumb-item">', this._escape(parts[i]), '</span>');
			if (i < parts.length - 1) out.push(' <i class="fa fa-angle-right breadcrumb-sep"></i> ');
		}

		ui.$currentFile.html(out.join(''));
	},

	bindEvents: function ($) {
		var self = this;
		var ui = self._getUI();
		var $body = ui.$body;

		self.applyToolbarTranslations();
		$body.off('.wsp_files');

		function isEditorReady() { return !!self._getEditor(); }

		function setLoadingEffect(on) {
			if (on) ui.$currentFile.addClass('loading-effect');
			else ui.$currentFile.removeClass('loading-effect');
		}

		function hasUnsavedChanges() {
			var ed = self._getEditor();
			if (!ed || !WSP.activeFileId) return false;
			return ed.getValue() !== (typeof WSP.originalContent === 'string' ? WSP.originalContent : '');
		}

		function restorePreviousSelection(prevActiveId) {
			if (!prevActiveId) {
				jQuery('.file-item').removeClass('active-file');
				self.renderBreadcrumbs('');
				return;
			}

			var $prevLink = jQuery('.load-file[data-id="' + prevActiveId + '"]');
			jQuery('.file-item').removeClass('active-file');

			if ($prevLink.length) {
				$prevLink.closest('.file-item').addClass('active-file');
				var prevPath = ($prevLink.attr('data-path') || $prevLink.text() || '').trim();
				self.renderBreadcrumbs(prevPath);
			} else {
				self.renderBreadcrumbs('');
			}
		}

		function setSaveButtonVisible(visible) {
			if (!ui.$saveBtn.length) return;

			var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

			if (visible) {
				ui.$saveBtn
					.stop(true, true)
					.fadeIn(200)
					.attr('title', saveTitle)
					.attr('aria-label', saveTitle);

				if (!ui.$saveBtn.find('i.fa').length) {
					ui.$saveBtn.html('<i class="fa fa-save fa-fw"></i>');
				}
			} else {
				ui.$saveBtn.hide();
			}
		}

		// 1) LOAD FILE
		$body.on('click.wsp_files', '.load-file', function (e) {
			e.preventDefault();

			if (!isEditorReady()) {
				WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
				return;
			}

			var $link = jQuery(this);
			var fileId = $link.data('id');
			if (!fileId) return;

			if (hasUnsavedChanges() && !confirm(WSP.lang('WSP_UNSAVED_CHANGES'))) return;

			var prevActiveId = WSP.activeFileId || null;

			var fullPath = ($link.attr('data-path') || $link.text() || '').trim();
			self.renderBreadcrumbs(fullPath);

			jQuery('.file-item').removeClass('active-file');
			$link.closest('.file-item').addClass('active-file');

			setLoadingEffect(true);

			WSP.ajaxGetJson(window.wspVars.loadUrl, { file_id: fileId }, function (r) {
				setLoadingEffect(false);

				if (!r || !r.success) {
					var errorMsg = (r && r.error) ? r.error : WSP.lang('WSP_ERROR_OPEN_FILE');
					WSP.ui.notify(errorMsg, 'error');
					restorePreviousSelection(prevActiveId);
					return;
				}

				WSP.activeFileId = fileId;
				WSP.versionViewMode = false;
				localStorage.setItem('wsp_active_file_id', fileId);

				var ed = self._getEditor();
				if (!ed) {
					WSP.ui.notify(WSP.lang('WSP_EDITOR_LOADING'), 'warning');
					restorePreviousSelection(prevActiveId);
					return;
				}

				// ✅ Edit real (granular)
				ed.setReadOnly(!self._canEdit());

				var fileName = String(r.name || '').toLowerCase();

				if (fileName === 'changelog.txt') {
					ed.session.setMode("ace/mode/diff");
					ui.$bbcodeBtn.stop(true, true).fadeIn(200);
				} else if (self._endsWith(fileName, '.txt')) {
					ed.session.setMode("ace/mode/text");
					ui.$bbcodeBtn.hide();
				} else {
					var modesMap = (WSP.modes && typeof WSP.modes === 'object') ? WSP.modes : ((window.WSP && window.WSP.modes) ? window.WSP.modes : {});
					ed.session.setMode(modesMap[r.type] || 'ace/mode/text');
					ui.$bbcodeBtn.hide();
				}

				var finalContent = (typeof r.content === 'string') ? r.content : '';
				ed.setValue(finalContent, -1);
				WSP.originalContent = finalContent;

				self.loadComments(fileId, r.name || fullPath);
				self.loadReviewStatus(fileId);
				self.loadVersions(fileId);
				self.loadFileLock(fileId);

				ed.resize();
				ed.focus();

				var $activeLink = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
				if ($activeLink.length) {
					var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
					$activeLink.text(cleanName).removeClass('is-dirty');
				}

				// Workspace UI logic.
				setSaveButtonVisible(self._canEdit());

				if (typeof WSP.updateUIState === 'function') WSP.updateUIState();

				self.initAutoSave();
				self.bindDirtyDetector();
				self.bindSaveShortcut();
			}, 'WSP_ERROR_CRITICAL', function () {
				setLoadingEffect(false);
				restorePreviousSelection(prevActiveId);
			});
		});

		// 2) SAVE FILE
		$body.on('click.wsp_files', '#save-file', function () {
			if (!isEditorReady() || !WSP.activeFileId) return;

			if (!self._canEdit()) return self._notifyLockedOrDenied();
			if (WSP.versionViewMode) {
				if (WSP.ui && WSP.ui.notify) WSP.ui.notify(WSP.lang('WSP_VERSION_VIEW_MODE_SAVE_BLOCKED'), 'warning');
				return;
			}

			if (WSP.activeFileLock && String(WSP.activeFileLock.locked || 0) === '1' && String(WSP.activeFileLock.mine || 0) !== '1' && !(window.wspVars && window.wspVars.WSP_CAN_MANAGE_ALL)) {
				WSP.ui.notify(WSP.lang('WSP_FILE_LOCKED_SAVE_BLOCKED'), 'warning');
				return;
			}

			var ed = self._getEditor();
			if (!ed) return;

			var $btn = ui.$saveBtn;
			if ($btn.prop('disabled')) return;

			var saveTitle = WSP.lang('WSP_SAVE_CHANGES');

			// Workspace UI logic.
			$btn
				.prop('disabled', true)
				.attr('title', saveTitle)
				.attr('aria-label', saveTitle)
				.html('<i class="fa fa-spinner fa-spin fa-fw"></i>');

			var contentToSave = ed.getValue();

			self._postJson($, window.wspVars.saveUrl, { file_id: WSP.activeFileId, content: contentToSave }, function (r) {
				if (r && r.success) {
					WSP.originalContent = contentToSave;
					localStorage.removeItem('wsp_backup_' + WSP.activeFileId);
					self.loadVersions(WSP.activeFileId);

					var $activeLink = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
					if ($activeLink.length) {
						var cleanName = ($activeLink.text() || '').replace(/^\* /, '');
						$activeLink.text(cleanName).removeClass('is-dirty');
					}

					// Workspace UI logic.
					$btn.html('<i class="fa fa-check fa-fw"></i>').addClass('btn-success-temporary');

					setTimeout(function () {
						if (!self._canEdit()) {
							$btn.hide();
							return;
						}

						$btn
							.prop('disabled', false)
							.attr('title', saveTitle)
							.attr('aria-label', saveTitle)
							.html('<i class="fa fa-save fa-fw"></i>')
							.removeClass('btn-success-temporary');
					}, 900);
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_SAVE'), 'error');
					$btn
						.prop('disabled', false)
						.attr('title', saveTitle)
						.attr('aria-label', saveTitle)
						.html('<i class="fa fa-save fa-fw"></i>');
				}
			}, 'WSP_ERROR_CRITICAL');
		});


		// 3) REVIEW COMMENTS
		$body.on('click.wsp_files', '#lock-file', function (e) {
			e.preventDefault();
			if (!WSP.activeFileId || !window.wspVars || !window.wspVars.lockFileUrl) return;
			self._postJson($, window.wspVars.lockFileUrl, { file_id: WSP.activeFileId }, function (r) {
				if (r && r.success) {
					self.renderFileLock(r.lock || {});
					WSP.ui.notify(WSP.lang('WSP_FILE_LOCKED_BY_YOU'), 'success');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_FILE_LOCK'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});

		$body.on('click.wsp_files', '#unlock-file', function (e) {
			e.preventDefault();
			if (!WSP.activeFileId || !window.wspVars || !window.wspVars.unlockFileUrl) return;
			self._postJson($, window.wspVars.unlockFileUrl, { file_id: WSP.activeFileId }, function (r) {
				if (r && r.success) {
					self.renderFileLock(r.lock || {});
					WSP.ui.notify(WSP.lang('WSP_FILE_UNLOCKED'), 'success');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_FILE_LOCK'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});

		$body.on('click.wsp_files', '#wsp-toggle-review-form', function (e) {
			e.preventDefault();
			jQuery('#wsp-review-form').slideToggle(120);
		});

		$body.on('submit.wsp_files', '#wsp-review-form', function (e) {
			e.preventDefault();
			if (!WSP.activeFileId) return;

			var $form = jQuery(this);
			var message = jQuery.trim($form.find('[name="message"]').val() || '');
			var line = parseInt(String($form.find('[name="line_number"]').val() || '0'), 10) || 0;
			if (!message) return;

			self._postJson($, window.wspVars.addCommentUrl, {
				file_id: WSP.activeFileId,
				line_number: line,
				message: message
			}, function (r) {
				if (r && r.success) {
					$form.find('[name="message"]').val('');
					self.renderComments(r.comments || []);
					if (WSP.ui && WSP.ui.notify) WSP.ui.notify(WSP.lang('WSP_COMMENT_ADDED'), 'success');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_COMMENTS'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});

		$body.on('click.wsp_files', '.wsp-resolve-comment', function (e) {
			e.preventDefault();
			var $item = jQuery(this).closest('.wsp-comment-item');
			var cid = parseInt(String($item.data('comment-id') || 0), 10) || 0;
			var resolved = parseInt(String(jQuery(this).attr('data-resolved') || '1'), 10) || 0;
			if (!cid) return;

			self._postJson($, window.wspVars.resolveCommentUrl, { comment_id: cid, resolved: resolved }, function (r) {
				if (r && r.success) self.renderComments(r.comments || []);
				else WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_COMMENTS'), 'error');
			}, 'WSP_ERROR_CRITICAL');
		});

		$body.on('click.wsp_files', '.wsp-delete-comment', function (e) {
			e.preventDefault();
			if (!confirm(WSP.lang('WSP_CONFIRM_DELETE_COMMENT'))) return;
			var $item = jQuery(this).closest('.wsp-comment-item');
			var cid = parseInt(String($item.data('comment-id') || 0), 10) || 0;
			if (!cid) return;

			self._postJson($, window.wspVars.deleteCommentUrl, { comment_id: cid }, function (r) {
				if (r && r.success) self.renderComments(r.comments || []);
				else WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_COMMENTS'), 'error');
			}, 'WSP_ERROR_CRITICAL');
		});


		$body.on('click.wsp_files', '.wsp-version-refresh', function (e) {
			e.preventDefault();
			if (WSP.activeFileId) self.loadVersions(WSP.activeFileId);
		});

		$body.on('click.wsp_files', '.wsp-view-version', function (e) {
			e.preventDefault();
			var vid = parseInt(String(jQuery(this).closest('.wsp-version-item').data('version-id') || 0), 10) || 0;
			if (!vid) return;
			self._postJson($, window.wspVars.fileVersionViewUrl, { version_id: vid }, function (r) {
				if (r && r.success) {
					var ed = self._getEditor();
					if (!ed) return;
					ed.setValue(String(r.content || ''), -1);
					ed.setReadOnly(true);
					WSP.versionViewMode = true;
					jQuery('#save-file').hide();
					if (WSP.ui && WSP.ui.notify) WSP.ui.notify(WSP.lang('WSP_VERSION_VIEWING'), 'info');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_VERSIONS'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});

		$body.on('click.wsp_files', '.wsp-restore-version', function (e) {
			e.preventDefault();
			if (!WSP.activeFileId || !self._canEdit()) return self._notifyLockedOrDenied();
			if (!confirm(WSP.lang('WSP_CONFIRM_RESTORE_VERSION'))) return;
			var vid = parseInt(String(jQuery(this).closest('.wsp-version-item').data('version-id') || 0), 10) || 0;
			if (!vid) return;
			self._postJson($, window.wspVars.fileVersionRestoreUrl, { version_id: vid }, function (r) {
				if (r && r.success) {
					var ed = self._getEditor();
					var content = String(r.content || '');
					if (ed) {
						ed.setValue(content, -1);
						ed.setReadOnly(!self._canEdit());
					}
					WSP.versionViewMode = false;
					WSP.originalContent = content;
					self.renderVersions(r.versions || []);
					self.loadReviewStatus(WSP.activeFileId);
					if (WSP.ui && WSP.ui.notify) WSP.ui.notify(WSP.lang('WSP_VERSION_RESTORED'), 'success');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_VERSIONS'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});


		$body.on('click.wsp_files', '.wsp-review-action', function (e) {
			e.preventDefault();
			if (!WSP.activeFileId) return;

			var status = String(jQuery(this).data('status') || 'pending');
			var note = '';
			if (status === 'changes_requested') {
				note = prompt(WSP.lang('WSP_REVIEW_NOTE_PROMPT')) || '';
			}

			var url = (status === 'pending') ? window.wspVars.requestReviewUrl : window.wspVars.setReviewUrl;
			self._postJson($, url, {
				file_id: WSP.activeFileId,
				status: status,
				note: note
			}, function (r) {
				if (r && r.success) {
					self.renderReviewStatus(r.review || {});
					WSP.ui.notify(WSP.lang('WSP_REVIEW_STATUS_UPDATED'), 'success');
				} else {
					WSP.ui.notify((r && r.error) ? r.error : WSP.lang('WSP_ERROR_REVIEW_STATUS'), 'error');
				}
			}, 'WSP_ERROR_CRITICAL');
		});

	},

	bindDirtyDetector: function () {
		if (this._dirtyBound) return;
		this._dirtyBound = true;

		var self = this;
		var ed = self._getEditor();
		if (!ed) return;

		var run = function () {
			self._dirtyRafQueued = false;

			if (!WSP.activeFileId) return;
			if (!self._canEdit()) return;

			var current = ed.getValue();
			var $link = jQuery('.load-file[data-id="' + WSP.activeFileId + '"]');
			if (!$link.length) return;

			var rawName = ($link.text() || '');
			var nameNoStar = rawName.replace(/^\* /, '');
			var isDirty = (current !== WSP.originalContent);

			if (isDirty && rawName.indexOf('* ') !== 0) {
				$link.text('* ' + nameNoStar).addClass('is-dirty');
			} else if (!isDirty && rawName.indexOf('* ') === 0) {
				$link.text(nameNoStar).removeClass('is-dirty');
			}
		};

		var schedule = function () {
			if (self._dirtyRafQueued) return;
			self._dirtyRafQueued = true;
			(window.requestAnimationFrame || function (cb) { return setTimeout(cb, 16); })(run);
		};

		if (ed.session && typeof ed.session.on === 'function') ed.session.on('change', schedule);
		else if (typeof ed.on === 'function') ed.on('change', schedule);
	},

	bindSaveShortcut: function () {
		if (this._saveCommandBound) return;
		this._saveCommandBound = true;

		var self = this;
		var ui = self._getUI();
		var ed = self._getEditor();
		if (!ed) return;

		if (ed.commands && typeof ed.commands.addCommand === 'function') {
			ed.commands.addCommand({
				name: 'save',
				bindKey: { win: 'Ctrl-S', mac: 'Command-S' },
				exec: function () {
					if (!self._canEdit()) return self._notifyLockedOrDenied();
					ui.$saveBtn.click();
				}
			});
		}

		ui.$body.off('click.wsp_files', '#copy-bbcode')
			.on('click.wsp_files', '#copy-bbcode', function () {
				var content = ed.getValue();
				var name = jQuery('.active-file .load-file').text().replace(/^\* /, '');
				var bbcode = "[diff=" + name + "]\n" + content + "\n[/diff]";

				var $temp = jQuery("<textarea>").val(bbcode).appendTo("body").select();
				document.execCommand("copy");
				$temp.remove();

				WSP.ui.notify(WSP.lang('WSP_BBCODE_COPIED'), 'info');
			});
	},

	initAutoSave: function () {
		if (this.autoSaveInterval) clearInterval(this.autoSaveInterval);

		var self = this;

		this.autoSaveInterval = setInterval(function () {
			if (!self._canEdit()) return;

			var ed = self._getEditor();
			if (WSP.activeFileId && ed) {
				var current = ed.getValue();
				if (current !== WSP.originalContent) {
					localStorage.setItem('wsp_backup_' + WSP.activeFileId, current);

					var logMsg = WSP.lang('WSP_LOG_BACKUP_UPDATED', { '%s': WSP.activeFileId });
					if (window.console && console.log) console.log("WSP: " + logMsg);
				}
			}
		}, 15000);
	}
};