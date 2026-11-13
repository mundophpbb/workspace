/** Workspace IDE 1.16.0 - read-only compatibility indicators and external CI template. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	function id(n) { return Math.max(0, parseInt(n, 10) || 0); }
	function items(x) { return Array.isArray(x) ? x : []; }
	function validName(x) { return /^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/.test(String(x || '')); }
	function tuple(v) { var p = String(v || '').split('.'); return (+p[0] || 0)*100 + (+p[1] || 0); }
	function lineLocation(point) {
		return String(point.path || '') + (point.line ? ':' + id(point.line) : '');
	}
	WSP.testLab = {
		result: null, project: 0, serial: 0, busy: false,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return id(WSP.activeProjectId); },
		allowed: function () { return !!(this.projectId() && window.wspVars &&
			window.wspVars.activeCanEdit == 1 && window.wspVars.testLabUrl); },
		status: function (message) { $('#wsp-lab-status').text(String(message || '')); },
		open: function () {
			if (!this.allowed()) return;
			$('#wsp-lab-modal').fadeIn(120);
			if (!this.result || this.project !== this.projectId()) this.scan();
			else this.render();
		},
		scan: function () {
			if (!this.allowed()) return;
			var self = this, project = this.projectId(), sequence = ++this.serial;
			this.project = 0; this.result = null; this.busy = true;
			$('#wsp-lab-scan').prop('disabled', true);
			$('#wsp-compat-matrix,#wsp-compat-deps,#wsp-compat-phpbb,#wsp-composer-advice').empty();
			$('#wsp-composer-patch').prop('hidden',true);
			$('#wsp-lab-summary,#wsp-lab-indicators,#wsp-lab-notes').empty();
			$('#wsp-lab-workflow,#wsp-lab-report').prop('disabled', true);
			this.status(this.lang('WSP_LAB_LOADING'));
			$.ajax({
				url: WSP.normalizeUrl(window.wspVars.testLabUrl), type: 'POST',
				dataType: 'json', cache: false, timeout: 30000,
				data: WSP.withCsrf({ project_id: project })
			}).done(function (data) {
				if (sequence !== self.serial || project !== self.projectId()) return;
				if (data && data.success && data.project_id == project && data.lab &&
					Array.isArray(data.lab.indicators) && Array.isArray(data.lab.notes)) {
					self.result = data; self.project = project; self.render();
				} else self.status((data && data.error) || self.lang('WSP_LAB_FAILED'));
			}).fail(function () {
				if (sequence === self.serial && project === self.projectId()) self.status(self.lang('WSP_LAB_FAILED'));
			}).always(function () {
				if (sequence !== self.serial) return;
				self.busy = false; $('#wsp-lab-scan').prop('disabled', false);
			});
		},
		source: function (point, container) {
			var fileId = id(point.file_id);
			if (!fileId) return;
			$('<button/>', {type:'button', 'class':'wsp-lab-source'})
				.attr('data-id',fileId).attr('data-line',Math.max(1,id(point.line)))
				.attr('title',this.lang('WSP_LAB_SOURCE')).text(lineLocation(point)).appendTo(container);
		},
		render: function () {
			if (!this.result || this.project !== this.projectId()) return;
			var self = this, lab = this.result.lab, target = String($('#wsp-lab-php').val() || '8.3');
			var summary = lab.summary || {}, manifest = lab.manifest || {};
			var incomplete = !(this.result.coverage || {}).complete || !(lab.coverage || {}).complete;
			var violations = items(lab.indicators).filter(function (x) { return tuple(x.php_min) > tuple(target); });
			var stats = $('#wsp-lab-summary').empty();
			[[summary.php_files_analyzed || 0,'WSP_LAB_PHP_FILES'],
			 [summary.indicators || 0,'WSP_LAB_FOUND'],
			 [summary.test_files || 0,'WSP_LAB_TESTS'],
			 [summary.ci_workflows || 0,'WSP_LAB_CI_COUNT']].forEach(function (row) {
				 $('<span/>',{'class':'wsp-lab-stat'}).text(row[0] + ' ' + self.lang(row[1])).appendTo(stats);
			 });
			$('<span/>', {'class':'wsp-lab-stat'}).text(this.lang('WSP_LAB_MIN') + ': ' +
				String(manifest.php_constraint || '—')).appendTo(stats);
			$('<span/>', {'class':'wsp-lab-stat'}).text(this.lang('WSP_LAB_SUGGESTED') + ': ' +
				String(summary.highest_indicator_php_min || '—')).appendTo(stats);
			this.status((incomplete ? this.lang('WSP_LAB_PARTIAL') + ' ' : '') +
				(violations.length ? this.lang('WSP_LAB_TARGET_WARNING') : ''));
			var notes = $('#wsp-lab-notes').empty();
			if (!items(lab.notes).length) $('<p/>').text(this.lang('WSP_LAB_NO_NOTES')).appendTo(notes);
			items(lab.notes).forEach(function (note) {
				var row = $('<div/>', {'class':'wsp-lab-row' + (note.severity === 'warning' ? ' is-warning' : '')}).appendTo(notes);
				$('<p/>').text(self.lang('WSP_LAB_NOTE_' + note.code)).appendTo(row);
				self.source(note,row);
			});
			var list = $('#wsp-lab-indicators').empty();
			if (!items(lab.indicators).length) $('<p/>').text(this.lang('WSP_LAB_NO_RESULTS')).appendTo(list);
			items(lab.indicators).forEach(function (point) {
				var incompatible = tuple(point.php_min) > tuple(target);
				var row = $('<div/>', {'class':'wsp-lab-row ' + (incompatible ? 'is-warning' : 'is-ok')}).appendTo(list);
				$('<p/>').text(self.lang('WSP_LAB_CODE_' + point.code) + ' — PHP ' + String(point.php_min || '?') + '+').appendTo(row);
				self.source(point,row);
			});
			var compat = lab.compatibility || {}, matrix = $('#wsp-compat-matrix').empty();
			var tbl = $('<table/>', {'class':'wsp-compat-table'}).appendTo(matrix);
			var heading = $('<tr/>').appendTo($('<thead/>').appendTo(tbl));
			[this.lang('WSP_COMPAT_PHP'), this.lang('WSP_COMPAT_RESULT')].forEach(function(h){ $('<th/>').text(h).appendTo(heading); });
			var body = $('<tbody/>').appendTo(tbl);
			items(compat.matrix).forEach(function(entry){
				var row = $('<tr/>').appendTo(body), conflicts = id(entry.detected_conflicts);
				$('<td/>').text('PHP ' + String(entry.php || '?')).appendTo(row);
				var explanation = conflicts ? self.lang('WSP_COMPAT_CONFLICTS') + ': ' + conflicts : self.lang('WSP_COMPAT_UNVERIFIED');
				if (entry.below_declared_minimum) explanation += ' — ' + self.lang('WSP_COMPAT_BELOW_MIN');
				$('<td/>').text(explanation).addClass(entry.status === 'warning' ? 'wsp-compat-warning' : '').appendTo(row);
			});
			var deps = $('#wsp-compat-deps').empty();
			if (!items(compat.dependencies).length) $('<p/>').text(this.lang('WSP_COMPAT_NONE')).appendTo(deps);
			items(compat.dependencies).forEach(function(dep){
				$('<div/>', {'class':'wsp-compat-dep'}).text(String(dep.name || '') + ': ' + String(dep.constraint || '')).appendTo(deps);
			});
			if (compat.dependencies_truncated) $('<p/>').text(this.lang('WSP_COMPAT_TRUNCATED')).appendTo(deps);
			$('#wsp-compat-phpbb').text(this.lang('WSP_COMPAT_PHPBB') + ': ' +
				String(compat.phpbb_constraint || this.lang('WSP_COMPAT_UNDECLARED')) + '. ' + this.lang('WSP_COMPAT_PHPBB_NOTE'));
			var advice = compat.composer_advice || {}, adviseBox = $('#wsp-composer-advice').empty();
			if (!items(advice.issues).length) $('<p/>').text(this.lang('WSP_COMPOSER_NO_ISSUES')).appendTo(adviseBox);
			items(advice.issues).forEach(function(issue){
				$('<div/>', {'class':'wsp-lab-row is-warning'}).text(self.lang('WSP_COMPOSER_' + String(issue.code || '').toUpperCase()) +
					' — ' + String(issue.package || '') + (issue.detail ? ' (' + String(issue.detail) + ')' : '')).appendTo(adviseBox);
			});
			if (advice.truncated) $('<p/>').text(this.lang('WSP_COMPOSER_TRUNCATED')).appendTo(adviseBox);
			var patch = compat.composer_patch || {}, patchPanel = $('#wsp-composer-patch');
			patchPanel.prop('hidden', !(patch.available && patch.file_id && patch.original !== patch.proposed));
			if (patch.available) {
				$('#wsp-composer-before').val(String(patch.original || ''));
				$('#wsp-composer-after').val(String(patch.proposed || ''));
				$('#wsp-composer-patch-changes').empty();
				items(patch.changes).forEach(function(change){
					$('<p/>').text(self.lang('WSP_COMPOSER_PATCH_DUPLICATE') + ': ' + String(change.package || '')).appendTo('#wsp-composer-patch-changes');
				});
			}
			$('#wsp-lab-report').prop('disabled',false);
			$('#wsp-lab-workflow').prop('disabled',!validName(manifest.name) || !manifest.type_valid);
		},
		stageComposer: function () {
			if (!this.allowed() || !this.result || this.project !== this.projectId()) return;
			var patch = ((this.result.lab || {}).compatibility || {}).composer_patch || {};
			if (!patch.available || !patch.file_id || typeof patch.original !== 'string' || typeof patch.proposed !== 'string') return;
			if (id(WSP.activeFileId) !== id(patch.file_id) || !WSP.editor || typeof WSP.editor.getValue !== 'function' ||
				typeof WSP.originalContent !== 'string' || WSP.originalContent !== patch.original ||
				WSP.editor.getValue() !== patch.original || this.unsaved()) {
				this.status(this.lang('WSP_COMPOSER_PATCH_STALE')); return;
			}
			if (!window.confirm(this.lang('WSP_COMPOSER_PATCH_CONFIRM'))) return;
			WSP.editor.setValue(patch.proposed, -1);
			$('#wsp-lab-modal').fadeOut(120);
			this.status(this.lang('WSP_COMPOSER_PATCH_STAGED'));
		},
		workflow: function () {
			if (!this.result || this.project !== this.projectId()) return '';
			var name = String((this.result.lab.manifest || {}).name || '');
			if (!validName(name) || !(this.result.lab.manifest || {}).type_valid) return '';
			var basic = String($('#wsp-lab-ci-mode').val()) === 'basic';
			var content = [
				'name: phpBB extension tests',
				'',
				'on:',
				'  push:',
				'  pull_request:',
				'  workflow_dispatch:',
				'',
				'jobs:',
				'  extension-tests:',
				'    uses: phpbb-extensions/test-framework/.github/workflows/tests.yml@3.3.x',
				'    with:',
				'      EXTNAME: ' + name,
				'      PHPBB_BRANCH: "3.3.x"',
				'      EPV: 1'
			];
			if (basic) content.push('      RUN_MYSQL_JOBS: 0', '      RUN_PGSQL_JOBS: 0', '      RUN_WINDOWS_JOBS: 0');
			return content.join('\n') + '\n';
		},
		report: function () {
			if (!this.result || this.project !== this.projectId()) return '';
			var lab = this.result.lab, manifest=lab.manifest || {}, self=this;
			var out = [this.lang('WSP_LAB_TITLE'), 'Projeto: ' + (manifest.name || '?'),
				this.lang('WSP_LAB_MIN') + ': ' + String(manifest.php_constraint || '?'),
				this.lang('WSP_LAB_SUGGESTED') + ': ' + String((lab.summary || {}).highest_indicator_php_min || '?'),
				this.lang('WSP_LAB_TARGET') + ': PHP ' + String($('#wsp-lab-php').val() || '8.3'),
				'', this.lang('WSP_LAB_STATIC_WARNING'), '', this.lang('WSP_LAB_NOTES')];
			items(lab.notes).forEach(function(n){ out.push('- ' + self.lang('WSP_LAB_NOTE_' + n.code) + ' ('+lineLocation(n)+')'); });
			var compat = lab.compatibility || {};
			out.push('', this.lang('WSP_COMPAT_MATRIX'));
			items(compat.matrix).forEach(function(e){out.push('PHP ' + e.php + ': ' +
				(e.detected_conflicts ? self.lang('WSP_COMPAT_CONFLICTS') + ' ' + e.detected_conflicts : self.lang('WSP_COMPAT_UNVERIFIED')) +
				(e.below_declared_minimum ? ' / ' + self.lang('WSP_COMPAT_BELOW_MIN') : ''));});
			out.push('',this.lang('WSP_COMPAT_DEPENDENCIES'));
			items(compat.dependencies).forEach(function(e){out.push(e.name + ': ' + e.constraint);});
			if (compat.dependencies_truncated) out.push(this.lang('WSP_COMPAT_TRUNCATED'));
			out.push(this.lang('WSP_COMPAT_PHPBB') + ': ' + String(compat.phpbb_constraint || '?'),
				this.lang('WSP_COMPAT_DISCLAIMER'), this.lang('WSP_COMPAT_PHPBB_NOTE'));
			out.push('', this.lang('WSP_COMPOSER_ADVISOR'), this.lang('WSP_COMPOSER_ADVISOR_NOTE'));
			items((compat.composer_advice || {}).issues).forEach(function(e){
				out.push('- ' + self.lang('WSP_COMPOSER_' + String(e.code || '').toUpperCase()) + ': ' + e.package + ' (' + e.detail + ')');
			});
			if ((compat.composer_advice || {}).truncated) out.push(this.lang('WSP_COMPOSER_TRUNCATED'));
			out.push('', this.lang('WSP_LAB_INDICATORS'));
			items(lab.indicators).forEach(function(n){ out.push('- ' + self.lang('WSP_LAB_CODE_' + n.code) + ': PHP ' + n.php_min + '+ ('+lineLocation(n)+')'); });
			if (!(this.result.coverage || {}).complete || !(lab.coverage || {}).complete) out.push('',this.lang('WSP_LAB_PARTIAL'));
			return out.join('\n')+'\n';
		},
		downloadText: function (name, mime, content) {
			if (!content) return false;
			var blob = new window.Blob([content], {type:mime});
			var uri = window.URL.createObjectURL(blob);
			var a = window.document.createElement('a');
			a.href=uri; a.download=name; window.document.body.appendChild(a); a.click();
			window.document.body.removeChild(a);
			window.setTimeout(function () { window.URL.revokeObjectURL(uri); }, 1000);
			return true;
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		openSource: function (fileId, line) {
			if (!this.allowed() || !fileId || this.project !== this.projectId()) return;
			if (this.unsaved()) { this.status(this.lang('WSP_LAB_UNSAVED')); return; }
			var self=this;
			function go() { if (WSP.editor && WSP.editor.gotoLine) { WSP.editor.gotoLine(Math.max(1,line),0,true); if(WSP.editor.focus) WSP.editor.focus(); } }
			if (id(WSP.activeFileId) === fileId) { $('#wsp-lab-modal').fadeOut(120); go(); return; }
			var link=$('.load-file[data-id]').filter(function(){return id(this.getAttribute('data-id'))===fileId;}).first();
			if (!link.length) { this.status(this.lang('WSP_LAB_NOT_IN_TREE')); return; }
			var project=this.projectId();
			$(document).off('wsp:file-loaded.wsp_lab').on('wsp:file-loaded.wsp_lab',function(_event, opened){
				if (project!==self.projectId() || id(opened)!==fileId) return;
				$(document).off('wsp:file-loaded.wsp_lab'); $('#wsp-lab-modal').fadeOut(120); go();
			});
			window.setTimeout(function(){ $(document).off('wsp:file-loaded.wsp_lab'); },15000);
			link.trigger('click');
		},
		bindEvents: function () {
			if (!$('#wsp-lab-modal').length) return;
			var self=this;
			$('body').on('click.wsp_lab','#wsp-lab-open',function(){self.open();});
			$('body').on('click.wsp_lab','#wsp-lab-close',function(){$('#wsp-lab-modal').fadeOut(120);});
			$('body').on('click.wsp_lab','#wsp-lab-scan',function(){self.scan();});
			$('body').on('change.wsp_lab','#wsp-lab-php',function(){self.render();});
			$('body').on('click.wsp_lab','#wsp-lab-workflow',function(){
				if (!self.downloadText('tests.yml','text/yaml;charset=utf-8',self.workflow())) self.status(self.lang('WSP_LAB_CI_INVALID'));
			});
			$('body').on('click.wsp_lab','#wsp-lab-report',function(){self.downloadText('workspace-test-report.txt','text/plain;charset=utf-8',self.report());});
			$('body').on('click.wsp_lab','#wsp-composer-open',function(){
				var patch = (((self.result || {}).lab || {}).compatibility || {}).composer_patch || {};
				if (patch.available) self.openSource(id(patch.file_id),1);
			});
			$('body').on('click.wsp_lab','#wsp-composer-stage',function(){self.stageComposer();});
			$('body').on('click.wsp_lab','.wsp-lab-source',function(){self.openSource(id(this.getAttribute('data-id')),id(this.getAttribute('data-line')));});
			$(document).on('keydown.wsp_lab',function(e){if(e.key==='Escape' && $('#wsp-lab-modal').is(':visible')) $('#wsp-lab-modal').fadeOut(120);});
		}
	};
})(window,window.jQuery);
