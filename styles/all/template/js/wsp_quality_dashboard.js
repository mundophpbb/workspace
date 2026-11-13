/** Workspace IDE 1.24.0 - evidence-based static quality view and version comparison. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function num(x) { return Math.max(0, parseInt(x, 10) || 0); }
	function rows(x) { return Array.isArray(x) ? x : []; }
	function safeCode(x) { return String(x || '').replace(/[^A-Za-z0-9_]/g, ''); }
	function itemValue(report, section, key) {
		return (report && report.metrics && report.metrics[section]) ? report.metrics[section][key] : null;
	}
	var COMPARISON_ROWS = [
		['security','high','WSP_QL_SECURITY','WSP_QL_HIGH'],
		['security','medium','WSP_QL_SECURITY','WSP_QL_MEDIUM'],
		['structure','errors','WSP_QL_STRUCTURE','WSP_QL_ERRORS'],
		['structure','warnings','WSP_QL_STRUCTURE','WSP_QL_WARNINGS'],
		['compatibility','warnings','WSP_QL_COMPATIBILITY','WSP_QL_WARNINGS'],
		['permissions','external_references','WSP_QL_PERMISSIONS','WSP_QL_EXTERNAL'],
		['tests','files_present','WSP_QL_TESTS','WSP_QL_TEST_FILES'],
		['tests','workflows_present','WSP_QL_TESTS','WSP_QL_WORKFLOWS'],
		['maintenance','language_files','WSP_QL_MAINTENANCE','WSP_QL_LANGUAGES'],
		['maintenance','migration_files','WSP_QL_MAINTENANCE','WSP_QL_MIGRATIONS']
	];
	WSP.qualityDashboard = {
		last: null, active: 0, comparison: 0, sequence: 0, busy: false,
		lang: function (key) { return String(WSP.lang(key) || key); },
		projectId: function () { return num(WSP.activeProjectId); },
		allowed: function () { return !!(this.projectId() && window.wspVars &&
			window.wspVars.activeCanEdit == 1 && window.wspVars.qualityDashboardUrl); },
		status: function (message) { $('#wsp-ql-status').text(String(message || '')); },
		reset: function () {
			++this.sequence;
			this.last = null; this.active = 0; this.comparison = 0; this.busy = false;
			$('#wsp-ql-download').prop('disabled', true);
			$('#wsp-ql-scan').prop('disabled', false);
			$('#wsp-ql-metrics,#wsp-ql-recommendations,#wsp-ql-alerts,#wsp-ql-comparison-body').empty();
			$('#wsp-ql-comparison').prop('hidden', true);
		},
		populate: function () {
			var select = $('#wsp-ql-compare').empty(), active = this.projectId();
			$('<option/>').val('0').text(this.lang('WSP_QL_NO_COMPARE')).appendTo(select);
			$('#project-list .project-group[data-project-id]').each(function () {
				var project = num(this.getAttribute('data-project-id'));
				if (!project || project === active || this.getAttribute('data-can-open') === '0') return;
				var label = this.getAttribute('data-project-name') || String(project);
				$('<option/>').val(String(project)).text(String(label) + ' (#' + project + ')').appendTo(select);
			});
			select.val('0');
		},
		open: function () {
			if (!this.allowed()) return;
			this.reset(); this.populate();
			this.status(this.lang('WSP_QL_WAITING'));
			$('#wsp-ql-modal').fadeIn(120);
			this.scan();
		},
		scan: function () {
			if (!this.allowed()) return;
			this.reset();
			var self=this, project=this.projectId(), compare=num($('#wsp-ql-compare').val());
			if (compare === project) compare = 0;
			var sequence=++this.sequence;
			this.busy=true;
			$('#wsp-ql-scan').prop('disabled', true);
			this.status(this.lang('WSP_QL_LOADING'));
			$.ajax({
				url: WSP.normalizeUrl(window.wspVars.qualityDashboardUrl),
				type:'POST', dataType:'json', cache:false, timeout:45000,
				data: WSP.withCsrf({project_id:project, compare_id:compare})
			}).done(function (data) {
				if (sequence !== self.sequence || project !== self.projectId() ||
					compare !== num($('#wsp-ql-compare').val())) return;
				if (!data || !data.success || num(data.project_id) !== project ||
					num(data.compare_id) !== compare || !data.reports ||
					!data.reports.primary || data.reports.primary.static_only !== true ||
					(compare && (!data.reports.comparison || data.reports.comparison.static_only !== true))) {
					self.status(data && data.error ? data.error : self.lang('WSP_QL_FAILED')); return;
				}
				self.last=data; self.active=project; self.comparison=compare;
				self.render();
				$('#wsp-ql-download').prop('disabled', false);
			}).fail(function () {
				if (sequence === self.sequence && project === self.projectId())
					self.status(self.lang('WSP_QL_FAILED'));
			}).always(function () {
				if (sequence !== self.sequence) return;
				self.busy=false;
				$('#wsp-ql-scan').prop('disabled', false);
			});
		},
		valid: function () {
			return !!(this.allowed() && this.last && this.active === this.projectId() &&
				this.comparison === num($('#wsp-ql-compare').val()));
		},
		truth: function (value) { return value ? this.lang('WSP_QL_YES') : this.lang('WSP_QL_NO'); },
		metricLines: function (key, report) {
			var m=report.metrics || {}, p=m[key] || {};
			if (key === 'security') return [this.lang('WSP_QL_HIGH')+': '+num(p.high),
				this.lang('WSP_QL_MEDIUM')+': '+num(p.medium)];
			if (key === 'structure') return [this.lang('WSP_QL_ERRORS')+': '+num(p.errors),
				this.lang('WSP_QL_WARNINGS')+': '+num(p.warnings)];
			if (key === 'compatibility') return [this.lang('WSP_QL_WARNINGS')+': '+num(p.warnings),
				this.lang('WSP_QL_INDICATORS')+': '+num(p.indicators),
				'PHP: '+String(p.php_constraint || '?'),
				this.lang('WSP_QL_PHP_DETECTED')+': '+String(p.php_min_detected || '?')];
			if (key === 'permissions') return [this.lang('WSP_QL_EXTERNAL')+': '+num(p.external_references),
				this.lang('WSP_QL_DYNAMIC')+': '+num(p.dynamic_checks)];
			if (key === 'tests') return [this.lang('WSP_QL_TEST_FILES')+': '+num(p.files_present),
				this.lang('WSP_QL_WORKFLOWS')+': '+num(p.workflows_present),
				this.lang('WSP_QL_NOT_EXECUTED')];
			return [this.lang('WSP_QL_README')+': '+this.truth(p.readme_present),
				this.lang('WSP_QL_CHANGELOG')+': '+this.truth(p.changelog_present),
				this.lang('WSP_QL_LANGUAGES')+': '+num(p.language_files),
				this.lang('WSP_QL_MIGRATIONS')+': '+num(p.migration_files)];
		},
		renderCards: function () {
			var self=this, report=this.last.reports.primary, box=$('#wsp-ql-metrics').empty();
			['security','structure','compatibility','permissions','tests','maintenance'].forEach(function (key) {
				var card=$('<section/>',{'class':'wsp-ql-card'}).appendTo(box);
				$('<h5/>').text(self.lang('WSP_QL_'+key.toUpperCase())).appendTo(card);
				self.metricLines(key,report).forEach(function (line) { $('<p/>').text(line).appendTo(card); });
			});
		},
		renderComparison: function () {
			var self=this, compared=this.last.reports.comparison;
			$('#wsp-ql-comparison').prop('hidden',!compared);
			var tbody=$('#wsp-ql-comparison-body').empty();
			if (!compared) return;
			var before=this.last.reports.primary;
			COMPARISON_ROWS.forEach(function (item) {
				var a=num(itemValue(before,item[0],item[1]));
				var b=num(itemValue(compared,item[0],item[1]));
				var delta=b-a;
				var tr=$('<tr/>').appendTo(tbody);
				$('<th/>',{'scope':'row'}).text(self.lang(item[2])+' - '+self.lang(item[3])).appendTo(tr);
				$('<td/>').text(String(a)).appendTo(tr);
				$('<td/>').text(String(b)).appendTo(tr);
				$('<td/>').text((delta>0 ? '+' : '') + delta).appendTo(tr);
			});
			// Never mark a numerical trend as improvement: sources and scope can differ.
		},
		alertLabel: function (finding) {
			var source=String(finding.source||''), code=safeCode(finding.code), key='';
			if (source === 'security') key='WSP_SEC_RULE_'+code;
			else if (source === 'compatibility') key='WSP_LAB_NOTE_'+code.toLowerCase();
			else if (source === 'permissions') key='WSP_PERM_FINDING_'+code;
			var value=key ? this.lang(key) : '';
			return value && value !== key ? value : code.replace(/_/g,' ');
		},
		renderAlerts: function () {
			var self=this, target=$('#wsp-ql-alerts').empty();
			var primary=this.last.reports.primary;
			function group(report, projectId, caption) {
				if (!report) return;
				var alerts=rows(report.alerts);
				if (caption) $('<h6/>').text(caption).appendTo(target);
				if (!alerts.length) $('<p/>').text(self.lang('WSP_QL_NO_FINDINGS')).appendTo(target);
				alerts.forEach(function (finding) {
					var row=$('<div/>',{'class':'wsp-ql-alert'}).appendTo(target);
					var source=String(finding.source||'').toUpperCase();
					$('<strong/>').text(self.lang('WSP_QL_ALERT_'+safeCode(source))).appendTo(row);
					$('<span/>').text(' - '+self.alertLabel(finding)+' ('+String(finding.severity||'')+')').appendTo(row);
					var location=String(finding.path||'')+':'+num(finding.line);
					if (projectId === self.projectId() && num(finding.file_id))
						$('<button/>',{type:'button','class':'wsp-ql-source'}).attr('data-id',num(finding.file_id))
							.attr('data-line',Math.max(1,num(finding.line))).text(location).appendTo(row);
					else $('<code/>').text(location).appendTo(row);
				});
			}
			group(primary,this.active, this.last.reports.comparison ? this.lang('WSP_QL_BEFORE') : '');
			if (this.last.reports.comparison)
				group(this.last.reports.comparison,this.comparison,this.lang('WSP_QL_AFTER'));
		},
		render: function () {
			if (!this.valid()) return;
			var self=this, primary=this.last.reports.primary, compared=this.last.reports.comparison;
			this.renderCards(); this.renderComparison();
			var area=$('#wsp-ql-recommendations').empty();
			var recommendations=rows(primary.recommendations);
			if (!recommendations.length) $('<p/>').text(this.lang('WSP_QL_NO_RECOMMENDATIONS')).appendTo(area);
			recommendations.forEach(function (code) {
				$('<p/>').text(self.lang('WSP_QL_RECO_'+safeCode(code))).appendTo(area);
			});
			this.renderAlerts();
			this.status((!primary.complete || (compared && !compared.complete) ?
				this.lang('WSP_QL_PARTIAL') : this.lang('WSP_QL_IN_SCOPE')));
		},
		reportText: function () {
			if (!this.valid()) return '';
			var self=this, listLines=[this.lang('WSP_QL_TITLE'), this.lang('WSP_QL_STATIC_WARNING')];
			function append(label,report) {
				listLines.push('',label+': '+String((report.identity||{}).name||'?')+' '+String((report.identity||{}).version||''),
					report.complete ? self.lang('WSP_QL_IN_SCOPE') : self.lang('WSP_QL_PARTIAL'));
				['security','structure','compatibility','permissions','tests','maintenance'].forEach(function (key) {
					listLines.push(self.lang('WSP_QL_'+key.toUpperCase()));
					self.metricLines(key,report).forEach(function (line) {listLines.push('  '+line);});
				});
				listLines.push(self.lang('WSP_QL_RECOMMENDATIONS'));
				rows(report.recommendations).forEach(function (code) {
					listLines.push(' - '+self.lang('WSP_QL_RECO_'+safeCode(code)));
				});
				listLines.push(self.lang('WSP_QL_FINDINGS'));
				rows(report.alerts).forEach(function (finding) {
					listLines.push(' - '+self.alertLabel(finding)+' ['+String(finding.severity||'')+'] '+
						String(finding.path||'')+':'+num(finding.line));
				});
			}
			append(this.lang('WSP_QL_BEFORE'),this.last.reports.primary);
			if (this.last.reports.comparison) append(this.lang('WSP_QL_AFTER'),this.last.reports.comparison);
			listLines.push('',this.lang('WSP_QL_LIMITATION'));
			return listLines.join('\n')+'\n';
		},
		download: function () {
			var content=this.reportText(); if (!content) return false;
			var blob=new window.Blob([content],{type:'text/plain;charset=utf-8'});
			var url=window.URL.createObjectURL(blob), a=window.document.createElement('a');
			a.href=url; a.download='workspace-painel-qualidade.txt';
			window.document.body.appendChild(a); a.click(); window.document.body.removeChild(a);
			window.setTimeout(function () {window.URL.revokeObjectURL(url);},1000);
			return true;
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue==='function' && WSP.activeFileId &&
				typeof WSP.originalContent==='string' && WSP.editor.getValue()!==WSP.originalContent);
		},
		openSource: function (fileId,line) {
			if (!this.valid() || !fileId) return;
			if (this.unsaved()) {this.status(this.lang('WSP_QL_UNSAVED'));return;}
			var self=this, project=this.projectId();
			function jump() {if (WSP.editor && typeof WSP.editor.gotoLine==='function') {
				WSP.editor.gotoLine(Math.max(1,line),0,true); if (WSP.editor.focus) WSP.editor.focus();}}
			if (num(WSP.activeFileId)===fileId) {$('#wsp-ql-modal').fadeOut(120); jump();return;}
			var target=$('.load-file[data-id]').filter(function(){return num(this.getAttribute('data-id'))===fileId;}).first();
			if (!target.length) {this.status(this.lang('WSP_QL_NOT_IN_TREE'));return;}
			$(document).off('wsp:file-loaded.wsp_ql').on('wsp:file-loaded.wsp_ql',function (_event,opened) {
				if (project!==self.projectId() || num(opened)!==fileId) return;
				$(document).off('wsp:file-loaded.wsp_ql'); $('#wsp-ql-modal').fadeOut(120); jump();
			});
			window.setTimeout(function() {$(document).off('wsp:file-loaded.wsp_ql');},15000);
			target.trigger('click');
		},
		bindEvents: function () {
			if (!$('#wsp-ql-modal').length) return;
			var self=this;
			$('body').on('click.wsp_ql','#wsp-ql-open',function (){self.open();});
			$('body').on('click.wsp_ql','#wsp-ql-close',function (){$('#wsp-ql-modal').fadeOut(120);});
			$('body').on('click.wsp_ql','#wsp-ql-scan',function (){self.scan();});
			$('body').on('change.wsp_ql','#wsp-ql-compare',function (){self.reset();self.status(self.lang('WSP_QL_WAITING'));});
			$('body').on('click.wsp_ql','#wsp-ql-download',function (){self.download();});
			$('body').on('click.wsp_ql','.wsp-ql-source',function (){
				self.openSource(num(this.getAttribute('data-id')),num(this.getAttribute('data-line')));
			});
			$(document).on('keydown.wsp_ql',function (e) {
				if (e.key==='Escape' && $('#wsp-ql-modal').is(':visible')) $('#wsp-ql-modal').fadeOut(120);
			});
		}
	};
})(window, window.jQuery);
