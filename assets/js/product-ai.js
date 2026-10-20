/**
 * Fluxis for WooCommerce – Fluxis AI on the product screen and in the media library.
 *
 * - FluxisAi: a small API over admin-ajax (nonce + manage_woocommerce server-side).
 * - The product's Fluxis panel: sync, "Optimize product" and "Optimize images" dialogs.
 * - Per-field buttons: product data inputs, editors, Yoast fields, featured image,
 *   gallery thumbnails and the media modal.
 *
 * Synced fields of a linked product go through ai/runs (Fluxis writes them back);
 * everything else is optimized ad hoc (protocol §10) and applied here after the
 * admin accepts it. Plain ES2017, no build step.
 */
(function () {
	'use strict';

	const F = window.Fluxis;

	if (!F) {
		return;
	}

	const cfg = F.cfg;
	const t = F.t;
	const el = F.el;
	const ITEM_DONE = ['proposed', 'applied', 'failed', 'skipped', 'cancelled', 'reverted'];
	const ICON =
		'<svg class="fluxis-ai-svg" aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 14l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8-1.8-.7 1.8-.7z"/></svg>';
	let uid = 0;

	/** While the setup in Fluxis is not finished every Fluxis button is disabled (protocol §11.3). */
	const SETUP_BLOCKED = cfg.setupComplete === false;

	const nextId = (prefix) => prefix + '-' + ++uid;
	const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
	const $ = () => window.jQuery || null;

	/* ------------------------------------------------------------------ API */

	const FluxisAi = {
		/**
		 * Polls one run item until it has a result; resolves {ok, data: item} or {ok:false, data:{error}}.
		 */
		async pollItem(runId, itemId, onTick) {
			for (let attempt = 0; attempt < 240; attempt++) {
				const result = await F.request('fluxis_run_item', { run_id: runId, item_id: itemId });

				if (!result.ok) {
					if (result.status >= 400 && result.status < 500 && result.status !== 429) {
						return result;
					}
				} else {
					if (typeof onTick === 'function') {
						onTick(result.data);
					}

					if (ITEM_DONE.indexOf(result.data.status) !== -1) {
						if (result.data.status === 'failed' || result.data.status === 'cancelled' || result.data.status === 'reverted') {
							return { ok: false, status: 200, data: { error: result.data.error_code || 'item_failed' } };
						}

						return result;
					}
				}

				await wait(attempt < 10 ? 1500 : 3000);
			}

			return { ok: false, status: 0, data: { error: 'timeout' } };
		},

		/**
		 * fields: [{key, label, value, kind, max_length, hint, path}]; options: {mode, instruction, language, language_other}.
		 * path: the §5.1 source path of a synced field (protocol §12.3), absent for WordPress-only fields.
		 */
		async text(context, fields, options, onTick) {
			const created = await F.request(
				'fluxis_adhoc_text',
				Object.assign({}, context, options, {
					fields: JSON.stringify(
						fields.map((field) => ({
							key: field.key,
							label: field.label,
							value: field.value,
							kind: field.kind || 'plain',
							max_length: field.max_length || null,
							hint: field.hint || null,
							path: field.path || null,
						}))
					),
				})
			);

			if (!created.ok) {
				return created;
			}

			const result = await this.pollItem(created.data.run_id, created.data.item_id, onTick);

			if (result.ok) {
				result.data.run_id = created.data.run_id;
				result.data.item_id = created.data.item_id;
			}

			return result;
		},

		async image(context, attachmentId, options, onTick) {
			const created = await F.request('fluxis_adhoc_image', Object.assign({}, context, options, { attachment_id: attachmentId }));

			if (!created.ok) {
				return created;
			}

			const result = await this.pollItem(created.data.run_id, created.data.item_id, onTick);

			if (result.ok) {
				result.data.run_id = created.data.run_id;
				result.data.item_id = created.data.item_id;
			}

			return result;
		},

		preview(runId, itemId) {
			return F.request('fluxis_image_preview', { run_id: runId, item_id: itemId });
		},

		acceptImage(args) {
			return F.request('fluxis_accept_image', args);
		},

		productImages(productId) {
			return F.request('fluxis_product_images', { product_id: productId });
		},

		productFields(productId) {
			return F.request('fluxis_product_fields', { product_id: productId });
		},

		applyFields(productId, values) {
			return F.request('fluxis_apply_fields', { product_id: productId, values: JSON.stringify(values) });
		},

		/** Queues the Fluxis apply of results WordPress already saved (protocol §12.3). */
		applyNow(productId, entries) {
			return F.request('fluxis_adhoc_apply', { product_id: productId, accepted: JSON.stringify(entries) });
		},
	};

	window.FluxisAi = FluxisAi;

	/* --------------------------------------------------------- dirty state */

	let dirty = false;

	function markDirty() {
		dirty = true;
	}

	window.addEventListener('beforeunload', (event) => {
		if (dirty) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	/* ------------------------------------------- accepted ad-hoc results */

	/**
	 * Ad-hoc results accepted into fields that WordPress saves on "Update": sent with
	 * the product form as fluxis_adhoc_accepted[] so the save applies them to the
	 * mapped Fluxis fields too (protocol §12.3). The server reads the SAVED values.
	 */
	const acceptedEntries = [];

	function rememberAccepted(entry) {
		if (!entry || !entry.run_id || !entry.item_id || !document.getElementById('post')) {
			return;
		}

		const same = (other) => other.run_id === entry.run_id && other.item_id === entry.item_id && (entry.image ? Boolean(other.image) : other.key === entry.key);
		const index = acceptedEntries.findIndex(same);

		if (index !== -1) {
			acceptedEntries.splice(index, 1);
		}

		acceptedEntries.push(entry);
	}

	function writeAcceptedInputs(form) {
		form.querySelectorAll('.fluxis-adhoc-accepted').forEach((input) => input.remove());

		acceptedEntries.forEach((entry) => {
			form.appendChild(el('input', { type: 'hidden', class: 'fluxis-adhoc-accepted', name: (cfg.adhocFormField || 'fluxis_adhoc_accepted') + '[]', value: JSON.stringify(entry) }));
		});
	}

	/** Refreshes the panel's status while applies are pending (set by initPanel). */
	let refreshPanel = () => {};

	/** Results WordPress saved at once: the Fluxis apply is queued right away. */
	async function applyNow(entries) {
		const result = await FluxisAi.applyNow(productId(), entries);

		if (result.ok) {
			refreshPanel();
		}
	}

	document.addEventListener('submit', (event) => {
		if (event.target && event.target.id === 'post') {
			dirty = false;
			writeAcceptedInputs(event.target);
		}
	});

	/* ---------------------------------------------------------- triggers */

	/**
	 * Every Fluxis AI trigger is a <button type="button" data-fluxis-ai-action="…">,
	 * handled by delegation on document so re-rendered elements (Yoast, TinyMCE media
	 * buttons, WooCommerce panels loaded later) always work.
	 */
	const actions = {};
	const triggerOptions = new WeakMap();

	document.addEventListener('click', (event) => {
		const trigger = event.target.closest && event.target.closest('[data-fluxis-ai-action]');

		if (!trigger) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if (SETUP_BLOCKED || trigger.disabled) {
			return;
		}

		const handler = actions[trigger.getAttribute('data-fluxis-ai-action')];

		if (!handler) {
			return;
		}

		try {
			const result = handler(trigger, event);

			if (result && typeof result.catch === 'function') {
				result.catch((error) => window.console.error('[fluxis-ai]', error));
			}
		} catch (error) {
			window.console.error('[fluxis-ai]', error);
		}
		// Capture phase: React roots (Yoast) and WooCommerce handlers below never see the click.
	}, true);

	/* ------------------------------------------------------------- helpers */

	function productId() {
		const panel = document.querySelector('[data-fluxis-panel]');
		const postId = document.getElementById('post_ID');
		const postType = document.getElementById('post_type');

		if (panel) {
			return parseInt(panel.getAttribute('data-product-id'), 10) || 0;
		}

		return postId && postType && postType.value === 'product' ? parseInt(postId.value, 10) || 0 : 0;
	}

	function wireKey(raw) {
		const key = String(raw || '')
			.toLowerCase()
			.replace(/\[(\d+)\]/g, '.$1')
			.replace(/[^a-z0-9_:.-]+/g, '_')
			.replace(/^[_.]+|[_.]+$/g, '');

		return (key || 'field').slice(0, 64);
	}

	/** sanitize_title() of a custom attribute name, as the attribute discovery reports its slug. */
function slugify(value) {
	return String(value || '')
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9_\s-]/g, '')
		.replace(/[\s-]+/g, '-')
		.replace(/^-+|-+$/g, '');
}

/** §12.3 path of an attribute value input: attributes.<slug> (custom attribute rows only). */
function attributePath(input) {
	const row = input.closest('.woocommerce_attribute');

	if (!row || input.closest('.woocommerce_variation') || !/^attribute_values\[/.test(input.name || '')) {
		return null;
	}

	const nameInput = row.querySelector('input.attribute_name, .attribute_name input, input[name^="attribute_names"]');
	const raw = nameInput ? String(nameInput.value || '').trim() : '';
	const path = raw ? 'attributes.' + (/^pa_/.test(raw) ? raw : slugify(raw)) : '';

	return /^[a-z0-9_.:-]{1,191}$/.test(path) ? path : null;
}

/** Source path of a synced field key (name, description, Yoast …), null otherwise. */
function syncedPath(key) {
	return (cfg.adhocPaths || {})[key] || null;
}

function setValue(input, value) {
		const proto = input.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
		const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;

		// The native setter keeps React-controlled inputs (Yoast) in sync.
		setter.call(input, value);
		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));

		const jq = $();
		if (jq) {
			// WooCommerce marks variations as changed on jQuery change events.
			jq(input).trigger('change');
		}

		markDirty();
	}

	function stripTags(html) {
		const box = document.createElement('div');
		box.innerHTML = html;
		return box.textContent || '';
	}

	function errorBox(target, data) {
		target.hidden = false;

		const messages = {
			ai_optimizer_nothing_to_do: t('nothingToDo'),
			image_too_large: t('imageTooLarge'),
			image_not_found: t('imageNotFound'),
			timeout: t('timeout'),
		};

		if (data && messages[data.error]) {
			target.innerHTML = '';
			target.appendChild(el('span', {}, messages[data.error]));
			return;
		}

		F.renderError(target, data);
	}

	/** Upsell when Fluxis says the add-on is inactive; true when the action may continue. */
	function gate(container) {
		if (cfg.addonActive === false) {
			F.renderError(container, { error: 'addon_required' });
			return false;
		}

		return true;
	}

	/* ------------------------------------------------------ popover/modal */

	let openPopover = null;

	function closePopover() {
		if (openPopover) {
			openPopover.close();
		}
	}

	/**
	 * A small dialog anchored to a button. build(body, close) fills it.
	 */
	function popover(anchor, title, build, subtitle) {
		closePopover();

		const id = nextId('fluxis-popover');
		const box = el('div', { class: 'fluxis-popover', role: 'dialog', 'aria-labelledby': id + '-title' });
		const head = el('div', { class: 'fluxis-popover__head' });
		const heading = el('div', { class: 'fluxis-popover__heading' });
		const mark = el('span', { class: 'fluxis-popover__mark', 'aria-hidden': 'true' });
		const titles = el('div', { class: 'fluxis-popover__titles' });
		const closeButton = el('button', { type: 'button', class: 'fluxis-popover__close', 'aria-label': t('close') }, '×');
		const body = el('div', { class: 'fluxis-popover__body' });

		mark.innerHTML = ICON;
		titles.appendChild(el('strong', { id: id + '-title' }, title));
		if (subtitle) {
			titles.appendChild(el('span', { class: 'fluxis-popover__subtitle' }, subtitle));
		}
		heading.appendChild(mark);
		heading.appendChild(titles);
		head.appendChild(heading);
		head.appendChild(closeButton);
		box.appendChild(head);
		box.appendChild(body);
		// Positioning inline as well, so the popover shows next to its trigger even with a stale stylesheet.
		Object.assign(box.style, { position: 'absolute', zIndex: '170000', background: '#fff', boxSizing: 'border-box' });
		document.body.appendChild(box);

		function place() {
			const rect = anchor.getBoundingClientRect();
			const width = Math.min(380, window.innerWidth - 24);
			const left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));

			box.style.width = width + 'px';
			box.style.left = left + window.scrollX + 'px';
			box.style.top = rect.bottom + window.scrollY + 6 + 'px';
		}

		function onKey(event) {
			if (event.key === 'Escape') {
				event.stopPropagation();
				close();
			}
		}

		function onOutside(event) {
			if (!box.contains(event.target) && !anchor.contains(event.target) && !event.target.closest('.fluxis-modal')) {
				close();
			}
		}

		function close() {
			document.removeEventListener('keydown', onKey, true);
			document.removeEventListener('mousedown', onOutside, true);
			window.removeEventListener('resize', place);
			box.remove();
			openPopover = null;
			anchor.setAttribute('aria-expanded', 'false');
			anchor.focus({ preventScroll: true });
		}

		closeButton.addEventListener('click', close);
		document.addEventListener('keydown', onKey, true);
		document.addEventListener('mousedown', onOutside, true);
		window.addEventListener('resize', place);
		anchor.setAttribute('aria-expanded', 'true');
		openPopover = { close: close, box: box };

		build(body, close);
		place();

		const focusable = body.querySelector('button, select, textarea, input, a[href]');
		(focusable || closeButton).focus({ preventScroll: true });

		return { close: close, body: body };
	}

	/**
	 * A modal dialog with a scrollable body and a footer; build(body, footer, close).
	 */
	function modal(title, build) {
		closePopover();

		const returnFocus = document.activeElement;
		const id = nextId('fluxis-modal');
		const backdrop = el('div', { class: 'fluxis-modal-backdrop' });
		const dialog = el('div', { class: 'fluxis-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': id + '-title' });
		const head = el('div', { class: 'fluxis-modal__head' });
		const closeButton = el('button', { type: 'button', class: 'fluxis-modal__close', 'aria-label': t('close') }, '×');
		const body = el('div', { class: 'fluxis-modal__body' });
		const footer = el('div', { class: 'fluxis-modal__footer' });

		head.appendChild(el('h2', { id: id + '-title' }, title));
		head.appendChild(closeButton);
		dialog.appendChild(head);
		dialog.appendChild(body);
		dialog.appendChild(footer);
		backdrop.appendChild(dialog);
		Object.assign(backdrop.style, { position: 'fixed', top: '0', right: '0', bottom: '0', left: '0', zIndex: '160000', display: 'flex', alignItems: 'center', justifyContent: 'center' });
		Object.assign(dialog.style, { background: '#fff', maxHeight: 'calc(100vh - 40px)', display: 'flex', flexDirection: 'column' });
		document.body.appendChild(backdrop);
		document.body.classList.add('fluxis-modal-open');

		function onKey(event) {
			if (event.key === 'Escape' && !openPopover) {
				close();
			} else if (event.key === 'Tab') {
				const items = Array.prototype.filter.call(
					dialog.querySelectorAll('button, select, textarea, input, a[href]'),
					(node) => !node.disabled && node.offsetParent !== null
				);

				if (items.length === 0) {
					return;
				}

				const first = items[0];
				const last = items[items.length - 1];

				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}
		}

		function close() {
			document.removeEventListener('keydown', onKey);
			backdrop.remove();
			document.body.classList.remove('fluxis-modal-open');

			if (returnFocus && typeof returnFocus.focus === 'function') {
				returnFocus.focus({ preventScroll: true });
			}
		}

		closeButton.addEventListener('click', close);
		backdrop.addEventListener('mousedown', (event) => {
			if (event.target === backdrop) {
				close();
			}
		});
		document.addEventListener('keydown', onKey);

		build(body, footer, close);
		closeButton.focus({ preventScroll: true });

		return { close: close, body: body, footer: footer };
	}

	/* ------------------------------------------------------- option forms */

	function languageSelect(container) {
		const id = nextId('fluxis-language');
		const wrap = el('p', { class: 'fluxis-field fluxis-language fluxis-inline-field' });
		const select = el('select', { id: id });
		const other = el('input', {
			type: 'text',
			class: 'small-text',
			maxlength: '2',
			pattern: '[a-z]{2}',
			placeholder: t('languageOtherPlaceholder'),
			'aria-label': t('languageOtherLabel'),
			hidden: 'hidden',
		});

		(cfg.languages || []).forEach((language) => {
			select.appendChild(el('option', { value: language.code }, language.label));
		});
		select.appendChild(el('option', { value: 'other' }, t('languageOther')));
		select.addEventListener('change', () => {
			other.hidden = select.value !== 'other';
		});

		wrap.appendChild(el('label', { for: id }, t('language')));
		wrap.appendChild(select);
		wrap.appendChild(document.createTextNode(' '));
		wrap.appendChild(other);
		container.appendChild(wrap);

		return {
			values: () => ({ language: select.value, language_other: select.value === 'other' ? other.value.trim().toLowerCase() : '' }),
			label: () => (select.value === 'other' ? other.value.trim().toLowerCase() || t('languageOther') : select.options[select.selectedIndex].text.replace(/\s+—.*$/, '')),
			validate: () => (select.value === 'other' && !/^[a-z]{2}$/.test(other.value.trim().toLowerCase()) ? t('languageInvalid') : null),
		};
	}

	/**
	 * Optional instruction + language. Without an instruction Fluxis improves the
	 * text from the product data (mode from_data); with one it follows it.
	 */
	function textOptions(container) {
		const group = el('div', { class: 'fluxis-field fluxis-instruction' });
		const toggle = el('button', { type: 'button', class: 'fluxis-link-button', 'aria-expanded': 'false' }, '+ ' + t('addInstruction'));
		const instruction = el('textarea', { rows: '3', class: 'fluxis-input', placeholder: t('instructionPlaceholder'), 'aria-label': t('instruction'), hidden: 'hidden' });

		group.appendChild(el('p', { class: 'fluxis-hint fluxis-hint--top' }, t('modeFromDataHint')));
		group.appendChild(toggle);
		group.appendChild(instruction);
		container.appendChild(group);

		toggle.addEventListener('click', () => {
			const open = instruction.hidden;
			instruction.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.textContent = (open ? '− ' : '+ ') + t(open ? 'removeInstruction' : 'addInstruction');

			if (open) {
				instruction.focus({ preventScroll: true });
			} else {
				instruction.value = '';
			}
		});

		const language = languageSelect(container);
		const text = () => (instruction.hidden ? '' : instruction.value.trim());

		return {
			values: () => Object.assign({ mode: text() ? 'instruction' : 'from_data', instruction: text() }, language.values()),
			validate: () => language.validate(),
			summary: () => (text() ? t('withInstruction') : t('modeImprove')) + ' · ' + language.label(),
		};
	}

	function presetSelect(container, compact) {
		const id = nextId('fluxis-preset');
		const wrap = el('div', { class: 'fluxis-field fluxis-preset-select' });
		const select = el('select', { id: id });
		const prompt = el('textarea', { rows: '2', class: 'large-text', placeholder: t('promptPlaceholder'), 'aria-label': t('prompt'), hidden: 'hidden' });
		const description = el('p', { class: 'description' });

		(cfg.imagePresets || []).forEach((preset) => {
			select.appendChild(el('option', { value: preset.code }, preset.label));
		});

		function sync() {
			const preset = (cfg.imagePresets || []).find((item) => item.code === select.value);
			prompt.hidden = select.value !== 'custom';
			description.textContent = preset ? preset.description : '';
		}

		select.addEventListener('change', sync);

		if (!compact) {
			wrap.appendChild(el('label', { for: id }, t('imageEdit')));
			wrap.appendChild(el('br'));
		} else {
			select.setAttribute('aria-label', t('imageEdit'));
		}

		wrap.appendChild(select);
		if (!compact) {
			wrap.appendChild(description);
		}
		wrap.appendChild(prompt);
		container.appendChild(wrap);
		sync();

		return {
			values: () => ({ preset: select.value, prompt: select.value === 'custom' ? prompt.value.trim() : '' }),
			validate: () => (select.value === 'custom' && !prompt.value.trim() ? t('promptRequired') : null),
			select: select,
		};
	}

	function radios(container, legend, options, selected) {
		const name = nextId('fluxis-choice');
		const fieldset = el('fieldset', { class: 'fluxis-field fluxis-inline-choice' });

		fieldset.appendChild(el('legend', {}, legend));
		options.forEach(([value, label]) => {
			const option = el('label', { class: 'fluxis-check' });
			const radio = el('input', { type: 'radio', name: name, value: value });
			radio.checked = value === selected;
			option.appendChild(radio);
			option.appendChild(document.createTextNode(' ' + label));
			fieldset.appendChild(option);
		});
		container.appendChild(fieldset);

		return () => {
			const checked = fieldset.querySelector('input:checked');
			return checked ? checked.value : selected;
		};
	}

	function busy(container, label) {
		container.innerHTML = '';
		const line = el('p', { class: 'fluxis-busy', role: 'status' });
		line.appendChild(el('span', { class: 'spinner is-active' }));
		line.appendChild(document.createTextNode(label));
		container.appendChild(line);
		return line;
	}

	/** Animated placeholder while Fluxis AI works. */
	function skeleton(container, label) {
		container.innerHTML = '';
		const box = el('div', { class: 'fluxis-skeleton', role: 'status' });
		box.appendChild(el('p', { class: 'fluxis-skeleton__label' }, label));
		[92, 78, 60].forEach((width) => {
			const bar = el('span', { class: 'fluxis-skeleton__bar' });
			bar.style.width = width + '%';
			box.appendChild(bar);
		});
		container.appendChild(box);
		return box;
	}

	/* --------------------------------------------------- proposal views */

	/**
	 * Before/after per field with Accept per field and Accept all.
	 * fields: [{key, label, value, kind}]; apply(field, value) writes one value.
	 */
	function textProposals(container, fields, after, apply, extra) {
		const list = el('div', { class: 'fluxis-proposals' });
		const pending = [];

		fields.forEach((field) => {
			if (!after || typeof after[field.key] !== 'string') {
				return;
			}

			const value = after[field.key];
			const card = el('div', { class: 'fluxis-proposal' });
			const actions = el('p', { class: 'fluxis-proposal__actions' });
			const accept = el('button', { type: 'button', class: 'button fluxis-ai-button fluxis-ai-button--primary button-small' }, t('accept'));

			card.appendChild(el('h4', {}, field.label));

			if (fields.length > 1 || (extra && extra.showBefore)) {
				card.appendChild(el('p', { class: 'fluxis-proposal__label' }, t('before')));
				card.appendChild(el('div', { class: 'fluxis-proposal__before' }, field.kind === 'rich' ? stripTags(field.value || '') || '—' : field.value || '—'));
				card.appendChild(el('p', { class: 'fluxis-proposal__label' }, t('after')));
			}

			const proposal = el('div', { class: 'fluxis-proposal__after' + (field.kind === 'rich' ? ' fluxis-proposal__after--rich' : '') });
			if (field.kind === 'rich') {
				// Sanitized server-side (wp_kses_post) before it reaches the page.
				proposal.innerHTML = value;
			} else {
				proposal.textContent = stripTags(value);
			}
			card.appendChild(proposal);

			async function doAccept() {
				accept.disabled = true;
				const done = await apply(field, field.kind === 'rich' ? value : stripTags(value));
				if (done !== false) {
					accept.textContent = t('accepted');
					card.classList.add('is-accepted');
				} else {
					accept.disabled = false;
				}
			}

			accept.addEventListener('click', doAccept);
			pending.push(doAccept);
			actions.appendChild(accept);
			card.appendChild(actions);
			list.appendChild(card);
		});

		if (pending.length === 0) {
			container.appendChild(el('p', {}, t('nothingToDo')));
			return;
		}

		container.appendChild(list);

		if (pending.length > 1) {
			const all = el('button', { type: 'button', class: 'button' }, t('acceptAll'));
			all.addEventListener('click', () => {
				all.disabled = true;
				pending.forEach((fn) => fn());
			});
			container.appendChild(el('p', {}, '')).appendChild(all);
		}
	}

	/**
	 * Before/after image card; onAccept(card) resolves the accept request.
	 */
	async function imageProposal(container, before, item, onAccept, applyLabel) {
		const card = el('div', { class: 'fluxis-image-result' });
		const pair = el('div', { class: 'fluxis-image-result__pair' });
		const beforeBox = el('figure', {});
		const afterBox = el('figure', {});
		const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });

		beforeBox.appendChild(el('img', { src: before.url, alt: '' }));
		beforeBox.appendChild(el('figcaption', {}, t('before')));
		afterBox.appendChild(el('span', { class: 'spinner is-active' }));
		afterBox.appendChild(el('figcaption', {}, t('after')));
		pair.appendChild(beforeBox);
		pair.appendChild(afterBox);
		if (before.label) {
			card.appendChild(el('h4', {}, before.label));
		}
		card.appendChild(pair);
		card.appendChild(message);
		container.appendChild(card);

		const preview = await FluxisAi.preview(item.run_id, item.item_id);

		afterBox.querySelector('.spinner').remove();

		if (!preview.ok) {
			errorBox(message, preview.data);
			return card;
		}

		afterBox.insertBefore(el('img', { src: preview.data.data_uri, alt: t('after') }), afterBox.firstChild);

		const actions = el('p', { class: 'fluxis-proposal__actions' });
		const accept = el('button', { type: 'button', class: 'button fluxis-ai-button fluxis-ai-button--primary' }, applyLabel || t('accept'));

		accept.addEventListener('click', async () => {
			accept.disabled = true;
			accept.textContent = t('saving');
			const result = await onAccept(card);

			if (!result || !result.ok) {
				accept.disabled = false;
				accept.textContent = applyLabel || t('accept');
				errorBox(message, result ? result.data : {});
				return;
			}

			message.hidden = true;
			accept.textContent = t('accepted');
			card.classList.add('is-accepted');
		});

		actions.appendChild(accept);
		card.appendChild(actions);

		return card;
	}

	/* ------------------------------------------------- DOM field access */

	function editorContent(editorId) {
		const editor = window.tinymce && window.tinymce.get(editorId);

		if (editor && !editor.isHidden()) {
			return editor.getContent();
		}

		const textarea = document.getElementById(editorId);
		return textarea ? textarea.value : '';
	}

	function setEditorContent(editorId, html) {
		const editor = window.tinymce && window.tinymce.get(editorId);
		const textarea = document.getElementById(editorId);

		if (editor) {
			editor.setContent(html);
			editor.save();
			editor.setDirty(true);
			editor.fire('change');
		}

		if (textarea) {
			setValue(textarea, html);
		}

		markDirty();
	}

	const yoast = {
		store() {
			try {
				return window.wp && window.wp.data && window.wp.data.select('yoast-seo/editor') ? window.wp.data : null;
			} catch (error) {
				return null;
			}
		},
		get(key) {
			const data = this.store();

			try {
				if (data && key === 'seo_title') {
					return data.select('yoast-seo/editor').getSnippetEditorData().title || '';
				}
				if (data && key === 'seo_description') {
					return data.select('yoast-seo/editor').getSnippetEditorData().description || '';
				}
				if (data && key === 'seo_focus_keyword') {
					return data.select('yoast-seo/editor').getFocusKeyphrase() || '';
				}
			} catch (error) {
				// Fall back to the hidden inputs below.
			}

			const hidden = document.getElementById({ seo_title: 'yoast_wpseo_title', seo_description: 'yoast_wpseo_metadesc', seo_focus_keyword: 'yoast_wpseo_focuskw' }[key]);
			return hidden ? hidden.value : '';
		},
		set(key, value) {
			const data = this.store();
			const hiddenId = { seo_title: 'yoast_wpseo_title', seo_description: 'yoast_wpseo_metadesc', seo_focus_keyword: 'yoast_wpseo_focuskw' }[key];
			let done = false;

			try {
				if (data) {
					const dispatch = data.dispatch('yoast-seo/editor');

					if (key === 'seo_title') {
						dispatch.updateData({ title: value });
					} else if (key === 'seo_description') {
						dispatch.updateData({ description: value });
					} else if (key === 'seo_focus_keyword') {
						dispatch.setFocusKeyword(value);
					}

					done = true;
				}
			} catch (error) {
				done = false;
			}

			const hidden = document.getElementById(hiddenId);
			if (hidden && (!done || hidden.value !== value)) {
				hidden.value = value;
			}

			markDirty();
		},
	};

	/** Current value and writer of a synced target on the edit screen. */
	const syncedFields = {
		name: { get: () => (document.getElementById('title') || {}).value || '', set: (value) => setValue(document.getElementById('title'), value), kind: 'plain' },
		description: { get: () => editorContent('content'), set: (value) => setEditorContent('content', value), kind: 'rich' },
		short_description: { get: () => editorContent('excerpt'), set: (value) => setEditorContent('excerpt', value), kind: 'rich' },
		seo_title: { get: () => yoast.get('seo_title'), set: (value) => yoast.set('seo_title', value), kind: 'plain', max: 70 },
		seo_description: { get: () => yoast.get('seo_description'), set: (value) => yoast.set('seo_description', value), kind: 'plain', max: 160 },
		seo_focus_keyword: { get: () => yoast.get('seo_focus_keyword'), set: (value) => yoast.set('seo_focus_keyword', value), kind: 'plain', max: 100 },
	};

	/** Custom Fields box textarea of a meta key, when the box shows it. */
	function postcustomInput(metaKey) {
		const keys = document.querySelectorAll('#postcustom input[name^="meta["][name$="[key]"]');

		for (let index = 0; index < keys.length; index++) {
			if (keys[index].value === metaKey) {
				return document.querySelector('[name="' + keys[index].name.replace('[key]', '[value]') + '"]');
			}
		}

		return null;
	}

	function fieldTarget(field) {
		if (field.target) {
			const node = document.querySelector(field.target);
			if (node) {
				return node;
			}
		}

		return field.key.indexOf('meta:') === 0 ? postcustomInput(field.key.slice(5)) : null;
	}

	/* ------------------------------------------- single-field ad-hoc flow */

	/**
	 * Popover for one text field: options → suggestion → Accept / Try again.
	 * After a suggestion the options collapse into a one-line summary.
	 */
	function fieldPopover(anchor, field, apply, extraNote) {
		popover(
			anchor,
			t('fluxisAi'),
			(body, close) => {
				if (!gate(body)) {
					return;
				}

				const optionsBox = el('div', { class: 'fluxis-popover__options' });
				const options = textOptions(optionsBox);
				const summary = el('div', { class: 'fluxis-popover__summary', hidden: 'hidden' });
				const summaryText = el('span', {});
				const change = el('button', { type: 'button', class: 'button-link' }, t('changeOptions'));
				const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });
				const result = el('div', { class: 'fluxis-popover__result', 'aria-live': 'polite' });
				const run = generateButton();
				summary.appendChild(summaryText);
				summary.appendChild(change);
				body.appendChild(optionsBox);
				body.appendChild(summary);
				body.appendChild(run);
				body.appendChild(message);
				body.appendChild(result);
				body.appendChild(el('p', { class: 'fluxis-shortcut' }, t('shortcutHint')));

				change.addEventListener('click', () => {
					optionsBox.hidden = false;
					summary.hidden = true;
				});

				async function generate() {
					const error = options.validate();

					if (error) {
						message.textContent = error;
						message.hidden = false;
						return;
					}

					message.hidden = true;
					run.disabled = true;
					skeleton(result, t('generating'));

					const current = Object.assign({}, field, { value: typeof field.read === 'function' ? field.read() : field.value });
					const response = await FluxisAi.text(field.context || { product_id: productId() }, [current], options.values());

					run.disabled = false;
					result.innerHTML = '';

					if (!response.ok) {
						errorBox(message, response.data);
						return;
					}

					const value = response.data.after && typeof response.data.after[current.key] === 'string' ? response.data.after[current.key] : null;

					if (value === null) {
						errorBox(message, { error: 'ai_optimizer_nothing_to_do' });
						return;
					}

					optionsBox.hidden = true;
					summary.hidden = false;
					summaryText.textContent = options.summary();
					run.querySelector('span').textContent = t('tryAgain');
					run.classList.remove('fluxis-ai-button--primary');

					const card = el('div', { class: 'fluxis-suggestion' });
					const text = el('div', { class: 'fluxis-suggestion__text' + (current.kind === 'rich' ? ' fluxis-suggestion__text--rich' : '') });
					const plain = stripTags(value);

					if (current.kind === 'rich') {
						// Sanitized server-side (wp_kses_post) before it reaches the page.
						text.innerHTML = value;
					} else {
						text.textContent = plain;
					}

					card.appendChild(el('p', { class: 'fluxis-suggestion__label' }, t('suggestion')));
					card.appendChild(text);
					card.appendChild(el('p', { class: 'fluxis-suggestion__meta' }, F.sprintf(t('characters'), plain.length) + (current.max_length ? ' / ' + current.max_length : '')));

					const actions = el('div', { class: 'fluxis-suggestion__actions' });
					const accept = el('button', { type: 'button', class: 'button fluxis-ai-button fluxis-ai-button--primary' }, '✓ ' + t('accept'));
					const dismiss = el('button', { type: 'button', class: 'button-link' }, t('discard'));

					accept.addEventListener('click', () => {
						apply(current.kind === 'rich' ? value : plain);
						// Saved on "Update"; the save applies it to the mapped Fluxis field too.
						rememberAccepted({ run_id: response.data.run_id, item_id: response.data.item_id, key: current.key, path: current.path || null, label: current.label });
						close();
					});
					dismiss.addEventListener('click', close);
					actions.appendChild(accept);
					actions.appendChild(dismiss);
					card.appendChild(actions);
					result.appendChild(card);

					if (extraNote) {
						result.appendChild(el('p', { class: 'fluxis-hint' }, extraNote));
					}

					accept.focus({ preventScroll: true });
				}

				run.addEventListener('click', generate);
				body.addEventListener('keydown', (event) => {
					if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
						event.preventDefault();
						generate();
					}
				});
			},
			field.label
		);
	}

	/* --------------------------------------- product data panel buttons */

	function blacklisted(nameOrId) {
		const value = String(nameOrId || '').toLowerCase();
		const words = value.split(/[^a-z0-9]+/).filter(Boolean);

		return (cfg.fieldBlacklist || []).some((needle) => (needle.indexOf('_') !== -1 ? value.indexOf(needle) !== -1 : words.indexOf(needle) !== -1));
	}

	function eligible(input) {
		if (input.dataset.fluxisAi || input.readOnly || input.disabled || input.type === 'hidden') {
			return false;
		}

		if (input.closest('.select2-container, .fluxis-popover, .fluxis-modal, .wc-metabox-template') || input.classList.contains('date-picker')) {
			return false;
		}

		if (/wc_input_(price|decimal|stock)/.test(input.className)) {
			return false;
		}

		return !blacklisted(input.name) && !blacklisted(input.id) && Boolean(input.name || input.id);
	}

	function labelFor(input) {
		const byFor = input.id ? document.querySelector('label[for="' + window.CSS.escape(input.id) + '"]') : null;
		const row = input.closest('.form-field, .form-row, td, p');
		const label = byFor || (row && row.querySelector('label'));
		let text = label ? label.textContent.trim() : '';

		const variation = input.closest('.woocommerce_variation');
		if (variation) {
			const title = variation.querySelector('h3');
			const name = title ? title.textContent.replace(/\s+/g, ' ').trim().slice(0, 60) : '';
			text = text + (name ? ' — ' + name : '');
		}

		const attribute = input.closest('.woocommerce_attribute');
		if (attribute) {
			const attrName = attribute.querySelector('.attribute_name input, input.attribute_name, strong.attribute_name');
			text = t('attributeValues') + (attrName ? ': ' + (attrName.value || attrName.textContent || '').trim() : '');
		}

		return (text || input.placeholder || input.name || input.id).slice(0, 120);
	}

	/** Disables a Fluxis trigger while the setup in Fluxis is not finished; the tooltip says why. */
	function gate(button) {
		if (SETUP_BLOCKED) {
			button.disabled = true;
			button.setAttribute('aria-disabled', 'true');
			button.setAttribute('title', t('setupFirst'));
		}

		return button;
	}

	function iconButton(label, action) {
		const button = el('button', { type: 'button', class: 'fluxis-ai-icon', 'aria-label': label, title: label, 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'data-fluxis-ai-action': action });
		button.innerHTML = ICON;
		return gate(button);
	}

	/** Labelled Fluxis AI button, the same everywhere (editors, Yoast). */
	function aiButton(label, action, extraClass) {
		const button = el('button', { type: 'button', class: 'button fluxis-ai-button' + (extraClass ? ' ' + extraClass : ''), 'aria-label': t('optimizeWithFluxis'), title: t('optimizeWithFluxis'), 'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'data-fluxis-ai-action': action });
		button.innerHTML = ICON;
		button.appendChild(el('span', {}, label));
		return gate(button);
	}

	/** Full-width "Generate" button with the Fluxis AI mark (not a delegated trigger). */
	function generateButton() {
		const button = el('button', { type: 'button', class: 'button fluxis-ai-button fluxis-ai-button--primary fluxis-ai-button--block' });
		button.innerHTML = ICON;
		button.appendChild(el('span', {}, t('generate')));
		return button;
	}

	/**
	 * The icon sits inside the input's row at its right end: the input moves into a
	 * wrapper that takes over its layout, so the input width is not reduced.
	 */
	function attachFieldButton(input) {
		input.dataset.fluxisAi = '1';

		const wrapper = el('span', { class: 'fluxis-ai-field' + (input.tagName === 'TEXTAREA' ? ' fluxis-ai-field--textarea' : '') });
		const button = iconButton(t('optimizeWithFluxis'), 'field');

		input.parentNode.insertBefore(wrapper, input);
		wrapper.appendChild(input);
		wrapper.appendChild(button);
		triggerOptions.set(button, input);
	}

	actions.field = (button) => {
		const input = triggerOptions.get(button);

		if (!input) {
			return;
		}

		fieldPopover(button, {
			key: wireKey(input.name || input.id),
			label: labelFor(input),
			kind: 'plain',
			max_length: input.maxLength > 0 ? input.maxLength : null,
			hint: input.closest('.woocommerce_attribute') ? t('attributeHint') : null,
			path: attributePath(input),
			read: () => input.value,
		}, (value) => setValue(input, value));
	};

	function scanProductData() {
		const root = document.getElementById('woocommerce-product-data');

		if (!root) {
			return;
		}

		root.querySelectorAll('input[type="text"], textarea').forEach((input) => {
			if (eligible(input)) {
				attachFieldButton(input);
			}
		});
	}

	/* ------------------------------------------------------ editor buttons */

	function initEditors() {
		actions.editor = (button) => {
			const editorId = button.getAttribute('data-fluxis-editor');
			const key = { content: 'description', excerpt: 'short_description' }[editorId] || wireKey(editorId);
			const label = (cfg.textTargets || {})[key] || editorId;

			fieldPopover(button, { key: key, label: label, kind: 'rich', path: syncedPath(key), read: () => editorContent(editorId) }, (value) => setEditorContent(editorId, value), t('savedOnUpdate'));
		};
	}

	/* -------------------------------------------------------------- Yoast */

	function initYoast() {
		if (!cfg.yoast) {
			return;
		}

		const targets = [
			['[id^="yst-replacevar__use-ai-button__title"]', 'seo_title'],
			['[id^="yst-replacevar__use-ai-button__description"]', 'seo_description'],
		];

		function attach() {
			if (!yoast.store()) {
				return;
			}

			targets.forEach(([selector, key]) => {
				document.querySelectorAll(selector).forEach((yoastButton) => {
					const anchor = yoastButton.closest('.yst-root') || yoastButton;

					if (anchor.nextElementSibling && anchor.nextElementSibling.classList.contains('fluxis-yoast-button')) {
						return;
					}

					anchor.insertAdjacentElement('afterend', yoastButtonFor(key));
				});
			});

			const keyphrase = document.querySelector('#focus-keyword-input-metabox');
			const field = keyphrase && keyphrase.closest('.yst-text-field');

			if (field && !(field.nextElementSibling && field.nextElementSibling.classList.contains('fluxis-yoast-button'))) {
				field.insertAdjacentElement('afterend', yoastButtonFor('seo_focus_keyword'));
			}
		}

		function yoastButtonFor(key) {
			const button = aiButton(t('optimizeWithFluxisShort'), 'yoast', 'fluxis-yoast-button');
			button.setAttribute('data-fluxis-yoast', key);
			return button;
		}

		actions.yoast = (button) => {
			const key = button.getAttribute('data-fluxis-yoast');
			const meta = syncedFields[key];

			if (meta) {
				fieldPopover(button, { key: key, label: cfg.textTargets[key] || key, kind: 'plain', max_length: meta.max, path: syncedPath(key), read: meta.get }, meta.set, t('savedOnUpdate'));
			}
		};

		let timer = null;
		const observer = new MutationObserver(() => {
			window.clearTimeout(timer);
			timer = window.setTimeout(attach, 250);
		});
		const root = document.getElementById('wpseo_meta') || document.body;

		observer.observe(root, { childList: true, subtree: true });
		attach();
	}

	/* ------------------------------------------- image popover (one image) */

	function imagePopover(anchor, attachmentId, beforeUrl, options) {
		popover(anchor, t('optimizeImage'), (body, close) => {
			if (!gate(body)) {
				return;
			}

			const presets = presetSelect(body);
			const mode = options.chooseMode ? radios(body, t('applyAs'), [['replace', t('applyReplace')], ['add', t('applyAdd')]], 'replace') : null;
			const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });
			const actions = el('p', { class: 'fluxis-popover__actions' });
			const run = generateButton();
			const result = el('div', { class: 'fluxis-popover__result' });

			actions.appendChild(run);
			body.appendChild(actions);
			body.appendChild(message);
			body.appendChild(result);

			run.addEventListener('click', async () => {
				const error = presets.validate();

				if (error) {
					message.textContent = error;
					message.hidden = false;
					return;
				}

				message.hidden = true;
				run.disabled = true;
				skeleton(result, t('generatingImage'));

				const context = options.productId ? { product_id: options.productId } : {};
				const response = await FluxisAi.image(context, attachmentId, presets.values());

				run.disabled = false;
				run.querySelector('span').textContent = t('tryAgain');
				result.innerHTML = '';

				if (!response.ok) {
					errorBox(message, response.data);
					return;
				}

				await imageProposal(result, { url: beforeUrl }, response.data, async () => {
					const accepted = await options.accept(response.data, mode ? mode() : null);
					if (accepted && accepted.ok) {
						window.setTimeout(close, 600);
					}
					return accepted;
				});
			});
		});
	}

	/* ------------------------------------------------- featured image box */

	function applyProductImageState(state) {
		if (!state) {
			return;
		}

		const inside = document.querySelector('#postimagediv .inside');
		if (inside && typeof state.featured_html === 'string' && state.featured_html !== '') {
			inside.innerHTML = state.featured_html;
		}

		if (window.wp && window.wp.media && window.wp.media.view && window.wp.media.view.settings && window.wp.media.view.settings.post) {
			window.wp.media.view.settings.post.featuredImageId = state.thumbnail_id || -1;
		}

		const galleryInput = document.getElementById('product_image_gallery');
		const galleryList = document.querySelector('#product_images_container ul.product_images');
		if (galleryInput && Array.isArray(state.gallery_ids)) {
			galleryInput.value = state.gallery_ids.join(',');
		}
		if (galleryList && typeof state.gallery_html === 'string') {
			galleryList.innerHTML = state.gallery_html;
			decorateGallery();
		}
	}

	function initFeatured() {
		actions.featured = (button) => {
			const attachmentId = parseInt(button.getAttribute('data-fluxis-featured'), 10);
			const img = document.querySelector('#postimagediv img');

			imagePopover(button, attachmentId, img ? img.src : '', {
				productId: productId(),
				accept: async (item) => {
					const result = await FluxisAi.acceptImage({ run_id: item.run_id, item_id: item.item_id, attachment_id: attachmentId, product_id: productId(), mode: 'replace', only: 'featured' });
					if (result.ok) {
						applyProductImageState({ featured_html: result.data.featured_html, thumbnail_id: result.data.thumbnail_id });
						refreshPanel();
					}
					return result;
				},
			});
		};
	}

	/* ---------------------------------------------------- gallery overlay */

	function syncGalleryInput() {
		const input = document.getElementById('product_image_gallery');
		const ids = Array.prototype.map.call(document.querySelectorAll('#product_images_container ul.product_images li.image'), (li) => li.getAttribute('data-attachment_id'));

		if (input) {
			input.value = ids.filter(Boolean).join(',');
		}

		markDirty();
	}

	function decorateGallery() {
		document.querySelectorAll('#product_images_container ul.product_images li.image').forEach((li) => {
			if (li.querySelector('.fluxis-gallery-ai')) {
				return;
			}

			const button = iconButton(t('optimizeImageWithFluxis'), 'gallery');
			button.classList.add('fluxis-gallery-ai');
			li.appendChild(button);
		});
	}

	actions.gallery = (button) => {
		const li = button.closest('li.image');

		if (li) {
				const attachmentId = parseInt(li.getAttribute('data-attachment_id'), 10);
				const img = li.querySelector('img');

				imagePopover(button, attachmentId, img ? img.src : '', {
					productId: productId(),
					chooseMode: true,
					accept: async (item, mode) => {
						// Saved with the product (like WooCommerce's own gallery): only the form changes here.
						const result = await FluxisAi.acceptImage({ run_id: item.run_id, item_id: item.item_id, attachment_id: attachmentId, product_id: productId(), mode: 'none' });

						if (result.ok) {
							const entry = el('li', { class: 'image', 'data-attachment_id': String(result.data.attachment_id) });
							entry.innerHTML = result.data.thumb_html + '<ul class="actions"><li><a href="#" class="delete tips">' + t('delete') + '</a></li></ul>';

							const list = li.parentNode;

							if (mode === 'add') {
								list.appendChild(entry);
							} else {
								list.replaceChild(entry, li);
							}

							decorateGallery();
							syncGalleryInput();
							// The gallery is saved on "Update"; the save applies the image in Fluxis too.
							rememberAccepted({
								run_id: item.run_id,
								item_id: item.item_id,
								image: { attachment_id: result.data.attachment_id, role: mode === 'add' ? 'gallery' : 'replace', replaced_attachment_id: mode === 'add' ? null : attachmentId },
								label: t('galleryImage'),
							});
						}

						return result;
					},
				});
		}
	};

	function initGallery() {
		const list = document.querySelector('#product_images_container ul.product_images');

		if (!list) {
			return;
		}

		decorateGallery();
		new MutationObserver(decorateGallery).observe(list, { childList: true });
	}

	/* --------------------------------------------------------- media modal */

	const MEDIA_FIELDS = [
		['alt', '[data-setting="alt"] textarea, [data-setting="alt"] input, #attachment_alt', 'altText', 'plain', 300],
		['title', '[data-setting="title"] input, #title', 'attachmentTitle', 'plain', 200],
		['caption', '[data-setting="caption"] textarea, #attachment_caption', 'caption', 'plain', null],
		['description', '[data-setting="description"] textarea, #attachment_content', 'attachmentDescription', 'plain', null],
	];

	function mediaScope(node) {
		let scope = node.closest('.media-sidebar, .attachment-details, .media-frame-content, #post-body, form');

		while (scope && !MEDIA_FIELDS.some((field) => scope.querySelector(field[1]))) {
			scope = scope.parentElement ? scope.parentElement.closest('.media-modal-content, .media-frame, #post-body, form, body') : null;
		}

		return scope || document;
	}

	function selectInMediaFrame(attachmentId) {
		const media = window.wp && window.wp.media;
		const frame = media && media.frame;

		if (!media || !frame || typeof frame.state !== 'function') {
			return;
		}

		const attachment = media.attachment(attachmentId);

		attachment.fetch().then(() => {
			const state = frame.state();
			const library = state && state.get('library');
			const selection = state && state.get('selection');

			if (library) {
				library.add(attachment, { at: 0 });
			}

			if (selection) {
				if (selection.multiple) {
					selection.add(attachment);
				} else {
					selection.reset([attachment]);
				}
			}
		});
	}

	/**
	 * A "Fluxis AI" card in the attachment details sidebar (media modal and media
	 * library), above the "Required fields are marked *" note.
	 */
	function mediaCard(attachmentId) {
		const card = el('div', { class: 'fluxis-ai-card', 'data-fluxis-media': String(attachmentId) });
		const head = el('p', { class: 'fluxis-ai-card__head' });
		const imageButton = aiButton(t('optimizeImage'), 'media-image', 'fluxis-ai-button--primary');
		const textsButton = aiButton(t('optimizeTexts'), 'media-texts');

		head.innerHTML = ICON;
		head.appendChild(el('span', {}, t('fluxisAi')));
		card.appendChild(head);
		card.appendChild(el('p', { class: 'fluxis-ai-card__help' }, t('mediaCardHelp')));
		imageButton.setAttribute('aria-label', t('optimizeImage'));
		textsButton.setAttribute('aria-label', t('optimizeTexts'));
		card.appendChild(imageButton);
		card.appendChild(textsButton);

		return card;
	}

	/** The attachment shown in a details sidebar: data-id, its edit link, or the frame selection. */
	function detailsAttachmentId(details) {
		if (details.getAttribute('data-id')) {
			return parseInt(details.getAttribute('data-id'), 10) || 0;
		}

		const link = details.querySelector('a[href*="post.php?post="]');
		const match = link ? /[?&]post=(\d+)/.exec(link.getAttribute('href')) : null;

		if (match) {
			return parseInt(match[1], 10);
		}

		try {
			const frame = window.wp && window.wp.media && window.wp.media.frame;
			const selection = frame && frame.state() && frame.state().get('selection');
			const first = selection && selection.first();
			return first ? parseInt(first.id, 10) || 0 : 0;
		} catch (error) {
			return 0;
		}
	}

	function decorateMediaDetails() {
		document.querySelectorAll('.attachment-details').forEach((details) => {
			const attachmentId = detailsAttachmentId(details);
			const existing = details.querySelector('.fluxis-ai-card');

			if (existing && existing.getAttribute('data-fluxis-media') === String(attachmentId)) {
				return;
			}

			if (existing) {
				existing.remove();
			}

			if (!attachmentId || !details.querySelector('.thumbnail-image, .details-image, .attachment-media-view img')) {
				return;
			}

			const card = mediaCard(attachmentId);
			const note = details.querySelector('.media-types-required-info');
			const url = details.querySelector('[data-setting="url"]');

			if (note) {
				note.parentNode.insertBefore(card, note);
			} else if (url) {
				url.insertAdjacentElement('afterend', card);
			} else {
				details.appendChild(card);
			}
		});
	}

	function initMedia() {
		actions['media-texts'] = (button) => media(button, true);
		actions['media-image'] = (button) => media(button, false);

		let timer = null;
		new MutationObserver(() => {
			window.clearTimeout(timer);
			timer = window.setTimeout(decorateMediaDetails, 150);
		}).observe(document.body, { childList: true, subtree: true });
		decorateMediaDetails();
	}

	function media(trigger, isTexts) {
		{
			const texts = isTexts ? trigger : null;
			const image = isTexts ? null : trigger;
			const holder = trigger.closest('[data-fluxis-media]');
			const attachmentId = parseInt(holder.getAttribute('data-fluxis-media'), 10);
			const scope = mediaScope(holder);
			const product = productId();
			const context = product ? { product_id: product, attachment_id: attachmentId } : { attachment_id: attachmentId };

			if (texts) {
				const fields = MEDIA_FIELDS.map(([key, selector, label, kind, max]) => ({ key: key, input: scope.querySelector(selector), label: t(label), kind: kind, max_length: max })).filter((field) => field.input);

				popover(texts, t('optimizeTexts'), (body, close) => {
					if (!gate(body)) {
						return;
					}

					const checks = el('fieldset', { class: 'fluxis-field' });
					checks.appendChild(el('legend', {}, t('fieldsToOptimize')));
					fields.forEach((field) => {
						const label = el('label', { class: 'fluxis-check' });
						const box = el('input', { type: 'checkbox', value: field.key });
						box.checked = true;
						label.appendChild(box);
						label.appendChild(document.createTextNode(' ' + field.label));
						checks.appendChild(label);
					});
					body.appendChild(checks);

					const options = textOptions(body);
					const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });
					const actions = el('p', { class: 'fluxis-popover__actions' });
					const run = generateButton();
					const result = el('div', { class: 'fluxis-popover__result' });

					actions.appendChild(run);
					body.appendChild(actions);
					body.appendChild(message);
					body.appendChild(result);

					run.addEventListener('click', async () => {
						const chosen = Array.prototype.map.call(checks.querySelectorAll('input:checked'), (box) => box.value);
						const selected = fields.filter((field) => chosen.indexOf(field.key) !== -1).map((field) => Object.assign({}, field, { value: field.input.value }));
						const error = selected.length === 0 ? t('selectTarget') : options.validate();

						if (error) {
							message.textContent = error;
							message.hidden = false;
							return;
						}

						message.hidden = true;
						run.disabled = true;
						skeleton(result, t('generating'));
						const response = await FluxisAi.text(context, selected, options.values());
						run.disabled = false;
						run.querySelector('span').textContent = t('tryAgain');
						result.innerHTML = '';

						if (!response.ok) {
							errorBox(message, response.data);
							return;
						}

						const appliedKeys = [];

textProposals(
	result,
	selected,
	response.data.after,
	(field, value) => {
		setValue(field.input, value);
		// Attachment fields have no Fluxis counterpart; the apply marks the item delivered.
		appliedKeys.push(field.key);
		applyNow(
			appliedKeys.map((key) => ({
				run_id: response.data.run_id,
				item_id: response.data.item_id,
				key: key,
				label: (fields.find((candidate) => candidate.key === key) || {}).label || key,
				scope: 'attachment',
				attachment_id: attachmentId,
			}))
		);
	},
	{ showBefore: true }
);
						const done = el('button', { type: 'button', class: 'button-link' }, t('close'));
						done.addEventListener('click', close);
						result.appendChild(done);
					});
				});

				return;
			}

			const thumb = scope.querySelector('.attachment-details .thumbnail img, .details-image, .wp_attachment_image img, img');

			imagePopover(image, attachmentId, thumb ? thumb.src : '', {
				productId: product,
				accept: async (item) => {
					const result = await FluxisAi.acceptImage({ run_id: item.run_id, item_id: item.item_id, attachment_id: attachmentId, product_id: product, mode: 'none' });
					if (result.ok) {
						selectInMediaFrame(result.data.attachment_id);
					}
					return result;
				},
			});
		}
	}

	/* -------------------------------------------------- image rows (modal) */

	/**
	 * Selectable image tiles; the edit and "apply as" choices appear on a selected tile.
	 * onChange() runs whenever the selection changes.
	 */
	function imageRows(container, images, onChange) {
		const rows = [];

		if (!images.length) {
			container.appendChild(el('p', { class: 'fluxis-empty' }, t('noImages')));
			return rows;
		}

		const grid = el('div', { class: 'fluxis-tiles' });

		images.forEach((image) => {
			const tile = el('div', { class: 'fluxis-tile' });
			const pick = el('label', { class: 'fluxis-tile__pick' });
			const check = el('input', { type: 'checkbox', class: 'fluxis-tile__check', 'aria-label': t('selectImage') + ': ' + (image.title || image.attachment_id) });
			const media = el('span', { class: 'fluxis-tile__media' });
			const info = el('span', { class: 'fluxis-tile__info' });
			const controls = el('div', { class: 'fluxis-tile__controls', hidden: 'hidden' });

			media.appendChild(el('img', { src: image.thumb_url || image.src, alt: image.alt || '' }));
			media.appendChild(el('span', { class: 'fluxis-tile__tick', 'aria-hidden': 'true' }, '✓'));
			info.appendChild(el('strong', {}, image.title || '#' + image.attachment_id));
			const usages = el('span', { class: 'fluxis-tile__usages' });
			image.usages.forEach((usage) => usages.appendChild(el('span', { class: 'fluxis-chip' }, usage.label)));
			info.appendChild(usages);
			pick.appendChild(check);
			pick.appendChild(media);
			pick.appendChild(info);
			tile.appendChild(pick);

			const presets = presetSelect(controls, true);
			const apply = el('select', { 'aria-label': t('applyAs') });
			apply.appendChild(el('option', { value: 'replace' }, t('applyReplace')));
			apply.appendChild(el('option', { value: 'add' }, t('applyAdd')));
			controls.appendChild(apply);
			tile.appendChild(controls);
			grid.appendChild(tile);

			check.addEventListener('change', () => {
				tile.classList.toggle('is-selected', check.checked);
				controls.hidden = !check.checked;

				if (typeof onChange === 'function') {
					onChange();
				}
			});

			rows.push({ image: image, check: check, presets: presets, apply: apply });
		});

		container.appendChild(grid);

		return rows;
	}

	async function runImages(container, rows, product) {
		const selected = rows.filter((row) => row.check.checked);

		for (const row of selected) {
			const slot = el('div', { class: 'fluxis-image-slot' });
			container.appendChild(slot);
			skeleton(slot, t('generatingImage') + ' ' + (row.image.title || ''));

			const response = await FluxisAi.image({ product_id: product }, row.image.attachment_id, row.presets.values());
			slot.innerHTML = '';

			if (!response.ok) {
				const message = el('p', { class: 'fluxis-error', role: 'alert' });
				slot.appendChild(el('h4', {}, row.image.title || '#' + row.image.attachment_id));
				slot.appendChild(message);
				errorBox(message, response.data);
				continue;
			}

			const mode = row.apply.value;

			await imageProposal(
				slot,
				{ url: row.image.thumb_url || row.image.src, label: (row.image.title || '') + ' — ' + row.image.usages.map((usage) => usage.label).join(' · ') },
				response.data,
				async () => {
					const result = await FluxisAi.acceptImage({ run_id: response.data.run_id, item_id: response.data.item_id, attachment_id: row.image.attachment_id, product_id: product, mode: mode });
					if (result.ok) {
						applyProductImageState(result.data);
						refreshPanel();
					}
					return result;
				},
				mode === 'add' ? t('acceptAdd') : t('acceptReplace')
			);
		}
	}

	/* ----------------------------------------------------- product dialogs */

	function initPanel() {
		const panel = document.querySelector('[data-fluxis-panel]');

		if (!panel) {
			return;
		}

		const product = parseInt(panel.getAttribute('data-product-id'), 10);
		const hasSku = panel.getAttribute('data-has-sku') === '1';
		const message = panel.querySelector('[data-fluxis-panel-message]');
		const state = panel.querySelector('[data-fluxis-sync-state]');
		const runPanel = panel.querySelector('[data-fluxis-run]');
		const syncButton = panel.querySelector('[data-fluxis-sync]');

		F.detachInputs(panel);

		function say(text, isError) {
			message.hidden = false;
			message.classList.toggle('fluxis-error', Boolean(isError));
			message.textContent = text;
		}

		if (syncButton) {
			actions.sync = async () => {
				syncButton.disabled = true;
				say(t('syncQueued'));

				const queued = await F.request('fluxis_sync_product', { product_id: product });

				if (!queued.ok) {
					syncButton.disabled = false;
					errorBox(message, queued.data);
					return;
				}

				for (let attempt = 0; attempt < 60; attempt++) {
					await wait(2000);
					const status = await F.request('fluxis_product_status', { product_id: product });

					if (status.ok && status.data.batch === queued.data.import_batch && status.data.result_code) {
						state.innerHTML = status.data.html;
						say(status.data.result_code === 'sent' ? t('syncSent') : t('syncFailed') + ' ' + status.data.last_result, status.data.result_code !== 'sent');
						break;
					}

					if (attempt === 59) {
						say(t('syncStillQueued'));
					}
				}

				syncButton.disabled = false;
			};
		}

		let watching = false;

refreshPanel = async () => {
	if (watching) {
		return;
	}

	watching = true;

	for (let attempt = 0; attempt < 20; attempt++) {
		await wait(attempt === 0 ? 1500 : 3000);
		const status = await F.request('fluxis_product_status', { product_id: product });

		if (status.ok) {
			state.innerHTML = status.data.html;

			if (!status.data.adhoc_pending) {
				break;
			}
		}
	}

	watching = false;
};

if (state && state.querySelector('[data-fluxis-adhoc-pending]')) {
	refreshPanel();
}

actions['optimize-images'] = () => openImagesModal(product);
		actions['optimize-product'] = () => openProductModal(product, hasSku, runPanel);
	}

	/** A titled card inside a dialog. */
	function section(container, title, description) {
		const root = el('section', { class: 'fluxis-section' });
		const head = el('div', { class: 'fluxis-section__head' });
		const titles = el('div', {});
		const body = el('div', { class: 'fluxis-section__body' });

		titles.appendChild(el('h3', {}, title));
		if (description) {
			titles.appendChild(el('p', { class: 'fluxis-hint' }, description));
		}
		head.appendChild(titles);
		root.appendChild(head);
		root.appendChild(body);
		container.appendChild(root);

		return { root: root, head: head, body: body };
	}

	/** Checkbox styled as a selectable chip. */
	function choiceChip(container, value, label, checked, detail) {
		const chip = el('label', { class: 'fluxis-choice' + (checked ? ' is-selected' : ''), title: detail || '' });
		const box = el('input', { type: 'checkbox', value: value });

		box.checked = checked;
		box.addEventListener('change', () => chip.classList.toggle('is-selected', box.checked));
		chip.appendChild(box);
		chip.appendChild(el('span', { class: 'fluxis-choice__tick', 'aria-hidden': 'true' }, '✓'));
		chip.appendChild(el('span', { class: 'fluxis-choice__label' }, label));
		container.appendChild(chip);

		return box;
	}

	/** "+ Add …" action button that turns into a loading state and disappears once used. */
	function addButton(label) {
		const button = el('button', { type: 'button', class: 'button fluxis-ai-button fluxis-add-button' });
		button.appendChild(el('span', { class: 'fluxis-add-button__plus', 'aria-hidden': 'true' }, '+'));
		button.appendChild(el('span', {}, label));
		return button;
	}

	function switchControl(container, label, checked) {
		const wrap = el('label', { class: 'fluxis-switch' });
		const input = el('input', { type: 'checkbox', role: 'switch' });

		input.checked = checked;
		wrap.appendChild(input);
		wrap.appendChild(el('span', { class: 'fluxis-switch__track', 'aria-hidden': 'true' }));
		wrap.appendChild(el('span', {}, label));
		container.appendChild(wrap);

		return input;
	}

	function dialogFooter(footer, close, startLabel) {
		const summary = el('span', { class: 'fluxis-modal__summary', 'aria-live': 'polite' });
		const cancel = el('button', { type: 'button', class: 'button fluxis-ai-button' }, t('cancelDialog'));
		const start = generateButton();

		start.classList.remove('fluxis-ai-button--block');
		start.querySelector('span').textContent = startLabel;
		footer.appendChild(summary);
		footer.appendChild(cancel);
		footer.appendChild(start);
		cancel.addEventListener('click', close);

		return { summary: summary, start: start, cancel: cancel };
	}

	function openImagesModal(product) {
		modal(t('optimizeImages'), async (body, footer, close) => {
			if (!gate(body)) {
				return;
			}

			const controls = dialogFooter(footer, close, t('start'));
			const images = section(body, t('imagesSection'), t('imagesIntro'));
			let rows = [];

			function update() {
				const count = rows.filter((row) => row.check.checked).length;
				controls.summary.textContent = F.sprintf(t('imagesSelected'), count);
				controls.start.disabled = count === 0;
			}

			update();
			skeleton(images.body, t('loadingImages'));
			const response = await FluxisAi.productImages(product);
			images.body.innerHTML = '';

			if (!response.ok) {
				errorBox(images.body, response.data);
				return;
			}

			rows = imageRows(images.body, response.data.images || [], update);

			const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });
			const results = el('div', { class: 'fluxis-results' });
			body.appendChild(message);
			body.appendChild(results);

			controls.start.addEventListener('click', async () => {
				const invalid = rows.filter((row) => row.check.checked).map((row) => row.presets.validate()).find(Boolean);

				if (invalid) {
					message.textContent = invalid;
					message.hidden = false;
					return;
				}

				message.hidden = true;
				controls.start.hidden = true;
				controls.summary.textContent = '';
				controls.cancel.textContent = t('close');
				images.root.hidden = true;
				const output = section(results, t('resultsTitle'), t('resultsIntro'));
				await runImages(output.body, rows, product);
			});
		});
	}

	function openProductModal(product, hasSku, runPanel) {
		modal(t('optimizeProduct'), (body, footer, close) => {
			if (!gate(body)) {
				return;
			}

			const controls = dialogFooter(footer, close, t('start'));
			const form = el('div', { class: 'fluxis-product-form' });
			body.appendChild(form);

			// Texts
			const texts = section(form, t('textsSection'), hasSku ? t('syncedNote') : t('adhocNoSkuNote'));
			const chips = el('div', { class: 'fluxis-choices' });
			const syncedBoxes = Object.keys(cfg.textTargets || {}).map((key) => choiceChip(chips, key, cfg.textTargets[key], true));
			texts.body.appendChild(chips);

			const moreWrap = el('div', { class: 'fluxis-subsection', hidden: 'hidden' });
			const moreChips = el('div', { class: 'fluxis-choices' });
			const moreButton = addButton(t('addMoreFields'));
			let moreFields = [];
			let moreBoxes = [];
			moreWrap.appendChild(el('p', { class: 'fluxis-subsection__title' }, t('moreFieldsLegend')));
			moreWrap.appendChild(el('p', { class: 'fluxis-hint' }, t('adhocNote')));
			moreWrap.appendChild(moreChips);
			texts.body.appendChild(moreWrap);
			texts.body.appendChild(moreButton);

			moreButton.addEventListener('click', async () => {
				moreButton.disabled = true;
				moreButton.lastChild.textContent = t('loadingFields');
				const response = await FluxisAi.productFields(product);
				moreWrap.hidden = false;

				if (!response.ok) {
					moreButton.disabled = false;
					moreButton.lastChild.textContent = t('addMoreFields');
					errorBox(moreChips, response.data);
					return;
				}

				moreButton.remove();
				moreFields = response.data.fields || [];

				if (!moreFields.length) {
					moreChips.appendChild(el('p', { class: 'fluxis-empty' }, t('noMoreFields')));
				}

				moreBoxes = moreFields.map((field) => {
					const box = choiceChip(moreChips, field.key, field.label, true, field.value ? stripTags(field.value).slice(0, 120) : '');
					box.addEventListener('change', update);
					return box;
				});
				update();
			});

			// Images
			const images = section(form, t('imagesSection'), t('imagesIntroShort'));
			const imagesButton = addButton(t('addImages'));
			let imageRowsList = [];
			images.body.appendChild(imagesButton);

			imagesButton.addEventListener('click', async () => {
				imagesButton.disabled = true;
				imagesButton.lastChild.textContent = t('loadingImages');
				const response = await FluxisAi.productImages(product);

				if (!response.ok) {
					imagesButton.disabled = false;
					imagesButton.lastChild.textContent = t('addImages');
					errorBox(images.body.appendChild(el('p')), response.data);
					return;
				}

				imagesButton.remove();
				imageRowsList = imageRows(images.body, response.data.images || [], update);
			});

			// Options
			const settings = section(form, t('optionsSection'));
			const options = textOptions(settings.body);
			const overwrite = switchControl(settings.body, t('overwrite'), true);

			const message = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });
			const results = el('div', { class: 'fluxis-results', hidden: 'hidden' });
			body.appendChild(message);
			body.appendChild(results);

			function selection() {
				return {
					targets: syncedBoxes.filter((box) => box.checked).map((box) => box.value),
					extraKeys: moreBoxes.filter((box) => box.checked).map((box) => box.value),
					images: imageRowsList.filter((row) => row.check.checked),
				};
			}

			function update() {
				const chosen = selection();
				const textCount = chosen.targets.length + chosen.extraKeys.length;
				controls.summary.textContent = F.sprintf(t('selectionSummary'), textCount, chosen.images.length);
				controls.start.disabled = textCount + chosen.images.length === 0;
			}

			syncedBoxes.forEach((box) => box.addEventListener('change', update));
			update();

			controls.start.addEventListener('click', async () => {
				const { targets, extraKeys, images: chosenImages } = selection();
				const error = options.validate() || chosenImages.map((row) => row.presets.validate()).find(Boolean) || (extraKeys.length + (hasSku ? 0 : targets.length) > 20 ? t('tooManyFields') : null);

				if (error) {
					message.textContent = error;
					message.hidden = false;
					return;
				}

				message.hidden = true;
				form.hidden = true;
				controls.start.hidden = true;
				controls.summary.textContent = '';
				controls.cancel.textContent = t('close');
				results.hidden = false;

				const values = options.values();
				const jobs = [];

				// Synced fields of a linked product: Fluxis optimizes, writes and pushes back.
				if (targets.length && hasSku) {
					const part = section(results, t('syncedSection'), t('syncedRunning'));
					const status = el('p', { class: 'fluxis-hint' });
					const tracker = el('div', { class: 'fluxis-run' });
					part.body.appendChild(status);
					part.body.appendChild(tracker);

					jobs.push(
						F.createRun(Object.assign({ kind: 'text', product_ids: [product], targets: targets, overwrite: overwrite.checked ? 'overwrite' : 'fill_empty' }, values), (waiting) => {
							status.textContent = waiting;
						}).then((created) => {
							if (!created.ok) {
								errorBox(status, created.data);
								return;
							}

							status.textContent = '';
							new F.RunTracker(tracker, created.data.run_id, {
								showItems: false,
								onDelivered: (run, notice) => {
									notice.hidden = false;
									notice.className = 'notice notice-success inline fluxis-run__notice';
									notice.innerHTML = '';
									const line = el('p', {}, t('updatedNotice') + ' ');
									const reload = el('button', { type: 'button', class: 'button button-small fluxis-ai-button' }, t('reload'));
									reload.addEventListener('click', () => window.location.reload());
									line.appendChild(reload);
									notice.appendChild(line);
								},
							});
						})
					);
				}

				// Ad-hoc text: other fields, and synced fields of a product without SKU.
				const adhoc = [];

				if (!hasSku) {
					targets.forEach((key) => {
						const meta = syncedFields[key];
						adhoc.push({ key: key, label: cfg.textTargets[key], value: meta.get(), kind: meta.kind, max_length: meta.max || null, path: syncedPath(key), apply: meta.set });
					});
				}

				moreFields
					.filter((field) => extraKeys.indexOf(field.key) !== -1)
					.forEach((field) => {
						const target = fieldTarget(field);
						adhoc.push(Object.assign({}, field, { value: target ? target.value : field.value, target: target }));
					});

				if (adhoc.length) {
					const part = section(results, t('adhocSection'), t('adhocReview'));
					const slot = el('div');
					part.body.appendChild(slot);
					skeleton(slot, t('generating'));

					jobs.push(
						FluxisAi.text({ product_id: product }, adhoc.slice(0, 20), values).then((response) => {
							slot.remove();

							if (!response.ok) {
								errorBox(part.body.appendChild(el('p', { class: 'fluxis-error', role: 'alert' })), response.data);
								return;
							}

							textProposals(
								part.body,
								adhoc,
								response.data.after,
								async (field, value) => {
									const entry = { run_id: response.data.run_id, item_id: response.data.item_id, key: field.key, path: field.path || null, label: field.label };

									if (typeof field.apply === 'function') {
										field.apply(value);
										rememberAccepted(entry);
										return true;
									}

									if (field.target) {
										setValue(field.target, value);
										rememberAccepted(entry);
										return true;
									}

									const saved = await FluxisAi.applyFields(product, { [field.key]: value });
									const done = saved.ok && (saved.data.applied || []).indexOf(field.key) !== -1;

									if (done) {
										// Saved right away: apply it in Fluxis now.
										applyNow([entry]);
									}

									return done;
								},
								{ showBefore: true }
							);
							part.body.appendChild(el('p', { class: 'fluxis-hint' }, t('savedOnUpdate')));
						})
					);
				}

				if (chosenImages.length) {
					const part = section(results, t('imagesSection'), t('resultsIntro'));
					jobs.push(runImages(part.body, chosenImages, product));
				}

				await Promise.all(jobs);

				if (runPanel) {
					runPanel.hidden = true;
				}
			});
		});
	}

	/* --------------------------------------------------------------- boot */

	function boot() {
		if (!cfg.connected) {
			return;
		}

		initMedia();

		if (!document.body.classList.contains('post-type-product')) {
			return;
		}

		initPanel();
		initEditors();
		initFeatured();
		initGallery();
		initYoast();
		scanProductData();

		const data = document.getElementById('woocommerce-product-data');

		const jq = $();

		if (jq) {
			jq('#woocommerce-product-data').on('woocommerce_variations_loaded woocommerce_variations_added', scanProductData);
		}

		if (data) {
			let timer = null;
			new MutationObserver(() => {
				window.clearTimeout(timer);
				timer = window.setTimeout(scanProductData, 300);
			}).observe(data, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
