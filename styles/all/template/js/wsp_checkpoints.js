/** Workspace IDE 1.17.0: guarded full-project restoration checkpoints. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function num(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function rows(value) { return Array.isArray(value) ? value : []; }
	WSP.checkpoints = {
		project: 0, serial: 0, busy: false, previewData: null, canManage: false, pendingReleaseCheckpoint: 0,
		lang: function (key) { return WSP.lang(key); },
		activeId: function () { return num(WSP.activeProjectId); },
		allowed: function () { return !!(this.activeId() && window.wspVars &&
			num(window.wspVars.activeCanEdit) && window.wspVars.checkpointListUrl); },
		unsaved: function () { return !!(WSP.editor && typeof WSP.editor.getValue === 'function' &&
			WSP.activeFileId && typeof WSP.originalContent === 'string' &&
			WSP.editor.getValue() !== WSP.originalContent); },
		status: function (message) { $('#wsp-cp-status').text(String(message || '')); },
		clearPreview: function () {
			this.previewData = null;
			$('#wsp-cp-preview').prop('hidden', true);
			$('#wsp-cp-restore').prop('disabled', true);
			$('#wsp-cp-changes,#wsp-cp-summary').empty();
		},
		open: function () {
			if (!this.allowed()) return;
			$('#wsp-cp-modal').fadeIn(120);
			this.refresh();
		},
		request: function (url, data, success) {
			if (!url || this.busy || !this.allowed()) return false;
			var self = this, project = this.activeId(), sequence = ++this.serial;
			this.busy = true;
			$('#wsp-cp-create,#wsp-cp-refresh,#wsp-cp-restore').prop('disabled', true);
			$.ajax({
				url: WSP.normalizeUrl(url), type: 'POST', dataType: 'json', cache: false,
				timeout: 60000, data: WSP.withCsrf($.extend({ project_id: project }, data || {}))
			}).done(function (result) {
				if (sequence !== self.serial || project !== self.activeId()) return;
				if (result && result.success && (!result.project_id || num(result.project_id) === project))
					success(result);
				else self.status((result && result.error) || self.lang('WSP_CP_RETRY'));
			}).fail(function () {
				if (sequence === self.serial && project === self.activeId()) self.status(self.lang('WSP_CP_RETRY'));
			}).always(function () {
				if (sequence !== self.serial) return;
				self.busy = false;
				$('#wsp-cp-create,#wsp-cp-refresh').prop('disabled', false);
				if (self.previewData && self.previewData.can_restore && rows(self.previewData.changes).length)
					$('#wsp-cp-restore').prop('disabled', false);
			});
			return true;
		},
		refresh: function () {
			if (!this.allowed()) return;
			this.clearPreview();
			this.project = this.activeId();
			this.status(this.lang('WSP_CP_LOADING'));
			var self = this;
			this.request(window.wspVars.checkpointListUrl, {}, function (result) {
				self.canManage = !!result.can_manage;
				self.renderList(rows(result.checkpoints));
				self.status('');
				if (self.pendingReleaseCheckpoint) {
					var target = self.pendingReleaseCheckpoint;
					self.pendingReleaseCheckpoint = 0;
					window.setTimeout(function () { if (!self.busy && self.allowed()) self.preview(target); }, 0);
				}
			});
		},
		renderList: function (checkpoints) {
			var self = this, host = $('#wsp-cp-list').empty();
			if (!checkpoints.length) { $('<p/>').text(this.lang('WSP_CP_EMPTY')).appendTo(host); return; }
			checkpoints.forEach(function (point) {
				var id = num(point.checkpoint_id);
				var row = $('<div/>', {'class':'wsp-cp-row'}).appendTo(host);
				var info = $('<div/>', {'class':'wsp-cp-info'}).appendTo(row);
				$('<strong/>').text(String(point.label || '')).appendTo(info);
				var datetime = new Date(num(point.created_time) * 1000);
				var when = !isNaN(datetime.getTime()) ? datetime.toLocaleString() : '';
				$('<small/>').text('#' + id + ' · ' + when + ' · ' + num(point.file_count) + ' ' + self.lang('WSP_CP_FILES')).appendTo(info);
				var controls = $('<div/>', {'class':'wsp-cp-buttons'}).appendTo(row);
				$('<button/>',{type:'button','class':'btn-main wsp-cp-compare'})
					.attr('data-checkpoint',id).text(self.lang('WSP_CP_PREVIEW_ACTION')).appendTo(controls);
				if (self.canManage && point.kind !== 'release') $('<button/>',{type:'button','class':'btn-main wsp-cp-delete'})
					.attr('data-checkpoint',id).text(self.lang('WSP_CP_DELETE')).appendTo(controls);
			});
		},
		create: function () {
			if (!this.allowed() || this.unsaved()) { this.status(this.lang('WSP_CP_UNSAVED')); return; }
			var label = String($('#wsp-cp-label').val() || '').trim();
			if (!label || label.length > 120) { this.status(this.lang('WSP_CP_INVALID_NAME')); return; }
			var self = this;
			this.clearPreview();
			this.request(window.wspVars.checkpointCreateUrl, {label:label}, function () {
				$('#wsp-cp-label').val('');
				self.status(self.lang('WSP_CP_CREATED'));
				window.setTimeout(function () { self.refresh(); }, 0);
			});
		},
		preview: function (checkpointId) {
			if (!this.allowed()) return;
			var self = this;
			this.clearPreview();
			this.status(this.lang('WSP_CP_LOADING'));
			this.request(window.wspVars.checkpointPreviewUrl, {checkpoint_id:checkpointId}, function (data) {
				self.previewData = data;
				self.status('');
				self.renderPreview(data);
			});
		},
		renderPreview: function (data) {
			var self = this, changes = rows(data.changes);
			if (!data.preview_token || data.project_id != this.activeId()) return;
			var counts = {restore:0, remove:0, change:0};
			changes.forEach(function (change) { if (Object.prototype.hasOwnProperty.call(counts,change.action)) counts[change.action]++; });
			$('#wsp-cp-preview').prop('hidden',false);
			$('#wsp-cp-summary').text(changes.length ?
				counts.restore + ' ' + this.lang('WSP_CP_RECREATED') + ' · ' +
				counts.change + ' ' + this.lang('WSP_CP_CHANGED') + ' · ' +
				counts.remove + ' ' + this.lang('WSP_CP_REMOVED') : this.lang('WSP_CP_NO_CHANGES'));
			var host = $('#wsp-cp-changes').empty();
			changes.forEach(function (change) {
				var row = $('<div/>',{'class':'wsp-cp-change'}).appendTo(host);
				var label = change.action === 'remove' ? self.lang('WSP_CP_REMOVED') :
					change.action === 'restore' ? self.lang('WSP_CP_RECREATED') : self.lang('WSP_CP_CHANGED');
				$('<strong/>').text(String(change.path || '')).appendTo(row);
				$('<span/>').addClass('is-' + change.action).text(label).appendTo(row);
			});
			$('#wsp-cp-restore').prop('disabled', !data.can_restore || !changes.length || this.busy);
		},
		restore: function () {
			var data = this.previewData;
			if (!data || !data.can_restore || !rows(data.changes).length || data.project_id != this.activeId()) return;
			if (this.unsaved()) { this.status(this.lang('WSP_CP_UNSAVED')); return; }
			var question = this.lang('WSP_CP_CONFIRM_RESTORE').replace('%s',String(data.changes.length));
			if (!window.confirm(question)) return;
			// A preview is single-use in the UI. Re-preview after an error.
			this.clearPreview();
			var self=this;
			this.request(window.wspVars.checkpointRestoreUrl,
				{ checkpoint_id:num(data.checkpoint_id), preview_token:String(data.preview_token) },function (result) {
					self.status(self.lang('WSP_CP_RESTORED'));
					try { window.localStorage.removeItem('wsp_active_file_id'); } catch (_e) {}
					window.location.reload();
				});
		},
		remove: function (checkpointId) {
			if (!this.allowed() || !this.canManage || !window.confirm(this.lang('WSP_CP_CONFIRM_DELETE'))) return;
			this.clearPreview();
			var self=this;
			this.request(window.wspVars.checkpointDeleteUrl,{checkpoint_id:checkpointId},function () {
				window.setTimeout(function () { self.refresh(); }, 0);
			});
		},
		init: function () {},
		bindEvents: function () {
			var self=this;
			$('body').off('.wsp_cp');
			$('body').on('click.wsp_cp','#wsp-cp-open',function () { self.open(); });
			$('body').on('click.wsp_cp','#wsp-cp-close',function () { $('#wsp-cp-modal').fadeOut(120); });
			$('body').on('click.wsp_cp','#wsp-cp-refresh',function () { self.refresh(); });
			$('body').on('click.wsp_cp','#wsp-cp-create',function () { self.create(); });
			$('body').on('click.wsp_cp','#wsp-cp-restore',function () { self.restore(); });
			$('body').on('click.wsp_cp','.wsp-cp-compare',function () { self.preview(num(this.getAttribute('data-checkpoint'))); });
			$('body').on('click.wsp_cp','.wsp-cp-delete',function () { self.remove(num(this.getAttribute('data-checkpoint'))); });
			$(document).on('keydown.wsp_cp',function (event) {
				if (event.key === 'Escape' && $('#wsp-cp-modal').is(':visible')) $('#wsp-cp-modal').fadeOut(120);
			});
		}
	};
})(window, window.jQuery);
