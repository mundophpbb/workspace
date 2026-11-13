/** Release history, project-local, snapshots are restored only through the checkpoint preview. */
(function (window, $) {
	'use strict';
	if (!window.WSP || !$) return;
	function number(value) { return Math.max(0, parseInt(value, 10) || 0); }
	var history = {
		busy:false, serial:0, prepared:null,
		projectId:function () { return number(WSP.activeProjectId); },
		allowed:function () { return !!(window.wspVars && window.wspVars.releaseHistoryListUrl &&
			number(window.wspVars.activeCanEdit) && this.projectId()); },
		lang:function (key) { return WSP.lang(key); },
		status:function (value) { $('#wsp-rh-status').text(String(value || '')); },
		dirty:function () { return !!(WSP.activeFileId && WSP.editor &&
			typeof WSP.editor.getValue === 'function' && typeof WSP.originalContent === 'string' &&
			WSP.editor.getValue() !== WSP.originalContent); },
		openPrepared:function (details) {
			if (!this.allowed() || !details || number(details.projectId)!==this.projectId()) return;
			this.open();
			this.prepared={projectId:this.projectId(),fingerprint:String(details.fingerprint||'')};
			$('#wsp-rh-version').val(String(details.version||''));
			$('#wsp-rh-package').val(String(details.packageName||''));
			this.status(this.lang('WSP_RELEASE_RECORD_VERIFY_DOWNLOAD'));
		},
		open:function () {
			if (!this.allowed()) return;
			$('#wsp-rh-modal').fadeIn(120);
			this.refresh();
		},
		request:function (url, values, success) {
			if (!this.allowed() || !url || this.busy) return;
			var self=this, project=this.projectId(), serial=++this.serial;
			this.busy=true;
			$('#wsp-rh-create,#wsp-rh-refresh').prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(url),type:'POST',dataType:'json',cache:false,
				timeout:60000,data:WSP.withCsrf($.extend({project_id:project}, values || {}))})
			.done(function (result) {
				if (serial!==self.serial || project!==self.projectId()) return;
				if (result && result.success && number(result.project_id)===project) success(result);
				else self.status(result && result.error || self.lang('WSP_RH_FAILED'));
			}).fail(function () { if (serial===self.serial) self.status(self.lang('WSP_RH_FAILED')); })
			.always(function () { if (serial===self.serial) { self.busy=false; $('#wsp-rh-create,#wsp-rh-refresh').prop('disabled',false); } });
		},
		refresh:function () {
			if (!this.allowed()) return;
			var self=this;
			this.status(this.lang('WSP_RH_LOADING'));
			this.request(window.wspVars.releaseHistoryListUrl, {}, function (response) {
				self.render(Array.isArray(response.releases) ? response.releases : []);
				self.status('');
			});
		},
		render:function (releases) {
			var self=this, host=$('#wsp-rh-list').empty();
			if (!releases.length) { $('<p/>').text(this.lang('WSP_RH_NONE')).appendTo(host); return; }
			releases.forEach(function (release) {
				var row=$('<div/>',{'class':'wsp-cp-row'}).appendTo(host);
				var text=$('<div/>',{'class':'wsp-cp-info'}).appendTo(row);
				$('<strong/>').text('v'+String(release.version || '').replace(/^v/, '')).appendTo(text);
				var stamp = new Date(number(release.created_time)*1000);
				$('<small/>').text((isNaN(stamp.getTime())?'':stamp.toLocaleString()+' · ')+String(release.package_name||'')).appendTo(text);
				if (release.notes) $('<p/>').css('white-space','pre-wrap').text(String(release.notes)).appendTo(text);
				$('<small/>').text(self.lang('WSP_RH_FINGERPRINT')+': '+String(release.snapshot_sha||'')).appendTo(text);
				$('<button/>',{type:'button','class':'btn-main wsp-rh-recover'})
					.attr('data-checkpoint',number(release.checkpoint_id))
					.text(self.lang('WSP_RH_RECOVER')).appendTo(row);
			});
		},
		create:function () {
			if (!this.allowed()) return;
			if (this.dirty()) { this.status(this.lang('WSP_RH_UNSAVED')); return; }
			if (this.prepared && this.prepared.projectId!==this.projectId()) { this.prepared=null; this.status(this.lang('WSP_RELEASE_SNAPSHOT_CHANGED')); return; }
			if (this.prepared && !window.confirm(this.lang('WSP_RELEASE_RECORD_CONFIRM'))) return;
			var version=String($('#wsp-rh-version').val()||'').trim(),
				name=String($('#wsp-rh-package').val()||'').trim(),
				notes=String($('#wsp-rh-notes').val()||'').trim();
			if (!/^v?[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/.test(version) ||
				!/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/.test(name) || name.indexOf('..')!==-1 ||
				notes.length>12000) { this.status(this.lang('WSP_RH_INVALID')); return; }
			var self=this;
			this.request(window.wspVars.releaseHistoryCreateUrl,
				{version:version,package_name:name,notes:notes,
					validated_fingerprint:(self.prepared && self.prepared.projectId===self.projectId()) ? self.prepared.fingerprint : ''},function () {
					self.prepared=null;
					self.status(self.lang('WSP_RH_SAVED'));
					$('#wsp-rh-notes').val('');
					window.setTimeout(function () { self.refresh(); }, 0);
				});
		},
		init:function () {
			var self=this;
			$('body').on('click.wsp_rh','#wsp-rh-open',function () { self.prepared=null; self.open(); });
			$('body').on('click.wsp_rh','#wsp-rh-close',function () { $('#wsp-rh-modal').hide(); });
			$('body').on('click.wsp_rh','#wsp-rh-refresh',function () { self.refresh(); });
			$('body').on('click.wsp_rh','#wsp-rh-create',function () { self.create(); });
			$('body').on('click.wsp_rh','.wsp-rh-recover',function () {
				var id=number($(this).attr('data-checkpoint'));
				if (!id || !WSP.checkpoints || !WSP.checkpoints.allowed()) return;
				$('#wsp-rh-modal').hide();
				WSP.checkpoints.pendingReleaseCheckpoint=id;
				WSP.checkpoints.open();
			});
		}
	};
	WSP.releaseHistory=history;
	$(function () { history.init(); });
})(window, window.jQuery);
