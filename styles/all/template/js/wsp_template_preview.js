/** Workspace 1.25.0: read-only phpBB template visual mock in an opaque sandbox. */
(function (window, $) {
	'use strict';
	if (!window.WSP || !$) return;
	var WSP = window.WSP;
	function id(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function pick(select, rows) {
		select.empty();
		(rows || []).forEach(function (item) {
			$('<option/>').val(String(id(item.id))).prop('disabled', !!item.large)
				.text(String(item.path || '') + (item.large ? ' (limite)' : '')).appendTo(select);
		});
	}
	function safeCss(css) {
		return String(css || '').replace(/@import\b[^;]*;/gi, '/* blocked */')
			.replace(/url\s*\([^)]*\)/gi, 'none')
			.replace(/expression\s*\(/gi, 'disabled(')
			.replace(/</g, '\\3C '); // prevent breaking out of the new <style> element
	}
	// DOMParser is inert, but sanitize before serializing. The iframe's CSP and
	// sandbox remain the security boundary even for malformed, adversarial HTML.
	function sanitizedHtml(source) {
		if (typeof window.DOMParser !== 'function') return '';
		var dom = new window.DOMParser().parseFromString(String(source || ''), 'text/html');
		var forbidden = 'script,iframe,frame,frameset,object,embed,base,meta,link,portal,applet,foreignobject';
		Array.prototype.forEach.call(dom.querySelectorAll(forbidden), function (node) {
			if (node.parentNode) node.parentNode.removeChild(node);
		});
		Array.prototype.forEach.call(dom.querySelectorAll('*'), function (node) {
			Array.prototype.slice.call(node.attributes || []).forEach(function (attr) {
				var name = String(attr.name || '').toLowerCase();
				if (name.indexOf('on') === 0 || name === 'href' || name === 'src' || name === 'srcset' ||
					name === 'action' || name === 'formaction' || name === 'target' || name === 'download' ||
					name === 'data' || name === 'xlink:href' || name === 'srcdoc' ||
					name === 'integrity' || name === 'content' || name === 'http-equiv') {
					node.removeAttribute(attr.name);
				}
			});
			if (String(node.tagName).toLowerCase() === 'style') node.textContent = safeCss(node.textContent);
			if (String(node.tagName).toLowerCase() === 'form') node.setAttribute('autocomplete', 'off');
		});
		return String(dom.body ? dom.body.innerHTML : '');
	}
	function framedDocument(html, css) {
		// CSP also blocks fetch(), CSS remote assets, forms, fonts, embeds and scripts.
		// The iframe has sandbox="" (no allow-scripts / no allow-same-origin).
		var csp = "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src data:; " +
			"font-src 'none'; connect-src 'none'; media-src 'none'; object-src 'none'; " +
			"frame-src 'none'; form-action 'none'; base-uri 'none'";
		return '<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="' + csp + '">' +
			'<meta name="referrer" content="no-referrer"><style>' +
			'html{background:#fff;color:#222;font:14px Arial,sans-serif;padding:16px;box-sizing:border-box}' +
			'body{margin:0;overflow-wrap:anywhere}.wsp-preview-placeholder{border:1px dashed #aaa;' +
			'color:#666;font-size:12px;padding:6px;margin:4px 0;background:#f8f8f8}' +
			safeCss(css) + '</style></head><body>' + sanitizedHtml(html) + '</body></html>';
	}
	WSP.templatePreview = {
		sequence: 0, project: 0, active: false, catalog: null,
		lang: function (key) { return String(WSP.lang(key) || key); },
		projectId: function () { return id(WSP.activeProjectId); },
		allowed: function () {
			return !!(this.projectId() && window.wspVars && window.wspVars.activeCanEdit == 1 &&
				window.wspVars.templatePreviewCatalogUrl && window.wspVars.templatePreviewRenderUrl);
		},
		status: function (text) { $('#wsp-tp-status').text(String(text || '')); },
		clearFrame: function () {
			var frame=document.getElementById('wsp-tp-frame');
			if (frame) frame.srcdoc = framedDocument('', '');
			$('#wsp-tp-warnings').empty();
		},
		open: function () {
			if (!this.allowed()) return;
			this.active = true; this.project = this.projectId(); this.sequence++;
			this.catalog = null;
			this.clearFrame();
			this.status(this.lang('WSP_TP_LOADING'));
			$('#wsp-tp-preview').prop('disabled', true);
			$('#wsp-tp-modal').fadeIn(120);
			this.loadCatalog();
		},
		close: function () {
			this.active = false; this.sequence++; this.catalog = null;
			this.clearFrame();
			$('#wsp-tp-modal').fadeOut(120);
		},
		loadCatalog: function () {
			if (!this.allowed() || !this.active) return;
			var self=this, project=this.projectId(), sequence=++this.sequence;
			this.status(this.lang('WSP_TP_LOADING'));
			$.ajax({ url:WSP.normalizeUrl(window.wspVars.templatePreviewCatalogUrl), type:'POST',
				dataType:'json', timeout:30000, data:WSP.withCsrf({project_id:project})
			}).done(function (data) {
				if (!self.active || sequence!==self.sequence || project!==self.projectId()) return;
				if (!data || !data.success || id(data.project_id)!==project ||
					!Array.isArray(data.templates) || !Array.isArray(data.styles)) {
					self.status(data && data.error ? data.error : self.lang('WSP_TP_ERROR'));return;
				}
				self.catalog=data;
				var templates=data.templates.filter(function (x) {return !x.large;}),
					styles=data.styles.filter(function (x) {return !x.large;});
				pick($('#wsp-tp-template'), data.templates);
				var css=$('#wsp-tp-css').empty();
				$('<option/>').val('0').text(self.lang('WSP_TP_NO_CSS')).appendTo(css);
				(data.styles || []).forEach(function (x) {
					$('<option/>').val(String(id(x.id))).prop('disabled',!!x.large)
						.text(String(x.path || '')+(x.large?' (limite)':'')).appendTo(css);
				});
				var current=id(WSP.activeFileId);
				if (templates.some(function (x){return id(x.id)===current;})) $('#wsp-tp-template').val(String(current));
				else if (templates.length) $('#wsp-tp-template').val(String(id(templates[0].id)));
				else $('#wsp-tp-template').val('');
				if (styles.some(function (x){return id(x.id)===current;})) css.val(String(current));
				else css.val('0');
				$('#wsp-tp-preview').prop('disabled', !templates.length);
				self.status(templates.length ? (data.partial ? self.lang('WSP_TP_PARTIAL') : self.lang('WSP_TP_READY')) : self.lang('WSP_TP_EMPTY'));
				if (templates.length) self.render();
			}).fail(function () {
				if (sequence===self.sequence && self.active && project===self.projectId()) self.status(self.lang('WSP_TP_ERROR'));
			});
		},
		draft: function (template, css) {
			if (!$('#wsp-tp-use-draft').prop('checked') || !WSP.editor ||
				typeof WSP.editor.getValue!=='function') return null;
			var active=id(WSP.activeFileId);
			if (active!==template && active!==css) return null;
			var content=WSP.editor.getValue();
			if (typeof content !== 'string') return null;
			if (active===template && new window.Blob([content]).size>131072) return null;
			if (active===css && new window.Blob([content]).size>98304) return null;
			return {draft_file_id:active,draft_content:content};
		},
		render: function () {
			if (!this.active || !this.allowed() || !this.catalog) return;
			var template=id($('#wsp-tp-template').val()), css=id($('#wsp-tp-css').val());
			if (!template) {this.status(this.lang('WSP_TP_EMPTY')); return;}
			var profile=String($('#wsp-tp-profile').val() || 'member');
			var project=this.projectId(), sequence=++this.sequence, self=this;
			this.clearFrame(); this.status(this.lang('WSP_TP_RENDERING'));
			$('#wsp-tp-preview').prop('disabled', true);
			var params={project_id:project,template_id:template,css_id:css,profile:profile};
			var draft=this.draft(template,css);
			if (draft) {params.draft_file_id=draft.draft_file_id;params.draft_content=draft.draft_content;}
			$.ajax({ url:WSP.normalizeUrl(window.wspVars.templatePreviewRenderUrl),
				type:'POST', dataType:'json', timeout:30000, data:WSP.withCsrf(params)
			}).done(function (data) {
				if (!self.active || sequence!==self.sequence || project!==self.projectId() ||
					template!==id($('#wsp-tp-template').val()) || css!==id($('#wsp-tp-css').val()) ||
					profile!==String($('#wsp-tp-profile').val())) return;
				if (!data || !data.success || id(data.project_id)!==project ||
					id(data.template_id)!==template || id(data.css_id)!==css ||
					!data.preview || data.preview.static_only!==true ||
					typeof data.preview.html!=='string' || typeof data.preview.css!=='string') {
					self.status(data && data.error ? data.error : self.lang('WSP_TP_ERROR'));return;
				}
				var frame=document.getElementById('wsp-tp-frame');
				if (!frame || frame.getAttribute('sandbox')!=='' || !window.DOMParser) {
					self.status(self.lang('WSP_TP_ERROR'));return;
				}
				frame.srcdoc=framedDocument(data.preview.html,data.preview.css);
				var warnings=$('#wsp-tp-warnings').empty();
				(Array.isArray(data.preview.warnings)?data.preview.warnings:[]).forEach(function (code) {
					var key='WSP_TP_WARN_'+String(code).replace(/[^A-Za-z0-9_]/g,'');
					$('<span/>',{'class':'wsp-tp-warning'}).text(self.lang(key)).appendTo(warnings);
				});
				self.status((data.draft_used ? self.lang('WSP_TP_DRAFT_USED')+' · ' : '') + self.lang('WSP_TP_STATIC'));
			}).fail(function () {
				if (self.active && sequence===self.sequence && project===self.projectId()) self.status(self.lang('WSP_TP_ERROR'));
			}).always(function () {
				if (self.active && sequence===self.sequence) $('#wsp-tp-preview').prop('disabled',false);
			});
		},
		bindEvents: function () {
			if (!$('#wsp-tp-modal').length) return;
			var self=this;
			$('body').on('click.wsp_tp','#wsp-tp-open',function () {self.open();});
			$('body').on('click.wsp_tp','#wsp-tp-close',function () {self.close();});
			$('body').on('click.wsp_tp','#wsp-tp-preview',function () {self.render();});
			$('body').on('change.wsp_tp','#wsp-tp-template,#wsp-tp-css,#wsp-tp-profile,#wsp-tp-use-draft',function () {
				self.sequence++; self.clearFrame(); self.status(self.lang('WSP_TP_CHANGED'));
				$('#wsp-tp-preview').prop('disabled', !id($('#wsp-tp-template').val()));
			});
			$('body').on('change.wsp_tp','#wsp-tp-width',function () {
				$('#wsp-tp-frame').toggleClass('wsp-tp-mobile',String(this.value)==='mobile');
			});
			$(document).on('keydown.wsp_tp',function (e) {
				if (e.key==='Escape' && $('#wsp-tp-modal').is(':visible')) self.close();
			});
			$(document).on('wsp:file-loaded.wsp_tp',function () {
				if (self.active) {self.sequence++;self.clearFrame();self.status(self.lang('WSP_TP_CHANGED'));}
			});
		}
	};
	// Explicitly expose pure helpers for local security regression tests.
	WSP.templatePreview._safeCss=safeCss;
	WSP.templatePreview._sanitizedHtml=sanitizedHtml;
	WSP.templatePreview._framedDocument=framedDocument;
})(window, window.jQuery);
