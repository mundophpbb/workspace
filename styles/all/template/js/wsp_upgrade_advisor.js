/** Workspace IDE 1.21.0 - preview-only, read-only extension upgrade analysis. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function id(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function list(value) { return Array.isArray(value) ? value : []; }
	function code(value) { return String(value || '').replace(/[^A-Z_]/g, ''); }
	var GRADES = ['high', 'medium', 'low'];

	WSP.upgradeAdvisor = {
		last: null, busy: false, seq: 0, before: 0, after: 0, current: 0,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return id(WSP.activeProjectId); },
		allowed: function () {
			return !!(this.projectId() && window.wspVars && window.wspVars.activeCanEdit == 1 &&
				window.wspVars.upgradeAdvisorUrl);
		},
		status: function (value) { $('#wsp-up-status').text(String(value || '')); },
		populate: function () {
			var before = $('#wsp-up-before').empty(), after = $('#wsp-up-after').empty(),
				projects = [], current = this.projectId();
			$('#project-list .project-group[data-project-id]').each(function () {
				var projectId = id(this.getAttribute('data-project-id'));
				if (!projectId || this.getAttribute('data-can-open') === '0') return;
				projects.push({id: projectId, title: this.getAttribute('data-project-name') || String(projectId)});
			});
			projects.forEach(function (project) {
				var label = String(project.title) + ' (#' + project.id + ')';
				$('<option/>').val(String(project.id)).text(label).appendTo(before);
				$('<option/>').val(String(project.id)).text(label).appendTo(after);
			});
			after.val(String(current));
			var other = projects.filter(function (item) { return item.id !== current; })[0];
			if (other) before.val(String(other.id));
			if (projects.length < 2) this.status(this.lang('WSP_UP_NEED_TWO'));
			return projects.length;
		},
		reset: function () {
			++this.seq; this.busy = false; this.last = null; this.before = 0; this.after = 0;
			$('#wsp-up-scan').prop('disabled', false);
			$('#wsp-up-report').prop('disabled', true);
			$('#wsp-up-summary,#wsp-up-findings,#wsp-up-plan').empty();
		},
		open: function () {
			if (!this.allowed()) return;
			this.reset();
			this.current = this.projectId();
			this.status(this.lang('WSP_UP_WAITING'));
			var count = this.populate();
			$('#wsp-up-modal').fadeIn(120);
			if (count >= 2) this.analyze();
		},
		analyze: function () {
			if (!this.allowed() || this.busy) return;
			var before = id($('#wsp-up-before').val()), after = id($('#wsp-up-after').val());
			this.reset();
			if (!before || !after || before === after) {
				this.status(this.lang('WSP_UP_PICK_DIFFERENT')); return;
			}
			var self = this, seq = ++this.seq, project = this.projectId();
			this.busy = true;
			this.status(this.lang('WSP_UP_LOADING'));
			$('#wsp-up-scan').prop('disabled', true);
			$.ajax({
				url: WSP.normalizeUrl(window.wspVars.upgradeAdvisorUrl),
				type: 'POST', dataType: 'json', cache: false, timeout: 30000,
				data: WSP.withCsrf({before_id: before, after_id: after})
			}).done(function (data) {
				if (seq !== self.seq || project !== self.projectId() ||
					id($('#wsp-up-before').val()) !== before || id($('#wsp-up-after').val()) !== after) return;
				if (!data || !data.success || data.before_id != before || data.after_id != after ||
					!data.upgrade || data.upgrade.static_only !== true || !Array.isArray(data.upgrade.findings)) {
					self.status(data && data.error ? data.error : self.lang('WSP_UP_FAILED')); return;
				}
				self.last = data; self.before = before; self.after = after; self.current = project;
				self.render();
				$('#wsp-up-report').prop('disabled', false);
			}).fail(function () {
				if (seq === self.seq && project === self.projectId()) self.status(self.lang('WSP_UP_FAILED'));
			}).always(function () {
				if (seq !== self.seq) return;
				self.busy = false; $('#wsp-up-scan').prop('disabled', false);
			});
		},
		validReport: function () {
			return !!(this.last && this.allowed() && this.current === this.projectId() &&
				id($('#wsp-up-before').val()) === this.before &&
				id($('#wsp-up-after').val()) === this.after);
		},
		render: function () {
			if (!this.validReport()) return;
			var report = this.last.upgrade, self = this;
			var summary = report.summary || {}, levels = summary.by_severity || {},
				files = summary.files || {}, migrations = summary.migrations || {},
				complete = report.complete && (this.last.coverage || {}).before &&
					this.last.coverage.before.complete && (this.last.coverage || {}).after &&
					this.last.coverage.after.complete;
			var box = $('#wsp-up-summary').empty();
			[['high','WSP_UP_HIGH'], ['medium','WSP_UP_MEDIUM'], ['low','WSP_UP_LOW']].forEach(function (entry) {
				$('<span/>', {'class':'wsp-up-pill wsp-up-'+entry[0]})
					.text(id(levels[entry[0]]) + ' ' + self.lang(entry[1])).appendTo(box);
			});
			$('<span/>', {'class':'wsp-up-pill'}).text(this.lang('WSP_UP_MIGRATIONS') + ': +'+
				id(migrations.added)+' / ~'+id(migrations.changed)+' / -'+id(migrations.removed)).appendTo(box);
			$('<span/>', {'class':'wsp-up-pill'}).text(this.lang('WSP_UP_FILES') + ': +'+
				id(files.added)+' / ~'+id(files.changed)+' / -'+id(files.removed)).appendTo(box);
			var source = $('#wsp-up-findings').empty();
			list(report.findings).forEach(function (finding) {
				var severity = GRADES.indexOf(finding.severity) >= 0 ? finding.severity : 'low';
				var card = $('<article/>', {'class':'wsp-up-item wsp-up-'+severity}).appendTo(source);
				var title = $('<div/>', {'class':'wsp-up-item-title'}).appendTo(card);
				$('<strong/>').text(self.lang('WSP_UP_RULE_'+code(finding.code))).appendTo(title);
				$('<small/>').text(self.lang('WSP_UP_'+severity.toUpperCase())).appendTo(title);
				$('<p/>').text(self.lang('WSP_UP_ADVICE_'+code(finding.code))).appendTo(card);
				if (finding.detail) $('<p/>').text(String(finding.detail)).appendTo(card);
				var row = $('<div/>').appendTo(card), fileId = id(finding.new_file_id);
				var position = String(finding.file || '') + (id(finding.line) ? ':'+id(finding.line) : '');
				if (fileId && self.after === self.projectId())
					$('<button/>', {type:'button','class':'wsp-up-source'}).text(position)
						.attr('data-id',fileId).attr('data-line',Math.max(1,id(finding.line)))
						.appendTo(row);
				else $('<code/>').text(position).appendTo(row);
			});
			if (!source.children().length)
				$('<p/>').text(this.lang('WSP_UP_NO_FINDINGS')).appendTo(source);
			var ordered = $('#wsp-up-plan').empty();
			list(report.plan).forEach(function (key) {
				$('<li/>').text(self.lang('WSP_UP_STEP_'+code(key))).appendTo(ordered);
			});
			this.status((complete ? '' : this.lang('WSP_UP_PARTIAL') + ' ') +
				this.lang('WSP_UP_RECOMMEND_'+code(report.recommendation)) + ' ' +
				this.lang('WSP_UP_TOTAL') + ': ' + list(report.findings).length);
		},
		reportText: function () {
			if (!this.validReport()) return '';
			var self=this, report=this.last.upgrade, identity=report.identity || {}, summary=report.summary || {};
			var out = [this.lang('WSP_UP_TITLE'), this.lang('WSP_UP_STATIC_WARNING'),
				this.lang('WSP_UP_BEFORE') + ': '+(identity.before_name || '?')+' '+(identity.before_version || '?')+' (#'+this.before+')',
				this.lang('WSP_UP_AFTER') + ': '+(identity.after_name || '?')+' '+(identity.after_version || '?')+' (#'+this.after+')',
				this.lang('WSP_UP_RECOMMEND_'+code(report.recommendation)),
				this.lang('WSP_UP_PARTIAL') + ': ' + (!report.complete ? 'SIM' : 'NAO'),
				this.lang('WSP_UP_TOTAL') + ': '+id(summary.findings), ''];
			list(report.findings).forEach(function (finding) {
				out.push('['+String(finding.severity).toUpperCase()+'] '+self.lang('WSP_UP_RULE_'+code(finding.code))+
					' — '+String(finding.file)+':'+id(finding.line));
				out.push('  '+self.lang('WSP_UP_ADVICE_'+code(finding.code)));
				if (finding.detail) out.push('  '+String(finding.detail));
			});
			out.push('',this.lang('WSP_UP_PLAN'));
			list(report.plan).forEach(function (key,i) {out.push((i+1)+'. '+self.lang('WSP_UP_STEP_'+code(key)));});
			out.push('',this.lang('WSP_UP_DISCLAIMER'));
			return out.join('\n')+'\n';
		},
		download: function () {
			var content=this.reportText(); if (!content) return false;
			var blob=new window.Blob([content],{type:'text/plain;charset=utf-8'}),
				url=window.URL.createObjectURL(blob), a=window.document.createElement('a');
			a.href=url;a.download='workspace-plano-atualizacao.txt';
			window.document.body.appendChild(a);a.click();window.document.body.removeChild(a);
			window.setTimeout(function(){window.URL.revokeObjectURL(url);},1000);
			return true;
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		openSource: function (fileId, line) {
			if (!this.validReport() || this.after !== this.projectId()) return;
			if (this.unsaved()) {this.status(this.lang('WSP_UP_UNSAVED')); return;}
			var self=this, project=this.projectId();
			function gotoLine() {
				if (WSP.editor && typeof WSP.editor.gotoLine === 'function') {
					WSP.editor.gotoLine(Math.max(1,line),0,true);
					if (WSP.editor.focus) WSP.editor.focus();
				}
			}
			if (id(WSP.activeFileId)===fileId) {$('#wsp-up-modal').fadeOut(120);gotoLine();return;}
			var link=$('.load-file[data-id]').filter(function(){return id(this.getAttribute('data-id'))===fileId;}).first();
			if (!link.length) {this.status(this.lang('WSP_UP_SOURCE_MISSING'));return;}
			$(document).off('wsp:file-loaded.wsp_up').on('wsp:file-loaded.wsp_up',function (_event, opened) {
				if (project!==self.projectId() || id(opened)!==fileId) return;
				$(document).off('wsp:file-loaded.wsp_up');$('#wsp-up-modal').fadeOut(120);gotoLine();
			});
			window.setTimeout(function(){$(document).off('wsp:file-loaded.wsp_up');},15000);
			link.trigger('click');
		},
		bindEvents: function () {
			if (!$('#wsp-up-modal').length) return;
			var self=this;
			$('body').on('click.wsp_up','#wsp-up-open',function(){self.open();});
			$('body').on('click.wsp_up','#wsp-up-close',function(){$('#wsp-up-modal').fadeOut(120);});
			$('body').on('click.wsp_up','#wsp-up-scan',function(){self.analyze();});
			$('body').on('click.wsp_up','#wsp-up-report',function(){self.download();});
			$('body').on('change.wsp_up','#wsp-up-before,#wsp-up-after',function(){self.reset();self.status(self.lang('WSP_UP_WAITING'));});
			$('body').on('click.wsp_up','.wsp-up-source',function(){self.openSource(id(this.getAttribute('data-id')),id(this.getAttribute('data-line')));});
			$(document).on('keydown.wsp_up',function(event){if(event.key==='Escape' && $('#wsp-up-modal').is(':visible'))$('#wsp-up-modal').fadeOut(120);});
		}
	};
})(window, window.jQuery);
