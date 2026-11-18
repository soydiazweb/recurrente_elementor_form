/* Recurrente Elementor Form — admin */
(function () {
	'use strict';

	var cfg = window.REF_ADMIN || {};

	document.addEventListener('click', function (e) {
		var test = e.target.closest('.ref-test');
		if (test) {
			e.preventDefault();
			var mode = test.getAttribute('data-mode');
			var out = document.querySelector('.ref-test-result[data-mode="' + mode + '"]');
			out.className = 'ref-test-result';
			out.textContent = cfg.i18n.testing;
			test.disabled = true;

			var body = new URLSearchParams();
			body.append('action', 'ref_test_connection');
			body.append('nonce', cfg.nonce);
			body.append('mode', mode);

			fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					out.classList.add(res.success ? 'is-ok' : 'is-error');
					out.textContent = (res.data && res.data.message) ? res.data.message : cfg.i18n.error;
				})
				.catch(function () {
					out.classList.add('is-error');
					out.textContent = cfg.i18n.error;
				})
				.finally(function () { test.disabled = false; });
			return;
		}

		var copy = e.target.closest('.ref-copy');
		if (copy) {
			e.preventDefault();
			var src = copy.parentNode.querySelector('.ref-copy-src');
			if (src && navigator.clipboard) {
				navigator.clipboard.writeText(src.textContent.trim()).then(function () {
					var old = copy.textContent;
					copy.textContent = cfg.i18n.copied;
					setTimeout(function () { copy.textContent = old; }, 1500);
				});
			}
		}
	});
})();
