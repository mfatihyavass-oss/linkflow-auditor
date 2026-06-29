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

	function scanBatch($root, token, tabName) {
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
					reloadToTab($root, tabName);
				}, 700);
				return;
			}

			window.setTimeout(function () {
				scanBatch($root, token, tabName);
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
		var tabName = scanModeToTab(scanMode);
		var checkExternalLinks = $root.find('.lfa-check-external input').prop('checked') ? '1' : '0';

		rememberTab($root, tabName);
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
					reloadToTab($root, tabName);
				}, 700);
				return;
			}

			setMessage($root, LinkFlowAuditor.messages.scanning, false);
			scanBatch($root, data.token, tabName);
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, LinkFlowAuditor.messages.error, true);
		});
	});

	$(document).on('click', '.lfa-clear', function () {
		var $root = $(this).closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var tabName = $root.data('lfa-panel') || getActiveTab($root);

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

			reloadToTab($root, tabName);
		}).fail(function () {
			setBusy($root, false);
			setMessage($root, LinkFlowAuditor.messages.error, true);
		});
	});

	function lfaMessages() {
		return (LinkFlowAuditor && LinkFlowAuditor.messages) || {};
	}

	function updateTabCount($row, scope, count) {
		var selector = 'broken' === scope ? '[data-lfa-tab="broken"]' : '[data-lfa-tab="redirects"]';
		var $tab = $row.closest('[data-lfa-tabs]').find(selector + ' .lfa-tab-count');

		if ($tab.length && typeof count !== 'undefined' && count !== null) {
			$tab.text(count);
		}
	}

	function removeRow($row) {
		var $table = $row.closest('table');
		$row.fadeOut(200, function () {
			$row.remove();

			if ($table.find('tbody tr').length === 0) {
				$table.closest('.lfa-table-wrap').remove();
			}
		});
	}

	function sendFix($button, payload) {
		var $row = $button.closest('tr');
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();

		$row.find('button').prop('disabled', true);
		setMessage($root, 'remove' === payload.mode ? messages.removing : messages.fixing, false);

		request($.extend({ action: 'linkflow_auditor_fix_link' }, payload)).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$row.find('button').prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			setMessage($root, data.message || messages.done, false);
			updateTabCount($row, payload.scope, 'broken' === payload.scope ? data.broken_count : data.redirect_count);
			removeRow($row);
		}).fail(function () {
			$row.find('button').prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	}

	$(document).on('click', '.lfa-fix-remove', function () {
		var $button = $(this);
		var messages = lfaMessages();

		if (!window.confirm(messages.confirmRemove)) {
			return;
		}

		sendFix($button, {
			scope: $button.data('scope'),
			source_id: $button.data('source-id'),
			raw_url: $button.data('raw-url'),
			mode: 'remove'
		});
	});

	$(document).on('click', '.lfa-fix-replace-toggle', function () {
		var $actions = $(this).closest('.lfa-actions');
		$actions.find('.lfa-replace-box').prop('hidden', false).find('.lfa-replace-input').trigger('focus');
	});

	$(document).on('click', '.lfa-fix-cancel', function () {
		var $box = $(this).closest('.lfa-replace-box');
		$box.prop('hidden', true).find('.lfa-replace-input').val('');
	});

	$(document).on('click', '.lfa-fix-replace', function () {
		var $button = $(this);
		var messages = lfaMessages();
		var newUrl;

		if ($button.data('direct')) {
			newUrl = $button.data('new-url');

			if (!window.confirm(messages.confirmReplace)) {
				return;
			}
		} else {
			newUrl = ($button.closest('.lfa-actions').find('.lfa-replace-input').val() || '').trim();

			if (!newUrl) {
				window.alert(messages.emptyUrl);
				return;
			}
		}

		sendFix($button, {
			scope: $button.data('scope'),
			source_id: $button.data('source-id'),
			raw_url: $button.data('raw-url'),
			mode: 'replace',
			new_url: newUrl
		});
	});

	function scanModeToTab(scanMode) {
		return 'redirect' === scanMode ? 'redirects' : scanMode;
	}

	function getTabHash($tabs, tabName) {
		var id = $tabs.find('[data-lfa-panel="' + tabName + '"]').attr('id');

		return id ? '#' + id : '';
	}

	function rememberTab($root, tabName) {
		var $tabs = $root.is('[data-lfa-tabs]') ? $root : $root.closest('[data-lfa-tabs]');
		var hash = $tabs.length ? getTabHash($tabs, tabName) : '';

		if (!hash) {
			return;
		}

		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', window.location.pathname + window.location.search + hash);
			return;
		}

		window.location.hash = hash;
	}

	function reloadToTab($root, tabName) {
		rememberTab($root, tabName);
		window.location.reload();
	}

	function getActiveTab($root) {
		var $tabs = $root.closest('[data-lfa-tabs]');
		var $active = $tabs.find('[data-lfa-tab].nav-tab-active');

		return $active.data('lfa-tab') || 'internal';
	}

	function getHashTab($tabs) {
		var hash = window.location.hash ? window.location.hash.substring(1) : '';
		var $panel;
		var $tab;

		if (!hash) {
			return '';
		}

		$panel = $tabs.find('[data-lfa-panel]').filter(function () {
			return this.id === hash;
		});

		if ($panel.length) {
			return $panel.data('lfa-panel');
		}

		$tab = $tabs.find('[data-lfa-tab]').filter(function () {
			return $(this).data('lfa-tab') === hash;
		});
		if ($tab.length) {
			return $tab.data('lfa-tab');
		}

		return '';
	}

	function activateTab($tabs, tabName, updateHash) {
		var $targetPanel = $tabs.find('[data-lfa-panel="' + tabName + '"]');

		if (!$targetPanel.length) {
			return;
		}

		$tabs.find('[data-lfa-tab]').removeClass('nav-tab-active').attr('aria-selected', 'false');
		$tabs.find('[data-lfa-tab="' + tabName + '"]').addClass('nav-tab-active').attr('aria-selected', 'true');
		$tabs.find('[data-lfa-panel]').prop('hidden', true);
		$targetPanel.prop('hidden', false);

		if (updateHash) {
			rememberTab($tabs, tabName);
		}
	}

	$(document).on('click', '[data-lfa-tab]', function (event) {
		var $tab = $(this);
		var $tabs = $tab.closest('[data-lfa-tabs]');

		event.preventDefault();
		activateTab($tabs, $tab.data('lfa-tab'), true);
	});

	$(function () {
		$('[data-lfa-tabs]').each(function () {
			var $tabs = $(this);
			var initialTab = getHashTab($tabs) || 'internal';

			activateTab($tabs, initialTab, false);
		});
	});
})(jQuery);
