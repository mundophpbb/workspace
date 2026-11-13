(function ($) {
	'use strict';

	var repositionQueued = false;
	function closeToolbarMenus() {
		$('.wsp-toolbar-menu.is-open')
			.removeClass('is-open')
			.find('.wsp-toolbar-menu-toggle').attr('aria-expanded', 'false');

		$('.wsp-toolbar-dropdown').removeAttr('style').removeAttr('data-placement');
	}

	function positionDropdown($menu) {
		var $toggle = $menu.find('.wsp-toolbar-menu-toggle').first();
		var $dropdown = $menu.find('.wsp-toolbar-dropdown').first();
		if (!$toggle.length || !$dropdown.length) {
			return;
		}

		var rect = $toggle[0].getBoundingClientRect();
		var viewportW = window.innerWidth || document.documentElement.clientWidth;
		var viewportH = window.innerHeight || document.documentElement.clientHeight;
		var margin = 10;

		$dropdown.css({
			display: 'block',
			visibility: 'hidden',
			left: '0px',
			top: '0px'
		});

		var menuW = Math.min($dropdown.outerWidth(), viewportW - (margin * 2));
		var menuH = Math.min($dropdown.outerHeight(), viewportH - (margin * 2));
		var left = rect.left;
		var top = rect.bottom + 8;
		var placement = 'bottom';

		if ($dropdown.hasClass('wsp-toolbar-dropdown-right')) {
			left = rect.right - menuW;
		}

		if (left + menuW > viewportW - margin) {
			left = viewportW - menuW - margin;
		}
		if (left < margin) {
			left = margin;
		}

		if (top + menuH > viewportH - margin && rect.top > menuH + margin) {
			top = rect.top - menuH - 8;
			placement = 'top';
		}
		if (top < margin) {
			top = margin;
		}

		$dropdown.attr('data-placement', placement).css({
			display: 'block',
			visibility: 'visible',
			left: Math.round(left) + 'px',
			top: Math.round(top) + 'px'
		});
	}

	function normalizeSearch(text) {
		var value = String(text || '').toLocaleLowerCase();
		if (typeof value.normalize === 'function') {
			value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}
		return value;
	}

	function filterTools($dropdown) {
		var query = normalizeSearch($dropdown.find('#wsp-tools-search').val()).trim();
		var visible = 0;
		var $items = $dropdown.children('.wsp-menu-item');
		$items.each(function () {
			var $item = $(this);
			var match = !query || normalizeSearch($item.text()).indexOf(query) !== -1;
			$item.toggleClass('wsp-tools-search-hidden', !match);
			if (match) { visible++; }
		});
		var $blocks = $dropdown.children('.wsp-menu-heading, .wsp-menu-separator');
		$blocks.each(function () { $(this).toggleClass('wsp-tools-search-hidden', !!query); });
		$dropdown.find('.wsp-tools-search-empty').prop('hidden', !query || visible !== 0);
		$dropdown.scrollTop(0);
	}

	function scheduleReposition() {
		if (repositionQueued) { return; }
		repositionQueued = true;
		var schedule = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };
		schedule(function () { repositionQueued = false; repositionOpenMenu(); });
	}

	function openToolbarMenu($menu) {
		closeToolbarMenus();
		$menu.addClass('is-open')
			.find('.wsp-toolbar-menu-toggle').first().attr('aria-expanded', 'true');
		positionDropdown($menu);
	}

	function repositionOpenMenu() {
		var $menu = $('.wsp-toolbar-menu.is-open').first();
		if ($menu.length) {
			positionDropdown($menu);
		}
	}

	$(function () {
		var $doc = $(document);
		var $body = $('body');

		$body.on('click.wsp_toolbar', '.wsp-toolbar-menu-toggle', function (e) {
			e.preventDefault();
			e.stopPropagation();

			var $menu = $(this).closest('.wsp-toolbar-menu');
			if ($menu.hasClass('is-open')) {
				closeToolbarMenus();
				return;
			}
			if ($menu.find('#wsp-tools-search').length) {
				$menu.find('#wsp-tools-search').val('');
				filterTools($menu.find('.wsp-toolbar-dropdown').first());
			}
			openToolbarMenu($menu);
		});

		$body.on('click.wsp_toolbar', '.wsp-toolbar-dropdown', function (e) {
			e.stopPropagation();
		});

		$body.on('input.wsp_toolbar', '#wsp-tools-search', function () {
			var $dropdown = $(this).closest('.wsp-toolbar-dropdown');
			filterTools($dropdown);
			scheduleReposition();
		});

		$body.on('keydown.wsp_toolbar', '#wsp-tools-search', function (e) {
			if (e.key === 'Enter') {
				var $items = $(this).closest('.wsp-toolbar-dropdown').children('.wsp-menu-item:not(.wsp-tools-search-hidden):not(:disabled)');
				if ($items.length === 1) { e.preventDefault(); $items.first().trigger('click'); }
			}
		});

		$body.on('click.wsp_toolbar', '.wsp-toolbar-dropdown button:not(.wsp-toolbar-menu-toggle):not(:disabled)', function () {
			var $btn = $(this);
			if ($btn.is('#validate-release-project')) {
				window.setTimeout(closeToolbarMenus, 120);
				return;
			}
			closeToolbarMenus();
		});

		$body.on('click.wsp_toolbar', '[data-wsp-scope]', function (e) {
			e.preventDefault();
			var scope = ($(this).attr('data-wsp-scope') || 'normal').trim();
			$('#wsp-validator-scope').val(scope);
			$('#validate-release-project').trigger('click');
		});

		$doc.on('click.wsp_toolbar', function () {
			closeToolbarMenus();
		});

		$doc.on('keydown.wsp_toolbar', function (e) {
			if (e.key === 'Escape') {
				closeToolbarMenus();
			}
		});

		$(window).on('resize.wsp_toolbar scroll.wsp_toolbar', function () {
			scheduleReposition();
		});

		$('.editor-toolbar-rich').on('scroll.wsp_toolbar', function () {
			scheduleReposition();
		});
	});
})(jQuery);
