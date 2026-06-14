(function ($) {
	'use strict';

	function setBusy($root, busy) {
		$root.find('.lfa-start, .lfa-clear').prop('disabled', busy);
		$root.find('.lfa-spinner').toggleClass('is-active', busy);
	}

	function setMessage($root, message, isError) {
		$root.find('.lfa-message')
			.toggleClass('lfa-error', Boolean(isError))
			.text(message || '');
	}

	function setProgress($root, percent, label) {
		var safePercent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
		var $progress = $root.find('.lfa-progress');

		$progress.prop('hidden', false);
		$progress.find('.lfa-progress-bar span').css('width', safePercent + '%');
		$progress.find('strong').text(label || (safePercent + '%'));
	}

	function request(data) {
		return $.ajax({
			url: LinkFlowAuditor.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({}, data, {
				nonce: LinkFlowAuditor.nonce
			})
		});
	}

	function scanBatch($root, token) {
		request({
			action: 'linkflow_auditor_scan_batch',
			token: token
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || LinkFlowAuditor.messages.error, true);
				return;
			}

			setProgress(
				$root,
				data.percent,
				(data.processed || 0) + ' / ' + (data.total || 0)
			);

			if (data.done) {
				setMessage($root, LinkFlowAuditor.messages.done, false);
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
			setMessage($root, LinkFlowAuditor.messages.error, true);
		});
	}

	$(document).on('click', '.lfa-start', function () {
		var $button = $(this);
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var scanMode = $button.data('scan-mode') || 'internal';
		var checkExternalLinks = $root.find('.lfa-check-external input').prop('checked') ? '1' : '0';

		setBusy($root, true);
		setMessage($root, LinkFlowAuditor.messages.starting, false);
		setProgress($root, 0, '0%');

		request({
			action: 'linkflow_auditor_start_scan',
			scan_mode: scanMode,
			check_external_links: checkExternalLinks
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || LinkFlowAuditor.messages.error, true);
				return;
			}

			if (data.done) {
				setProgress($root, 100, '100%');
				setMessage($root, LinkFlowAuditor.messages.done, false);
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
				return;
			}

			setMessage($root, LinkFlowAuditor.messages.scanning, false);
			scanBatch($root, data.token);
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, LinkFlowAuditor.messages.error, true);
		});
	});

	$(document).on('click', '.lfa-clear', function () {
		var $root = $(this).closest('.lfa-tab-panel, .lfa-widget, .lfa-page');

		setBusy($root, true);
		setMessage($root, LinkFlowAuditor.messages.clearing, false);

		request({
			action: 'linkflow_auditor_clear_report'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setBusy($root, false);
				setMessage($root, data.message || LinkFlowAuditor.messages.error, true);
				return;
			}

			window.location.reload();
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, LinkFlowAuditor.messages.error, true);
		});
	});

	function activateTab($tabs, tabName) {
		var $targetPanel = $tabs.find('[data-lfa-panel="' + tabName + '"]');

		if (!$targetPanel.length) {
			return;
		}

		$tabs.find('[data-lfa-tab]').removeClass('nav-tab-active').attr('aria-selected', 'false');
		$tabs.find('[data-lfa-tab="' + tabName + '"]').addClass('nav-tab-active').attr('aria-selected', 'true');
		$tabs.find('[data-lfa-panel]').prop('hidden', true);
		$targetPanel.prop('hidden', false);
	}

	$(document).on('click', '[data-lfa-tab]', function (event) {
		var $tab = $(this);
		var $tabs = $tab.closest('[data-lfa-tabs]');

		event.preventDefault();
		activateTab($tabs, $tab.data('lfa-tab'));
	});

	$(function () {
		$('[data-lfa-tabs]').each(function () {
			var $tabs = $(this);
			var hash = window.location.hash || '';
			var initialTab = 'internal';

			if (hash === '#lfa-broken-links') {
				initialTab = 'broken';
			} else if (hash === '#lfa-redirect-links') {
				initialTab = 'redirects';
			}

			activateTab($tabs, initialTab);
		});
	});
})(jQuery);
