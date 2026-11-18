(function () {
	'use strict';
	var reopenPanelCount = 0;
	var bodyOverflowBeforeReopen = '';

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

	function getLocalStorage() {
		try {
			return window.localStorage;
		} catch (error) {
			return null;
		}
	}

	function syncLocalStorage(record) {
		var storage = getLocalStorage();

		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.localStorageKey || !storage) {
			return;
		}

		try {
			storage.setItem(FeuerCookieData.localStorageKey, JSON.stringify(record || {}));
		} catch (error) {
			/* noop */
		}
	}

	function clearLocalStorage() {
		var storage = getLocalStorage();

		if (typeof FeuerCookieData === 'undefined' || !FeuerCookieData.localStorageKey || !storage) {
			return;
		}

		try {
			storage.removeItem(FeuerCookieData.localStorageKey);
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
			function submitDecision() {
				if (button.disabled || 'true' === button.getAttribute('data-feuer-cookie-pending')) {
					return;
				}

				button.setAttribute('data-feuer-cookie-pending', 'true');
				var root = getConsentRoot(button);

				postDecision(button.getAttribute('data-feuer-cookie-decision'), root).then(function () {
					button.removeAttribute('data-feuer-cookie-pending');
				});
			}

			button.addEventListener('click', submitDecision);
			button.addEventListener('touchend', function (event) {
				/* Prevent the synthetic click after a tap from posting twice. */
				event.preventDefault();
				submitDecision();
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
			setReopenPanelState(panel, true, false);
		});
	}

	function setReopenPanelState(panel, expanded, restoreFocus) {
		var openButton = panel ? panel.querySelector('[data-feuer-cookie-open]') : null;
		var popup = panel ? panel.querySelector('.feuer-cookie-reopen__panel') : null;
		var closeButton;

		if (!openButton || !popup) {
			return;
		}

		if (expanded && popup.hasAttribute('hidden')) {
			if (0 === reopenPanelCount) {
				bodyOverflowBeforeReopen = document.body.style.overflow;
				document.body.style.overflow = 'hidden';
			}
			reopenPanelCount += 1;
		} else if (!expanded && !popup.hasAttribute('hidden')) {
			reopenPanelCount = Math.max(0, reopenPanelCount - 1);
			if (0 === reopenPanelCount) {
				document.body.style.overflow = bodyOverflowBeforeReopen;
			}
		}

		setElementVisibility(popup, expanded);
		panel.classList.toggle('is-open', !!expanded);
		openButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');

		if (expanded) {
			closeButton = popup.querySelector('[data-feuer-cookie-close]');
			if (closeButton) {
				closeButton.focus();
			}
		} else if (restoreFocus) {
			openButton.focus();
		}
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
				expanded = popup.hasAttribute('hidden');
				setReopenPanelState(panel, expanded, false);
			});

			panel.querySelectorAll('[data-feuer-cookie-close]').forEach(function (closeButton) {
				closeButton.addEventListener('click', function () {
					setReopenPanelState(panel, false, true);
				});
			});

			document.addEventListener('click', function (event) {
				if (popup.hasAttribute('hidden')) {
					return;
				}

				if (!popup.contains(event.target) && event.target !== openButton) {
					setReopenPanelState(panel, false, false);
				}
			});

			document.addEventListener('keydown', function (event) {
				var focusable;
				var first;
				var last;

				if (popup.hasAttribute('hidden')) {
					return;
				}

				if ('Escape' === event.key) {
					setReopenPanelState(panel, false, true);
					return;
				}

				if ('Tab' !== event.key) {
					return;
				}

				focusable = Array.prototype.slice.call(popup.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'));
				first = focusable[0];
				last = focusable[focusable.length - 1];

				if (!first || !last) {
					return;
				}

				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			});
		});
	}

	function bindConsentDialogFocus() {
		var banner = document.querySelector('[data-feuer-cookie-banner]');
		var backdrop = document.querySelector('.feuer-cookie-backdrop');
		var dialog;
		var backgroundElements = [];

		if (!banner) {
			return;
		}

		dialog = banner.querySelector('[role="dialog"]') || banner;
		document.body.style.overflow = 'hidden';
		document.documentElement.style.overflow = 'hidden';
		Array.prototype.forEach.call(document.body.children, function (element) {
			if (element === banner || element === backdrop) {
				return;
			}

			backgroundElements.push({
				element: element,
				inert: element.hasAttribute('inert'),
				ariaHidden: element.hasAttribute('aria-hidden'),
				ariaHiddenValue: element.getAttribute('aria-hidden')
			});
			element.setAttribute('inert', '');
			element.setAttribute('aria-hidden', 'true');
		});

		window.setTimeout(function () {
			var first = dialog.querySelector('[data-feuer-cookie-toggle-settings]') || dialog.querySelector('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])');

			if (first) {
				first.focus();
			}
		}, 0);

		document.addEventListener('keydown', function (event) {
			var focusable;
			var first;
			var last;

			if ('Tab' !== event.key) {
				return;
			}

			focusable = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'));
			first = focusable[0];
			last = focusable[focusable.length - 1];

			if (!dialog.contains(document.activeElement)) {
				event.preventDefault();
				if (first) {
					first.focus();
				}
				return;
			}

			if (!first || !last) {
				return;
			}

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});

		window.addEventListener('pagehide', function () {
			backgroundElements.forEach(function (state) {
				if (!state.inert) {
					state.element.removeAttribute('inert');
				}

				if (state.ariaHidden) {
					state.element.setAttribute('aria-hidden', state.ariaHiddenValue);
				} else {
					state.element.removeAttribute('aria-hidden');
				}
			});
		});
	}

	function initializeFrontend() {
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
		bindConsentDialogFocus();
		if (typeof FeuerCookieData !== 'undefined' && FeuerCookieData.consentRecord) {
			syncLocalStorage(FeuerCookieData.consentRecord);
		}
	}

	/*
	 * Optimisation plugins may defer this file until after DOMContentLoaded. In
	 * that case, registering only an event listener leaves every consent button
	 * without a handler. Initialise immediately when the DOM is already ready.
	 */
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initializeFrontend, { once: true });
	} else {
		initializeFrontend();
	}
}());
