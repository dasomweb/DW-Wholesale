/**
 * DW Wholesale — admin UI behaviour (no dependencies).
 *
 * data-dwc-add="x"        clone <template data-dwc-template="x"> into table[data-dwc-rows="x"]
 * data-dwc-remove         remove the row
 * data-dwc-up / -down     move the row (or image) up / down — form order = saved order
 * data-dwc-checkall       toggle all row checkboxes (selection bar shows the count)
 * data-dwc-autosubmit     GET filter form: a changed select applies at once
 * data-dwc-confirm="msg"  confirm before submitting
 * data-dwc-preset="g.k"   business-type preset: switch field + variation column set
 */
(function () {
	'use strict';

	var seq = 0;

	// Translated strings printed by the server on .dwc-admin[data-dwc-i18n]; %s / %1$s placeholders.
	var strings = {};
	(function () {
		var root = document.querySelector('.dwc-admin[data-dwc-i18n]');
		try {
			strings = root ? JSON.parse(root.getAttribute('data-dwc-i18n')) || {} : {};
		} catch (e) {
			strings = {};
		}
	})();
	function T(text) {
		var out = strings[text] || text;
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return out.replace(/%(?:(\d+)\$)?[sd]/g, function (m, n) {
			var v = n ? args[parseInt(n, 10) - 1] : args[i++];
			return v === undefined ? m : String(v);
		});
	}

	// View-only screens: the server refuses every change; lock the form controls so it is obvious.
	document.querySelectorAll('.dwc-admin.is-readonly form[method=post]').forEach(function (form) {
		if (form.classList.contains('dwc-lang')) {
			return;
		}
		form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
			if (el.type !== 'hidden') {
				el.disabled = true;
			}
		});
	});

	function rowsBody(name) {
		var table = document.querySelector('[data-dwc-rows="' + name + '"]');
		return table ? table.tBodies[0] : null;
	}

	function newRow(name) {
		var tpl = document.querySelector('template[data-dwc-template="' + name + '"]');
		if (!tpl) {
			return null;
		}
		var html = tpl.innerHTML.replace(/__i__/g, 'n' + Date.now().toString(36) + (seq++));
		var holder = document.createElement('tbody');
		holder.innerHTML = html.trim();
		return holder.firstElementChild;
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest('[data-dwc-add],[data-dwc-remove],[data-dwc-up],[data-dwc-down],[data-dwc-preset]');
		if (!t) {
			return;
		}
		if (t.hasAttribute('data-dwc-add')) {
			var name = t.getAttribute('data-dwc-add');
			var row = newRow(name);
			var body = rowsBody(name);
			if (row && body) {
				body.appendChild(row);
				var first = row.querySelector('input[type=text],input:not([type]),select');
				if (first) {
					first.focus();
				}
			}
		} else if (t.hasAttribute('data-dwc-remove')) {
			t.closest('tr').remove();
		} else if (t.hasAttribute('data-dwc-up') || t.hasAttribute('data-dwc-down')) {
			var item = t.closest('tr, li');
			if (t.hasAttribute('data-dwc-up') && item.previousElementSibling) {
				item.parentNode.insertBefore(item, item.previousElementSibling);
			} else if (t.hasAttribute('data-dwc-down') && item.nextElementSibling) {
				item.parentNode.insertBefore(item.nextElementSibling, item);
			}
		} else if (t.hasAttribute('data-dwc-preset')) {
			applyPreset(t);
		}
	});

	// ── Item list: instant filters, selection bar, row menus ───

	document.querySelectorAll('.dwc-admin').forEach(function (el) { el.classList.add('dwc-js'); });

	function syncSelection(form) {
		var boxes = form.querySelectorAll('input[name="ids[]"]');
		var n = 0;
		boxes.forEach(function (cb) {
			var tr = cb.closest('tr');
			if (tr) {
				tr.classList.toggle('is-selected', cb.checked);
			}
			n += cb.checked ? 1 : 0;
		});
		var bar = form.querySelector('[data-dwc-selbar]');
		if (bar) {
			bar.hidden = n === 0;
			bar.querySelector('[data-dwc-selcount]').textContent = n;
		}
		var all = form.querySelector('[data-dwc-checkall]');
		if (all) {
			all.checked = n > 0 && n === boxes.length;
			all.indeterminate = n > 0 && n < boxes.length;
		}
	}

	document.addEventListener('change', function (e) {
		var t = e.target;
		if (t.matches('[data-dwc-checkall]')) {
			t.closest('form').querySelectorAll('input[name="ids[]"]').forEach(function (cb) {
				cb.checked = t.checked;
			});
			syncSelection(t.closest('form'));
		} else if (t.matches('input[name="ids[]"]') && t.closest('[data-dwc-bulk]')) {
			syncSelection(t.closest('form'));
		} else if (t.matches('[data-dwc-move]') && t.value) {
			var go = t.form.querySelector('[data-dwc-move-submit]');
			if (go) {
				t.form.requestSubmit ? t.form.requestSubmit(go) : go.click();
			}
		} else if (t.tagName === 'SELECT' && t.form && t.form.hasAttribute('data-dwc-autosubmit')) {
			t.form.submit(); // filters apply at once (page resets: paged is not part of the form)
		}
	});

	document.addEventListener('click', function (e) {
		var clear = e.target.closest('[data-dwc-clearsel]');
		if (clear) {
			var form = clear.closest('form');
			form.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = false; });
			syncSelection(form);
		}
		// One row menu open at a time; click outside closes
		document.querySelectorAll('.dwc-menu[open]').forEach(function (d) {
			if (!d.contains(e.target)) {
				d.removeAttribute('open');
			}
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			document.querySelectorAll('.dwc-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
		}
	});

	// ── Editor: category finder, drop zone, card preview ───────

	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches('[data-dwc-catfind]')) {
			var q = t.value.trim().toLowerCase();
			var list = t.closest('.dwc-card-body').querySelector('[data-dwc-catlist]');
			// Show a category when it or any child matches
			(function walk(ul) {
				var any = false;
				Array.prototype.forEach.call(ul.children, function (li) {
					var label = li.querySelector(':scope > label');
					var child = li.querySelector(':scope > ul');
					var hit = !q || (label && label.textContent.toLowerCase().indexOf(q) !== -1);
					var sub = child ? walk(child) : false;
					li.classList.toggle('is-hidden', !(hit || sub));
					any = any || hit || sub;
				});
				return any;
			})(list.querySelector('ul'));
		}
		if (t.closest('[data-dwc-editor]')) {
			updatePreview(t.closest('[data-dwc-editor]'));
		}
	});

	function updatePreview(form) {
		var card = document.querySelector('[data-dwc-previewcard]');
		if (!card) {
			return;
		}
		var val = function (role) {
			var el = form.querySelector('[data-dwc-preview="' + role + '"]');
			return el ? el.value.trim() : '';
		};
		var cat = form.querySelector('[data-dwc-preview-cat]:checked');
		var kicker = [cat ? cat.getAttribute('data-dwc-preview-cat') : '', val('brand')].filter(Boolean).join(' · ');
		var meta = [val('code') ? '#' + val('code') : '', val('pack')].filter(Boolean).join(' · ');
		card.querySelector('[data-dwc-out="title"]').textContent = val('title') || '—';
		card.querySelector('[data-dwc-out="kicker"]').textContent = kicker;
		card.querySelector('[data-dwc-out="meta"]').textContent = meta;
	}

	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-dwc-preview-cat]') || e.target.matches('select[data-dwc-preview]')) {
			updatePreview(e.target.closest('[data-dwc-editor]'));
		}
	});

	['dragenter', 'dragover'].forEach(function (ev) {
		document.addEventListener(ev, function (e) {
			var drop = e.target.closest && e.target.closest('[data-dwc-drop]');
			if (drop) {
				drop.classList.add('is-over');
			}
		});
	});
	['dragleave', 'drop'].forEach(function (ev) {
		document.addEventListener(ev, function (e) {
			var drop = e.target.closest && e.target.closest('[data-dwc-drop]');
			if (drop) {
				drop.classList.remove('is-over');
			}
		});
	});

	document.addEventListener('input', function (e) {
		if (e.target.matches('.dwc-color')) {
			var sw = e.target.nextElementSibling;
			if (sw && sw.classList.contains('dwc-swatch')) {
				sw.style.background = e.target.value;
			}
		}
	});

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-dwc-confirm]');
		if (btn && !window.confirm(btn.getAttribute('data-dwc-confirm'))) {
			e.preventDefault();
		}
	}, true);

	// ── Photos: shrink in the browser before uploading ─────────
	//
	// The website never shows more than 1200px (detail page on a 2× PC / 3× phone screen), so a
	// 12 MP phone photo is reduced to MAX_EDGE before it leaves the device: uploads are ~10–20×
	// smaller and faster. The server still re-encodes whatever arrives (security), and if the
	// browser cannot resize, the original is sent and the server shrinks it.

	var MAX_EDGE = 1600;
	var QUALITY = 0.85;

	function loadBitmap(file) {
		if (window.createImageBitmap) {
			return createImageBitmap(file, { imageOrientation: 'from-image' });
		}
		return new Promise(function (resolve, reject) {
			var img = new Image();
			img.onload = function () { resolve(img); };
			img.onerror = reject;
			img.src = URL.createObjectURL(file);
		});
	}

	function encode(canvas, type) {
		return new Promise(function (resolve) {
			canvas.toBlob(resolve, type, QUALITY);
		});
	}

	function shrink(file) {
		if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
			return Promise.resolve(file); // GIF etc.: let the server handle it
		}
		return loadBitmap(file).then(function (bmp) {
			var w = bmp.width;
			var h = bmp.height;
			var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
			if (scale === 1 && file.size <= 1.5 * 1048576) {
				return file; // already web-sized
			}
			var canvas = document.createElement('canvas');
			canvas.width = Math.round(w * scale);
			canvas.height = Math.round(h * scale);
			var ctx = canvas.getContext('2d');
			ctx.imageSmoothingQuality = 'high';
			ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
			return encode(canvas, 'image/webp').then(function (blob) {
				if (blob && blob.type === 'image/webp') {
					return blob;
				}
				// No WebP encoder (older Safari): JPEG on white so transparency does not turn black
				ctx.globalCompositeOperation = 'destination-over';
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				return encode(canvas, 'image/jpeg');
			}).then(function (blob) {
				if (!blob || blob.size >= file.size) {
					return file;
				}
				var ext = blob.type === 'image/webp' ? '.webp' : '.jpg';
				return new File([blob], file.name.replace(/\.[^.]+$/, '') + ext, { type: blob.type, lastModified: Date.now() });
			});
		}).catch(function () {
			return file;
		});
	}

	function mb(bytes) {
		return (bytes / 1048576).toFixed(bytes < 1048576 ? 2 : 1) + ' MB';
	}

	document.addEventListener('change', function (e) {
		var input = e.target;
		if (!input.matches || !input.matches('input[type=file][data-dwc-resize]') || !input.files.length) {
			return;
		}
		if (!window.DataTransfer || !HTMLCanvasElement.prototype.toBlob) {
			return;
		}
		var files = Array.prototype.slice.call(input.files);
		var status = document.querySelector('[data-dwc-resize-status]');
		var buttons = input.form ? input.form.querySelectorAll('button[type=submit]') : [];
		Array.prototype.forEach.call(buttons, function (b) { b.disabled = true; });
		if (status) {
			status.textContent = T('Optimizing photos…');
		}
		Promise.all(files.map(shrink)).then(function (out) {
			var dt = new DataTransfer();
			var before = 0;
			var after = 0;
			out.forEach(function (f, i) {
				dt.items.add(f);
				before += files[i].size;
				after += f.size;
			});
			input.files = dt.files;
			if (status) {
				status.textContent = T('%1$d photo(s): %2$s → %3$s', out.length, mb(before), mb(after));
			}
		}).catch(function () {
			if (status) {
				status.textContent = '';
			}
		}).then(function () {
			Array.prototype.forEach.call(buttons, function (b) { b.disabled = false; });
		});
	});

	// ── Category tree: drag & drop (order + parent) ────────────
	//
	// Drag the ⠿ handle. Over another category: top quarter = before it, bottom quarter = after it,
	// middle = inside it (last child). The arrow buttons do the same from the keyboard.
	// Nothing is saved until "Save order" — the whole tree is sent as [{id, parent}] in order.

	var drag = null;

	function treeRoot() {
		return document.querySelector('[data-dwc-tree]');
	}

	function childList(li, create) {
		var ol = li.querySelector(':scope > ol');
		if (!ol && create) {
			ol = document.createElement('ol');
			ol.className = 'dwc-tree';
			li.appendChild(ol);
		}
		return ol;
	}

	function refreshTree() {
		var root = treeRoot();
		root.querySelectorAll('li').forEach(function (li) {
			var ol = childList(li, false);
			var has = !!(ol && ol.children.length);
			var caret = li.querySelector(':scope > .dwc-node [data-dwc-caret]');
			if (caret) {
				caret.hidden = !has;
			}
			if (!has) {
				li.classList.remove('is-collapsed');
			}
		});
		var nodes = [];
		(function walk(ol, parent) {
			Array.prototype.forEach.call(ol.children, function (li) {
				var id = parseInt(li.getAttribute('data-id'), 10);
				nodes.push({ id: id, parent: parent });
				var sub = childList(li, false);
				if (sub) {
					walk(sub, id);
				}
			});
		})(root, 0);
		var form = document.querySelector('[data-dwc-tree-form]');
		var json = JSON.stringify(nodes);
		if (form.getAttribute('data-initial') === null) {
			form.setAttribute('data-initial', json);
		}
		var dirty = json !== form.getAttribute('data-initial');
		form.querySelector('input[name="tree"]').value = json;
		form.querySelector('[data-dwc-tree-submit]').disabled = !dirty;
		form.querySelector('[data-dwc-tree-undo]').hidden = !dirty;
		form.classList.toggle('is-dirty', dirty);
		var status = form.querySelector('[data-dwc-tree-status]');
		status.textContent = dirty ? status.getAttribute('data-dirty') : '';
	}

	function flash(li) {
		li.classList.remove('is-moved');
		void li.offsetWidth;
		li.classList.add('is-moved');
	}

	function dropSpot(x, y) {
		var el = document.elementFromPoint(x, y);
		var node = el && el.closest && el.closest('.dwc-node');
		if (!node || !treeRoot().contains(node)) {
			return null;
		}
		var li = node.parentNode;
		if (li === drag.li || drag.li.contains(li)) {
			return null; // not into itself
		}
		var r = node.getBoundingClientRect();
		var rel = (y - r.top) / r.height;
		return { li: li, where: rel < 0.25 ? 'before' : rel > 0.75 ? 'after' : 'inside' };
	}

	function placeMarker(spot) {
		document.querySelectorAll('.dwc-node.is-target').forEach(function (n) { n.classList.remove('is-target'); });
		if (!drag) {
			return;
		}
		var m = drag.marker;
		if (!spot || spot.where === 'inside') {
			m.hidden = true;
			if (spot) {
				spot.li.querySelector(':scope > .dwc-node').classList.add('is-target');
			}
			return;
		}
		var r = spot.li.querySelector(':scope > .dwc-node').getBoundingClientRect();
		m.hidden = false;
		m.style.left = r.left + 'px';
		m.style.width = r.width + 'px';
		m.style.top = (spot.where === 'before' ? r.top - 2 : spot.li.getBoundingClientRect().bottom - 1) + 'px';
	}

	function moveGhost(x, y) {
		drag.ghost.style.transform = 'translate(' + (x + 14) + 'px,' + (y + 10) + 'px)';
	}

	document.addEventListener('pointerdown', function (e) {
		var handle = e.target.closest && e.target.closest('[data-dwc-handle]');
		if (!handle || e.button !== 0) {
			return;
		}
		e.preventDefault();
		var li = handle.closest('li');
		var marker = document.createElement('div');
		marker.className = 'dwc-tree-marker';
		marker.hidden = true;
		document.body.appendChild(marker);
		var ghost = document.createElement('div');
		ghost.className = 'dwc-tree-ghost';
		ghost.textContent = li.querySelector('.dwc-node-name').textContent;
		document.body.appendChild(ghost);
		drag = { li: li, marker: marker, ghost: ghost, spot: null, id: e.pointerId };
		handle.setPointerCapture(e.pointerId);
		li.classList.add('is-dragging');
		document.documentElement.classList.add('dwc-dragging');
		moveGhost(e.clientX, e.clientY);
	});

	document.addEventListener('pointermove', function (e) {
		if (!drag || e.pointerId !== drag.id) {
			return;
		}
		moveGhost(e.clientX, e.clientY);
		if (e.clientY < 60) {
			window.scrollBy(0, -12);
		} else if (e.clientY > window.innerHeight - 60) {
			window.scrollBy(0, 12);
		}
		drag.spot = dropSpot(e.clientX, e.clientY);
		placeMarker(drag.spot);
	});

	function endDrag(apply) {
		var d = drag;
		placeMarker(null);
		drag = null;
		d.marker.remove();
		d.ghost.remove();
		d.li.classList.remove('is-dragging');
		document.documentElement.classList.remove('dwc-dragging');
		if (!apply || !d.spot) {
			return;
		}
		var t = d.spot.li;
		if (d.spot.where === 'inside') {
			childList(t, true).appendChild(d.li);
			t.classList.remove('is-collapsed');
		} else {
			t.parentNode.insertBefore(d.li, d.spot.where === 'before' ? t : t.nextSibling);
		}
		refreshTree();
		flash(d.li);
	}

	document.addEventListener('pointerup', function (e) {
		if (drag && e.pointerId === drag.id) {
			endDrag(true);
		}
	});
	document.addEventListener('pointercancel', function (e) {
		if (drag && e.pointerId === drag.id) {
			endDrag(false);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (drag && e.key === 'Escape') {
			endDrag(false);
		}
	});

	document.addEventListener('click', function (e) {
		var t = e.target;
		var key = t.closest('[data-dwc-key]');
		if (key) {
			var li = key.closest('li');
			var how = key.getAttribute('data-dwc-key');
			var prev = li.previousElementSibling;
			var next = li.nextElementSibling;
			if (how === 'up' && prev) {
				li.parentNode.insertBefore(li, prev);
			} else if (how === 'down' && next) {
				li.parentNode.insertBefore(next, li);
			} else if (how === 'out' && li.parentNode !== treeRoot()) {
				var parentLi = li.parentNode.closest('li');
				parentLi.parentNode.insertBefore(li, parentLi.nextSibling);
			} else if (how === 'in' && prev) {
				childList(prev, true).appendChild(li);
				prev.classList.remove('is-collapsed');
			} else {
				return;
			}
			refreshTree();
			flash(li);
			key.focus();
			return;
		}
		var caret = t.closest('[data-dwc-caret]');
		if (caret) {
			var closed = caret.closest('li').classList.toggle('is-collapsed');
			caret.setAttribute('aria-expanded', closed ? 'false' : 'true');
			return;
		}
		var all = t.closest('[data-dwc-tree-all]');
		if (all) {
			var close = all.getAttribute('data-dwc-tree-all') === 'close';
			treeRoot().querySelectorAll('li').forEach(function (li) {
				var ol = childList(li, false);
				li.classList.toggle('is-collapsed', close && !!(ol && ol.children.length));
			});
		}
	});

	window.addEventListener('beforeunload', function (e) {
		var form = document.querySelector('[data-dwc-tree-form].is-dirty');
		if (form && !form.hasAttribute('data-submitting')) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
	document.addEventListener('submit', function (e) {
		if (e.target.matches('[data-dwc-tree-form]')) {
			e.target.setAttribute('data-submitting', '1');
		}
	});

	if (treeRoot()) {
		refreshTree();
	}

	// ── Presets ────────────────────────────────────────────────

	var colsSnapshot = null;

	function keysIn(name, input) {
		var body = rowsBody(name);
		if (!body) {
			return [];
		}
		return Array.prototype.map.call(body.querySelectorAll('input[name$="[' + input + ']"]'), function (i) {
			return i.value.trim();
		}).filter(Boolean);
	}

	function fill(row, values) {
		Object.keys(values).forEach(function (k) {
			var el = row.querySelector('[name$="[' + k + ']"]:not([type=hidden])');
			if (!el) {
				return;
			}
			if (el.type === 'checkbox') {
				el.checked = !!values[k];
			} else {
				el.value = values[k];
			}
		});
		row.classList.add('dwc-added');
	}

	function applyPreset(btn) {
		var box = btn.closest('[data-dwc-presets]');
		var presets = JSON.parse(box.getAttribute('data-dwc-presets'));
		var defaults = JSON.parse(box.getAttribute('data-dwc-default-cols'));
		var parts = btn.getAttribute('data-dwc-preset').split('.');
		var preset = presets[parts[0]].presets[parts[1]];
		var fieldsBody = rowsBody('fields');
		var colsBody = rowsBody('cols');
		var result = box.querySelector('[data-dwc-preset-result]');
		var wasActive = btn.classList.contains('dwc-btn-primary');

		if (colsSnapshot === null) {
			colsSnapshot = colsBody.innerHTML;
		}
		// Undo the previous pick (unsaved rows only)
		fieldsBody.querySelectorAll('tr.dwc-added').forEach(function (tr) { tr.remove(); });
		colsBody.innerHTML = colsSnapshot;
		box.querySelectorAll('[data-dwc-preset]').forEach(function (b) { b.classList.remove('dwc-btn-primary'); });
		if (wasActive) {
			result.textContent = result.getAttribute('data-removed') || T('Preset removed. Saved fields are unchanged.');
			result.hidden = false;
			return;
		}
		btn.classList.add('dwc-btn-primary');

		var keys = keysIn('fields', 'meta_key');
		var added = 0;
		var skipped = 0;
		preset.fields.forEach(function (f) {
			if (keys.indexOf(f.meta_key) !== -1) {
				skipped++;
				return;
			}
			var row = newRow('fields');
			fill(row, {
				label: f.label, meta_key: f.meta_key, type: f.type, options: f.options,
				show_in_list: f.show_in_list, show_in_export: f.show_in_export, show_in_detail: f.show_in_detail
			});
			fieldsBody.appendChild(row);
			keys.push(f.meta_key);
			added++;
		});

		// Variation columns: an untouched default set (or none) is replaced, a customised set extended
		var cur = keysIn('cols', 'key');
		var colsAdded = 0;
		if (preset.variation_columns.length) {
			if (!cur.length || cur.join(',') === defaults.join(',')) {
				colsBody.innerHTML = '';
				cur = [];
			}
			preset.variation_columns.forEach(function (c) {
				if (cur.indexOf(c.key) !== -1) {
					return;
				}
				var row = newRow('cols');
				fill(row, { label: c.label, key: c.key, type: c.type, options: c.options, public: true });
				colsBody.appendChild(row);
				cur.push(c.key);
				colsAdded++;
			});
		}
		result.textContent = T('%1$s: %2$d fields added, %3$d already existed, %4$d variation columns added. Pick another type to switch, or click it again to undo. Click "Save fields" to keep.', preset.label, added, skipped, colsAdded);
		result.hidden = false;
	}
})();

/**
 * Phase 2 — screens that talk to the server without leaving the page:
 *   [data-dwc-migrate]   migration run: batches until done, pause / continue
 *   [data-dwc-rollback]  undo, batch by batch
 *   [data-dwc-codecheck] item code: checked against other products while typing
 *   [data-dwc-bulkpick]  bulk bar select that submits its own action
 */
(function () {
	'use strict';

	var strings = {};
	var root = document.querySelector('.dwc-admin[data-dwc-i18n]');
	try {
		strings = root ? JSON.parse(root.getAttribute('data-dwc-i18n')) || {} : {};
	} catch (e) {
		strings = {};
	}
	function T(text, n) {
		return (strings[text] || text).replace('%d', n);
	}

	function post(box, action, fields) {
		var body = new URLSearchParams();
		body.set('do', action);
		body.set('_dwcat_token', box.getAttribute('data-dwc-token'));
		Object.keys(fields).forEach(function (k) {
			body.set(k, fields[k]);
		});
		return fetch(box.getAttribute('data-dwc-post'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			body: body
		}).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	// ── Migration run ──
	var runBox = document.querySelector('[data-dwc-migrate]');
	if (runBox && runBox.querySelector('[data-dwc-bar]')) {
		var statusEl = runBox.querySelector('[data-dwc-status]');
		var pauseBtn = runBox.querySelector('[data-dwc-pause]');
		var resumeBtn = runBox.querySelector('[data-dwc-resume]');
		var state = runBox.getAttribute('data-dwc-migrate');
		var failures = 0;

		var show = function (run) {
			var pct = run.total ? Math.min(100, Math.floor((100 * run.done) / run.total)) : 0;
			runBox.querySelector('[data-dwc-bar]').style.width = pct + '%';
			runBox.querySelector('.dwc-progress').setAttribute('aria-valuenow', pct);
			runBox.querySelector('[data-dwc-done]').textContent = run.done;
			runBox.querySelector('[data-dwc-total]').textContent = run.total;
			Object.keys(run.counts || {}).forEach(function (k) {
				var el = runBox.querySelector('[data-dwc-count="' + k + '"]');
				if (el) {
					el.textContent = run.counts[k];
				}
			});
			var list = runBox.querySelector('[data-dwc-errors]');
			list.textContent = '';
			(run.errors || []).forEach(function (line) {
				var li = document.createElement('li');
				li.textContent = line;
				list.appendChild(li);
			});
			state = run.status;
			if (pauseBtn) {
				pauseBtn.hidden = state !== 'running';
				resumeBtn.hidden = state !== 'paused';
			}
			statusEl.textContent = state === 'running' ? T('Working…') : state === 'paused' ? T('Paused.') : state === 'done' ? T('Finished.') : '';
		};

		var next = function () {
			if (state !== 'running') {
				return;
			}
			post(runBox, 'migrate', { op: 'batch' }).then(function (run) {
				failures = 0;
				show(run);
				if (run.status === 'done') {
					window.location.href = runBox.getAttribute('data-dwc-done-url');
				} else if (run.status === 'running') {
					window.setTimeout(next, run.busy ? 1500 : 50);
				}
			}).catch(function () {
				failures++;
				if (failures < 5) {
					window.setTimeout(next, 3000);
				} else {
					statusEl.textContent = T('Something went wrong. Reload the page and continue — nothing is lost.');
				}
			});
		};

		if (pauseBtn) {
			pauseBtn.addEventListener('click', function () {
				state = 'paused';
				post(runBox, 'migrate', { op: 'pause' }).then(show);
			});
			resumeBtn.addEventListener('click', function () {
				post(runBox, 'migrate', { op: 'resume' }).then(function (run) {
					show(run);
					next();
				});
			});
		}
		if (state === 'running') {
			statusEl.textContent = T('Working…');
			next();
		}
	}

	// ── Undo ──
	var undoBox = document.querySelector('[data-dwc-rollback]');
	var undoBtn = undoBox && undoBox.querySelector('[data-dwc-rollback-start]');
	if (undoBtn) {
		undoBtn.addEventListener('click', function () {
			if (!window.confirm(undoBtn.getAttribute('data-dwc-confirm-text'))) {
				return;
			}
			undoBtn.disabled = true;
			var status = undoBox.querySelector('[data-dwc-status]');
			var step = function () {
				post(undoBox, 'migrate', { op: 'rollback' }).then(function (res) {
					if (res.done) {
						window.location.href = undoBox.getAttribute('data-dwc-done-url');
						return;
					}
					status.textContent = T('Undoing… %d left', res.left);
					window.setTimeout(step, 50);
				}).catch(function () {
					status.textContent = T('Something went wrong. Reload the page and continue — nothing is lost.');
					undoBtn.disabled = false;
				});
			};
			step();
		});
	}

	// ── Item code: is it already in use? ──
	var check = document.querySelector('[data-dwc-codecheck]');
	var codeInput = check && document.getElementById(check.getAttribute('data-dwc-codecheck'));
	if (codeInput) {
		var timer = 0;
		var seq = 0;
		codeInput.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				var value = codeInput.value.trim();
				var mine = ++seq;
				if (!value) {
					check.textContent = '';
					return;
				}
				post(check, 'check_code', { code: value, id: check.getAttribute('data-dwc-id'), type: check.getAttribute('data-dwc-type') }).then(function (res) {
					if (mine !== seq) {
						return; // an older answer arriving late
					}
					var hint = document.createElement('span');
					check.textContent = '';
					if (res.used && res.used.length) {
						hint.className = 'dwc-hint dwc-hint-warn';
						hint.appendChild(document.createTextNode(T('Also used by:') + ' '));
						res.used.forEach(function (item, i) {
							var a = document.createElement('a');
							a.href = item.url;
							a.textContent = item.title;
							if (i) {
								hint.appendChild(document.createTextNode(', '));
							}
							hint.appendChild(a);
						});
					} else {
						hint.className = 'dwc-hint dwc-hint-ok';
						hint.textContent = T('Unique — no other item uses this code.');
					}
					check.appendChild(hint);
				}).catch(function () {});
			}, 350);
		});
	}

	// ── Bulk bar: a select that is its own action ──
	document.addEventListener('change', function (e) {
		var pick = e.target.closest && e.target.closest('[data-dwc-bulkpick]');
		if (pick && pick.value) {
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'bulk';
			hidden.value = pick.getAttribute('data-dwc-bulkpick');
			pick.form.appendChild(hidden);
			pick.form.submit();
		}
	});
})();

