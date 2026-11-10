/**
 * DW Wholesale — visitor behaviour (no dependencies).
 *
 * The catalog works as plain links and GET forms. This upgrades it:
 *  - filter / sort / per-page / view / tab / page changes fetch the same page and swap only the
 *    catalog block, keeping the URL in sync (history.pushState) — back/forward and sharing work;
 *  - an item opens in a side drawer over the list (its URL is the item's own page, so a reload
 *    or a shared link shows the full item page);
 *  - small helpers: filter panel on phones, "Show all" choices, gallery thumbs, copy button.
 * Any failure falls back to a normal page load.
 */
(function () {
	'use strict';

	var ROOT = '[data-dwcat-catalog]';
	var controller = null;
	var searchTimer = null;
	var drawer = null;
	var lastFocus = null;
	var skipPop = false;

	function getRoot() {
		return document.querySelector(ROOT);
	}

	function fetchDoc(url) {
		if (controller) {
			controller.abort();
		}
		controller = window.AbortController ? new AbortController() : null;
		return fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.text();
			})
			.then(function (html) {
				return new DOMParser().parseFromString(html, 'text/html');
			});
	}

	// ── List: swap the catalog block ───────────────────────────

	function formUrl(form) {
		var params = new URLSearchParams();
		new FormData(form).forEach(function (value, key) {
			if (String(value).trim() !== '') {
				params.append(key, value);
			}
		});
		// Controls outside the form (form="…") that still hold their default: keep URLs short
		Array.prototype.forEach.call(document.querySelectorAll('[form="' + form.id + '"][data-default]'), function (el) {
			if (params.get(el.name) === el.getAttribute('data-default')) {
				params.delete(el.name);
			}
		});
		var qs = params.toString();
		return form.action.split('?')[0] + (qs ? '?' + qs : '');
	}

	function load(url, push) {
		var root = getRoot();
		if (!root || !window.fetch || !window.DOMParser) {
			window.location.href = url;
			return;
		}
		var active = document.activeElement;
		var keepSearch = active && active.id === 'dwcat-search';
		var caret = keepSearch ? active.selectionStart : 0;
		root.classList.add('is-loading');

		fetchDoc(url).then(function (doc) {
			var next = doc.querySelector(ROOT);
			if (!next) {
				window.location.href = url;
				return;
			}
			root.innerHTML = next.innerHTML;
			adoptDrawer(root);
			root.classList.remove('is-loading');
			if (doc.title) {
				document.title = doc.title;
			}
			if (push) {
				window.history.pushState({ dwcat: 'list' }, '', url);
			}
			if (keepSearch) {
				var input = document.getElementById('dwcat-search');
				if (input) {
					input.focus();
					input.setSelectionRange(caret, caret);
				}
			} else {
				var top = root.getBoundingClientRect().top;
				if (top < 0) {
					window.scrollBy({ top: top - 24, behavior: 'smooth' });
				}
			}
		}).catch(function (err) {
			if (err && err.name === 'AbortError') {
				return;
			}
			window.location.href = url;
		});
	}

	// ── Drawer ─────────────────────────────────────────────────

	/** The drawer lives under <body> so it covers the viewport (the catalog root is a CSS container). */
	function adoptDrawer(root) {
		var fresh = root.querySelector('[data-dwcat-drawer]');
		if (!fresh) {
			return;
		}
		if (drawer && drawer.parentNode) {
			fresh.parentNode.removeChild(fresh);
			return;
		}
		fresh.setAttribute('style', root.getAttribute('style') || '');
		document.body.appendChild(fresh);
		drawer = fresh;
	}

	function openDrawer(url, push) {
		var root = getRoot();
		if (!drawer || !root || !window.fetch) {
			window.location.href = url;
			return;
		}
		var body = drawer.querySelector('[data-dwcat-drawer-body]');
		var kicker = drawer.querySelector('[data-dwcat-drawer-kicker]');
		lastFocus = document.activeElement;
		body.innerHTML = '';
		kicker.textContent = '';
		drawer.hidden = false;
		document.documentElement.classList.add('dwcat-lock');
		drawer.querySelector('button[data-dwcat-close]').focus();

		fetchDoc(url).then(function (doc) {
			var detail = doc.querySelector(ROOT + ' .dwcat-detail');
			if (!detail) {
				window.location.href = url;
				return;
			}
			body.appendChild(document.importNode(detail, true));
			kicker.textContent = detail.getAttribute('data-dwcat-kicker') || '';
			body.scrollTop = 0;
			if (push) {
				window.history.pushState({ dwcat: 'drawer' }, '', url);
			}
			if (doc.title) {
				drawer.setAttribute('data-title', document.title);
				document.title = doc.title;
			}
		}).catch(function (err) {
			if (!err || err.name !== 'AbortError') {
				window.location.href = url;
			}
		});
	}

	function closeDrawer(fromHistory) {
		if (!drawer || drawer.hidden) {
			return;
		}
		drawer.hidden = true;
		document.documentElement.classList.remove('dwcat-lock');
		if (drawer.getAttribute('data-title')) {
			document.title = drawer.getAttribute('data-title');
		}
		if (!fromHistory && history.state && history.state.dwcat === 'drawer') {
			skipPop = true;
			history.back(); // the list URL is the entry under the drawer; nothing to reload
		}
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	// ── Events ─────────────────────────────────────────────────

	function inCatalog(el) {
		return el && el.closest && el.closest(ROOT);
	}

	function catalogForm(el) {
		var form = el.form || (el.closest && el.closest('form'));
		return form && form.hasAttribute('data-dwcat-form') ? form : null;
	}

	// Checkboxes and selects (sort, per page) apply at once
	document.addEventListener('change', function (e) {
		var t = e.target;
		if (!inCatalog(t) || !(t.type === 'checkbox' || t.tagName === 'SELECT')) {
			return;
		}
		var form = catalogForm(t);
		if (form) {
			load(formUrl(form), true);
		}
	});

	// Search: submit, or live after a short pause
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (form.hasAttribute && form.hasAttribute('data-dwcat-form')) {
			e.preventDefault();
			clearTimeout(searchTimer);
			load(formUrl(form), true);
		}
	});
	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.id !== 'dwcat-search' || !inCatalog(t)) {
			return;
		}
		clearTimeout(searchTimer);
		searchTimer = setTimeout(function () {
			var form = catalogForm(t);
			if (form) {
				load(formUrl(form), true);
			}
		}, 450);
	});

	document.addEventListener('click', function (e) {
		var t = e.target;

		// Drawer controls (the drawer is outside the catalog root)
		if (drawer && drawer.contains(t)) {
			if (t.closest('[data-dwcat-close]')) {
				closeDrawer(false);
				return;
			}
		}

		// Copy item code
		var copy = t.closest && t.closest('[data-dwcat-copy]');
		if (copy) {
			var text = copy.getAttribute('data-dwcat-copy');
			var label = copy.textContent;
			var done = function () {
				copy.textContent = copy.getAttribute('data-copied') || '✓';
				setTimeout(function () { copy.textContent = label; }, 1500);
			};
			if (navigator.clipboard) {
				navigator.clipboard.writeText(text).then(done);
			}
			return;
		}

		// Gallery thumbnails
		var thumb = t.closest && t.closest('[data-dwcat-thumb]');
		if (thumb) {
			var main = thumb.closest('.dwcat-detail-media').querySelector('[data-dwcat-main] img');
			if (main) {
				main.removeAttribute('srcset');
				main.src = thumb.getAttribute('data-dwcat-thumb');
			}
			Array.prototype.forEach.call(thumb.parentNode.querySelectorAll('[data-dwcat-thumb]'), function (b) {
				b.classList.toggle('is-active', b === thumb);
			});
			return;
		}

		if (!inCatalog(t)) {
			return;
		}

		// Filter panel on phones
		var toggle = t.closest('[data-dwcat-toggle]');
		if (toggle) {
			var sidebar = getRoot().querySelector('[data-dwcat-sidebar]');
			var open = sidebar.classList.toggle('is-open');
			Array.prototype.forEach.call(getRoot().querySelectorAll('.dwcat-filter-toggle'), function (b) {
				b.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			return;
		}

		// "Show all" choices
		var more = t.closest('[data-dwcat-more]');
		if (more) {
			var list = more.previousElementSibling;
			if (list) {
				list.classList.add('is-open');
			}
			more.remove();
			return;
		}

		var link = t.closest('a[href]');
		if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.origin !== window.location.origin) {
			return;
		}
		// Items open in the drawer over the list
		if (link.classList.contains('dwcat-card-link') && drawer) {
			e.preventDefault();
			openDrawer(link.href, true);
			return;
		}
		// Tabs, view, pagination, reset, back
		if (link.hasAttribute('data-dwcat-link')) {
			e.preventDefault();
			load(link.href, true);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && drawer && !drawer.hidden) {
			closeDrawer(false);
		}
	});

	window.addEventListener('popstate', function (e) {
		if (skipPop) {
			skipPop = false;
			return;
		}
		if (!getRoot()) {
			return;
		}
		if (e.state && e.state.dwcat === 'drawer') {
			openDrawer(window.location.href, false);
			return;
		}
		if (drawer && !drawer.hidden) {
			closeDrawer(true); // back from an open item: the list underneath is already right
			return;
		}
		load(window.location.href, false);
	});

	function init() {
		var root = getRoot();
		if (root) {
			adoptDrawer(root);
			if (!history.state) {
				history.replaceState({ dwcat: 'list' }, '', window.location.href);
			}
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
