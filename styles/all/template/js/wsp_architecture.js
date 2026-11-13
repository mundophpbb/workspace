/** Workspace IDE 1.14.0 - project comparison and static dependency diagram. */
(function (window, $) {
	'use strict';
	if (!$ || !window.WSP) return;

	var NS = 'http://www.w3.org/2000/svg';
	var TYPE_ORDER = ['route', 'service', 'controller', 'component', 'event', 'migration', 'config', 'template', 'language'];
	var AREA_KEYS = ['migrations', 'permissions', 'routes', 'services', 'events', 'controllers', 'languages', 'templates', 'composer', 'other'];

	function svgEl(tag, attributes, text) {
		var el = document.createElementNS(NS, tag);
		Object.keys(attributes || {}).forEach(function (key) { el.setAttribute(key, attributes[key]); });
		if (text !== undefined) el.textContent = String(text);
		return el;
	}
	function intId(value) { return Math.max(0, parseInt(value, 10) || 0); }

	WSP.architecture = {
		lastCompare: null,
		lastMap: null,
		activeTab: 'compare',
		diffPath: '',
		selectedNode: null,
		busy: { compare: false, map: false, diff: false },
		sequence: { compare: 0, map: 0, diff: 0 },

		lang: function (key) { return WSP.lang(key); },
		projectId: function () { return intId(WSP.activeProjectId || 0); },
		allowed: function () {
			return !!(this.projectId() && window.wspVars && window.wspVars.activeCanEdit == 1 &&
				window.wspVars.architectureCompareUrl && window.wspVars.architectureMapUrl &&
				window.wspVars.architectureDiffUrl);
		},
		unsaved: function () {
			return !!(WSP.editor && typeof WSP.editor.getValue === 'function' && WSP.activeFileId &&
				typeof WSP.originalContent === 'string' && WSP.editor.getValue() !== WSP.originalContent);
		},
		status: function (area, message) { $('#wsp-arch-' + area + '-status').text(String(message || '')); },
		fail: function (area, error) {
			this.status(area, error || this.lang('WSP_ARCH_FAILED'));
		},
		request: function (kind, url, payload, done) {
			var self = this;
			if (this.busy[kind]) return;
			var seq = ++this.sequence[kind];
			this.busy[kind] = true;
			var $button = kind === 'compare' ? $('#wsp-arch-run-compare') :
				kind === 'map' ? $('#wsp-arch-refresh-map') : $();
			$button.prop('disabled', true);
			$.ajax({
				url: WSP.normalizeUrl(url), type: 'POST', dataType: 'json',
				data: WSP.withCsrf(payload), cache: false, timeout: 30000
			}).done(function (data) {
				if (self.sequence[kind] !== seq) return;
				if (data && data.success) done(data);
				else self.fail(kind === 'diff' ? 'compare' : kind, data && data.error);
			}).fail(function () {
				if (self.sequence[kind] !== seq) return;
				self.fail(kind === 'diff' ? 'compare' : kind);
			}).always(function () {
				if (self.sequence[kind] !== seq) return;
				self.busy[kind] = false;
				$button.prop('disabled', false);
			});
		},
		projects: function () {
			var result = [];
			$('#project-list .project-group[data-project-id]').each(function () {
				var id = intId(this.getAttribute('data-project-id'));
				var visible = this.getAttribute('data-can-open');
				if (id && visible !== '0') result.push({ id: id, name: this.getAttribute('data-project-name') || String(id) });
			});
			return result;
		},
		fillProjects: function () {
			var rows = this.projects();
			var before = $('#wsp-arch-before').empty();
			var after = $('#wsp-arch-after').empty();
			var current = this.projectId();
			rows.forEach(function (project) {
				var label = project.name + ' (#' + project.id + ')';
				before.append($('<option/>', { value: project.id }).text(label));
				after.append($('<option/>', { value: project.id }).text(label));
			});
			after.val(String(current));
			var old = rows.filter(function (row) { return row.id !== current; })[0];
			if (old) before.val(String(old.id));
			if (rows.length < 2) this.status('compare', this.lang('WSP_ARCH_NO_SECOND'));
			return rows.length;
		},
		open: function (tab) {
			if (!this.allowed()) return;
			this.fillProjects();
			$('#wsp-architecture-modal').fadeIn(120);
			this.tab(tab || 'compare');
		},
		tab: function (tab) {
			this.activeTab = tab === 'map' ? 'map' : 'compare';
			$('#wsp-arch-pane-compare').prop('hidden', this.activeTab !== 'compare');
			$('#wsp-arch-pane-map').prop('hidden', this.activeTab !== 'map');
			$('#wsp-arch-tab-compare').toggleClass('is-active', this.activeTab === 'compare')
				.attr('aria-selected', this.activeTab === 'compare' ? 'true' : 'false');
			$('#wsp-arch-tab-map').toggleClass('is-active', this.activeTab === 'map')
				.attr('aria-selected', this.activeTab === 'map' ? 'true' : 'false');
			if (this.activeTab === 'map') this.map();
		},
		compare: function () {
			if (!this.allowed() || this.busy.compare) return;
			var before = intId($('#wsp-arch-before').val());
			var after = intId($('#wsp-arch-after').val());
			if (!before || !after || before === after) {
				this.status('compare', this.lang('WSP_ARCH_PICK_DIFFERENT')); return;
			}
			this.lastCompare = null;
			this.diffPath = '';
			this.sequence.diff++;
			this.busy.diff = false;
			$('#wsp-arch-compare-summary,#wsp-arch-changes,#wsp-arch-diff').empty();
			$('#wsp-arch-diff-wrap').prop('hidden', true);
			$('.wsp-arch-filterbar').prop('hidden', true);
			this.status('compare', this.lang('WSP_ARCH_LOADING'));
			var self = this;
			this.request('compare', window.wspVars.architectureCompareUrl,
				{ before_id: before, after_id: after }, function (data) {
					if (intId($('#wsp-arch-before').val()) !== before ||
						intId($('#wsp-arch-after').val()) !== after) return;
					self.lastCompare = data;
					self.drawComparison();
				});
		},
		areaLabel: function (key) {
			return this.lang('WSP_ARCH_AREA_' + String(key || 'other'));
		},
		drawComparison: function () {
			if (!this.lastCompare) return;
			var report = this.lastCompare.comparison || {};
			var summary = report.summary || {};
			var coverage = this.lastCompare.coverage || {};
			var partial = !((coverage.before || {}).complete && (coverage.after || {}).complete);
			var container = $('#wsp-arch-compare-summary').empty();
			var self = this;
			[['added', 'WSP_ARCH_ADDED'], ['removed', 'WSP_ARCH_REMOVED'],
			 ['modified', 'WSP_ARCH_MODIFIED'], ['unchanged', 'WSP_ARCH_UNCHANGED']].forEach(function (item) {
				$('<span/>', { 'class': 'wsp-arch-pill is-' + item[0] })
					.text(String(intId(summary[item[0]])) + ' ' + self.lang(item[1]))
					.appendTo(container);
			});
			this.status('compare', partial ? this.lang('WSP_ARCH_PARTIAL') :
				report.list_truncated ? this.lang('WSP_ARCH_MORE') : '');
			var $filter = $('#wsp-arch-area-filter').empty();
			$('<option/>', { value: 'all' }).text(this.lang('WSP_ARCH_ALL_AREAS')).appendTo($filter);
			Object.keys(report.areas || {}).filter(function (key) { return AREA_KEYS.indexOf(key) !== -1; })
				.forEach(function (key) {
					$('<option/>', { value: key }).text(self.areaLabel(key) + ' (' + report.areas[key] + ')').appendTo($filter);
				});
			$filter.val('all');
			$('.wsp-arch-filterbar').prop('hidden', !(report.files || []).length);
			this.filterChanges();
		},
		filterChanges: function () {
			var rows = (this.lastCompare && this.lastCompare.comparison && this.lastCompare.comparison.files) || [];
			var selected = String($('#wsp-arch-area-filter').val() || 'all');
			var target = $('#wsp-arch-changes').empty();
			var self = this;
			rows.forEach(function (item, index) {
				if (selected !== 'all' && item.area !== selected) return;
				var key = item.status === 'added' ? 'WSP_ARCH_ADDED' :
					item.status === 'removed' ? 'WSP_ARCH_REMOVED' : 'WSP_ARCH_MODIFIED';
				var button = $('<button/>', { type: 'button', 'class': 'wsp-arch-change is-' + item.status })
					.attr('data-arch-index', index);
				$('<span/>', { 'class': 'wsp-arch-change-state' }).text(self.lang(key)).appendTo(button);
				$('<code/>').text(String(item.path || '')).appendTo(button);
				$('<small/>').text(self.areaLabel(item.area)).appendTo(button);
				button.appendTo(target);
			});
			if (!target.children().length)
				$('<p/>', { 'class': 'wsp-arch-empty' }).text(this.lang('WSP_ARCH_NO_CHANGES')).appendTo(target);
		},
		diff: function (index) {
			if (!this.lastCompare || this.busy.diff) return;
			var item = ((this.lastCompare.comparison || {}).files || [])[index];
			if (!item) return;
			this.diffPath = String(item.path || '');
			var self = this;
			$('#wsp-arch-diff-wrap').prop('hidden', false);
			$('#wsp-arch-diff-title').text(this.lang('WSP_ARCH_DIFF') + ' - ' + this.diffPath);
			$('#wsp-arch-diff').text(this.lang('WSP_ARCH_LOADING'));
			this.request('diff', window.wspVars.architectureDiffUrl, {
				before_id: intId(this.lastCompare.before_id), after_id: intId(this.lastCompare.after_id),
				path: this.diffPath
			}, function (result) {
				if (result.path !== self.diffPath) return;
				if (result.too_large) {
					$('#wsp-arch-diff').text(self.lang('WSP_ARCH_DIFF_TOO_LARGE')); return;
				}
				self.renderPatch(String(result.patch || ''));
			});
		},
		renderPatch: function (patch) {
			var target = $('#wsp-arch-diff').empty();
			if (!patch) { target.text(this.lang('WSP_ARCH_DIFF_EMPTY')); return; }
			patch.split('\n').forEach(function (line) {
				var kind = line.charAt(0) === '+' ? 'plus' : line.charAt(0) === '-' ? 'minus' :
					line.charAt(0) === '@' ? 'hunk' : 'context';
				$('<span/>', { 'class': 'wsp-arch-diff-line is-' + kind }).text(line + '\n').appendTo(target);
			});
		},
		map: function () {
			if (!this.allowed() || this.busy.map) return;
			var self = this;
			this.lastMap = null;
			$('#wsp-arch-diagram,#wsp-arch-map-summary').empty();
			$('#wsp-arch-node-details').prop('hidden', true);
			this.status('map', this.lang('WSP_ARCH_LOADING'));
			this.request('map', window.wspVars.architectureMapUrl,
				{ project_id: this.projectId() }, function (data) {
					if (intId(data.project_id) !== self.projectId()) return;
					self.lastMap = data;
					self.drawGraph();
				});
		},
		drawGraph: function () {
			if (!this.lastMap) return;
			var report = this.lastMap.graph || {};
			var nodes = report.nodes || [];
			var edges = report.edges || [];
			var summary = report.summary || {};
			var coverage = this.lastMap.coverage || {};
			$('#wsp-arch-map-summary').text(intId(summary.nodes) + ' ' + this.lang('WSP_ARCH_MAP_NODES') +
				' / ' + intId(summary.edges) + ' ' + this.lang('WSP_ARCH_MAP_EDGES'));
			this.status('map', !coverage.complete || summary.truncated ?
				this.lang('WSP_ARCH_PARTIAL') : this.lang('WSP_ARCH_MAP_READY'));
			var groups = {};
			var positions = {};
			TYPE_ORDER.forEach(function (type) { groups[type] = []; });
			nodes.forEach(function (node) { if (groups[node.type]) groups[node.type].push(node); });
			var column = 0;
			var cellWidth = 218;
			var rowHeight = 47;
			var maxRows = 19;
			var maxCount = 0;
			var typeCenters = [];
			var self = this;
			TYPE_ORDER.forEach(function (type) {
				var group = groups[type];
				if (!group.length) return;
				group.sort(function (a, b) { return String(a.label).localeCompare(String(b.label)); });
				var countCols = Math.ceil(group.length / maxRows);
				typeCenters.push({ type: type, x: column * cellWidth + 9 });
				group.forEach(function (node, i) {
					var row = i % maxRows;
					var col = Math.floor(i / maxRows);
					positions[node.id] = { x: (column + col) * cellWidth + 10, y: row * rowHeight + 60, node: node };
					maxCount = Math.max(maxCount, row + 1);
				});
				column += countCols;
			});
			var width = Math.max(440, column * cellWidth + 20);
			var height = Math.max(170, maxCount * rowHeight + 73);
			var svg = svgEl('svg', { viewBox: '0 0 ' + width + ' ' + height,
				width: width, height: height, 'class': 'wsp-arch-svg',
				'role': 'group', 'aria-label': this.lang('WSP_ARCH_MAP') });
			var linesLayer = svgEl('g', { 'class': 'wsp-arch-links' });
			var nodesLayer = svgEl('g', { 'class': 'wsp-arch-nodes' });
			typeCenters.forEach(function (section) {
				nodesLayer.appendChild(svgEl('text', { x: section.x, y: 27, 'class': 'wsp-arch-column-title' }, self.lang('WSP_ARCH_TYPE_' + section.type)));
			});
			edges.forEach(function (edge) {
				var a = positions[edge.from], b = positions[edge.to];
				if (!a || !b) return;
				var ax = a.x + 192, bx = b.x;
				var ay = a.y + 17, by = b.y + 17;
				var delta = Math.max(40, Math.abs(bx - ax) / 2);
				var path = svgEl('path', { 'class': 'wsp-arch-link',
					'data-from': edge.from, 'data-to': edge.to,
					d: 'M ' + ax + ' ' + ay + ' C ' + (ax + delta) + ' ' + ay + ' ' +
						(bx - delta) + ' ' + by + ' ' + bx + ' ' + by });
				linesLayer.appendChild(path);
			});
			Object.keys(positions).forEach(function (id) {
				var pos = positions[id], node = pos.node;
				var g = svgEl('g', { 'class': 'wsp-arch-node type-' + node.type,
					'data-node-id': id, 'transform': 'translate(' + pos.x + ',' + pos.y + ')',
					tabindex: '0', role: 'button', 'aria-label': String(node.label || '') });
				g.appendChild(svgEl('rect', { x: 0, y: 0, rx: 7, ry: 7, width: 192, height: 34 }));
				var label = String(node.label || '');
				if (label.length > 25) label = label.substring(0, 22) + '...';
				g.appendChild(svgEl('text', { x: 10, y: 21 }, label));
				g.appendChild(svgEl('title', {}, String(node.label || '') + '\n' + String(node.path || '')));
				var activate = function () { self.selectNode(node, g, svg); };
				g.addEventListener('click', activate);
				g.addEventListener('keydown', function (event) {
					if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); }
				});
				nodesLayer.appendChild(g);
			});
			svg.appendChild(linesLayer);
			svg.appendChild(nodesLayer);
			$('#wsp-arch-diagram').empty().append(svg);
		},
		selectNode: function (node, g, svg) {
			$(svg).find('.wsp-arch-node').removeClass('is-selected');
			$(g).addClass('is-selected');
			$(svg).find('.wsp-arch-link').removeClass('is-highlighted');
			$(svg).find('.wsp-arch-link').filter(function () {
				return this.getAttribute('data-from') === node.id || this.getAttribute('data-to') === node.id;
			}).addClass('is-highlighted');
			this.selectedNode = node;
			$('#wsp-arch-node-name').text(String(node.label || ''));
			$('#wsp-arch-node-path').text(String(node.path || ''));
			$('#wsp-arch-open-file').prop('disabled', intId(node.file_id) <= 0);
			$('#wsp-arch-node-details').prop('hidden', false);
		},
		openFile: function () {
			if (!this.selectedNode) return;
			var id = intId(this.selectedNode.file_id);
			if (!id) return;
			if (this.unsaved()) { this.status('map', this.lang('WSP_ARCH_UNSAVED')); return; }
			var link = $('.load-file[data-id]').filter(function () {
				return intId(this.getAttribute('data-id')) === id;
			}).first();
			if (!link.length) { this.status('map', this.lang('WSP_ARCH_NOT_IN_TREE')); return; }
			$('#wsp-architecture-modal').fadeOut(100);
			link.trigger('click');
		},
		bindEvents: function () {
			var self = this;
			if (!$('#wsp-architecture-modal').length) return;
			$('body').on('click.wsp_arch', '#wsp-arch-open-compare', function () { self.open('compare'); });
			$('body').on('click.wsp_arch', '#wsp-arch-open-map', function () { self.open('map'); });
			$('body').on('click.wsp_arch', '#wsp-arch-close', function () { $('#wsp-architecture-modal').fadeOut(120); });
			$('body').on('click.wsp_arch', '#wsp-arch-tab-compare', function () { self.tab('compare'); });
			$('body').on('click.wsp_arch', '#wsp-arch-tab-map', function () { self.tab('map'); });
			$('body').on('click.wsp_arch', '#wsp-arch-run-compare', function () { self.compare(); });
			$('body').on('change.wsp_arch', '#wsp-arch-before,#wsp-arch-after', function () {
				self.lastCompare = null;
				self.diffPath = '';
				self.sequence.diff++;
				self.busy.diff = false;
				$('#wsp-arch-compare-summary,#wsp-arch-changes').empty();
				$('#wsp-arch-diff-wrap').prop('hidden', true);
				$('.wsp-arch-filterbar').prop('hidden', true);
			});
			$('body').on('change.wsp_arch', '#wsp-arch-area-filter', function () { self.filterChanges(); });
			$('body').on('click.wsp_arch', '.wsp-arch-change', function () {
				self.diff(intId(this.getAttribute('data-arch-index')));
			});
			$('body').on('click.wsp_arch', '#wsp-arch-refresh-map', function () { self.map(); });
			$('body').on('click.wsp_arch', '#wsp-arch-open-file', function () { self.openFile(); });
			$(document).on('keydown.wsp_arch', function (event) {
				if (event.key === 'Escape' && $('#wsp-architecture-modal').is(':visible'))
					$('#wsp-architecture-modal').fadeOut(120);
			});
		}
	};
})(window, window.jQuery);