/**
 * Phase 3 — Design screen: live preview (desktop / tablet / phone widths), color pickers, and a
 * new preset that starts from the values on screen.
 */
(function () {
	'use strict';

	var frame = document.querySelector('[data-dwc-preview-frame]');
	var form = document.querySelector('[data-dwc-design]');
	if (!frame || !form) {
		return;
	}
	var spec = {};
	try {
		spec = JSON.parse(frame.getAttribute('data-dwc-design-spec')) || {};
	} catch (e) {
		spec = {};
	}
	var root = frame.querySelector('[data-dwc-preview-root]');
	var loaded = {};

	function font(set) {
		var url = spec.fonts && spec.fonts[set];
		if (url && !loaded[url]) {
			loaded[url] = true;
			var link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = url;
			document.head.appendChild(link);
		}
	}

	function apply(el) {
		var key = el.getAttribute('data-dwc-dkey');
		var value = el.value;
		if (spec.enums && spec.enums[key] && spec.enums[key][value]) {
			var vars = spec.enums[key][value];
			Object.keys(vars).forEach(function (v) {
				root.style.setProperty(v, vars[v]);
			});
			if (key === 'font_set') {
				font(value);
			}
		} else if (spec.direct && spec.direct[key] && value !== '') {
			var d = spec.direct[key];
			if (d[1] === 'px' && isNaN(parseFloat(value))) {
				return;
			}
			root.style.setProperty(d[0], value + d[1]);
		}
		if (key === 'columns') {
			Array.prototype.forEach.call(root.querySelectorAll('.dwcat-grid'), function (g) {
				g.style.setProperty('--dwcat-cols', value);
			});
		}
	}

	form.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches('[data-dwc-colorpick]')) {
			var text = t.parentNode.querySelector('[data-dwc-dkey]');
			text.value = t.value;
			apply(text);
		} else if (t.matches('[data-dwc-dkey]')) {
			apply(t);
			var pick = t.parentNode.querySelector('[data-dwc-colorpick]');
			if (pick && /^#[0-9a-f]{6}$/i.test(t.value)) {
				pick.value = t.value;
			}
		}
	});
	form.addEventListener('change', function (e) {
		if (e.target.matches('select[data-dwc-dkey]')) {
			apply(e.target);
		}
	});
	var current = form.querySelector('[data-dwc-dkey="font_set"]');
	if (current) {
		font(current.value);
	}

	// The preview is laid out at the real device width and scaled down to fit the panel
	var stage = frame.querySelector('[data-dwc-preview-stage]');
	var width = 1200;
	function fit() {
		if (!stage) {
			return;
		}
		stage.style.width = width + 'px';
		var scale = Math.min(1, frame.clientWidth / width);
		stage.style.transform = 'scale(' + scale + ')';
		stage.style.left = Math.max(0, Math.floor((frame.clientWidth - width * scale) / 2)) + 'px';
		frame.style.height = Math.ceil(stage.offsetHeight * scale) + 'px';
	}
	window.addEventListener('resize', fit);
	if (window.ResizeObserver && stage) {
		new ResizeObserver(fit).observe(stage);
	}
	fit();

	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-dwc-preview-width]');
		if (!b) {
			return;
		}
		e.preventDefault();
		width = parseInt(b.getAttribute('data-dwc-preview-width'), 10) || 1200;
		fit();
		Array.prototype.forEach.call(b.parentNode.children, function (x) {
			x.classList.toggle('is-active', x === b);
		});
	});

	// "Save as preset": take the values currently on screen, not the saved ones
	var copy = document.querySelector('[data-dwc-design-copy]');
	if (copy) {
		copy.addEventListener('submit', function () {
			Array.prototype.forEach.call(copy.querySelectorAll('input[data-dwc-copied]'), function (x) {
				x.remove();
			});
			Array.prototype.forEach.call(form.querySelectorAll('[name^="design["]'), function (el) {
				var h = document.createElement('input');
				h.type = 'hidden';
				h.name = el.name;
				h.value = el.value;
				h.setAttribute('data-dwc-copied', '');
				copy.appendChild(h);
			});
		});
	}
})();
