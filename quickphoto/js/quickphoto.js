/* Quick Photo client: decode, shrink, confirm, upload. No dependencies. */
(function () {
	'use strict';

	var MAX_EDGE = 4032;
	var QUALITY = 0.85;
	var DECODE_ERROR = 'This photo format cannot be read by the browser. ' +
		'On older iPhones set Camera -> Formats -> Most Compatible.';

	var root = null;
	var busy = false;
	var sheet = null;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	function setBusy(flag) {
		busy = flag;
		var inputs = root.querySelectorAll('.qp-file');
		for (var i = 0; i < inputs.length; i++) {
			inputs[i].disabled = flag;
			if (inputs[i].parentNode) {
				inputs[i].parentNode.classList.toggle('qp-disabled', flag);
			}
		}
	}

	function showMessage(text) {
		var box = root.querySelector('.qp-msg');
		if (!box) {
			box = el('div', 'qp-msg qp-error');
			box.setAttribute('role', 'alert');
			root.insertBefore(box, root.firstChild);
		}
		box.textContent = text;
	}

	function clearMessage() {
		var box = root.querySelector('.qp-msg');
		if (box && box.parentNode) box.parentNode.removeChild(box);
	}

	function loadImage(file) {
		var url = URL.createObjectURL(file);
		var img = new Image();
		img.src = url;
		return img.decode().then(function () {
			return { img: img, url: url };
		}, function () {
			URL.revokeObjectURL(url);
			var err = new Error('decode');
			err.qpDecode = true;
			throw err;
		});
	}

	function readBytes(blob) {
		if (blob.arrayBuffer) return blob.arrayBuffer();
		return new Promise(function (resolve, reject) {
			var r = new FileReader();
			r.onload = function () { resolve(r.result); };
			r.onerror = function () { reject(new Error('read')); };
			r.readAsArrayBuffer(blob);
		});
	}

	// Removes APP1 (Exif/XMP) and APP13 segments before SOS; everything else is kept
	// byte for byte. Returns a Uint8Array, or null when the JPEG is not well formed
	// up to SOS (fail closed). Needed because Apple's encoder adds Exif even to canvas output.
	function stripJpegMetadata(buf) {
		var b = new Uint8Array(buf);
		var n = b.length;
		if (n < 4 || b[0] !== 0xFF || b[1] !== 0xD8) return null;
		var parts = [b.subarray(0, 2)];
		var i = 2;
		while (true) {
			if (i + 2 > n || b[i] !== 0xFF) return null;
			var m = b[i + 1];
			if (m === 0xFF) { i++; continue; } // fill byte
			if (m === 0x00) return null; // stuffed zero is not a marker (server rejects it too)
			if (m === 0xDA) { // SOS: copy the rest (scan data and EOI) untouched
				parts.push(b.subarray(i));
				break;
			}
			if (m === 0xD8 || m === 0x01 || (m >= 0xD0 && m <= 0xD9)) return null; // no length, not valid here
			if (i + 4 > n) return null;
			var len = (b[i + 2] << 8) | b[i + 3];
			if (len < 2 || i + 2 + len > n) return null;
			if (m !== 0xE1 && m !== 0xED) parts.push(b.subarray(i, i + 2 + len));
			i += 2 + len;
		}
		var total = 0, k;
		for (k = 0; k < parts.length; k++) total += parts[k].length;
		var out = new Uint8Array(total);
		var o = 0;
		for (k = 0; k < parts.length; k++) { out.set(parts[k], o); o += parts[k].length; }
		return out;
	}

	// Resolves to { blob, width, height }. Frees the source image and canvas.
	function shrink(file) {
		var canvas = null;
		var src = null;
		return loadImage(file).then(function (loaded) {
			src = loaded;
			var w = src.img.naturalWidth;
			var h = src.img.naturalHeight;
			if (!w || !h) {
				var e = new Error('decode');
				e.qpDecode = true;
				throw e;
			}
			var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
			var cw = Math.max(1, Math.round(w * scale));
			var ch = Math.max(1, Math.round(h * scale));
			canvas = document.createElement('canvas');
			canvas.width = cw;
			canvas.height = ch;
			var ctx = canvas.getContext('2d');
			ctx.imageSmoothingQuality = 'high';
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, cw, ch);
			ctx.drawImage(src.img, 0, 0, cw, ch);
			return new Promise(function (resolve, reject) {
				canvas.toBlob(function (blob) {
					if (!blob) {
						reject(new Error('encode'));
						return;
					}
					readBytes(blob).then(function (buf) {
						var clean = stripJpegMetadata(buf);
						if (!clean) throw new Error('strip');
						resolve({ blob: new Blob([clean], { type: 'image/jpeg' }), width: cw, height: ch });
					}).catch(reject);
				}, 'image/jpeg', QUALITY);
			});
		}).then(function (res) {
			cleanup();
			return res;
		}, function (err) {
			cleanup();
			throw err;
		});

		function cleanup() {
			if (src) {
				URL.revokeObjectURL(src.url);
				src.img.removeAttribute('src');
				src = null;
			}
			if (canvas) {
				canvas.width = 0;
				canvas.height = 0;
				canvas = null;
			}
		}
	}

	function closeSheet() {
		if (!sheet) return;
		if (sheet.previewUrl) URL.revokeObjectURL(sheet.previewUrl);
		if (sheet.overlay.parentNode) sheet.overlay.parentNode.removeChild(sheet.overlay);
		sheet = null;
		setBusy(false);
	}

	// Tag list rendered by the server in data-tags: [{id, name, color}]. Anything
	// malformed is ignored (no Tags block).
	function readTags(form) {
		var list;
		try {
			list = JSON.parse(form.getAttribute('data-tags') || '[]');
		} catch (e) {
			return [];
		}
		if (!Array.isArray(list)) return [];
		return list.filter(function (t) {
			return t && typeof t.id === 'number' && t.id > 0 && typeof t.name === 'string';
		});
	}

	function upload(form, result, comment, name, tagIds) {
		var data = new FormData();
		var hidden = form.querySelectorAll('input[type=hidden]');
		for (var i = 0; i < hidden.length; i++) {
			data.append(hidden[i].name, hidden[i].value);
		}
		if (comment !== null) data.append('comment', comment);
		if (name !== null) data.append('name', name);
		if (tagIds) {
			for (var t = 0; t < tagIds.length; t++) data.append('taglist[]', String(tagIds[t]));
		}
		data.append('file', result.blob, 'photo.jpg');
		return fetch(form.getAttribute('action'), {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			redirect: 'manual'
		});
	}

	function openSheet(form, result) {
		var isReplace = form.classList.contains('qp-replace');
		var overlay = el('div', 'qp-overlay');
		var box = el('div', 'qp-sheet');
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');

		var previewUrl = URL.createObjectURL(result.blob);
		var img = el('img', 'qp-preview');
		img.alt = 'Preview';
		img.src = previewUrl;
		box.appendChild(img);

		var mb = (result.blob.size / 1048576).toFixed(1);
		box.appendChild(el('div', 'qp-size', result.width + ' x ' + result.height + ', ' + mb + ' MB'));

		var warning = form.getAttribute('data-warning');
		if (isReplace && warning) box.appendChild(el('div', 'qp-warning', warning));

		var commentInput = null;
		var nameInput = null;
		var tagChips = [];
		if (!isReplace) {
			var nameLabel = el('label', 'qp-sheet-label', 'Name');
			nameInput = el('input', 'qp-sheet-name');
			nameInput.type = 'text';
			nameInput.maxLength = 100;
			nameInput.setAttribute('autocapitalize', 'off');
			nameInput.setAttribute('autocorrect', 'off');
			nameInput.spellcheck = false;
			nameInput.value = form.getAttribute('data-name-suggestion') || '';
			nameLabel.appendChild(nameInput);
			box.appendChild(nameLabel);

			var tags = readTags(form);
			if (tags.length) {
				var tagBox = el('div', 'qp-tags');
				tagBox.setAttribute('role', 'group');
				tagBox.setAttribute('aria-labelledby', 'qp-tags-label');
				var tagLabel = el('div', 'qp-tags-label', 'Tags');
				tagLabel.id = 'qp-tags-label';
				tagBox.appendChild(tagLabel);
				var chipRow = el('div', 'qp-tag-list');
				tags.forEach(function (t) {
					var chip = el('button', 'qp-tag', t.name);
					chip.type = 'button';
					chip.setAttribute('aria-pressed', 'false');
					if (typeof t.color === 'string' && /^#[0-9a-f]{6}$/i.test(t.color)) {
						chip.style.setProperty('--qp-tag', t.color);
						chip.classList.add('qp-tag-colored');
					}
					chip.addEventListener('click', function () {
						chip.setAttribute('aria-pressed', chip.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
					});
					chip.qpTagId = t.id;
					tagChips.push(chip);
					chipRow.appendChild(chip);
				});
				tagBox.appendChild(chipRow);
				box.appendChild(tagBox);
			}

			commentInput = el('input', 'qp-sheet-comment');
			commentInput.type = 'text';
			commentInput.maxLength = 1000;
			commentInput.placeholder = 'Comment (optional)';
			var pageComment = form.querySelector('.qp-comment');
			if (pageComment) commentInput.value = pageComment.value;
			box.appendChild(commentInput);
		}

		var status = el('div', 'qp-sheet-status');
		status.setAttribute('role', 'alert');
		box.appendChild(status);

		var row = el('div', 'qp-actions');
		var cancel = el('button', 'qp-cancel qp-button', 'Cancel');
		cancel.type = 'button';
		var save = el('button', 'qp-save qp-button qp-primary', isReplace ? 'Replace photo' : 'Save');
		save.type = 'button';
		row.appendChild(cancel);
		row.appendChild(save);
		box.appendChild(row);
		overlay.appendChild(box);
		sheet = { overlay: overlay, previewUrl: previewUrl };
		document.body.appendChild(overlay);

		cancel.addEventListener('click', function () {
			closeSheet();
		});
		save.addEventListener('click', function () {
			if (save.disabled) return;
			save.disabled = true;
			cancel.disabled = true;
			status.textContent = 'Uploading...';
			function fail(msg) {
				status.textContent = msg;
				save.disabled = false;
				cancel.disabled = false;
			}
			var tagIds = tagChips.filter(function (c) {
				return c.getAttribute('aria-pressed') === 'true';
			}).map(function (c) { return c.qpTagId; });
			upload(form, result, commentInput ? commentInput.value : null, nameInput ? nameInput.value : null, tagIds).then(function (r) {
				if (r.type === 'opaqueredirect' || (r.status >= 300 && r.status < 400)) {
					location.reload();
				} else if (r.status === 401) {
					fail('You are no longer logged in. Reload the page and try again.');
				} else if (r.status === 413) {
					fail('The photo is too large for the server.');
				} else {
					fail('Upload failed (server error ' + r.status + '). Try again.');
				}
			}, function () {
				fail('Upload failed (network error). Try again.');
			});
		});
	}

	function onPick(ev) {
		var input = ev.target;
		if (!input || !input.classList || !input.classList.contains('qp-file')) return;
		var file = input.files && input.files[0];
		input.value = '';
		if (!file || busy) return;
		var form = input.form || input.closest('form');
		if (!form) return;
		clearMessage();
		setBusy(true);
		shrink(file).then(function (result) {
			openSheet(form, result);
		}).catch(function (err) {
			closeSheet();
			setBusy(false);
			showMessage(err && err.qpDecode ? DECODE_ERROR : 'Could not prepare the photo. Nothing was uploaded.');
		});
	}

	function init() {
		root = document.getElementById('quickphoto');
		if (!root) return;
		root.addEventListener('change', onPick);
		// clear the value before the picker opens so re-picking the same photo fires change
		root.addEventListener('click', function (ev) {
			var t = ev.target;
			if (t && t.classList && t.classList.contains('qp-file')) t.value = '';
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
