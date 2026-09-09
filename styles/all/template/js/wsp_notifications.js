/* global jQuery, WSP, wspVars */
(function ($) {
	'use strict';

	window.WSP = window.WSP || {};

	WSP.notifications = {
		init: function () {
			var self = this;
			this.$toggle = $('#wsp-notifications-toggle');
			this.$panel = $('#wsp-notifications-panel');
			this.$list = $('#wsp-notifications-list');
			this.$badge = $('#wsp-notifications-badge');
			this.$markRead = $('#wsp-notifications-mark-read');

			if (!this.$toggle.length || !this.$panel.length) return;

			this.$toggle.on('click', function (e) {
				e.preventDefault();
				self.$panel.toggle();
				if (self.$panel.is(':visible')) self.load();
			});

			this.$markRead.on('click', function (e) {
				e.preventDefault();
				self.markRead(0);
			});

			$(document).on('click', function (e) {
				if (!$(e.target).closest('#wsp-notifications-panel, #wsp-notifications-toggle').length) {
					self.$panel.hide();
				}
			});
		},

		load: function () {
			var self = this;
			if (!wspVars || !wspVars.notificationsUrl) return;
			this.$list.html('<p class="wsp-muted">' + WSP.lang('WSP_LOADING_NOTIFICATIONS') + '</p>');

			$.post(wspVars.notificationsUrl, WSP.withCsrf({}))
				.done(function (r) {
					if (!r || !r.success) {
						self.$list.html('<p class="wsp-muted">' + WSP.lang('WSP_ERROR_NOTIFICATIONS') + '</p>');
						return;
					}
					self.setBadge(parseInt(r.unread || 0, 10));
					self.render(r.notifications || []);
				})
				.fail(function () {
					self.$list.html('<p class="wsp-muted">' + WSP.lang('WSP_ERROR_NOTIFICATIONS') + '</p>');
				});
		},

		render: function (items) {
			var html = [];
			if (!items.length) {
				this.$list.html('<p class="wsp-muted">' + WSP.lang('WSP_NO_NOTIFICATIONS') + '</p>');
				return;
			}

			$.each(items, function (_, n) {
				var unread = parseInt(n.is_read || 0, 10) ? '' : ' is-unread';
				var actor = WSP._escapeHtml(n.actor_username || WSP.lang('WSP_SYSTEM_USER'));
				var project = WSP._escapeHtml(n.project_name || '');
				var path = WSP._escapeHtml(n.object_path || '');
				var key = n.message_key || ('WSP_NOTIFY_' + String(n.event || '').toUpperCase());
				var message = WSP.lang(key);
				if (!message || message === key) message = WSP._escapeHtml(n.event || '');

				html.push('<div class="wsp-notification-item' + unread + '" data-id="' + parseInt(n.notification_id || 0, 10) + '">');
				html.push('<span class="wsp-notification-dot"></span>');
				html.push('<div class="wsp-notification-body">');
				html.push('<strong>' + actor + '</strong> ' + message);
				if (path) html.push(' <em>' + path + '</em>');
				if (project) html.push('<small>' + project + '</small>');
				html.push('</div>');
				html.push('</div>');
			});

			this.$list.html(html.join(''));
		},

		markRead: function (notificationId) {
			var self = this;
			if (!wspVars || !wspVars.notificationsReadUrl) return;
			$.post(wspVars.notificationsReadUrl, WSP.withCsrf({ notification_id: notificationId || 0 }))
				.done(function (r) {
					if (r && r.success) {
						self.setBadge(parseInt(r.unread || 0, 10));
						self.load();
					}
				});
		},

		setBadge: function (count) {
			count = parseInt(count || 0, 10);
			if (!this.$badge || !this.$badge.length) return;
			if (count > 0) {
				this.$badge.text(count > 99 ? '99+' : count).show();
			} else {
				this.$badge.hide();
			}
		}
	};

	$(function () {
		if (window.WSP && WSP.notifications) WSP.notifications.init();
	});
})(jQuery);
