/** Workspace 1.33.0: public repositories, Actions, workflow and read-only Releases comparison. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;

	function num(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function sha(value) { return /^[a-f0-9]{40}$/.test(String(value || '')); }
	function safeUrl(value) {
		return /^https:\/\/github\.com\/[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+$/.test(String(value || '')) ? value : '';
	}

	WSP.githubRepositories = {
		serial: 0, busy: false, link: null, previewData: null,
		workflowData: null, commits: [], commitReport: null, commitSha: '', releases: [], releaseReport: null, releaseId: null, automationKey: '', automationProject: 0,
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return num(WSP.activeProjectId); },
		config: function () { return window.wspVars || {}; },
		allowed: function () {
			var c = this.config();
			return !!(num(c.githubEnabled) && num(c.activeCanEdit) && this.projectId() && c.githubStatusUrl &&
				(typeof WSP.canEditActiveProjectUI !== 'function' || WSP.canEditActiveProjectUI()));
		},
		canManage: function () { return this.allowed() && !!num(this.config().activeCanManage); },
		canImport: function () {
			var c = this.config();
			return this.allowed() && !!num(c.githubImportEnabled) && !!num(c.activeCanUpload) &&
				!!num(c.activeCanRenameMove);
		},
		dirtyEditor: function () {
			return !!(WSP.activeFileId && WSP.editor && typeof WSP.editor.getValue === 'function' &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		message: function (text) { $('#wsp-gh-state').text(String(text || '')); },
		clearPreview: function () {
			this.serial++;
			this.previewData = null;
			$('#wsp-gh-summary').empty();
			$('#wsp-gh-list').empty();
			this.sync();
		},
		sync: function () {
			$('#wsp-gh-link').prop('disabled', this.busy || !this.canManage());
			$('#wsp-gh-unlink').prop('disabled', this.busy || !this.canManage() || !this.link);
			$('#wsp-gh-check').prop('disabled', this.busy || !this.allowed());
			$('#wsp-gh-preview').prop('disabled', this.busy || !this.canImport() || !this.link || this.dirtyEditor());
			$('#wsp-gh-apply').prop('disabled', this.busy || !this.canImport() || !this.previewData || this.dirtyEditor());
			$('#wsp-gh-health-check').prop('disabled', this.busy || !this.allowed() || !this.link);
			$('#wsp-gh-actions-check').prop('disabled', this.busy || !this.allowed() || !this.link);
			$('#wsp-gh-releases-check').prop('disabled', this.busy || !this.allowed() || !this.link);
			$('#wsp-gh-commits-check').prop('disabled', this.busy || !this.allowed() || !this.link);
			$('#wsp-gh-workflow-generate').prop('disabled', this.busy || !this.allowed() || !this.link);
			$('#wsp-gh-workflow-copy').prop('disabled', this.busy || !this.workflowData ||
				this.workflowData.project !== this.projectId());
			$('#wsp-gh-workflow-download').prop('disabled', this.busy || !this.workflowData ||
				this.workflowData.project !== this.projectId());
		},
		showLink: function (link) {
			var newKey = link ? String(link.owner) + '/' + String(link.repo) + '/' + String(link.ref) : '';
			if (this.automationProject !== this.projectId() || this.automationKey !== newKey) {
				this.resetHealth();
				this.resetAutomation();
				this.resetReleases();
				this.resetCommits();
			}
			this.automationProject = this.projectId();
			this.automationKey = newKey;
			this.link = link || null;
			var url = link ? safeUrl(link.url) : '';
			if (link) {
				$('#wsp-gh-repo').val(link.owner + '/' + link.repo);
				$('#wsp-gh-ref').val(link.ref);
			}
			$('#wsp-gh-goto').prop('hidden', !url).attr('href', url || '#');
			$('#wsp-gh-actions-goto').prop('hidden', !url).attr('href', url ? url + '/actions' : '#');
			$('#wsp-gh-releases-goto').prop('hidden', !url).attr('href', url ? url + '/releases' : '#');
			$('#wsp-gh-commits-goto').prop('hidden', !url).attr('href', url ? url + '/commits/' + encodeURIComponent(String(link.ref || '')) : '#');
			$('#wsp-gh-sha').prop('hidden', !link || !sha(link.head_sha))
				.text(link ? (String(link.ref || '') + ' · ' + String(link.head_sha || '').slice(0, 12)) : '');
			this.sync();
		},
		request: function (url, data, success, onError) {
			if (!this.allowed() || !url || this.busy) return false;
			var self = this, project = this.projectId(), requestId = ++this.serial;
			this.busy = true;
			this.sync();
			$.ajax({url: WSP.normalizeUrl(url), type: 'POST', dataType: 'json', cache: false,
				timeout: 60000, data: WSP.withCsrf($.extend({project_id: project}, data || {}))})
				.done(function (r) {
					if (requestId !== self.serial || project !== self.projectId()) return;
					if (!r || !r.success || num(r.project_id) !== project) {
						var reason = r && r.error ? r.error : self.lang('WSP_GH_FAILED');
						self.message(reason);
						if (onError) onError(reason);
					} else success(r);
				}).fail(function () {
					if (requestId === self.serial && project === self.projectId()) {
						self.message(self.lang('WSP_GH_UNAVAILABLE'));
						if (onError) onError(self.lang('WSP_GH_UNAVAILABLE'));
					}
				}).always(function () {
					self.busy = false;
					self.sync();
				});
			return true;
		},
		open: function () {
			if (!this.allowed()) return;
			this.clearPreview();
			this.resetHealth();
			this.resetAutomation();
			this.resetReleases();
			this.resetCommits();
			this.message(this.lang('WSP_GH_WORKING'));
			$('#wsp-gh-modal').fadeIn(120);
			this.load(false);
		},
		close: function () {
			this.clearPreview();
			$('#wsp-gh-modal').fadeOut(120);
		},
		load: function (refresh) {
			var self = this;
			this.clearPreview();
			this.message(this.lang('WSP_GH_WORKING'));
			this.request(this.config().githubStatusUrl, {refresh: refresh ? 1 : 0}, function (r) {
				self.showLink(r.linked ? r.link : null);
				if (!r.linked) self.message(self.lang('WSP_GH_NOT_LINKED'));
				else self.message(self.lang(r.cached ? 'WSP_GH_CACHED' : 'WSP_GH_CHECKED') + ' ' +
					self.lang(r.link.never_imported ? 'WSP_GH_NEVER_IMPORTED' :
						(r.link.updates_available ? 'WSP_GH_UPDATES' : 'WSP_GH_UP_TO_DATE')));
			});
		},
		linkRepository: function () {
			if (!this.canManage() || this.busy) return;
			var self = this;
			this.clearPreview();
			this.message(this.lang('WSP_GH_WORKING'));
			this.request(this.config().githubLinkUrl, {
				repository: String($('#wsp-gh-repo').val() || '').trim(),
				ref: String($('#wsp-gh-ref').val() || '').trim()
			}, function (r) {
				self.showLink(r.link);
				self.message(self.lang('WSP_GH_LINKED'));
			});
		},
		unlinkRepository: function () {
			if (!this.canManage() || !this.link || !window.confirm(this.lang('WSP_GH_CONFIRM_UNLINK'))) return;
			var self = this;
			this.clearPreview();
			this.request(this.config().githubUnlinkUrl, {}, function () {
				self.showLink(null);
				$('#wsp-gh-repo,#wsp-gh-ref').val('');
				self.message(self.lang('WSP_GH_NOT_LINKED'));
			});
		},
		previewImport: function () {
			if (!this.canImport() || !this.link || this.busy) return;
			if (this.dirtyEditor()) { this.message(this.lang('WSP_GH_UNSAVED')); return; }
			var self = this;
			this.clearPreview();
			this.message(this.lang('WSP_GH_WORKING'));
			this.request(this.config().githubPreviewUrl, {}, function (r) {
				if (!sha(r.sha) || !/^[a-f0-9]{64}$/.test(String(r.preview_token || ''))) {
					self.message(self.lang('WSP_GH_FAILED')); return;
				}
				self.previewData = {project_id: self.projectId(), sha: r.sha, token: r.preview_token};
				var counts = r.counts || {};
				$('#wsp-gh-summary').text(String(r.package || '') + ' · ' + r.sha.slice(0, 12) + '\n' +
					self.lang('WSP_GH_COUNTS').replace('%1$d', num(counts.created)).replace('%2$d', num(counts.updated)).replace('%3$d', num(counts.unchanged)) +
					(num(r.skipped) ? '\n' + self.lang('WSP_GH_SKIPPED').replace('%d', num(r.skipped)) : ''));
				var list = $('#wsp-gh-list').empty();
				(Array.isArray(r.files) ? r.files : []).forEach(function (file) {
					$('<div/>', {'class':'wsp-gh-file'}).text(String(file.action || '') + ' · ' + String(file.path || '')).appendTo(list);
				});
				if (r.truncated) $('<p/>').text(self.lang('WSP_GH_MORE')).appendTo(list);
				self.message(self.lang('WSP_GH_PREVIEW_READY'));
				self.sync();
			});
		},
		applyImport: function () {
			var plan = this.previewData;
			if (!this.canImport() || !plan || this.busy) return;
			if (this.dirtyEditor()) { this.message(this.lang('WSP_GH_UNSAVED')); return; }
			if (plan.project_id !== this.projectId()) { this.clearPreview(); return; }
			if (!window.confirm(this.lang('WSP_GH_CONFIRM_IMPORT'))) return;
			var self = this;
			this.message(this.lang('WSP_GH_IMPORTING'));
			this.request(this.config().githubApplyUrl, {preview_token: plan.token, sha: plan.sha}, function () {
				self.clearPreview();
				self.message(self.lang('WSP_GH_IMPORTED'));
				window.location.reload();
			});
		},
		resetCommits: function () {
			this.commits = []; this.commitSha = ''; this.commitReport = null;
			['#wsp-gh-commits-list','#wsp-gh-commit-files','#wsp-gh-commit-compare-list','#wsp-gh-commit-diff-lines'].forEach(function (id) { $(id).empty(); });
			$('#wsp-gh-commit-result').prop('hidden', true);
			$('#wsp-gh-commit-diff-panel').prop('hidden', true);
			$('#wsp-gh-commits-state').text('');
			$('#wsp-gh-commit-state').text('');
		},
		checkCommits: function () {
			if (!this.allowed() || !this.link || this.busy || !this.config().githubCommitsUrl) return;
			var self = this, base = safeUrl(this.link.url);
			this.resetCommits();
			$('#wsp-gh-commits-state').text(this.lang('WSP_GH_COMMITS_LOADING'));
			this.request(this.config().githubCommitsUrl, {}, function (r) {
				var list = $('#wsp-gh-commits-list').empty();
				(Array.isArray(r.commits) ? r.commits.slice(0, 8) : []).forEach(function (commit) {
					var hash = String(commit.sha || '');
					if (!sha(hash)) return;
					self.commits.push(hash);
					var card = $('<div/>', {'class':'wsp-gh-release'}).appendTo(list);
					var head = $('<div/>', {'class':'wsp-gh-release-head'}).appendTo(card);
					var info = $('<div/>').appendTo(head);
					$('<div/>', {'class':'wsp-gh-release-title'}).text(String(commit.message || hash.slice(0, 12))).appendTo(info);
					$('<div/>', {'class':'wsp-gh-release-meta'}).text(hash.slice(0, 12) +
						' · ' + String(commit.author || '') + ' · ' + String(commit.date || '').slice(0, 10)).appendTo(info);
					var actions = $('<div/>', {'class':'wsp-gh-actions'}).appendTo(head);
					if (base) $('<a/>', {href:base+'/commit/'+hash, target:'_blank', rel:'noopener noreferrer'})
						.text('GitHub').appendTo(actions);
					$('<button/>', {type:'button', 'class':'btn-main wsp-gh-commit-files-btn'})
						.attr('data-sha',hash).text(self.lang('WSP_GH_COMMITS_FILES')).appendTo(actions);
					$('<button/>', {type:'button', 'class':'btn-main wsp-gh-commit-compare-btn'})
						.attr('data-sha',hash).text(self.lang('WSP_GH_COMMITS_COMPARE')).appendTo(actions);
				});
				$('#wsp-gh-commits-state').text(self.commits.length ? '' : self.lang('WSP_GH_COMMITS_EMPTY'));
			}, function (reason) { $('#wsp-gh-commits-state').text(reason || self.lang('WSP_GH_COMMITS_FAILED')); });
		},
		commitSelected: function (hash) {
			return this.allowed() && this.link && this.commits.indexOf(hash) !== -1 && sha(hash);
		},
		showCommitFiles: function (hash) {
			if (!this.commitSelected(hash) || this.busy) return;
			var self = this;
			this.commitSha = hash; this.commitReport = null;
			$('#wsp-gh-commit-result').prop('hidden', false);
			$('#wsp-gh-commit-files').empty();
			$('#wsp-gh-commit-compare-list').empty();
			this.closeCommitDiff();
			$('#wsp-gh-commit-state').text(this.lang('WSP_GH_COMMITS_LOADING'));
			this.request(this.config().githubCommitDetailUrl, {sha:hash}, function (r) {
				if (self.commitSha !== hash || r.sha !== hash) return;
				$('#wsp-gh-commit-state').text(self.lang('WSP_GH_COMMITS_FILES_TITLE') + ' · ' + hash.slice(0,12));
				var list = $('#wsp-gh-commit-files').empty();
				(Array.isArray(r.files) ? r.files.slice(0,100) : []).forEach(function (file) {
					if (!file || typeof file.path !== 'string') return;
					$('<div/>', {'class':'wsp-gh-file'}).text(String(file.status || 'changed') + ' · ' + file.path).appendTo(list);
				});
				if (r.truncated) $('<p/>').text(self.lang('WSP_GH_COMMITS_PARTIAL')).appendTo(list);
			}, function (reason) { $('#wsp-gh-commit-state').text(reason || self.lang('WSP_GH_COMMITS_FAILED')); });
		},
		compareCommit: function (hash) {
			if (!this.commitSelected(hash) || this.busy) return;
			if (this.dirtyEditor()) { $('#wsp-gh-commits-state').text(this.lang('WSP_GH_RELEASES_UNSAVED')); return; }
			this.commitSha = hash; this.commitReport = null;
			$('#wsp-gh-commit-result').prop('hidden', false);
			$('#wsp-gh-commit-files').empty();
			$('#wsp-gh-commit-compare-list').empty();
			this.closeCommitDiff();
			$('#wsp-gh-commit-state').text(this.lang('WSP_GH_COMMITS_COMPARING'));
			var self = this;
			this.request(this.config().githubCommitCompareUrl, {sha:hash}, function (r) {
				if (self.commitSha !== hash || r.sha !== hash) return;
				var counts = r.counts || {};
				var files = (Array.isArray(r.files) ? r.files.slice(0,180) : []).filter(function (file) {
					return file && /^(create|update|local_only)$/.test(String(file.action || '')) &&
						typeof file.path === 'string' && file.path.length <= 255;
				});
				self.commitReport = {project:self.projectId(), sha:hash, files:files};
				var summary = self.lang('WSP_GH_RELEASES_COMPARE_READY').replace('%t','commit')
					.replace('%s',hash.slice(0,12)).replace('%a',num(counts.created))
					.replace('%m',num(counts.updated)).replace('%e',num(counts.unchanged))
					.replace('%l',num(counts.local_only));
				$('#wsp-gh-commit-state').text(summary + (r.truncated ? '\n'+self.lang('WSP_GH_REPORT_PARTIAL') : ''));
				var list = $('#wsp-gh-commit-compare-list').empty();
				files.forEach(function (file, i) {
					var div = $('<div/>', {'class':'wsp-gh-file'}).appendTo(list);
					$('<span/>', {'class':'wsp-gh-file-path'}).text(file.action + ' · ' + file.path).appendTo(div);
					$('<button/>', {type:'button','class':'btn-main wsp-gh-commit-diff-btn'})
						.attr('data-index',String(i)).text(self.lang('WSP_GH_COMMITS_DIFF')).appendTo(div);
				});
				if (!files.length) $('<p/>').text(self.lang('WSP_GH_REPORT_EMPTY')).appendTo(list);
			}, function (reason) { $('#wsp-gh-commit-state').text(reason || self.lang('WSP_GH_COMMITS_FAILED')); });
		},
		closeCommitDiff: function () {
			$('#wsp-gh-commit-diff-panel').prop('hidden', true);
			$('#wsp-gh-commit-diff-lines').empty();
		},
		showCommitDiff: function (index) {
			var report = this.commitReport, self = this, i = num(index);
			if (!report || report.project !== this.projectId() || report.sha !== this.commitSha ||
				!this.commitSelected(report.sha) || this.busy || String(i) !== String(index)) return;
			var file = report.files[i]; if (!file) return;
			this.closeCommitDiff();
			$('#wsp-gh-commit-diff-panel').prop('hidden', false);
			$('#wsp-gh-commit-diff-state').text(this.lang('WSP_GH_DIFF_LOADING') + ' · ' + file.path);
			this.request(this.config().githubCommitDiffUrl, {sha:report.sha, path:file.path}, function (r) {
				if (self.commitReport !== report || r.sha !== report.sha || r.path !== file.path ||
					r.action !== file.action) return;
				var list = $('#wsp-gh-commit-diff-lines').empty();
				(Array.isArray(r.rows) ? r.rows.slice(0,640) : []).forEach(function (row) {
					if (!row || ['equal','add','remove'].indexOf(row.kind) === -1 || typeof row.text !== 'string') return;
					var div = $('<div/>', {'class':'wsp-gh-diff-line wsp-gh-diff-'+row.kind}).appendTo(list);
					$('<span/>', {'class':'wsp-gh-diff-number'}).text(row.old == null ? '' : String(row.old)).appendTo(div);
					$('<span/>', {'class':'wsp-gh-diff-number'}).text(row.new == null ? '' : String(row.new)).appendTo(div);
					$('<span/>', {'class':'wsp-gh-diff-text'}).text(row.text).appendTo(div);
				});
				$('#wsp-gh-commit-diff-state').text(self.lang('WSP_GH_DIFF_LEGEND'));
			}, function (reason) { $('#wsp-gh-commit-diff-state').text(reason || self.lang('WSP_GH_DIFF_FAILED')); });
		},
		resetReleases: function () {
			this.releases = [];
			$('#wsp-gh-releases-list').empty();
			this.releaseReport = null;
			this.releaseId = null;
			this.closeFileDiff();
			$('#wsp-gh-report-export').prop('disabled', true);
			$('#wsp-gh-report-filter').val('all').prop('disabled', true);
			$('#wsp-gh-releases-state').text(this.lang('WSP_GH_RELEASES_READY'));
			$('#wsp-gh-release-compare').prop('hidden', true);
			$('#wsp-gh-release-compare-state').text('');
			$('#wsp-gh-release-compare-list').empty();
			this.sync();
		},
		checkReleases: function () {
			if (!this.allowed() || !this.link || this.busy) return;
			var self = this, base = safeUrl(this.link.url);
			this.resetReleases();
			$('#wsp-gh-releases-state').text(this.lang('WSP_GH_RELEASES_LOADING'));
			this.request(this.config().githubReleasesUrl, {}, function (r) {
				var list = $('#wsp-gh-releases-list').empty(), shown = 0;
				self.releases = [];
				(Array.isArray(r.releases) ? r.releases.slice(0, 8) : []).forEach(function (release) {
					var id = String(release.id || ''), tag = String(release.tag || '');
					if (!/^[1-9][0-9]{0,17}$/.test(id) || !/^[A-Za-z0-9][A-Za-z0-9_.+\/-]{0,119}$/.test(tag)) return;
					shown++;
					self.releases.push({id:id, tag:tag, supported:!!release.compare_supported});
					var card = $('<div/>', {'class':'wsp-gh-release'}).appendTo(list);
					var header = $('<div/>', {'class':'wsp-gh-release-head'}).appendTo(card);
					var title = $('<div/>').appendTo(header);
					$('<div/>', {'class':'wsp-gh-release-title'}).text(String(release.title || tag)).appendTo(title);
					var date = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(release.published_at || '') ?
						String(release.published_at).slice(0, 10) : '';
					$('<div/>', {'class':'wsp-gh-release-meta'}).text(tag + (date ? ' · '+date : '') +
						(release.prerelease ? ' · '+self.lang('WSP_GH_RELEASES_PRERELEASE') : '') +
						' · '+self.lang('WSP_GH_RELEASES_ASSETS').replace('%d', num(release.assets_count))).appendTo(title);
					var actions = $('<div/>', {'class':'wsp-gh-actions'}).appendTo(header);
					if (base) $('<a/>',{href:base+'/releases/tag/'+encodeURIComponent(tag),target:'_blank',rel:'noopener noreferrer'})
						.text(self.lang('WSP_GH_RELEASES_VIEW')).appendTo(actions);
					if (release.compare_supported) {
						$('<button/>',{'type':'button','class':'btn-main wsp-gh-release-compare-btn'})
							.attr('data-release-id',id).text(self.lang('WSP_GH_RELEASES_COMPARE')).appendTo(actions);
					} else {
						$('<span/>',{'class':'wsp-gh-release-meta'})
							.text(self.lang('WSP_GH_RELEASES_COMPARE_UNSUPPORTED')).appendTo(card);
					}
					if (release.notes) $('<div/>',{'class':'wsp-gh-release-notes'})
						.text(String(release.notes).slice(0, 900)).appendTo(card);
				});
				$('#wsp-gh-releases-state').text(shown ?
					self.lang('WSP_GH_RELEASES_COUNT').replace('%d', String(shown)) : self.lang('WSP_GH_RELEASES_EMPTY'));
			}, function () { $('#wsp-gh-releases-state').text(self.lang('WSP_GH_RELEASES_FAILURE')); });
		},
		compareRelease: function (releaseId) {
			if (!this.allowed() || !this.link || this.busy) return;
			var selected = this.releases.find(function (item) {return item.id === String(releaseId) && item.supported;});
			if (!selected) return;
			if (this.dirtyEditor()) {
				$('#wsp-gh-releases-state').text(this.lang('WSP_GH_RELEASES_UNSAVED')); return;
			}
			var self = this;
			$('#wsp-gh-release-compare').prop('hidden', false);
			$('#wsp-gh-release-compare-list').empty();
			this.releaseReport = null;
			this.releaseId = null;
			this.closeFileDiff();
			$('#wsp-gh-report-export').prop('disabled', true);
			$('#wsp-gh-report-filter').val('all').prop('disabled', true);
			$('#wsp-gh-release-compare-state').text(this.lang('WSP_GH_RELEASES_COMPARE_LOADING'));
			this.request(this.config().githubReleaseCompareUrl, {release_id:selected.id}, function (r) {
				if (String(r.release_id) !== selected.id || String(r.tag) !== selected.tag || !sha(r.sha)) {
					$('#wsp-gh-release-compare-state').text(self.lang('WSP_GH_RELEASES_FAILURE'));
					return;
				}
				var counts = r.counts || {}, summary = self.lang('WSP_GH_RELEASES_COMPARE_READY');
				summary = summary.replace('%t', selected.tag).replace('%s',String(r.sha).slice(0,12))
					.replace('%a',num(counts.created)).replace('%m',num(counts.updated))
					.replace('%e',num(counts.unchanged)).replace('%l',num(counts.local_only));
				$('#wsp-gh-release-compare-state').text(summary +
					(num(r.skipped) ? '\n'+self.lang('WSP_GH_SKIPPED').replace('%d',num(r.skipped)) : ''));
				var rows = (Array.isArray(r.files) ? r.files.slice(0, 180) : []).filter(function (file) {
					return /^(create|update|local_only)$/.test(String(file.action || '')) &&
						typeof file.path === 'string' && file.path.length <= 1024;
				}).map(function (file) { return {action:file.action, path:file.path}; });
				self.releaseId = selected.id;
				self.releaseReport = {project:self.projectId(), tag:selected.tag, sha:r.sha,
					package:String(r.package || ''), counts:counts, skipped:num(r.skipped),
					truncated:!!r.truncated, files:rows};
				$('#wsp-gh-report-filter').val('all').prop('disabled', false);
				$('#wsp-gh-report-export').prop('disabled', false);
				self.renderReleaseReport();
			}, function () { $('#wsp-gh-release-compare-state').text(self.lang('WSP_GH_RELEASES_FAILURE')); });
		},
		renderReleaseReport: function () {
			var report = this.releaseReport, list = $('#wsp-gh-release-compare-list').empty();
			if (!report || report.project !== this.projectId()) return;
			var selected = String($('#wsp-gh-report-filter').val() || 'all');
			if (!/^(all|create|update|local_only)$/.test(selected)) selected = 'all';
			var filtered = report.files.filter(function (file) {
				return selected === 'all' || file.action === selected;
			});
			var self = this;
			filtered.forEach(function (file) {
				var entry = $('<div/>',{'class':'wsp-gh-file'}).appendTo(list);
				$('<span/>',{'class':'wsp-gh-file-path'}).text(file.action+' · '+file.path).appendTo(entry);
				$('<button/>',{'type':'button','class':'btn-main wsp-gh-file-diff-btn'})
					.attr('data-diff-index',String(report.files.indexOf(file)))
					.text(self.lang('WSP_GH_DIFF_VIEW')).appendTo(entry);
			});
			if (!filtered.length) $('<p/>').text(this.lang('WSP_GH_REPORT_EMPTY')).appendTo(list);
			if (report.truncated) $('<p/>').text(this.lang('WSP_GH_REPORT_PARTIAL')).appendTo(list);
		},
		closeFileDiff: function () {
			$('#wsp-gh-diff-panel').prop('hidden', true);
			$('#wsp-gh-diff-lines').empty();
			$('#wsp-gh-diff-path').text('');
			$('#wsp-gh-diff-state').text('');
		},
		showFileDiff: function (index) {
			if (!this.allowed() || this.busy || !this.releaseReport || !this.releaseId ||
				!this.config().githubReleaseFileDiffUrl || this.releaseReport.project !== this.projectId()) return;
			var file = this.releaseReport.files[num(index)];
			if (!file || String(index) !== String(num(index))) return;
			this.closeFileDiff();
			$('#wsp-gh-diff-panel').prop('hidden', false);
			$('#wsp-gh-diff-path').text(file.path);
			$('#wsp-gh-diff-state').text(this.lang('WSP_GH_DIFF_LOADING'));
			var self = this, report = this.releaseReport, releaseId = this.releaseId;
			this.request(this.config().githubReleaseFileDiffUrl,
				{release_id:releaseId, sha:report.sha, path:file.path}, function (r) {
					if (self.releaseReport !== report || self.releaseId !== releaseId ||
						String(r.release_id) !== String(releaseId) || r.sha !== report.sha ||
						r.path !== file.path || r.action !== file.action) {
						self.closeFileDiff(); return;
					}
					var rows = Array.isArray(r.rows) ? r.rows.slice(0, 640) : [];
					var panel = $('#wsp-gh-diff-lines').empty();
					rows.forEach(function (row) {
						if (!row || ['equal','add','remove'].indexOf(row.kind) < 0 ||
							typeof row.text !== 'string') return;
						var line = $('<div/>',{'class':'wsp-gh-diff-line wsp-gh-diff-'+row.kind}).appendTo(panel);
						$('<span/>',{'class':'wsp-gh-diff-number'}).text(row.old == null ? '' : String(row.old)).appendTo(line);
						$('<span/>',{'class':'wsp-gh-diff-number'}).text(row.new == null ? '' : String(row.new)).appendTo(line);
						$('<span/>',{'class':'wsp-gh-diff-text'}).text(row.text).appendTo(line);
					});
					$('#wsp-gh-diff-state').text(self.lang('WSP_GH_DIFF_LEGEND'));
				}, function (reason) {
					$('#wsp-gh-diff-state').text(reason || self.lang('WSP_GH_DIFF_FAILED'));
				});
		},
		exportReleaseReport: function () {
			var r = this.releaseReport;
			if (!r || r.project !== this.projectId() || !window.Blob || !window.URL ||
				!window.URL.createObjectURL || !window.document) return;
			function csv(value) {
				var x = String(value == null ? '' : value);
				// Neutralize spreadsheet formulas, including leading whitespace followed by a trigger.
				if (/^[\s\u0000-\u001f]*[=+@-]/.test(x)) x = "'" + x;
				return '"' + x.replace(/"/g, '""') + '"';
			}
			var data = [ ['field','value'],['tag',r.tag],['sha',r.sha],
				['package',r.package], ['created',num(r.counts.created)],
				['updated',num(r.counts.updated)],['unchanged',num(r.counts.unchanged)],
				['local_only',num(r.counts.local_only)],['skipped',r.skipped],
				['partial_list',r.truncated ? 'yes' : 'no'],[],['action','path'] ];
			r.files.forEach(function (file) {data.push([file.action,file.path]);});
			var csvContent = '\uFEFF' + data.map(function (row) {return row.map(csv).join(',');}).join('\r\n')+'\r\n';
			var uri = window.URL.createObjectURL(new window.Blob([csvContent], {type:'text/csv;charset=utf-8'}));
			var link = window.document.createElement('a');
			link.href = uri;
			link.download = 'workspace-release-comparison-' + r.sha.slice(0,12) + '.csv';
			window.document.body.appendChild(link);
			link.click();
			window.document.body.removeChild(link);
			if (window.setTimeout) window.setTimeout(function () {window.URL.revokeObjectURL(uri);}, 1000);
		},
		resetHealth: function () {
			$('#wsp-gh-health-list').empty();
			$('#wsp-gh-health-state').text(this.lang('WSP_GH_HEALTH_READY'));
		},
		checkHealth: function () {
			if (!this.allowed() || !this.link || this.busy || !this.config().githubHealthUrl) return;
			var self = this, project = this.projectId();
			this.resetHealth();
			$('#wsp-gh-health-state').text(this.lang('WSP_GH_HEALTH_LOADING'));
			this.request(this.config().githubHealthUrl, {}, function (r) {
				if (project !== self.projectId() || !self.link || !r.link ||
					r.link.owner !== self.link.owner || r.link.repo !== self.link.repo || r.link.ref !== self.link.ref) {
					self.resetHealth(); return;
				}
				var list = $('#wsp-gh-health-list').empty();
				function card(title, headline, detail, state, url) {
					var item = $('<div/>', {'class':'wsp-gh-health-card'}).attr('data-state', state).appendTo(list);
					$('<strong/>').text(title).appendTo(item);
					$('<div/>',{'class':'wsp-gh-health-value'}).text(headline).appendTo(item);
					$('<div/>',{'class':'wsp-gh-health-detail'}).text(detail).appendTo(item);
					if (url) $('<a/>',{href:url,target:'_blank',rel:'noopener noreferrer'})
						.text(self.lang('WSP_GH_HEALTH_OPEN')).appendTo(item);
				}
				function unavailable(section) {
					return !section || !section.available ?
						self.lang(section && section.error === 'rate_limited' ? 'WSP_GH_HEALTH_RATE' : 'WSP_GH_HEALTH_UNAVAILABLE') : '';
				}
				var base = safeUrl(self.link.url), reference = String(self.link.ref || '');
				var c = r.commit || {}, latest = c.latest || null;
				card(self.lang('WSP_GH_HEALTH_COMMIT'), unavailable(c) || (latest ? String(latest.sha || '').slice(0,12) : self.lang('WSP_GH_HEALTH_NONE')),
					latest && c.available ? String(latest.message || '').slice(0,180) + ' · ' + String(latest.date || '').slice(0,10) : reference,
					!c.available ? 'unknown' : latest ? 'info' : 'neutral',
					base && latest && sha(latest.sha) ? base + '/commit/' + latest.sha : '');
				var rel = r.release || {}, release = rel.latest || null, tag = release ? String(release.tag || '') : '';
				card(self.lang('WSP_GH_HEALTH_RELEASE'), unavailable(rel) || (release ? tag : self.lang('WSP_GH_HEALTH_NONE')),
					rel.available && release ? String(release.title || '').slice(0,120) + ' · ' + String(release.published_at || '').slice(0,10) : '',
					!rel.available ? 'unknown' : release ? 'info' : 'neutral',
					base && /^[A-Za-z0-9][A-Za-z0-9_.+\/-]{0,119}$/.test(tag) ? base+'/releases/tag/'+encodeURIComponent(tag) : '');
				var acts = r.actions || {}, run = acts.latest || null;
				var status = run ? String(run.status || '') : '', conclusion = run ? String(run.conclusion || '') : '';
				var state = status === 'completed' ? conclusion : status;
				var allowedStates = /^(success|failure|cancelled|queued|in_progress|completed|skipped|neutral|timed_out|waiting|pending|requested|action_required|stale|startup_failure)$/;
				if (!allowedStates.test(state)) state = 'unknown';
				card(self.lang('WSP_GH_HEALTH_ACTIONS'), unavailable(acts) || (run ? self.lang('WSP_GH_RUN_'+state) : self.lang('WSP_GH_HEALTH_NONE')),
					run && acts.available ? String(run.name || '').slice(0,90) + ' · ' + String(run.branch || '') + ' · ' + String(run.created_at || '').slice(0,10) : '',
					!acts.available ? 'unknown' : state,
					base && run && /^[1-9][0-9]{0,17}$/.test(String(run.id || '')) ? base+'/actions/runs/'+run.id : '');
				var link = r.link || {}, imported = String(link.imported_sha || ''), head = String(link.head_sha || '');
				card(self.lang('WSP_GH_HEALTH_WORKSPACE'),
					!sha(imported) ? self.lang('WSP_GH_HEALTH_NEVER') :
					sha(head) && imported === head ? self.lang('WSP_GH_HEALTH_MATCH') :
					self.lang('WSP_GH_HEALTH_CHANGED'),
					self.lang('WSP_GH_HEALTH_LOCAL_NOTE') + ' · ' + String(link.checked_at ? new Date(Number(link.checked_at)*1000).toLocaleString() : '-'),
					!sha(imported) ? 'neutral' : imported === head ? 'success' : 'warning', '');
				var partial = !c.available || !rel.available || !acts.available;
				$('#wsp-gh-health-state').text((partial ? self.lang('WSP_GH_HEALTH_PARTIAL') : self.lang('WSP_GH_HEALTH_READY_RESULT')) +
					' · ' + self.lang('WSP_GH_HEALTH_NOTE'));
			}, function (reason) {
				$('#wsp-gh-health-state').text(reason || self.lang('WSP_GH_HEALTH_UNAVAILABLE'));
			});
		},
		resetAutomation: function () {
			this.workflowData = null;
			$('#wsp-gh-actions-list').empty();
			$('#wsp-gh-actions-state').text(this.lang('WSP_GH_ACTIONS_READY'));
			$('#wsp-gh-workflow-yaml').val('');
			$('#wsp-gh-workflow-state').text('');
			this.sync();
		},
		checkActions: function () {
			if (!this.allowed() || !this.link || this.busy) return;
			var self = this, base = safeUrl(this.link.url);
			$('#wsp-gh-actions-list').empty();
			$('#wsp-gh-actions-state').text(this.lang('WSP_GH_ACTIONS_LOADING'));
			this.request(this.config().githubActionsUrl, {}, function (r) {
				var list = $('#wsp-gh-actions-list').empty();
				var runs = Array.isArray(r.runs) ? r.runs.slice(0, 8) : [];
				var shown = 0;
				runs.forEach(function (run) {
					var id = String(run.id || '');
					if (!/^[1-9][0-9]{0,17}$/.test(id)) return;
					shown++;
					var url = base ? base + '/actions/runs/' + id : '';
					var state = run.status === 'completed' ? String(run.conclusion || 'completed') : String(run.status || 'unknown');
					var safeState = /^(success|failure|cancelled|queued|in_progress|completed|skipped|neutral|timed_out|waiting|pending|requested|action_required|stale|startup_failure)$/.test(state) ? state : 'unknown';
					var row = $('<div/>', {'class':'wsp-gh-run'}).attr('data-state', safeState).appendTo(list);
					var primary = $('<div/>', {'class':'wsp-gh-run-main'}).appendTo(row);
					$('<div/>', {'class':'wsp-gh-run-label'}).text(String(run.name || 'Workflow')).appendTo(primary);
					$('<div/>', {'class':'wsp-gh-run-meta'}).text(
						String(run.branch || '-') + ' · ' + String(run.sha || '').slice(0, 9) +
						(run.created_at ? ' · ' + String(run.created_at).slice(0,16).replace('T',' ') + ' UTC' : '')
					).appendTo(primary);
					var detail = $('<div/>').appendTo(row);
					$('<div/>', {'class':'wsp-gh-run-state'}).text(self.lang('WSP_GH_RUN_' + safeState)).appendTo(detail);
					if (url) $('<a/>', {href:url,target:'_blank',rel:'noopener noreferrer'}).text(self.lang('WSP_GH_ACTIONS_OPEN')).appendTo(detail);
				});
				$('#wsp-gh-actions-state').text(shown ?
					self.lang('WSP_GH_ACTIONS_COUNT').replace('%d', String(shown))
						.replace('%t', String(num(r.total_count))) : self.lang('WSP_GH_ACTIONS_EMPTY'));
			}, function () { $('#wsp-gh-actions-state').text(self.lang('WSP_GH_ACTIONS_FAILURE')); });
		},
		invalidateWorkflow: function () {
			this.workflowData = null;
			$('#wsp-gh-workflow-yaml').val('');
			$('#wsp-gh-workflow-state').text(this.lang('WSP_GH_WORKFLOW_RESET'));
			this.sync();
		},
		generateWorkflow: function () {
			if (!this.allowed() || !this.link || this.busy) return;
			var self = this, mode = String($('#wsp-gh-workflow-mode').val() || 'basic');
			var branch = String($('#wsp-gh-phpbb-branch').val() || '3.3.x');
			this.invalidateWorkflow();
			$('#wsp-gh-workflow-state').text(this.lang('WSP_GH_WORKFLOW_LOADING'));
			this.request(this.config().githubWorkflowUrl, {mode:mode,phpbb_branch:branch}, function (r) {
				if (r.mode !== mode || r.phpbb_branch !== branch || typeof r.yaml !== 'string' ||
					r.yaml.length > 10000 || !r.yaml || self.projectId() !== num(r.project_id)) {
					$('#wsp-gh-workflow-state').text(self.lang('WSP_GH_WORKFLOW_MANIFEST')); return;
				}
				self.workflowData = {project:self.projectId(),yaml:r.yaml};
				$('#wsp-gh-workflow-yaml').val(r.yaml);
				$('#wsp-gh-workflow-state').text(self.lang('WSP_GH_WORKFLOW_READY'));
				self.sync();
			}, function () { $('#wsp-gh-workflow-state').text(self.lang('WSP_GH_WORKFLOW_MANIFEST')); });
		},
		copyWorkflow: function () {
			if (!this.workflowData || this.workflowData.project !== this.projectId()) return;
			var text = this.workflowData.yaml, self = this;
			if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
				window.navigator.clipboard.writeText(text)
					.then(function(){ $('#wsp-gh-workflow-state').text(self.lang('WSP_GH_WORKFLOW_COPIED')); })
					.catch(function(){ $('#wsp-gh-workflow-state').text(self.lang('WSP_GH_WORKFLOW_COPY_FAIL')); });
			} else {
				var area = $('#wsp-gh-workflow-yaml');
				if (area[0] && area[0].select) area[0].select();
				var ok = !!(window.document && window.document.execCommand && window.document.execCommand('copy'));
				$('#wsp-gh-workflow-state').text(self.lang(ok ? 'WSP_GH_WORKFLOW_COPIED' : 'WSP_GH_WORKFLOW_COPY_FAIL'));
			}
		},
		downloadWorkflow: function () {
			if (!this.workflowData || this.workflowData.project !== this.projectId() ||
				!window.Blob || !window.URL || !window.URL.createObjectURL || !window.document) return;
			var uri = window.URL.createObjectURL(new window.Blob([this.workflowData.yaml], {type:'text/yaml;charset=utf-8'}));
			var link = window.document.createElement('a');
			link.href = uri;
			link.download = 'tests.yml';
			window.document.body.appendChild(link);
			link.click();
			window.document.body.removeChild(link);
			if (window.setTimeout) window.setTimeout(function () {window.URL.revokeObjectURL(uri);}, 1000);
		},
		init: function () {},
		bindEvents: function () {
			var self = this;
			$('body').off('.wsp_github');
			$('body').on('click.wsp_github','#wsp-gh-open',function () { self.open(); });
			$('body').on('click.wsp_github','#wsp-gh-close',function () { self.close(); });
			$('body').on('click.wsp_github','#wsp-gh-check',function () { self.load(true); });
			$('body').on('click.wsp_github','#wsp-gh-link',function () { self.linkRepository(); });
			$('body').on('click.wsp_github','#wsp-gh-unlink',function () { self.unlinkRepository(); });
			$('body').on('click.wsp_github','#wsp-gh-preview',function () { self.previewImport(); });
			$('body').on('click.wsp_github','#wsp-gh-apply',function () { self.applyImport(); });
			$('body').on('click.wsp_github','#wsp-gh-health-check',function () { self.checkHealth(); });
			$('body').on('click.wsp_github','#wsp-gh-actions-check',function () { self.checkActions(); });
			$('body').on('click.wsp_github','#wsp-gh-commits-check',function () { self.checkCommits(); });
			$('body').on('click.wsp_github','.wsp-gh-commit-files-btn',function () { self.showCommitFiles($(this).attr('data-sha')); });
			$('body').on('click.wsp_github','.wsp-gh-commit-compare-btn',function () { self.compareCommit($(this).attr('data-sha')); });
			$('body').on('click.wsp_github','.wsp-gh-commit-diff-btn',function () { self.showCommitDiff($(this).attr('data-index')); });
			$('body').on('click.wsp_github','#wsp-gh-commit-diff-close',function () { self.closeCommitDiff(); });
			$('body').on('click.wsp_github','#wsp-gh-releases-check' ,function () { self.checkReleases(); });
			$('body').on('change.wsp_github','#wsp-gh-report-filter',function () { self.renderReleaseReport(); });
			$('body').on('click.wsp_github','.wsp-gh-file-diff-btn',function () { self.showFileDiff($(this).attr('data-diff-index')); });
			$('body').on('click.wsp_github','#wsp-gh-diff-close',function () { self.closeFileDiff(); });
			$('body').on('click.wsp_github','#wsp-gh-report-export',function () { self.exportReleaseReport(); });
			$('body').on('click.wsp_github','.wsp-gh-release-compare-btn',function () { self.compareRelease($(this).attr('data-release-id')); });
			$('body').on('click.wsp_github','#wsp-gh-workflow-generate',function () { self.generateWorkflow(); });
			$('body').on('click.wsp_github','#wsp-gh-workflow-copy',function () { self.copyWorkflow(); });
			$('body').on('click.wsp_github','#wsp-gh-workflow-download',function () { self.downloadWorkflow(); });
			$('body').on('change.wsp_github','#wsp-gh-workflow-mode,#wsp-gh-phpbb-branch',function () { self.invalidateWorkflow(); });
			$('body').on('input.wsp_github','#wsp-gh-repo,#wsp-gh-ref',function () {
				self.clearPreview();
				self.resetHealth();
				self.resetAutomation();
				self.resetReleases();
				self.resetCommits();
			});
		}
	};
})(window, window.jQuery);
