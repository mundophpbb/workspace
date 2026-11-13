/** Workspace IDE 1.15.0 - read-only ACL inventory and hypothetical profile scenarios. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;

	function intId(n) { return Math.max(0, parseInt(n, 10) || 0); }
	function safeList(a) { return Array.isArray(a) ? a : []; }
	var PROFILES = ['guest', 'registered', 'moderator', 'administrator'];

	WSP.permissions = {
		result: null,
		activeProject: 0,
		serial: 0,
		busy: false,
		overrides: {},
		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return intId(WSP.activeProjectId || 0); },
		allowed: function () {
			return !!(this.projectId() && window.wspVars &&
				window.wspVars.activeCanEdit == 1 && window.wspVars.architecturePermissionsUrl);
		},
		profile: function () {
			var p = String($('#wsp-perm-profile').val() || 'guest');
			return PROFILES.indexOf(p) === -1 ? 'guest' : p;
		},
		preset: function (profile, key) {
			// Explicitly illustrative grants. NOT phpBB's actual default ACL roles.
			var scope = String(key || '').charAt(0);
			if (profile === 'guest') return false;
			if (profile === 'registered') return scope === 'u';
			if (profile === 'moderator') return scope === 'u' || scope === 'm' || scope === 'f';
			return scope === 'u' || scope === 'm' || scope === 'f' || scope === 'a';
		},
		grant: function (profile, key) {
			var map = this.overrides[profile] || {};
			return Object.prototype.hasOwnProperty.call(map, key) ? !!map[key] : this.preset(profile, key);
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		status: function (message) { $('#wsp-perm-status').text(String(message || '')); },
		open: function () {
			if (!this.allowed()) return;
			$('#wsp-perm-modal').fadeIn(120);
			if (!this.result || this.activeProject !== this.projectId()) this.scan();
			else this.render();
		},
		scan: function () {
			if (!this.allowed()) return;
			var self = this;
			var project = this.projectId(), seq = ++this.serial;
			this.result = null;
			this.busy = true;
			this.overrides = {};
			this.activeProject = 0;
			$('#wsp-perm-scan').prop('disabled', true);
			$('#wsp-perm-summary,#wsp-perm-list,#wsp-perm-findings').empty();
			this.status(this.lang('WSP_PERM_LOADING'));
			$.ajax({
				url: WSP.normalizeUrl(window.wspVars.architecturePermissionsUrl),
				type: 'POST', dataType: 'json', cache: false, timeout: 30000,
				data: WSP.withCsrf({ project_id: project })
			}).done(function (data) {
				if (seq !== self.serial || project !== self.projectId()) return;
				if (data && data.success && data.audit && Array.isArray(data.audit.permissions)) {
					self.result = data;
					self.activeProject = project;
					self.render();
				} else self.status(data && data.error || self.lang('WSP_PERM_FAILED'));
			}).fail(function () {
				if (seq === self.serial && project === self.projectId()) self.status(self.lang('WSP_PERM_FAILED'));
			}).always(function () {
				if (seq !== self.serial) return;
				self.busy = false;
				$('#wsp-perm-scan').prop('disabled', false);
			});
		},
		filtered: function () {
			if (!this.result) return [];
			var audit = this.result.audit;
			var filter = String($('#wsp-perm-filter').val() || 'all');
			var flagged = {};
			safeList(audit.findings).forEach(function (f) { flagged[String(f.key || '')] = true; });
			return safeList(audit.permissions).filter(function (row) {
				if (filter === 'checked') return !!row.checked;
				if (filter === 'declared') return !!row.declared;
				if (filter === 'findings') return !!flagged[row.key];
				return true;
			});
		},
		source: function (item, target) {
			var path = String(item.path || '');
			var line = intId(item.line);
			var button = $('<button/>', { type: 'button', 'class': 'wsp-perm-source' })
				.attr('data-file-id', intId(item.file_id))
				.attr('data-line', Math.max(1, line || 1))
				.attr('title', this.lang('WSP_PERM_SOURCE'))
				.text(path + (line ? ':' + line : ''));
			button.appendTo(target);
		},
		render: function () {
			if (!this.result || this.activeProject !== this.projectId()) return;
			var audit = this.result.audit, all = safeList(audit.permissions), rows = this.filtered();
			var profile = this.profile(), self = this, granted = 0;
			all.forEach(function (item) { if (self.grant(profile, item.key)) granted++; });
			var summary = $('#wsp-perm-summary').empty();
			[[all.length, 'WSP_PERM_TOTAL'], [granted, 'WSP_PERM_GRANTED'],
				[all.length - granted, 'WSP_PERM_DENIED'],
				[(audit.summary || {}).checked || 0, 'WSP_PERM_CHECKED'],
				[(audit.summary || {}).defined || 0, 'WSP_PERM_DEFINED']].forEach(function (item) {
				$('<span/>', { 'class': 'wsp-perm-stat' }).text(item[0] + ' ' + self.lang(item[1])).appendTo(summary);
			});
			var partial = !(this.result.coverage || {}).complete || !(audit.coverage || {}).complete;
			var dynamicCount = intId((audit.summary || {}).dynamic_checks);
			this.status((partial ? this.lang('WSP_PERM_PARTIAL') + ' ' : '') +
				(dynamicCount ? dynamicCount + ' ' + this.lang('WSP_PERM_DYNAMIC') : ''));
			var list = $('#wsp-perm-list').empty();
			if (!rows.length) {
				$('<p/>', { 'class': 'wsp-perm-empty' }).text(this.lang('WSP_PERM_EMPTY')).appendTo(list);
			}
			rows.forEach(function (item) {
				var row = $('<div/>', { 'class': 'wsp-perm-row' });
				var heading = $('<div/>', { 'class': 'wsp-perm-row-head' }).appendTo(row);
				var label = $('<label/>', { 'class': 'wsp-perm-grant-label' }).appendTo(heading);
				$('<input/>', { type: 'checkbox', 'class': 'wsp-perm-grant' })
					.attr('data-key', String(item.key || ''))
					.prop('checked', self.grant(profile, item.key)).appendTo(label);
				$('<code/>').text(String(item.key || '')).appendTo(label);
				$('<span/>', { 'class': 'wsp-perm-scope' })
					.text(self.lang('WSP_PERM_SCOPES_' + item.scope)).appendTo(heading);
				var badges = $('<div/>', { 'class': 'wsp-perm-badges' }).appendTo(heading);
				[['declared', 'WSP_PERM_DECLARATION'], ['registered', 'WSP_PERM_REGISTRATION'],
					['checked', 'WSP_PERM_CHECKED']].forEach(function (entry) {
					$('<span/>', { 'class': 'wsp-perm-marker' + (item[entry[0]] ? ' is-present' : '') })
						.text(self.lang(entry[1]) + ': ' + self.lang(item[entry[0]] ? 'WSP_PERM_YES' : 'WSP_PERM_NO'))
						.appendTo(badges);
				});
				var details = $('<div/>', { 'class': 'wsp-perm-sources' }).appendTo(row);
				var allSources = [].concat(safeList(item.declarations), safeList(item.registrations),
					safeList(item.display), safeList(item.checks));
				allSources.slice(0, 7).forEach(function (source) { self.source(source, details); });
				if (!allSources.length) details.text(self.lang('WSP_PERM_NO_SOURCE'));
				row.appendTo(list);
			});
			var findings = $('#wsp-perm-findings').empty();
			var notes = safeList(audit.findings);
			if (!notes.length)
				$('<p/>', { 'class': 'wsp-perm-empty' }).text(this.lang('WSP_PERM_NO_FINDINGS')).appendTo(findings);
			notes.slice(0, 70).forEach(function (note) {
				var f = $('<div/>', { 'class': 'wsp-perm-finding' }).appendTo(findings);
				$('<code/>').text(String(note.key || '')).appendTo(f);
				$('<span/>').text(self.lang('WSP_PERM_FINDING_' + note.code)).appendTo(f);
				self.source(note, f);
			});
		},
		toggle: function (key, enabled) {
			if (!this.result) return;
			var exists = safeList(this.result.audit.permissions).some(function (x) { return x.key === key; });
			if (!exists) return;
			var profile = this.profile();
			if (!this.overrides[profile]) this.overrides[profile] = {};
			this.overrides[profile][key] = !!enabled;
			this.render();
		},
		gotoLine: function (line) {
			var editor = WSP.editor;
			if (!editor || typeof editor.gotoLine !== 'function') return;
			editor.gotoLine(Math.max(1, intId(line)), 0, true);
			if (editor.selection && editor.selection.clearSelection) editor.selection.clearSelection();
			if (typeof editor.focus === 'function') editor.focus();
		},
		openSource: function (id, line) {
			if (!this.allowed() || !id || this.activeProject !== this.projectId()) return;
			if (this.unsaved()) { this.status(this.lang('WSP_PERM_UNSAVED')); return; }
			if (intId(WSP.activeFileId) === id) {
				$('#wsp-perm-modal').fadeOut(100);
				this.gotoLine(line);
				return;
			}
			var link = $('.load-file[data-id]').filter(function () {
				return intId(this.getAttribute('data-id')) === id;
			}).first();
			if (!link.length) { this.status(this.lang('WSP_PERM_NOT_IN_TREE')); return; }
			var self = this, project = this.projectId();
			$(document).off('wsp:file-loaded.wsp_perm').on('wsp:file-loaded.wsp_perm', function (_event, openedId) {
				if (project !== self.projectId() || intId(openedId) !== id) return;
				$(document).off('wsp:file-loaded.wsp_perm');
				$('#wsp-perm-modal').fadeOut(100);
				self.gotoLine(line);
			});
			// Clear a pending navigation if loading is cancelled or fails.
			window.setTimeout(function () { $(document).off('wsp:file-loaded.wsp_perm'); }, 15000);
			link.trigger('click');
		},
		bindEvents: function () {
			var self = this;
			if (!$('#wsp-perm-modal').length) return;
			$('body').on('click.wsp_perm', '#wsp-perm-open', function () { self.open(); });
			$('body').on('click.wsp_perm', '#wsp-perm-close', function () { $('#wsp-perm-modal').fadeOut(120); });
			$('body').on('click.wsp_perm', '#wsp-perm-scan', function () { self.scan(); });
			$('body').on('click.wsp_perm', '#wsp-perm-reset', function () {
				delete self.overrides[self.profile()]; self.render();
			});
			$('body').on('change.wsp_perm', '#wsp-perm-profile,#wsp-perm-filter', function () { self.render(); });
			$('body').on('change.wsp_perm', '.wsp-perm-grant', function () {
				self.toggle(String(this.getAttribute('data-key') || ''), !!this.checked);
			});
			$('body').on('click.wsp_perm', '.wsp-perm-source', function () {
				self.openSource(intId(this.getAttribute('data-file-id')), intId(this.getAttribute('data-line')));
			});
			$(document).on('keydown.wsp_perm', function (event) {
				if (event.key === 'Escape' && $('#wsp-perm-modal').is(':visible'))
					$('#wsp-perm-modal').fadeOut(120);
			});
		}
	};
})(window, window.jQuery);
