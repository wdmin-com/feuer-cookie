(function () {
	'use strict';

	function getConsentRoot(element) {
		if (!element) {
			return document.querySelector('[data-feuer-cookie-consent]');
		}

		return element.closest('[data-feuer-cookie-consent]') || document.querySelector('[data-feuer-cookie-consent]');
	}

	function getCategoryInput(root, category) {
		return root ? root.querySelector('[data-feuer-cookie-category="' + category + '"]') : null;
	}

	function getVendorInputs(root, category) {
		if (!root) {
			return [];
		}

		return Array.prototype.slice.call(
			root.querySelectorAll('[data-feuer-cookie-category-parent="' + category + '"]')
		);
	}

	function updateCategoryState(root, category) {
		var categoryInput = getCategoryInput(root, category);
		var vendorInputs = getVendorInputs(root, category);
		var enabledInputs;
		var checkedInputs;

		if (!categoryInput || categoryInput.disabled || !vendorInputs.length) {
			return;
		}

		enabledInputs = vendorInputs.filter(function (input) {
			return !input.disabled;
		});

		if (!enabledInputs.length) {
			categoryInput.checked = false;
			categoryInput.indeterminate = false;
			return;
		}

		checkedInputs = enabledInputs.filter(function (input) {
			return input.checked;
		});

		categoryInput.checked = checkedInputs.length > 0;
		categoryInput.indeterminate = checkedInputs.length > 0 && checkedInputs.length < enabledInputs.length;
	}

	function syncVendorsFromCategory(root, category, checked) {
		getVendorInputs(root, category).forEach(function (input) {
			if (!input.disabled) {
				input.checked = checked;
			}
		});

		updateCategoryState(root, category);
	}

	function collectPreferences(root) {
		var categories = {};
		var vendors = {};

		if (!root) {
			return {
				categories: categories,
				vendors: vendors
			};
		}

		root.querySelectorAll('[data-feuer-cookie-category]').forEach(function (input) {
			categories[input.getAttribute('data-feuer-cookie-category')] = !!input.checked;
		});

		root.querySelectorAll('[data-feuer-cookie-vendor]').forEach(function (input) {
			vendors[input.getAttribute('data-feuer-cookie-vendor')] = !!input.checked;
		});

		return {
			categories: categories,
			vendors: vendors
		};
	}

	function setButtonsDisabled(root, disabled) {
		if (root) {
			root.querySelectorAll('[data-feuer-cookie-decision]').forEach(function (button) {
				button.disabled = !!disabled;
			});
		}

		document.querySelectorAll('[data-feuer-cookie-allow-service]').forEach(function (button) {
			button.disabled = !!disabled;
		});
	}

	function syncLocalStorage(record) {
		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.localStorageKey || !window.localStorage) {
			return;
		}

		try {
			window.localStorage.setItem(FeuerCookieData.localStorageKey, JSON.stringify(record || {}));
		} catch (error) {
			/* noop */
		}
	}

	function clearLocalStorage() {
		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.localStorageKey || !window.localStorage) {
			return;
		}

		try {
			window.localStorage.removeItem(FeuerCookieData.localStorageKey);
		} catch (error) {
			/* noop */
		}
	}

	function updateGtmConsent(consent) {
		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.gtmConsentMode || !consent) {
			return;
		}

		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(['consent', 'update', consent]);
	}

	function postDecision(decision, root, extraData) {
		var payload;
		var extra = extraData || {};

		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.ajaxUrl) {
			return Promise.resolve();
		}

		payload = new URLSearchParams();
		payload.set('action', 'feuer_cookie_set_consent');
		payload.set('nonce', FeuerCookieData.nonce);
		payload.set('decision', decision);
		payload.set('current_url', window.location.href || FeuerCookieData.currentUrl || '/');
		payload.set('page_title', document.title || '');
		payload.set('referrer_url', document.referrer || '');

		if (extra.service) {
			payload.set('service', extra.service);
		}

		if (!extra.skipPreferences) {
			payload.set('preferences', JSON.stringify(collectPreferences(root)));
		}

		setButtonsDisabled(root, true);

		return fetch(FeuerCookieData.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: payload.toString(),
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				var redirect;

				setButtonsDisabled(root, false);

				if (!response || !response.success || !response.data) {
					window.location.reload();
					return;
				}

				if (response.data.clearClientStorage) {
					clearLocalStorage();
				} else {
					syncLocalStorage(response.data.consentRecord || {});
				}

				updateGtmConsent(response.data.gtmConsent || FeuerCookieData.gtmConsent);

				if (response.data.demoMode) {
					return;
				}

				redirect = response.data.redirect || window.location.href;
				window.location.href = redirect;
			})
			.catch(function () {
				setButtonsDisabled(root, false);
				window.location.reload();
			});
	}

	function bindDecisionButtons() {
		document.querySelectorAll('[data-feuer-cookie-decision]').forEach(function (button) {
			button.addEventListener('click', function () {
				var root = getConsentRoot(button);
				postDecision(button.getAttribute('data-feuer-cookie-decision'), root);
			});
		});
	}

	function bindConsentMatrix(root) {
		if (!root) {
			return;
		}

		root.querySelectorAll('[data-feuer-cookie-category]').forEach(function (input) {
			var category = input.getAttribute('data-feuer-cookie-category');

			updateCategoryState(root, category);

			input.addEventListener('change', function () {
				syncVendorsFromCategory(root, category, input.checked);
			});
		});

		root.querySelectorAll('[data-feuer-cookie-vendor]').forEach(function (input) {
			input.addEventListener('change', function () {
				updateCategoryState(root, input.getAttribute('data-feuer-cookie-category-parent'));
			});
		});
	}

	function setCategoryDetailsState(button, details, expanded) {
		var openLabel;
		var closedLabel;

		if (!button || !details) {
			return;
		}

		openLabel = button.getAttribute('data-feuer-cookie-label-open') || 'Dienste ausblenden';
		closedLabel = button.getAttribute('data-feuer-cookie-label-closed') || 'Dienste anzeigen';

		if (expanded) {
			details.removeAttribute('hidden');
			button.setAttribute('aria-expanded', 'true');
			button.textContent = openLabel;
			return;
		}

		details.setAttribute('hidden', 'hidden');
		button.setAttribute('aria-expanded', 'false');
		button.textContent = closedLabel;
	}

	function bindCategoryDetails(root) {
		if (!root) {
			return;
		}

		root.querySelectorAll('[data-feuer-cookie-category-toggle]').forEach(function (button) {
			var category = button.getAttribute('data-feuer-cookie-category-toggle');
			var details = root.querySelector('[data-feuer-cookie-category-details="' + category + '"]');

			if (!details) {
				return;
			}

			setCategoryDetailsState(button, details, !details.hasAttribute('hidden'));

			button.addEventListener('click', function () {
				var shouldExpand = details.hasAttribute('hidden');

				root.querySelectorAll('[data-feuer-cookie-category-toggle]').forEach(function (otherButton) {
					var otherCategory = otherButton.getAttribute('data-feuer-cookie-category-toggle');
					var otherDetails;

					if (otherButton === button) {
						return;
					}

					otherDetails = root.querySelector('[data-feuer-cookie-category-details="' + otherCategory + '"]');

					if (otherDetails) {
						setCategoryDetailsState(otherButton, otherDetails, false);
					}
				});

				setCategoryDetailsState(button, details, shouldExpand);
			});
		});
	}

	function setElementVisibility(element, visible) {
		if (!element) {
			return false;
		}

		if (visible) {
			element.removeAttribute('hidden');
			return true;
		}

		element.setAttribute('hidden', 'hidden');
		return false;
	}

	function toggleElementVisibility(element) {
		if (!element) {
			return false;
		}

		return setElementVisibility(element, element.hasAttribute('hidden'));
	}

	function openReopenPanels() {
		document.querySelectorAll('[data-feuer-cookie-panel]').forEach(function (panel) {
			var openButton = panel.querySelector('[data-feuer-cookie-open]');
			var popup = panel.querySelector('.feuer-cookie-reopen__panel');

			if (!openButton || !popup) {
				return;
			}

			setElementVisibility(popup, true);
			openButton.setAttribute('aria-expanded', 'true');
		});
	}

	function bindSettingsToggles() {
		document.querySelectorAll('[data-feuer-cookie-toggle-settings]').forEach(function (button) {
			button.addEventListener('click', function (event) {
				var bannerPanel = document.querySelector('[data-feuer-cookie-banner-settings]');
				var expanded;

				if (bannerPanel) {
					expanded = toggleElementVisibility(bannerPanel);
					button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
					return;
				}

				event.preventDefault();

				window.setTimeout(function () {
					openReopenPanels();
				}, 0);
			});
		});
	}

	function bindAllowServiceButtons() {
		document.querySelectorAll('[data-feuer-cookie-allow-service]').forEach(function (button) {
			button.addEventListener('click', function () {
				var root = getConsentRoot(button);
				var service = button.getAttribute('data-feuer-cookie-allow-service');

				if (!service) {
					return;
				}

				postDecision('allow_service', root, {
					service: service,
					skipPreferences: true
				});
			});
		});
	}

	function bindReopenPanel() {
		document.querySelectorAll('[data-feuer-cookie-panel]').forEach(function (panel) {
			var openButton = panel.querySelector('[data-feuer-cookie-open]');
			var popup = panel.querySelector('.feuer-cookie-reopen__panel');
			var expanded = false;

			if (!openButton || !popup) {
				return;
			}

			openButton.setAttribute('aria-expanded', 'false');

			openButton.addEventListener('click', function () {
				expanded = toggleElementVisibility(popup);
				openButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			});

			document.addEventListener('click', function (event) {
				if (popup.hasAttribute('hidden')) {
					return;
				}

				if (!panel.contains(event.target)) {
					setElementVisibility(popup, false);
					openButton.setAttribute('aria-expanded', 'false');
				}
			});

			document.addEventListener('keydown', function (event) {
				if ('Escape' !== event.key || popup.hasAttribute('hidden')) {
					return;
				}

				setElementVisibility(popup, false);
				openButton.setAttribute('aria-expanded', 'false');
			});
		});
	}

	function bindEngagementTracking() {
		var trackingAllowed = typeof FeuerCookieData !== 'undefined'
			&& !!FeuerCookieData.statisticsEnabled
			&& !!FeuerCookieData.ajaxUrl
			&& !!FeuerCookieData.statsNonce;
		var startedAt;
		var activeStartedAt;
		var activeDuration = 0;
		var sent = false;
		var maxScrollDepth = 0;

		if (!trackingAllowed) {
			return;
		}

		startedAt = Date.now();
		activeStartedAt = document.hidden ? 0 : startedAt;

		function updateScrollDepth() {
			var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
			var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
			var documentHeight = Math.max(
				document.body ? document.body.scrollHeight : 0,
				document.documentElement ? document.documentElement.scrollHeight : 0,
				1
			);
			var depth = Math.round(((scrollTop + viewportHeight) / documentHeight) * 100);

			maxScrollDepth = Math.max(maxScrollDepth, Math.min(depth, 100));
		}

		function pauseActiveTimer() {
			if (!activeStartedAt) {
				return;
			}

			activeDuration += Math.max(0, Date.now() - activeStartedAt);
			activeStartedAt = 0;
		}

		function resumeActiveTimer() {
			if (document.hidden || activeStartedAt) {
				return;
			}

			activeStartedAt = Date.now();
		}

		function transmitEngagement(reason) {
			var payload;
			var body;
			var durationSeconds;
			var activeSeconds;

			if (sent) {
				return;
			}

			updateScrollDepth();
			pauseActiveTimer();

			durationSeconds = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
			activeSeconds = Math.min(durationSeconds, Math.max(0, Math.round(activeDuration / 1000)));

			payload = new URLSearchParams();
			payload.set('action', 'feuer_cookie_track_engagement');
			payload.set('nonce', FeuerCookieData.statsNonce);
			payload.set('current_url', window.location.href || FeuerCookieData.currentUrl || '/');
			payload.set('page_title', document.title || '');
			payload.set('referrer_url', document.referrer || '');
			payload.set('duration', String(durationSeconds));
			payload.set('active_duration', String(activeSeconds));
			payload.set('scroll_depth', String(maxScrollDepth));
			payload.set('screen_width', String(window.screen && window.screen.width ? window.screen.width : window.innerWidth || 0));
			payload.set('screen_height', String(window.screen && window.screen.height ? window.screen.height : window.innerHeight || 0));
			payload.set('viewport_width', String(window.innerWidth || 0));
			payload.set('viewport_height', String(window.innerHeight || 0));
			payload.set('pixel_ratio', String(window.devicePixelRatio || 1));
			payload.set('reason', reason || 'pagehide');

			sent = true;

			if (navigator.sendBeacon) {
				body = new Blob(
					[payload.toString()],
					{ type: 'application/x-www-form-urlencoded; charset=UTF-8' }
				);
				navigator.sendBeacon(FeuerCookieData.ajaxUrl, body);
				return;
			}

			fetch(FeuerCookieData.ajaxUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: payload.toString(),
				credentials: 'same-origin',
				keepalive: true
			}).catch(function () {
				/* noop */
			});
		}

		window.addEventListener('scroll', updateScrollDepth, { passive: true });
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				pauseActiveTimer();
				return;
			}

			resumeActiveTimer();
		});
		window.addEventListener('focus', resumeActiveTimer);
		window.addEventListener('blur', pauseActiveTimer);
		window.addEventListener('pagehide', function () {
			transmitEngagement('pagehide');
		});
		window.addEventListener('beforeunload', function () {
			transmitEngagement('beforeunload');
		});
		updateScrollDepth();
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof FeuerCookieData !== 'undefined' && FeuerCookieData.demoMode) {
			clearLocalStorage();
		}

		document.querySelectorAll('[data-feuer-cookie-consent]').forEach(function (root) {
			bindConsentMatrix(root);
			bindCategoryDetails(root);
		});

		bindDecisionButtons();
		bindSettingsToggles();
		bindAllowServiceButtons();
		bindReopenPanel();
		bindEngagementTracking();

		if (typeof FeuerCookieData !== 'undefined' && FeuerCookieData.consentRecord) {
			syncLocalStorage(FeuerCookieData.consentRecord);
		}
	});
}());
