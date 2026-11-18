(function ($) {
	'use strict';

	$(function () {
		var adminData = window.FeuerCookieAdmin || {};

		$('[data-feuer-cookie-media]').on('click', function (event) {
			event.preventDefault();

			var $button = $(this);
			var target = $button.data('target');
			var $field = $(target);

			if (!$field.length || typeof wp === 'undefined' || !wp.media) {
				return;
			}

			var frame = wp.media({
				title: 'Logo auswaehlen',
				button: {
					text: 'Logo uebernehmen'
				},
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();

				if (attachment && attachment.url) {
					$field.val(attachment.url).trigger('change');
				}
			});

			frame.open();
		});

		(function syncDemoModeControls() {
			var $inputs = $('input[name="demo_mode"]');

			if ($inputs.length < 2) {
				return;
			}

			$inputs.eq(1).prop('disabled', true).closest('tr').hide();
		}());

		$('[data-feuer-cookie-clear-browser-consent]').on('click', function (event) {
			var $button = $(this);
			var $result = $('[data-feuer-cookie-clear-browser-consent-result]');

			event.preventDefault();

			if (!adminData.ajaxUrl || !adminData.nonce) {
				return;
			}

			$button.prop('disabled', true);
			$result.prop('hidden', false).removeClass('notice-success notice-error').text('');

			$.post(adminData.ajaxUrl, {
				action: 'feuer_cookie_set_consent',
				nonce: adminData.nonce,
				decision: 'delete_consent',
				current_url: adminData.currentUrl || window.location.href
			}).done(function (response) {
				if (adminData.localStorageKey && window.localStorage) {
					try {
						window.localStorage.removeItem(adminData.localStorageKey);
					} catch (error) {
						/* noop */
					}
				}

				if (response && response.success) {
					$result.addClass('notice-success').text((adminData.messages && adminData.messages.cleared) || 'Einwilligung geloescht.');
					return;
				}

				$result.addClass('notice-error').text((adminData.messages && adminData.messages.error) || 'Loeschen fehlgeschlagen.');
			}).fail(function () {
				$result.addClass('notice-error').text((adminData.messages && adminData.messages.error) || 'Loeschen fehlgeschlagen.');
			}).always(function () {
				$button.prop('disabled', false);
			});
		});

		$('[data-feuer-cookie-dashboard-widget]').on('click', '[data-feuer-cookie-dashboard-tab]', function () {
			var $button = $(this);
			var $widget = $button.closest('[data-feuer-cookie-dashboard-widget]');
			var tab = $button.attr('data-feuer-cookie-dashboard-tab');

			$widget.find('[data-feuer-cookie-dashboard-tab]')
				.removeClass('is-active')
				.attr('aria-selected', 'false')
				.attr('tabindex', '-1');
			$button.addClass('is-active').attr('aria-selected', 'true').attr('tabindex', '0');

			$widget.find('[data-feuer-cookie-dashboard-panel]').prop('hidden', true).removeClass('is-active');
			$widget.find('[data-feuer-cookie-dashboard-panel="' + tab + '"]').prop('hidden', false).addClass('is-active');
		});

		$('[data-feuer-cookie-dashboard-widget]').on('keydown', '[data-feuer-cookie-dashboard-tab]', function (event) {
			var $tabs = $(this).closest('[data-feuer-cookie-dashboard-widget]').find('[data-feuer-cookie-dashboard-tab]');
			var index = $tabs.index(this);
			var targetIndex = index;

			if ('ArrowRight' === event.key || 'ArrowDown' === event.key) {
				targetIndex = (index + 1) % $tabs.length;
			} else if ('ArrowLeft' === event.key || 'ArrowUp' === event.key) {
				targetIndex = (index - 1 + $tabs.length) % $tabs.length;
			} else if ('Home' === event.key) {
				targetIndex = 0;
			} else if ('End' === event.key) {
				targetIndex = $tabs.length - 1;
			} else {
				return;
			}

			event.preventDefault();
			$tabs.eq(targetIndex).trigger('focus').trigger('click');
		});
	});
}(jQuery));
