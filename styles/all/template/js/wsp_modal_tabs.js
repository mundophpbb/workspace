/* Workspace 1.51.0: accessible tab navigation; no data or server operations. */
(function () {
	'use strict';
	function init(modalId, prefix) {
		var dialog = document.getElementById(modalId);
		if (!dialog) { return; }
		var tabs = Array.prototype.slice.call(dialog.querySelectorAll('[role="tab"][data-wsp-tab]'));
		if (!tabs.length) { return; }
		function activate(tab, focus) {
			var target = tab.getAttribute('aria-controls');
			tabs.forEach(function (button) {
				var active = button === tab;
				button.classList.toggle('is-active', active);
				button.setAttribute('aria-selected', active ? 'true' : 'false');
				button.tabIndex = active ? 0 : -1;
				var pane = document.getElementById(button.getAttribute('aria-controls'));
				if (pane) { pane.hidden = !active; }
			});
			if (focus) { tab.focus(); }
			var pane = document.getElementById(target);
			if (pane) { pane.scrollTop = 0; }
		}
		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () { activate(tab, false); });
			tab.addEventListener('keydown', function (event) {
				if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].indexOf(event.key) === -1) { return; }
				event.preventDefault();
				var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
				activate(tabs[next], true);
			});
		});
		activate(tabs[0], false);
	}
	function ready() {
		['wsp-gh-modal', 'wsp-lab-modal', 'wsp-portfolio-modal', 'wsp-generator-modal', 'release-validator-modal'].forEach(init);
		// Never leave a newly generated preview hidden behind the configuration tab.
		var results = document.getElementById('wsp-gen-results');
		if (results && window.MutationObserver) {
			new MutationObserver(function () {
				if (!results.hidden) {
					var button = document.getElementById('wsp-gen-tab-preview');
					if (button) { button.click(); }
				}
			}).observe(results, {attributes:true, attributeFilter:['hidden']});
		}
		var wizard = document.getElementById('wsp-release-wizard');
		if (wizard && window.MutationObserver) {
			new MutationObserver(function () {
				if (!wizard.hidden) {
					var button = document.getElementById('wsp-validator-tab-process');
					if (button) { button.click(); }
				}
			}).observe(wizard, {attributes:true, attributeFilter:['hidden']});
		}
		// After generating a report, show the result panel without changing existing request handlers.
		var run = document.getElementById('run-release-validator');
		if (run) { run.addEventListener('click', function () {
			var button = document.getElementById('wsp-validator-tab-results');
			if (button) { button.click(); }
		}); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', ready); }
	else { ready(); }
}());
