/**
 * Fluxis for WooCommerce – bulk optimization wizard (products → options → run).
 */
(function () {
	'use strict';

	var F = window.Fluxis;

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('fluxis-wizard');

		if (!root) {
			return;
		}

		var config = JSON.parse(root.getAttribute('data-config') || '{}');
		var steps = root.querySelectorAll('[data-step]');
		var indicators = root.querySelectorAll('[data-step-indicator]');
		var back = root.querySelector('[data-fluxis-back]');
		var next = root.querySelector('[data-fluxis-next]');
		var start = root.querySelector('[data-fluxis-start]');
		var message = root.querySelector('[data-fluxis-message]');
		var runPanel = root.querySelector('[data-fluxis-run]');
		var options = root.querySelector('[data-step="2"]');
		var current = 1;
		var started = false;

		F.bindConditionalFields(options);

		function show(step) {
			current = step;

			steps.forEach(function (section) {
				section.hidden = parseInt(section.getAttribute('data-step'), 10) !== step;
			});

			indicators.forEach(function (indicator) {
				var number = parseInt(indicator.getAttribute('data-step-indicator'), 10);
				indicator.classList.toggle('is-active', number === step);
				indicator.classList.toggle('is-done', number < step);
			});

			back.hidden = step === 1 || started;
			next.hidden = step === 3;
			start.hidden = step !== 3 || started;
			message.hidden = true;
		}

		function validate() {
			var data = F.collect(options);

			if (config.kind === 'text') {
				if ((data.targets || []).length === 0 && (data.custom_fields || []).length === 0) {
					return F.t('selectTarget');
				}
				if (data.mode === 'instruction' && !String(data.instruction || '').trim()) {
					return F.t('instructionRequired');
				}
			} else if (data.preset === 'custom' && !String(data.prompt || '').trim()) {
				return F.t('promptRequired');
			}

			return null;
		}

		next.addEventListener('click', function () {
			if (current === 2) {
				var error = validate();
				if (error) {
					message.textContent = error;
					message.hidden = false;
					return;
				}
			}

			show(Math.min(3, current + 1));
		});

		back.addEventListener('click', function () {
			show(Math.max(1, current - 1));
		});

		start.addEventListener('click', function () {
			if (F.cfg.setupComplete === false) {
				return;
			}

			var payload = F.collect(options);

			payload.kind = config.kind;
			payload.product_ids = config.productIds;

			start.disabled = true;
			start.textContent = F.t('starting');

			F.createRun(payload, function (waiting) {
				message.classList.remove('fluxis-error');
				message.textContent = waiting;
				message.hidden = false;
			}).then(function (result) {
				start.disabled = false;
				start.textContent = F.t('start');

				message.classList.add('fluxis-error');

				if (!result.ok) {
					F.renderError(message, result.data);
					return;
				}

				started = true;
				show(3);
				new F.RunTracker(runPanel, result.data.run_id, {});
			});
		});

		show(1);
	});
})();
