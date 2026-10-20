/**
 * Fluxis for WooCommerce – settings page: sync progress and disconnect confirmation.
 */
(function () {
	'use strict';

	var F = window.Fluxis;

	document.addEventListener('DOMContentLoaded', function () {
		var progress = document.getElementById('fluxis-sync-progress');

		if (progress) {
			F.trackSync(progress);
		}

		document.querySelectorAll('form[data-fluxis-confirm]').forEach(function (form) {
			form.addEventListener('submit', function (event) {
				if (!window.confirm(F.t('confirmDisconnect'))) {
					event.preventDefault();
				}
			});
		});
	});
})();
