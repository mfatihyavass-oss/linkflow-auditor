(function ($) {
	'use strict';

	function setBusy($root, busy) {
		$root.find('.maya-ils-start, .maya-ils-clear').prop('disabled', busy);
		$root.find('.maya-ils-spinner').toggleClass('is-active', busy);
	}

	function setMessage($root, message, isError) {
		$root.find('.maya-ils-message')
			.toggleClass('maya-ils-error', Boolean(isError))
			.text(message || '');
	}

	function setProgress($root, percent, label) {
		var safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
		var $progress = $root.find('.maya-ils-progress');

		$progress.prop('hidden', false);
		$progress.find('.maya-ils-progress-bar span').css('width', safePercent + '%');
		$progress.find('strong').text(label || (safePercent + '%'));
	}

	function request(data) {
		return $.ajax({
			url: MayaILS.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({}, data, {
				nonce: MayaILS.nonce
			})
		});
	}

	function scanBatch($root, token) {
		request({
			action: 'maya_ils_scan_batch',
			token: token
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || MayaILS.messages.error, true);
				return;
			}

			setProgress(
				$root,
				data.percent,
				(data.processed || 0) + ' / ' + (data.total || 0)
			);

			if (data.done) {
				setMessage($root, MayaILS.messages.done, false);
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
				return;
			}

			window.setTimeout(function () {
				scanBatch($root, token);
			}, 120);
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, MayaILS.messages.error, true);
		});
	}

	$(document).on('click', '.maya-ils-start', function () {
		var $root = $(this).closest('.maya-ils-widget, .maya-ils-page');

		setBusy($root, true);
		setMessage($root, MayaILS.messages.starting, false);
		setProgress($root, 0, '0%');

		request({
			action: 'maya_ils_start_scan'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || MayaILS.messages.error, true);
				return;
			}

			if (data.done) {
				setProgress($root, 100, '100%');
				setMessage($root, MayaILS.messages.done, false);
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
				return;
			}

			setMessage($root, MayaILS.messages.scanning, false);
			scanBatch($root, data.token);
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, MayaILS.messages.error, true);
		});
	});

	$(document).on('click', '.maya-ils-clear', function () {
		var $root = $(this).closest('.maya-ils-widget, .maya-ils-page');

		setBusy($root, true);
		setMessage($root, MayaILS.messages.clearing, false);

		request({
			action: 'maya_ils_clear_report'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || MayaILS.messages.error, true);
				return;
			}

			window.location.reload();
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, MayaILS.messages.error, true);
		});
	});
})(jQuery);
