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
			status.textContent = 'Optimizing photos…';
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
				status.textContent = out.length + ' photo(s): ' + mb(before) + ' → ' + mb(after);
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
			result.textContent = result.getAttribute('data-removed') || 'Preset removed. Saved fields are unchanged.';
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
		result.textContent = preset.label + ': ' + added + ' fields added, ' + skipped + ' already existed, ' + colsAdded +
			' variation columns added. Pick another type to switch, or click it again to undo. Click "Save fields" to keep.';
		result.hidden = false;
	}
})();
