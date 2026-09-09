/**
 * Workspace module.
 */
(function checkjQuery() {
	// Workspace UI logic.
	if (window.jQuery) {

		(function($) {
			'use strict';

			$(document).ready(function() {
				// Workspace UI logic.
				$('.diff-content').each(function() {
					var $el = $(this);

					/**
 * Workspace module.
 */
					var rawText = $el.text() || '';

					// Workspace UI logic.
					rawText = rawText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

					// Workspace UI logic.
					var lines = rawText.split('\n');

					// Workspace UI logic.
					var coloredLines = lines.map(function(line) {
						var cleanLine = (line || '');

						// Workspace UI logic.
						if (cleanLine.trim() === '') {
							return '<span class="diff-line-context">&nbsp;</span>';
						}

						// Headers comuns do unified diff
						// --- a/file / +++ b/file
						if (cleanLine.indexOf('--- ') === 0 || cleanLine.indexOf('+++ ') === 0) {
							return '<span class="diff-line-info">' + escapeHtml(cleanLine) + '</span>';
						}

						// Workspace UI logic.
						if (cleanLine.indexOf('@@') === 0) {
							return '<span class="diff-line-info">' + escapeHtml(cleanLine) + '</span>';
						}

						// Workspace UI logic.
						if (cleanLine.charAt(0) === '+' && cleanLine.indexOf('+++ ') !== 0) {
							return '<span class="diff-line-add">' + escapeHtml(cleanLine) + '</span>';
						}

						// Workspace UI logic.
						if (cleanLine.charAt(0) === '-' && cleanLine.indexOf('--- ') !== 0) {
							return '<span class="diff-line-del">' + escapeHtml(cleanLine) + '</span>';
						}

						// Workspace UI logic.
						return '<span class="diff-line-context">' + escapeHtml(cleanLine) + '</span>';
					});

					// Workspace UI logic.
					$el.html(coloredLines.join('\n'));
				});

				// Workspace UI logic.
				function escapeHtml(str) {
					return String(str)
						.replace(/&/g, '&amp;')
						.replace(/</g, '&lt;')
						.replace(/>/g, '&gt;')
						.replace(/"/g, '&quot;')
						.replace(/'/g, '&#039;');
				}
			});

		})(window.jQuery);

	} else {
		// Workspace UI logic.
		setTimeout(checkjQuery, 50);
	}
})();