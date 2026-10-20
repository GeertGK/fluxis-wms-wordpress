/**
 * Fluxis for WooCommerce – shared admin helpers.
 *
 * The browser only talks to admin-ajax.php (nonce + manage_woocommerce);
 * WordPress talks to Fluxis server-side, so no secret ever reaches the page.
 */
(function () {
	'use strict';

	var cfg = window.FluxisAdmin || {};
	var i18n = cfg.i18n || {};
	var TERMINAL = ['completed', 'failed', 'cancelled'];

	function t(key) {
		return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : key;
	}

	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;

		return String(format).replace(/%(?:(\d+)\$)?([sd])/g, function (match, position, type) {
			var value = position ? args[parseInt(position, 10) - 1] : args[next++];

			if (type === 'd') {
				value = parseInt(value, 10);
				return isNaN(value) ? '0' : String(value);
			}

			return value === undefined || value === null ? '' : String(value);
		});
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);

		Object.keys(attrs || {}).forEach(function (name) {
			node.setAttribute(name, attrs[name]);
		});

		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}

		return node;
	}

	/**
	 * POST an admin-ajax action (every action is POST-only, so the nonce never
	 * lands in URLs or server logs). Resolves with {ok, status, data}.
	 */
	function request(action, data) {
		var body = new FormData();
		var url = cfg.ajaxUrl;

		body.append('action', action);
		body.append('nonce', cfg.nonce);

		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];

			if (Array.isArray(value)) {
				value.forEach(function (item) {
					body.append(key + '[]', item);
				});
			} else if (value !== undefined && value !== null) {
				body.append(key, value);
			}
		});

		return fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response
					.json()
					.catch(function () {
						return { success: false, data: { error: 'invalid_response' } };
					})
					.then(function (json) {
						return { ok: response.ok && json && json.success === true, status: response.status, data: (json && json.data) || {} };
					});
			})
			.catch(function () {
				return { ok: false, status: 0, data: { error: 'network_error' } };
			});
	}

	/**
	 * Collects named form controls inside a container; names ending in [] become arrays.
	 */
	function collect(container) {
		var data = {};

		container.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (input) {
			var name = input.getAttribute('name');
			var isList = name.slice(-2) === '[]';
			var key = isList ? name.slice(0, -2) : name;

			if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
				if (isList && !data[key]) {
					data[key] = [];
				}
				return;
			}

			if (isList) {
				data[key] = data[key] || [];
				if (input.value !== '') {
					data[key].push(input.value);
				}
			} else {
				data[key] = input.value;
			}
		});

		return data;
	}

	/**
	 * Shows an error from the proxy, including the upsell and budget panels.
	 */
	function renderError(target, payload) {
		var data = payload || {};

		target.innerHTML = '';
		target.hidden = false;

		if (data.error === 'addon_required') {
			var box = el('div', { class: 'fluxis-upsell' });
			box.appendChild(el('strong', {}, t('addonTitle')));
			box.appendChild(el('p', {}, t('addonBody')));

			var url = data.upsell_url || cfg.addonsUrl;
			if (url) {
				box.appendChild(el('a', { class: 'button', href: url, target: '_blank', rel: 'noopener noreferrer' }, t('addonLink')));
			}

			target.appendChild(box);
			return;
		}

		if (data.error === 'ai_budget_exceeded') {
			var budget = el('div', { class: 'fluxis-upsell' });
			var resets = data.resets_at ? new Date(data.resets_at) : null;
			budget.appendChild(el('strong', {}, t('budgetTitle')));
			budget.appendChild(
				el(
					'p',
					{},
					sprintf(
						t('budgetBody'),
						data.remaining !== undefined && data.remaining !== null ? data.remaining : t('unknown'),
						resets && !isNaN(resets.getTime()) ? resets.toLocaleString() : t('unknown')
					)
				)
			);
			target.appendChild(budget);
			return;
		}

		if (data.error === 'setup_incomplete') {
			var setup = el('div', { class: 'fluxis-upsell' });
			var setupUrl = data.settings_url || cfg.setupUrl;
			setup.appendChild(el('strong', {}, t('setupTitle')));
			setup.appendChild(el('p', {}, t('setupBody')));

			if (setupUrl) {
				setup.appendChild(el('a', { class: 'button', href: setupUrl, target: '_blank', rel: 'noopener noreferrer' }, t('setupLink')));
			}

			target.appendChild(setup);
			return;
		}

		var messages = {
			network_error: t('errorNetwork'),
			validation_failed: t('validation'),
			not_connected: t('notConnected'),
			kind_unavailable: t('kindUnavailable'),
			import_pending: t('importPending'),
			rate_limited: t('rateLimited'),
			payload_too_large: t('payloadTooLarge'),
		};

		if (data.error === 'validation_failed' && data.field === 'targets') {
			messages.validation_failed = t('selectTarget');
		} else if (data.error === 'validation_failed' && data.field === 'instruction') {
			messages.validation_failed = t('instructionRequired');
		} else if (data.error === 'validation_failed' && data.field === 'language') {
			messages.validation_failed = t('languageInvalid');
		} else if (data.error === 'validation_failed' && data.field === 'prompt') {
			messages.validation_failed = t('promptRequired');
		} else if (data.error === 'validation_failed' && data.field === 'products' && data.reason === 'too_large') {
			messages.validation_failed = t('runTooLarge');
		}

		target.appendChild(el('span', {}, messages[data.error] || data.message || sprintf(t('errorGeneric'), data.error || 'unknown')));
	}

	var RUN_CREATE_ATTEMPTS = 8;
	var RUN_CREATE_WAIT_BUDGET = 60;
	var RETRY_LATER = ['import_pending', 'rate_limited'];

	/**
	 * Creates an AI run. While Fluxis answers import_pending (it is still importing the
	 * products, or for an image run their images) or rate_limited, the same request is
	 * sent again after retry_after seconds: up to 8 attempts and about 60 s of waiting.
	 * onWait(message) is called before every wait so the screen can say what happens.
	 */
	function createRun(payload, onWait) {
		var attempt = 0;
		var waited = 0;

		function attemptOnce() {
			attempt++;

			return request('fluxis_ai_create', payload).then(function (result) {
				var data = result.data || {};
				var wait = parseInt(data.retry_after, 10);

				wait = isNaN(wait) || wait < 1 ? 5 : wait;

				if (result.ok || RETRY_LATER.indexOf(data.error) === -1 || attempt >= RUN_CREATE_ATTEMPTS || waited + wait > RUN_CREATE_WAIT_BUDGET) {
					return result;
				}

				if (typeof onWait === 'function') {
					onWait(data.error === 'rate_limited' ? t('waitingRateLimited') : payload.kind === 'image' ? t('importingImages') : t('importingProducts'));
				}

				waited += wait;

				return new Promise(function (resolve) {
					window.setTimeout(resolve, wait * 1000);
				}).then(attemptOnce);
			});
		}

		return attemptOnce();
	}

	/**
	 * Renders and polls the progress of one AI run.
	 */
	function RunTracker(container, runId, options) {
		this.container = container;
		this.runId = runId;
		this.options = options || {};
		this.timer = null;
		this.stopped = false;
		this.terminalPolls = 0;
		this.build();
		this.poll();
	}

	RunTracker.prototype.build = function () {
		var self = this;

		this.container.innerHTML = '';
		this.container.hidden = false;

		this.statusLabel = el('p', { class: 'fluxis-run__status', 'aria-live': 'polite' }, t('status_queued'));
		this.bar = el('div', { class: 'fluxis-progress__bar' });
		this.fill = el('span');
		this.bar.appendChild(this.fill);
		this.counts = el('p', { class: 'fluxis-progress__label' });
		this.cancelButton = el('button', { type: 'button', class: 'button' }, t('cancel'));
		this.cancelButton.addEventListener('click', function () {
			self.cancel();
		});
		this.notice = el('div', { class: 'fluxis-run__notice', hidden: 'hidden' });
		this.error = el('p', { class: 'fluxis-error', role: 'alert', hidden: 'hidden' });

		this.container.appendChild(this.statusLabel);
		this.container.appendChild(this.bar);
		this.container.appendChild(this.counts);
		this.container.appendChild(this.cancelButton);
		this.container.appendChild(this.notice);
		this.container.appendChild(this.error);

		if (this.options.showItems !== false) {
			this.table = el('table', { class: 'widefat striped fluxis-run__items' });
			var head = el('thead');
			var row = el('tr');
			['colSku', 'colTarget', 'colStatus', 'colError'].forEach(function (key) {
				row.appendChild(el('th', {}, t(key)));
			});
			head.appendChild(row);
			this.table.appendChild(head);
			this.tbody = el('tbody');
			this.table.appendChild(this.tbody);
			this.container.appendChild(this.table);
		}
	};

	RunTracker.prototype.poll = function () {
		var self = this;

		if (this.stopped) {
			return;
		}

		request('fluxis_ai_run', { run_id: this.runId }).then(function (result) {
			if (self.stopped) {
				return;
			}

			if (!result.ok) {
				self.error.hidden = false;
				renderError(self.error, result.data);

				if (result.status >= 400 && result.status < 500 && result.status !== 429) {
					self.stop();
					return;
				}
			} else {
				self.error.hidden = true;
				self.render(result.data);

				if (TERMINAL.indexOf(result.data.status) !== -1) {
					self.cancelButton.hidden = true;
					self.terminalPolls++;

					var delivered = parseInt(result.data.delivered, 10) || 0;
					var completed = parseInt(result.data.completed, 10) || 0;

					if (delivered >= completed || self.terminalPolls > 30) {
						self.stop();
						return;
					}
				}
			}

			var retryAfter = !result.ok && result.data ? parseInt(result.data.retry_after, 10) : NaN;

			self.timer = window.setTimeout(
				function () {
					self.poll();
				},
				isNaN(retryAfter) ? cfg.pollInterval || 2000 : Math.max(retryAfter * 1000, cfg.pollInterval || 2000)
			);
		});
	};

	RunTracker.prototype.render = function (run) {
		var total = parseInt(run.total, 10) || 0;
		var completed = parseInt(run.completed, 10) || 0;
		var failed = parseInt(run.failed, 10) || 0;
		var delivered = parseInt(run.delivered, 10) || 0;
		var done = completed + failed;
		var percent = total > 0 ? Math.min(100, Math.floor((done * 100) / total)) : 0;

		this.statusLabel.textContent = t('status_' + run.status);
		this.fill.style.width = percent + '%';
		this.bar.setAttribute('aria-valuenow', String(percent));

		var parts = [sprintf(t('progress'), done, total), sprintf(t('delivered'), delivered)];
		if (failed > 0) {
			parts.push(sprintf(t('failedCount'), failed));
		}
		this.counts.textContent = parts.join(' · ');

		if (this.tbody && Array.isArray(run.items)) {
			var tbody = this.tbody;
			tbody.innerHTML = '';
			run.items.forEach(function (item) {
				var row = el('tr');
				row.appendChild(el('td', {}, item.sku));
				row.appendChild(el('td', {}, item.target));
				row.appendChild(el('td', {}, t('status_' + item.status)));
				row.appendChild(el('td', {}, item.error_code || ''));
				tbody.appendChild(row);
			});
		}

		if (delivered > 0 && delivered !== this.lastDelivered && typeof this.options.onDelivered === 'function') {
			this.options.onDelivered(run, this.notice);
		}

		this.lastDelivered = delivered;
	};

	RunTracker.prototype.cancel = function () {
		var self = this;

		this.cancelButton.disabled = true;
		this.cancelButton.textContent = t('cancelling');

		request('fluxis_ai_cancel', { run_id: this.runId }).then(function (result) {
			if (!result.ok) {
				self.cancelButton.disabled = false;
				self.cancelButton.textContent = t('cancel');
				self.error.hidden = false;
				renderError(self.error, result.data);
			}
		});
	};

	RunTracker.prototype.stop = function () {
		this.stopped = true;
		window.clearTimeout(this.timer);
	};

	/**
	 * Polls the current sync batch and fills a progress element. The texts (what
	 * started the batch and when, the counts) are formatted server-side; a finished
	 * batch stays visible until the next one starts.
	 */
	function trackSync(container, onDone) {
		var fill = container.querySelector('.fluxis-progress__bar span');
		var title = container.querySelector('.fluxis-progress__title strong');
		var label = container.querySelector('.fluxis-progress__label');
		var status = container.querySelector('.fluxis-progress__status');

		function tick() {
			request('fluxis_sync_progress', {}).then(function (result) {
				var progress = result.ok ? result.data.progress : null;

				if (!progress) {
					return;
				}

				container.hidden = false;
				if (fill) {
					fill.style.width = progress.percent + '%';
				}
				if (title) {
					title.textContent = progress.title || '';
				}
				if (label) {
					label.textContent = progress.line || '';
				}
				if (status) {
					status.textContent = progress.status || '';
				}

				if (progress.done) {
					if (typeof onDone === 'function') {
						onDone(progress);
					}
					return;
				}

				window.setTimeout(tick, cfg.pollInterval || 2000);
			});
		}

		tick();
	}

	/**
	 * Inputs inside a box that lives in another form (the product edit form)
	 * must not be submitted with that form.
	 */
	function detachInputs(container) {
		container.querySelectorAll('input, select, textarea, button').forEach(function (input) {
			input.setAttribute('form', 'fluxis-detached');
		});
	}

	/**
	 * Show the instruction / prompt textarea only when it applies.
	 */
	function bindConditionalFields(container) {
		function sync() {
			var mode = container.querySelector('input[name="mode"]:checked');
			var instruction = container.querySelector('[data-fluxis-instruction]');
			if (instruction) {
				instruction.hidden = !mode || mode.value !== 'instruction';
			}

			var language = container.querySelector('[data-fluxis-language]');
			var other = container.querySelector('[data-fluxis-language-other]');
			if (language && other) {
				other.hidden = language.value !== 'other';
			}

			var preset = container.querySelector('input[name="preset"]:checked');
			var prompt = container.querySelector('[data-fluxis-prompt]');
			if (prompt) {
				prompt.hidden = !preset || preset.value !== 'custom';
			}
		}

		container.addEventListener('change', sync);
		sync();

		var add = container.querySelector('[data-fluxis-add-custom]');
		var list = container.querySelector('[data-fluxis-custom-list]');
		if (add && list) {
			add.addEventListener('click', function () {
				var input = list.querySelector('input').cloneNode(false);
				input.value = '';
				list.appendChild(input);
				input.focus();
			});
		}
	}

	window.Fluxis = {
		cfg: cfg,
		t: t,
		sprintf: sprintf,
		el: el,
		request: request,
		collect: collect,
		renderError: renderError,
		createRun: createRun,
		RunTracker: RunTracker,
		trackSync: trackSync,
		detachInputs: detachInputs,
		bindConditionalFields: bindConditionalFields,
	};
})();
