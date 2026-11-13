/** Workspace IDE 1.19.0: checkpoint-based, review-before-save release notes. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function positive(x) { return Math.max(0, parseInt(x, 10) || 0); }
	WSP.releaseNotes = {
		previewData: null, project: 0, busy: false, serial: 0,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return positive(WSP.activeProjectId); },
		allowed: function () { return !!(window.wspVars && this.projectId() &&
			positive(window.wspVars.activeCanEdit) && window.wspVars.releaseNotesPreviewUrl &&
			window.wspVars.checkpointListUrl); },
		dirtyEditor: function () { return !!(WSP.activeFileId && WSP.editor &&
			typeof WSP.editor.getValue === 'function' && typeof WSP.originalContent === 'string' &&
			WSP.editor.getValue() !== WSP.originalContent); },
		status: function (message) { $('#wsp-rn-status').text(String(message || '')); },
		clear: function () {
			this.previewData = null;
			$('#wsp-rn-preview-content').prop('hidden',true);
			$('#wsp-rn-save,#wsp-rn-copy,#wsp-rn-download').prop('disabled',true);
			$('#wsp-rn-summary').empty();
			$('#wsp-rn-draft').val('');
		},
		details: function () {
			return {
				project_id: this.projectId(),
				source_type: String($('#wsp-rn-source-type').val() || 'checkpoint'),
				source_id: positive(String($('#wsp-rn-source-type').val())==='project' ?
					$('#wsp-rn-project').val() : $('#wsp-rn-checkpoint').val()),
				version: String($('#wsp-rn-version').val() || '').trim(),
				locale: String($('#wsp-rn-locale').val() || 'pt_br')
			};
		},
		open: function () {
			if (!this.allowed()) return;
			this.clear();
			this.fillProjects();
			this.switchMode();
			this.status(this.lang('WSP_RN_LOADING'));
			$('#wsp-rn-modal').fadeIn(130);
			this.list();
		},
		request: function (url, data, onSuccess) {
			if (this.busy || !this.allowed() || !url) return false;
			var self=this, project=this.projectId(), sequence=++this.serial;
			this.busy=true;
			$('#wsp-rn-preview,#wsp-rn-save').prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(url),type:'POST',dataType:'json',cache:false,
				timeout:60000,data:WSP.withCsrf(data)}).done(function (result) {
				if (sequence!==self.serial || project!==self.projectId()) return;
				if (result && result.success && positive(result.project_id)===project) onSuccess(result);
				else self.status((result && result.error) || self.lang('WSP_RN_FAILED'));
			}).fail(function () {
				if (sequence===self.serial && project===self.projectId()) self.status(self.lang('WSP_RN_FAILED'));
			}).always(function () {
				if (sequence!==self.serial) return;
				self.busy=false; self.sync();
			});
			return true;
		},
		fillProjects: function () {
			var self=this, menu=$('#wsp-rn-project').empty();
			$('#project-list .project-group[data-project-id]').each(function () {
				var id=positive(this.getAttribute('data-project-id'));
				if (!id || id===self.projectId() || this.getAttribute('data-can-open')==='0') return;
				$('<option/>').val(String(id)).text(String(this.getAttribute('data-project-name')||id)+' (#'+id+')').appendTo(menu);
			});
		},
		switchMode: function () {
			var other=String($('#wsp-rn-source-type').val())==='project';
			$('.wsp-rn-checkpoint-control').prop('hidden',other);
			$('.wsp-rn-project-control').prop('hidden',!other);
			this.sync();
		},
		list: function () {
			if (!this.allowed() || this.dirtyEditor()) {
				this.status(this.lang('WSP_RN_UNSAVED')); return;
			}
			var self=this, project=this.projectId();
			$('#wsp-rn-checkpoint').empty();
			this.request(window.wspVars.checkpointListUrl, {project_id:project}, function (data) {
				var rows=Array.isArray(data.checkpoints)?data.checkpoints:[];
				rows.forEach(function (point) {
					var date = new Date(positive(point.created_time)*1000), title=String(point.label || '');
					$('<option/>').val(String(positive(point.checkpoint_id)))
						.text('#'+positive(point.checkpoint_id)+' · '+title+' · '+
							(!isNaN(date.getTime())?date.toLocaleDateString():'')).appendTo($('#wsp-rn-checkpoint'));
				});
				self.status(rows.length || String($('#wsp-rn-source-type').val())==='project' ?
					self.lang('WSP_RN_READY') : self.lang('WSP_RN_NO_CHECKPOINTS'));
			});
		},
		sync: function () {
			var criteria=this.details();
			var valid=!!(this.allowed() && criteria.source_id &&
				/^v?[0-9][a-zA-Z0-9.+_-]{0,39}$/.test(criteria.version) && !this.busy && !this.dirtyEditor());
			$('#wsp-rn-preview').prop('disabled',!valid);
			var fresh=!!(this.previewData && valid &&
				positive(this.previewData.project_id)===criteria.project_id &&
				positive(this.previewData.source_id)===criteria.source_id &&
				this.previewData.source_type===criteria.source_type &&
				this.previewData.version===criteria.version && this.previewData.locale===criteria.locale);
			var text=String($('#wsp-rn-draft').val() || '');
			var hasText=!!(fresh && text.trim());
			$('#wsp-rn-copy,#wsp-rn-download').prop('disabled',!hasText);
			$('#wsp-rn-save').prop('disabled',!(hasText && this.previewData.can_write &&
				positive((this.previewData.report || {}).summary.total) && text.length<=65536));
		},
		preview: function () {
			if (!this.allowed() || this.busy) return;
			if (this.dirtyEditor()) { this.status(this.lang('WSP_RN_UNSAVED')); return; }
			var data=this.details();
			this.clear();
			if (!data.source_id || !/^v?[0-9][a-zA-Z0-9.+_-]{0,39}$/.test(data.version)) {
				this.status(this.lang('WSP_RN_INVALID_VERSION')); return;
			}
			this.status(this.lang('WSP_RN_ANALYZING'));
			var self=this;
			this.request(window.wspVars.releaseNotesPreviewUrl,data,function(response){
				var r=response.report||{},summary=r.summary||{};
				self.previewData={
					project_id:response.project_id,source_id:response.source_id,
					source_type:response.source_type,
					preview_token:response.preview_token,report:r,
					can_write:!!response.can_write,
					version:data.version,locale:data.locale
				};
				$('#wsp-rn-preview-content').prop('hidden',false);
				$('#wsp-rn-draft').val(String(r.draft||''));
				$('#wsp-rn-summary').text(positive(summary.added)+' '+self.lang('WSP_RN_ADDED')+' · '+
					positive(summary.modified)+' '+self.lang('WSP_RN_CHANGED')+' · '+
					positive(summary.removed)+' '+self.lang('WSP_RN_REMOVED'));
				self.status(!positive(summary.total) ? self.lang('WSP_RN_NO_CHANGES') :
					(!response.can_write ? self.lang('WSP_RN_NO_CREATE') : self.lang('WSP_RN_PREVIEW_READY')));
				self.sync();
			});
		},
		edited: function () {
			this.sync();
		},
		apply: function () {
			var report=this.previewData, data=this.details();
			if (!report || this.busy || this.dirtyEditor()) {
				this.status(this.lang('WSP_RN_UNSAVED')); return;
			}
			if (positive(report.project_id)!==data.project_id ||
				positive(report.source_id)!==data.source_id || report.source_type!==data.source_type ||
				report.version!==data.version || report.locale!==data.locale || !report.can_write ||
				!positive((report.report.summary||{}).total)) return;
			var draft=String($('#wsp-rn-draft').val()||'');
			if (!draft.trim() || draft.length>65536 || !window.confirm(this.lang('WSP_RN_CONFIRM'))) return;
			data.preview_token=String(report.preview_token||'');
			data.draft=draft;
			var self=this;
			this.status(this.lang('WSP_RN_SAVING'));
			this.request(window.wspVars.releaseNotesApplyUrl,data,function(){
				self.clear();
				self.status(self.lang('WSP_RN_SAVED'));
				// Reload the tree and editor state after creating/updating CHANGELOG.md.
				window.location.reload();
			});
		},
		copy: function () {
			this.sync();
			if ($('#wsp-rn-copy').prop('disabled')) return;
			var self=this, text=String($('#wsp-rn-draft').val()||'');
			if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
				window.navigator.clipboard.writeText(text).then(function(){ self.status(self.lang('WSP_RN_COPIED')); },
					function(){ self.status(self.lang('WSP_RN_COPY_FAILED')); });
			} else {
				var input=$('#wsp-rn-draft');
				if (input[0] && input[0].select && window.document && window.document.execCommand) {
					input[0].select();
					self.status(window.document.execCommand('copy') ? self.lang('WSP_RN_COPIED') : self.lang('WSP_RN_COPY_FAILED'));
				} else self.status(self.lang('WSP_RN_COPY_FAILED'));
			}
		},
		download: function () {
			this.sync();
			if ($('#wsp-rn-download').prop('disabled')) return;
			var content=String($('#wsp-rn-draft').val()||'');
			var blob=new window.Blob([content],{type:'text/markdown;charset=utf-8'});
			var uri=window.URL.createObjectURL(blob);
			var link=window.document.createElement('a');
			link.href=uri;
			link.download='release-notes-'+String(this.details().version).replace(/[^a-zA-Z0-9_.-]/g,'_')+'.md';
			window.document.body.appendChild(link);link.click();window.document.body.removeChild(link);
			window.setTimeout(function(){window.URL.revokeObjectURL(uri);},1500);
		},
		init: function () {},
		bindEvents: function () {
			var self=this;
			$('body').off('.wsp_rn');
			$('body').on('click.wsp_rn','#wsp-rn-open',function(){self.open();});
			$('body').on('click.wsp_rn','#wsp-rn-close',function(){$('#wsp-rn-modal').fadeOut(120);});
			$('body').on('click.wsp_rn','#wsp-rn-preview',function(){self.preview();});
			$('body').on('click.wsp_rn','#wsp-rn-save',function(){self.apply();});
			$('body').on('click.wsp_rn','#wsp-rn-copy',function(){self.copy();});
			$('body').on('click.wsp_rn','#wsp-rn-download',function(){self.download();});
			$('body').on('input.wsp_rn change.wsp_rn','#wsp-rn-version,#wsp-rn-locale,#wsp-rn-checkpoint,#wsp-rn-source-type,#wsp-rn-project',function(){
				if (this.id==='wsp-rn-source-type') self.switchMode();
				if (self.previewData) {self.clear();self.status(self.lang('WSP_RN_STALE'));} self.sync();
			});
			$('body').on('input.wsp_rn','#wsp-rn-draft',function(){self.edited();});
			$(document).off('keydown.wsp_rn').on('keydown.wsp_rn',function(e){
				if(e.key==='Escape' && $('#wsp-rn-modal').is(':visible'))$('#wsp-rn-modal').fadeOut(120);
			});
		}
	};
})(window,window.jQuery);
