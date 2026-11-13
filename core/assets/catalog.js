/**
 * DW Wholesale — visitor behaviour (no dependencies).
 *
 * The catalog works as plain links and GET forms. This upgrades it:
 *  - filter / sort / per-page / view / tab / page changes fetch the same page and swap only the
 *    catalog block, keeping the URL in sync (history.pushState) — back/forward and sharing work;
 *  - an item opens in a side drawer over the list (its URL is the item's own page, so a reload
 *    or a shared link shows the full item page);
 *  - on tablets and phones the filters open as a sheet over the page (side / bottom);
 *  - tablets and phones first show the number of products set for them; "Load more" shows the
 *    rest and then appends the next page;
 *  - small helpers: search inside long filter lists, "Show all" choices, gallery thumbs, copy button.
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
	var sheet = null;
	var bucket = '';

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
			if (sheet && !sheet.hidden) {
				fillSheet(root); // filtering inside the open sheet: show the new filters there
			}
			bucket = '';
			applyLimit(root);
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

	// ── Filter sheet (tablets / phones) ────────────────────────

	/** Move the root's filter sidebar into the sheet (the sheet lives under <body>). */
	function fillSheet(root) {
		var aside = root.querySelector('[data-dwcat-sidebar]');
		var panel = sheet.querySelector('.dwcat-sheet-panel');
		var old = panel.querySelector('[data-dwcat-sidebar]');
		if (!aside) {
			return;
		}
		if (old) {
			old.parentNode.removeChild(old);
		}
		panel.insertBefore(aside, panel.querySelector('.dwcat-sheet-done'));
	}

	function openSheet() {
		var root = getRoot();
		if (!root) {
			return;
		}
		if (!sheet) {
			sheet = document.createElement('div');
			sheet.className = 'dwcat-sheet';
			sheet.innerHTML = '<div class="dwcat-sheet-backdrop" data-dwcat-sheet-close></div><div class="dwcat-sheet-panel" role="dialog" aria-modal="true"><button type="button" class="dwcat-btn dwcat-btn-primary dwcat-btn-block dwcat-sheet-done" data-dwcat-sheet-close></button></div>';
			document.body.appendChild(sheet);
		}
		var done = sheet.querySelector('.dwcat-sheet-done');
		done.textContent = root.getAttribute('data-dwcat-done') || '✓';
		sheet.setAttribute('style', root.getAttribute('style') || '');
		fillSheet(root);
		var head = sheet.querySelector('.dwcat-sheet-head strong');
		sheet.querySelector('.dwcat-sheet-panel').setAttribute('aria-label', head ? head.textContent : '');
		sheet.hidden = false;
		document.documentElement.classList.add('dwcat-lock');
		lastFocus = document.activeElement;
		var first = sheet.querySelector('input, button, summary');
		if (first) {
			first.focus();
		}
		setToggles(true);
	}

	function closeSheet() {
		if (!sheet || sheet.hidden) {
			return;
		}
		var root = getRoot();
		var aside = sheet.querySelector('[data-dwcat-sidebar]');
		var layout = root && root.querySelector('.dwcat-layout');
		if (aside && layout) {
			layout.insertBefore(aside, layout.firstChild); // back in the page, so a swap replaces it
		}
		sheet.hidden = true;
		document.documentElement.classList.remove('dwcat-lock');
		setToggles(false);
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	function setToggles(open) {
		var root = getRoot();
		if (root) {
			Array.prototype.forEach.call(root.querySelectorAll('.dwcat-filter-toggle'), function (b) {
				b.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}
	}

	// ── Products per device + "Load more" ──────────────────────

	/** How many products this screen shows at first (0 = all): from the catalog's own width. */
	function perDevice(root, grid) {
		var w = root.getBoundingClientRect().width;
		if (w <= 560) {
			return parseInt(grid.getAttribute('data-per-mobile'), 10) || 0;
		}
		if (w <= 860) {
			return parseInt(grid.getAttribute('data-per-tablet'), 10) || 0;
		}
		return 0;
	}

	function applyLimit(root) {
		var grid = root && root.querySelector('[data-dwcat-grid]');
		if (!grid) {
			return;
		}
		var per = perDevice(root, grid);
		var key = String(per);
		if (key === bucket) {
			return;
		}
		bucket = key;
		Array.prototype.forEach.call(grid.querySelectorAll('.dwcat-card'), function (card, i) {
			card.classList.toggle('is-extra', per > 0 && i >= per);
		});
		syncMore(root);
	}

	function syncMore(root) {
		var grid = root.querySelector('[data-dwcat-grid]');
		root.classList.toggle('has-extra', !!(grid && grid.querySelector('.is-extra')));
	}

	function loadMore(btn) {
		var root = getRoot();
		var grid = root && root.querySelector('[data-dwcat-grid]');
		if (!grid) {
			window.location.href = btn.href;
			return;
		}
		var per = perDevice(root, grid) || 24;
		var reveal = function () {
			var hidden = grid.querySelectorAll('.is-extra');
			for (var i = 0; i < hidden.length && i < per; i++) {
				hidden[i].classList.remove('is-extra');
			}
			syncMore(root);
		};
		if (grid.querySelector('.is-extra')) {
			reveal();
			if (!grid.querySelector('.is-extra') && btn.hasAttribute('data-last')) {
				btn.parentNode.removeChild(btn); // everything is shown
			}
			return;
		}
		if (btn.hasAttribute('data-last')) {
			btn.parentNode.removeChild(btn);
			return;
		}
		btn.classList.add('is-loading');
		fetchDoc(btn.href).then(function (doc) {
			var nextGrid = doc.querySelector(ROOT + ' [data-dwcat-grid]');
			if (!nextGrid) {
				window.location.href = btn.href;
				return;
			}
			Array.prototype.forEach.call(nextGrid.querySelectorAll('.dwcat-card'), function (card) {
				var node = document.importNode(card, true);
				node.classList.add('is-extra');
				grid.appendChild(node);
			});
			var nextBtn = doc.querySelector(ROOT + ' [data-dwcat-more-page]');
			if (nextBtn && !nextBtn.hasAttribute('data-last')) {
				btn.href = nextBtn.getAttribute('href');
			} else {
				btn.setAttribute('data-last', ''); // reveals what was appended, then goes away
			}
			var count = doc.querySelector(ROOT + ' .dwcat-pages');
			var pages = root.querySelector('.dwcat-pages');
			if (count && pages) {
				pages.innerHTML = count.innerHTML;
			}
			btn.classList.remove('is-loading');
			reveal();
			if (btn.hasAttribute('data-last') && !grid.querySelector('.is-extra') && btn.parentNode) {
				btn.parentNode.removeChild(btn);
			}
		}).catch(function (err) {
			if (!err || err.name !== 'AbortError') {
				window.location.href = btn.href;
			}
		});
	}

	// ── Events ─────────────────────────────────────────────────

	function inCatalog(el) {
		return el && el.closest && (el.closest(ROOT) || el.closest('.dwcat-sheet'));
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
	// Search inside a long filter list (categories, brands)
	document.addEventListener('input', function (e) {
		var t = e.target;
		if (!t.matches || !t.matches('[data-dwcat-listsearch]')) {
			return;
		}
		var q = t.value.trim().toLowerCase();
		var box = t.closest('.dwcat-panel').querySelector('[data-dwcat-searchable]');
		if (!box) {
			return;
		}
		Array.prototype.forEach.call(box.querySelectorAll('li'), function (li) {
			var text = (li.textContent || '').toLowerCase();
			li.classList.toggle('is-filtered-out', q !== '' && text.indexOf(q) === -1);
		});
		Array.prototype.forEach.call(box.querySelectorAll('.dwcat-group'), function (g) {
			var any = g.querySelector('li:not(.is-filtered-out)');
			var self = (g.querySelector('summary').textContent || '').toLowerCase().indexOf(q) !== -1;
			g.classList.toggle('is-filtered-out', q !== '' && !any && !self);
			if (q !== '' && any) {
				g.open = true;
			}
		});
		var list = box.classList.contains('is-collapsible') ? box : null;
		if (list && q !== '') {
			list.classList.add('is-open'); // searching looks through every choice
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-dwcat-listsearch]')) {
			e.preventDefault();
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

		// Filter sheet: backdrop / done / close
		if (sheet && sheet.contains(t) && t.closest('[data-dwcat-sheet-close]')) {
			closeSheet();
			return;
		}

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

		// Filters on tablets / phones: a sheet over the page
		var toggle = t.closest('[data-dwcat-toggle]');
		if (toggle) {
			if (sheet && !sheet.hidden) {
				closeSheet();
			} else {
				openSheet();
			}
			return;
		}

		// "Load more"
		var more = t.closest('[data-dwcat-more-page]');
		if (more && !e.metaKey && !e.ctrlKey) {
			e.preventDefault();
			loadMore(more);
			return;
		}

		// "Show all" choices
		var all = t.closest('[data-dwcat-more]');
		if (all) {
			var list = all.previousElementSibling;
			if (list) {
				list.classList.add('is-open');
			}
			all.remove();
			return;
		}

		var link = t.closest('a[href]');
		if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.origin !== window.location.origin) {
			return;
		}
		// Items open in the drawer over the list
		var catalogRoot = getRoot();
		if (link.classList.contains('dwcat-card-link') && drawer && !(catalogRoot && catalogRoot.getAttribute('data-dwcat-click') === 'page')) {
			e.preventDefault();
			openDrawer(link.href, true);
			return;
		}
		// Tabs, view, pagination, reset, back
		if (link.hasAttribute('data-dwcat-link')) {
			e.preventDefault();
			if (sheet && !sheet.hidden && !sheet.contains(link)) {
				closeSheet();
			}
			load(link.href, true);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && drawer && !drawer.hidden) {
			closeDrawer(false);
		} else if (e.key === 'Escape' && sheet && !sheet.hidden) {
			closeSheet();
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
			root.classList.add('dwcat-js');
			adoptDrawer(root);
			applyLimit(root);
			var timer = null;
			window.addEventListener('resize', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					var r = getRoot();
					if (r) {
						applyLimit(r);
						var wide = r.getBoundingClientRect().width > 860;
						if (wide && sheet && !sheet.hidden) {
							closeSheet(); // back to the sidebar
						}
					}
				}, 150);
			});
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
