/** Workspace 1.37.0: access-controlled, read-only project portfolio. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;
	var rows = [], busy = false, currentStats = null, currentIncomplete = false;
	function lang(key) { return (window.wspVars && window.wspVars.lang && window.wspVars.lang[key]) || key; }
	function status(message) { $('#wsp-portfolio-status').text(message || ''); }
	function number(x) { return Math.max(0, parseInt(x, 10) || 0); }
	// All chart elements are text nodes and styled bars; no remote chart libraries.
	function renderStatistics(stats, incomplete) {
		var box = $('#wsp-stats-totals').empty(), chart = $('#wsp-stats-monthly').empty();
		var limits = $('#wsp-stats-limits').empty();
		$('#wsp-stats-comparison,#wsp-stats-distribution').empty();
		if (!stats || !stats.totals || !Array.isArray(stats.monthly)) return;
		[['projects','WSP_STATS_PROJECTS'],['files','WSP_STATS_FILES'],
		 ['releases','WSP_STATS_RELEASES'],['tasks_todo','WSP_STATS_TODO'],
		 ['tasks_doing','WSP_STATS_DOING'],['tasks_done','WSP_STATS_DONE']].forEach(function (pair) {
			var tile = $('<div/>', {'class':'wsp-stats-tile'}).appendTo(box);
			$('<strong/>').text(number(stats.totals[pair[0]])).appendTo(tile);
			$('<span/>').text(lang(pair[1])).appendTo(tile);
		});
		var max = 1;
		stats.monthly.forEach(function (m) { max = Math.max(max, number(m.activities), number(m.releases)); });
		stats.monthly.forEach(function (m) {
			var item = $('<div/>', {'class':'wsp-stats-month'}).appendTo(chart);
			$('<span/>', {'class':'wsp-stats-month-label'}).text(String(m.month || '')).appendTo(item);
			[['activities','WSP_STATS_ACTIVITIES'],['releases','WSP_STATS_RELEASES']].forEach(function (kind) {
				var value = number(m[kind[0]]);
				var row = $('<div/>', {'class':'wsp-stats-bar-row'}).appendTo(item);
				$('<span/>').text(lang(kind[1])).appendTo(row);
				var rail = $('<div/>', {'class':'wsp-stats-rail'}).appendTo(row);
				$('<span/>', {'class':'wsp-stats-fill wsp-stats-fill-'+kind[0]}).css('width', (100*value/max)+'%').appendTo(rail);
				$('<strong/>').text(value).appendTo(row);
			});
		});
		if (stats.comparison) {
			var cmp = stats.comparison, comparison = $('#wsp-stats-comparison');
			[['activities','WSP_STATS_ACTIVITIES'],['releases','WSP_STATS_RELEASES']].forEach(function (part) {
				var prev=number(cmp.previous && cmp.previous[part[0]]), recent=number(cmp.recent && cmp.recent[part[0]]);
				var item=$('<div/>', {'class':'wsp-stats-tile'}).appendTo(comparison);
				$('<strong/>').text(lang(part[1])+': '+prev+' → '+recent).appendTo(item);
				$('<span/>').text(prev === 0 ? lang('WSP_STATS_NO_BASELINE') : ((recent-prev)*100/prev).toFixed(1)+'%').appendTo(item);
			});
		}
		if (Array.isArray(stats.distribution)) {
			var dist=$('#wsp-stats-distribution'), maxType=1;
			stats.distribution.forEach(function (d) {maxType=Math.max(maxType,number(d.count));});
			stats.distribution.forEach(function (d) {
				var line=$('<div/>',{'class':'wsp-stats-bar-row'}).appendTo(dist);
				var action=d.action==='__other__'?lang('WSP_STATS_OTHER'):String(d.action || '');
				$('<span/>').text(action).appendTo(line);
				var rail=$('<div/>',{'class':'wsp-stats-rail'}).appendTo(line);
				$('<span/>',{'class':'wsp-stats-fill wsp-stats-fill-activities'}).css('width',(100*number(d.count)/maxType)+'%').appendTo(rail);
				$('<strong/>').text(number(d.count)).appendTo(line);
			});
		}
		if (incomplete) limits.append($('<span/>').text(lang('WSP_STATS_PROJECT_LIMIT')));
		if (stats.activity_truncated) limits.append($('<span/>').text(lang('WSP_STATS_ACTIVITY_LIMIT')));
	}
	// CSV escape neutralizes spreadsheet formulas, including leading whitespace and control characters.
	function csvCell(value) {
		var v=String(value == null ? '' : value);
		if (/^[\s\x00-\x1f]*[=+@-]/.test(v)) v=String.fromCharCode(39)+v;
		return '"'+v.replace(/"/g,'""')+'"';
	}
	function exportCsv() {
		if (!currentStats || !Array.isArray(currentStats.monthly)) return;
		var lines=[['section','label','month','count','partial'],
			['metadata','projects_truncated','',currentIncomplete?1:0,''],
			['metadata','activities_truncated','',currentStats.activity_truncated?1:0,''],
			['metadata','current_month_incomplete','',1,'']];
		currentStats.monthly.forEach(function (m) {
			lines.push(['monthly','activities',String(m.month||''),number(m.activities),currentIncomplete||!!currentStats.activity_truncated?1:0]);
			lines.push(['monthly','releases',String(m.month||''),number(m.releases),currentIncomplete?1:0]);
		});
		(currentStats.distribution||[]).forEach(function (d) {
			lines.push(['action',String(d.action||''),'',number(d.count),currentIncomplete||!!currentStats.activity_truncated?1:0]);
		});
		var body='\ufeff'+lines.map(function (cells) {return cells.map(csvCell).join(',');}).join('\r\n')+'\r\n';
		var blob=new Blob([body],{type:'text/csv;charset=utf-8'}), href=URL.createObjectURL(blob);
		var a=document.createElement('a'); a.href=href; a.download='workspace-estatisticas.csv';
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
		setTimeout(function () {URL.revokeObjectURL(href);},1000);
	}
	function render() {
		var target = $('#wsp-portfolio-results').empty();
		var query = String($('#wsp-portfolio-filter').val() || '').toLocaleLowerCase();
		var pending = $('#wsp-portfolio-only-unreleased').prop('checked');
		var attention = $('#wsp-portfolio-only-attention').prop('checked');
		var shown = rows.filter(function (item) {
			return (!pending || !item.last_release) && (!attention || !!item.needs_attention) && String(item.project_name || '').toLocaleLowerCase().indexOf(query) !== -1;
		});
		if (!shown.length) { $('<p/>').text(lang('WSP_PORTFOLIO_NONE')).appendTo(target); return; }
		shown.sort(function (a,b) {
			if (!!a.needs_attention !== !!b.needs_attention) return a.needs_attention ? -1 : 1;
			return number(b.attention_count) - number(a.attention_count);
		});
		shown.forEach(function (item) {
			var card = $('<article/>', {'class':'wsp-portfolio-card'}).appendTo(target);
			$('<h5/>').text(String(item.project_name || '')).appendTo(card);
			var release = item.last_release;
			$('<p/>').text(release ? ('v' + String(release.version || '').replace(/^v/, '') + ' · ' + String(release.package_name || '')) : lang('WSP_PORTFOLIO_PENDING')).appendTo(card);
			if (release && number(release.created_time)) {
				var date = new Date(number(release.created_time) * 1000);
				if (!isNaN(date.getTime())) $('<small/>').text(date.toLocaleString()).appendTo(card);
			}
			$('<p/>').text(number(item.file_count) + ' ' + lang('WSP_PORTFOLIO_FILES') + ' · ' + number(item.release_count) + ' ' + lang('WSP_PORTFOLIO_RELEASES')).appendTo(card);
			var total=number(item.tasks_todo)+number(item.tasks_doing);
			var details = total ? (number(item.tasks_todo)+' '+lang('WSP_MAINT_TODO')+' · '+number(item.tasks_doing)+' '+lang('WSP_MAINT_DOING')) : lang('WSP_MAINT_NO_OPEN_TASKS');
			$('<p/>', {'class':total ? 'wsp-maint-attention' : 'wsp-maint-quiet'}).text(details).appendTo(card);
			if (item.project_locked) $('<p/>', {'class':'wsp-maint-attention'}).text(lang('WSP_MAINT_LOCKED')).appendTo(card);
			if (release && number(release.created_time)) {
				var age = Math.floor((Date.now()/1000-number(release.created_time))/86400);
				if (age >= 90) $('<p/>').text(lang('WSP_MAINT_OLDER')+' ('+age+' '+lang('WSP_MAINT_DAYS')+')').appendTo(card);
			}
			$('<small/>').text(lang('WSP_MAINT_VALIDATION_UNKNOWN')).appendTo(card);
			if (item.can_edit) {
				$('<button/>', {type:'button', 'class':'btn-main wsp-backup-audit'})
					.attr('data-project', number(item.project_id))
					.text(lang('WSP_BACKUP_AUDIT')).appendTo(card);
				$('<p/>', {'class':'wsp-backup-result', 'role':'status', 'aria-live':'polite'}).appendTo(card);
				$('<button/>', {type:'button', 'class':'btn-main wsp-backup-export'})
					.attr('data-project', number(item.project_id))
					.text(lang('WSP_BACKUP_EXPORT')).appendTo(card);
				var verifyForm=$('<form/>', {'class':'wsp-backup-verify-form', enctype:'multipart/form-data'}).appendTo(card);
				$('<label/>').text(lang('WSP_BACKUP_INSPECT_SELECT')+' ').appendTo(verifyForm)
					.append($('<input/>', {type:'file', name:'backup_zip', accept:'.zip,application/zip', required:true}));
				$('<button/>', {type:'submit', 'class':'btn-main'})
					.text(lang('WSP_BACKUP_INSPECT')).appendTo(verifyForm);
				$('<div/>', {'class':'wsp-backup-verify-result', role:'status', 'aria-live':'polite'}).appendTo(card);

			}
			$('<button/>', {type:'button', 'class':'btn-main wsp-portfolio-go'})
				.attr('data-project', number(item.project_id))
				.text(lang('WSP_PORTFOLIO_OPEN')).appendTo(card);
		});
	}
	function refresh() {
		if (busy || !window.wspVars || !window.wspVars.portfolioSummaryUrl) return;
		busy = true;
		$('#wsp-portfolio-refresh').prop('disabled', true);
		status(lang('WSP_PORTFOLIO_LOADING'));
		$.ajax({url:WSP.normalizeUrl(window.wspVars.portfolioSummaryUrl), type:'POST', dataType:'json', cache:false,
			timeout:60000, data:WSP.withCsrf({})})
			.done(function (result) {
				if (!result || !result.success || !Array.isArray(result.projects)) {
					status(result && result.error || lang('WSP_PORTFOLIO_FAILED')); return;
				}
				rows = result.projects;
				currentStats = result.statistics || null; currentIncomplete = !!result.truncated;
				$('#wsp-stats-export').prop('disabled', !currentStats);
				renderStatistics(result.statistics, !!result.truncated);
				$('#wsp-portfolio-summary').text(number(result.count) + ' / ' + number(result.without_recorded_release) + ' — ' + lang('WSP_PORTFOLIO_SUMMARY') + ' · ' + number(result.attention_projects) + ' ' + lang('WSP_MAINT_WITH_ATTENTION'));
				status(result.truncated ? lang('WSP_PORTFOLIO_LIMIT') : '');
				render();
			})
			.fail(function () { status(lang('WSP_PORTFOLIO_FAILED')); })
			.always(function () { busy=false; $('#wsp-portfolio-refresh').prop('disabled', false); });
	}
	$(function () {
		$('body').on('click.wspPortfolio', '#wsp-portfolio-open', function () {
			rows=[]; currentStats=null; currentIncomplete=false;
			$('#wsp-stats-export').prop('disabled',true);
			$('#wsp-portfolio-results,#wsp-portfolio-summary,#wsp-stats-totals,#wsp-stats-monthly,#wsp-stats-limits').empty();
			$('#wsp-portfolio-modal').fadeIn(120);
			refresh();
		});
		$('body').on('click.wspPortfolio', '#wsp-portfolio-close', function () { $('#wsp-portfolio-modal').hide(); });
		$('body').on('click.wspPortfolio', '#wsp-portfolio-refresh', refresh);
		$('body').on('click.wspPortfolio', '#wsp-stats-export', exportCsv);
		$('body').on('input.wspPortfolio change.wspPortfolio', '#wsp-portfolio-filter,#wsp-portfolio-only-unreleased,#wsp-portfolio-only-attention', render);
		$('body').on('click.wspPortfolio', '.wsp-backup-audit', function () {
			var button=$(this), project=number(button.attr('data-project'));
			var target=button.siblings('.wsp-backup-result');
			if (!project || !window.wspVars || !window.wspVars.backupAuditUrl ||
				!rows.some(function (r) { return number(r.project_id)===project && r.can_edit; })) return;
			button.prop('disabled', true);
			target.text(lang('WSP_BACKUP_CHECKING'));
			$.ajax({url:WSP.normalizeUrl(window.wspVars.backupAuditUrl),type:'POST',dataType:'json',
				timeout:60000,cache:false,data:WSP.withCsrf({project_id:project})})
			.done(function (r) {
				if (!r || !r.success || number(r.project_id)!==project) {
					target.text(lang('WSP_BACKUP_FAILED')); return;
				}
				var text='';
				if (r.status==='no_manual_checkpoint') text=lang('WSP_BACKUP_MISSING');
				else if (r.status==='checkpoint_unreadable') text=lang('WSP_BACKUP_BAD');
				else if (r.status==='matches_checkpoint') text=lang('WSP_BACKUP_MATCH');
				else if (r.status==='changed_since_checkpoint')
					text=lang('WSP_BACKUP_CHANGED')+' ('+number(r.changed_files)+' '+lang('WSP_BACKUP_DIFF')+')';
				else text=lang('WSP_BACKUP_FAILED');
				if (r.checkpoint && number(r.checkpoint.created_time)) {
					var age=Math.max(0,Math.floor((Date.now()/1000-number(r.checkpoint.created_time))/86400));
					text+=' · '+lang('WSP_BACKUP_AGE')+': '+age+' '+lang('WSP_BACKUP_DAYS');
					if (age>30) text+=' · '+lang('WSP_BACKUP_OLD');
				}
				target.text(text+' '+lang('WSP_BACKUP_OPEN'));
			}).fail(function () {target.text(lang('WSP_BACKUP_FAILED'));})
			.always(function () {button.prop('disabled',false);});
		});
		$('body').on('click.wspPortfolio', '.wsp-backup-export', function () {
			var btn=$(this), project=number(btn.attr('data-project'));
			if (!project || !rows.some(function(r){return number(r.project_id)===project && r.can_edit;}) ||
				!window.wspVars.backupExportUrl) return;
			var output=btn.siblings('.wsp-backup-verify-result');
			btn.prop('disabled',true); output.text('');
			var fields=WSP.withCsrf({project_id:project}), body=new URLSearchParams();
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			fetch(WSP.normalizeUrl(window.wspVars.backupExportUrl),
				{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
			.then(function(response){if(!response.ok || !String(response.headers.get('Content-Type')||'').includes('application/zip'))
				throw new Error('invalid response'); return response.blob();})
			.then(function(blob){
				var url=URL.createObjectURL(blob), a=document.createElement('a');
				a.href=url; a.download='workspace-project-'+project+'-backup.zip';
				document.body.appendChild(a); a.click(); a.remove();
				setTimeout(function(){URL.revokeObjectURL(url);},1000);
				output.text(lang('WSP_BACKUP_EXPORT_NOTE'));
			}).catch(function(){output.text(lang('WSP_BACKUP_EXPORT_FAILED'));})
			  .finally(function(){btn.prop('disabled',false);});
		});
		$('body').on('change.wspPortfolio', '.wsp-backup-verify-form input[type=file]', function(){
			$(this).closest('.wsp-portfolio-card').find('.wsp-backup-verify-result').empty();
		});
		$('body').on('submit.wspPortfolio', '.wsp-backup-verify-form', function(event) {
			event.preventDefault();
			var form=$(this), btn=form.find('button[type=submit]'), parent=form.parent(),
				project=number(parent.find('.wsp-backup-export').attr('data-project')),
				file=form.find('input[type=file]')[0], output=parent.find('.wsp-backup-verify-result');
			if (!project || !file || !file.files.length || !window.wspVars.backupInspectUrl ||
				!rows.some(function(r){return number(r.project_id)===project && r.can_edit;})) return;
			var body=new FormData();
			var fields=WSP.withCsrf({project_id:project});
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			body.append('backup_zip',file.files[0]);
			btn.prop('disabled',true);output.empty();
			$.ajax({url:WSP.normalizeUrl(window.wspVars.backupInspectUrl),type:'POST',
				data:body,processData:false,contentType:false,cache:false,dataType:'json',timeout:60000})
			.done(function(r){
				if(!r || !r.success || r.status!=='verified' || number(r.project_id)!==project){
					output.text((r && r.error) || lang('WSP_BACKUP_VERIFY_BAD'));return;
				}
				$('<strong/>').text(lang('WSP_BACKUP_VERIFY_OK')+' · '+number(r.files)+' files').appendTo(output);
				$('<p/>').text(lang('WSP_BACKUP_RESTORE_WARNING')).appendTo(output);
				var changes=Array.isArray(r.changes)?r.changes:[];
				$('<p/>').text(String(changes.length)+' '+lang('WSP_BACKUP_DIFF')).appendTo(output);
				var list=$('<div/>', {'class':'wsp-backup-select-list'}).appendTo(output);
				changes.forEach(function(c){
					var line=$('<label/>', {'class':'wsp-backup-select-row'}).appendTo(list);
					$('<input/>', {type:'checkbox', 'class':'wsp-backup-selected'}).val(String(c.path||'')).appendTo(line);
					$('<span/>').text(String(c.action||'')+' — '+String(c.path||'')).appendTo(line);
				});
				var prepare=$('<button/>',{type:'button','class':'btn-main wsp-backup-prepare'})
					.text(lang('WSP_BACKUP_RESTORE_PREPARE')).appendTo(output);
				prepare.attr('data-project',project);
				$('<p/>', {'class':'wsp-backup-restore-status', role:'status', 'aria-live':'polite'}).appendTo(output);
			}).fail(function(){output.text(lang('WSP_BACKUP_VERIFY_BAD'));})
			  .always(function(){btn.prop('disabled',false);});
		});
		// The user explicitly selects paths, then submits the same ZIP for a revision-bound preview.
		$('body').on('change.wspPortfolio', '.wsp-backup-selected', function(){
			var output=$(this).closest('.wsp-backup-verify-result');
			output.find('.wsp-backup-confirm').remove();
			output.find('.wsp-backup-restore-status').text('');
		});
		$('body').on('click.wspPortfolio', '.wsp-backup-prepare', function(){
			var btn=$(this), output=btn.closest('.wsp-backup-verify-result'), card=btn.closest('.wsp-portfolio-card'),
				project=number(btn.attr('data-project')), file=card.find('.wsp-backup-verify-form input[type=file]')[0],
				selected=output.find('.wsp-backup-selected:checked').map(function(){return $(this).val();}).get(),
				status=output.find('.wsp-backup-restore-status');
			output.find('.wsp-backup-confirm').remove();
			if(!selected.length){status.text(lang('WSP_BACKUP_RESTORE_NOTHING'));return;}
			if(!file || !file.files.length || !window.wspVars.backupPrepareRestoreUrl) return;
			var body=new FormData(), fields=WSP.withCsrf({project_id:project,selected_paths:JSON.stringify(selected)});
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			body.append('backup_zip',file.files[0]); btn.prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(window.wspVars.backupPrepareRestoreUrl),type:'POST',data:body,
				processData:false,contentType:false,dataType:'json',timeout:60000}).done(function(r){
				if(!r || !r.success || !r.can_restore || !r.preview_token){status.text(lang('WSP_BACKUP_RESTORE_FAILED'));return;}
				status.text(lang('WSP_BACKUP_RESTORE_READY')+' ('+selected.length+')');
				var confirmBtn=$('<button/>',{type:'button','class':'btn-main wsp-backup-confirm'})
					.text(lang('WSP_BACKUP_RESTORE_CONFIRM')).appendTo(output);
				confirmBtn.data('preview_token',r.preview_token).data('paths',selected).data('project',project);
			}).fail(function(){status.text(lang('WSP_BACKUP_RESTORE_FAILED'));})
			  .always(function(){btn.prop('disabled',false);});
		});
		$('body').on('click.wspPortfolio', '.wsp-backup-confirm', function(){
			var btn=$(this), output=btn.closest('.wsp-backup-verify-result'), card=btn.closest('.wsp-portfolio-card'),
				project=number(btn.data('project')), file=card.find('.wsp-backup-verify-form input[type=file]')[0],
				status=output.find('.wsp-backup-restore-status'), selected=btn.data('paths');
			if(!file || !file.files.length || !window.wspVars.backupRestoreZipUrl || !Array.isArray(selected)) return;
			if(!window.confirm(lang('WSP_BACKUP_RESTORE_WARNING')+'\n'+selected.join('\n'))) return;
			var body=new FormData(), fields=WSP.withCsrf({project_id:project,selected_paths:JSON.stringify(selected),
				preview_token:btn.data('preview_token'),confirmation:'RESTAURAR'});
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			body.append('backup_zip',file.files[0]);btn.prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(window.wspVars.backupRestoreZipUrl),type:'POST',data:body,
				processData:false,contentType:false,dataType:'json',timeout:60000}).done(function(r){
				if(!r || !r.success){status.text(lang('WSP_BACKUP_RESTORE_FAILED'));return;}
				status.text(lang('WSP_BACKUP_RESTORE_DONE')+' #'+number(r.backup_id)+' · '+number(r.changed));
				btn.remove();
				output.find('.wsp-backup-prepare').remove();
			}).fail(function(){status.text(lang('WSP_BACKUP_RESTORE_FAILED'));})
			  .always(function(){btn.prop('disabled',false);});
		});
		// Cross-install, file-only import: no source ID is reused and no existing project is modified.
		$('body').on('change.wspPortfolio input.wspPortfolio', '#wsp-migration-form input', function () {
			$('#wsp-migration-output').empty();
		});
		$('body').on('submit.wspPortfolio', '#wsp-migration-form', function(event) {
			event.preventDefault();
			var form=$(this), output=$('#wsp-migration-output').empty(), submit=form.find('button[type=submit]'),
				name=String(form.find('input[name=project_name]').val()||'').trim(),
				file=form.find('input[name=backup_zip]')[0];
			if (!name || name.length>120 || !file || !file.files.length || !window.wspVars.migrationPreviewUrl) return;
			var fields=WSP.withCsrf({project_name:name}), body=new FormData();
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			body.append('backup_zip',file.files[0]);
			submit.prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(window.wspVars.migrationPreviewUrl),type:'POST',data:body,
				processData:false,contentType:false,dataType:'json',timeout:60000})
			.done(function(r){
				if(!r || !r.success || !r.preview_token || r.project_name!==name){
					output.text(lang('WSP_MIGRATION_ERROR')); return;
				}
				$('<p/>').text(lang('WSP_MIGRATION_READY')+' '+number(r.file_count)+' · '+number(r.bytes)+' bytes').appendTo(output);
				var paths=$('<div/>',{'class':'wsp-backup-select-list'}).appendTo(output);
				(Array.isArray(r.paths)?r.paths:[]).forEach(function(path){
					$('<p/>').text(String(path)).appendTo(paths);
				});
				var button=$('<button/>',{type:'button','class':'btn-main wsp-migration-confirm'})
					.text(lang('WSP_MIGRATION_CONFIRM')).appendTo(output);
				button.data('preview_token',r.preview_token).data('project_name',name);
			}).fail(function(){output.text(lang('WSP_MIGRATION_ERROR'));})
			  .always(function(){submit.prop('disabled',false);});
		});
		$('body').on('click.wspPortfolio', '.wsp-migration-confirm', function(){
			var button=$(this), form=$('#wsp-migration-form'), output=$('#wsp-migration-output'),
				name=button.data('project_name'), file=form.find('input[name=backup_zip]')[0];
			if (!file || !file.files.length || !window.wspVars.migrationImportUrl ||
				String(form.find('input[name=project_name]').val()||'').trim()!==name) return;
			if (!window.confirm(lang('WSP_MIGRATION_NOTE')+'\n'+name)) return;
			var fields=WSP.withCsrf({project_name:name,preview_token:button.data('preview_token'),confirmation:'IMPORTAR'}),
				body=new FormData();
			Object.keys(fields).forEach(function(k){body.append(k,String(fields[k]));});
			body.append('backup_zip',file.files[0]);button.prop('disabled',true);
			$.ajax({url:WSP.normalizeUrl(window.wspVars.migrationImportUrl),type:'POST',data:body,
				processData:false,contentType:false,dataType:'json',timeout:60000})
			.done(function(r){
				if(!r || !r.success || !number(r.project_id)){
					output.text(lang('WSP_MIGRATION_ERROR')); return;
				}
				output.empty().text(lang('WSP_MIGRATION_OK')+' #'+number(r.project_id)+' · '+number(r.files));
				refresh();
			}).fail(function(){output.text(lang('WSP_MIGRATION_ERROR'));})
			  .always(function(){button.prop('disabled',false);});
		});
		$('body').on('click.wspPortfolio', '.wsp-portfolio-go', function () {
			var id=number($(this).attr('data-project'));
			if (!id || !rows.some(function (row) { return number(row.project_id)===id; })) return;
			var url=new URL(window.location.href);
			url.searchParams.set('p', String(id));
			window.location.assign(url.toString());
		});
	});
})(window, window.jQuery);
