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
		var tabName = $button.data('result-tab') || scanModeToTab(scanMode);
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

	$(document).on('click', '.lfa-clear-all-records', function () {
		var $button = $(this);
		var $root = $button.closest('.lfa-cleanup-bar');
		var messages = lfaMessages();

		if (!window.confirm(messages.confirmClearAllRecords)) {
			return;
		}

		$button.prop('disabled', true);
		setMessage($root, messages.clearingAll, false);

		request({
			action: 'linkflow_auditor_clear_all_records'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$button.prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			setMessage($root, data.message || messages.done, false);
			window.setTimeout(function () {
				window.location.reload();
			}, 700);
		}).fail(function () {
			$button.prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	});

	function lfaMessages() {
		return (LinkFlowAuditor && LinkFlowAuditor.messages) || {};
	}

	function getCurrentSuggestionIds($root) {
		var value = ($root.find('.lfa-current-suggestion-ids').first().val() || '').trim();

		if (!value) {
			return '';
		}

		return value.split(',').filter(Boolean).join(',');
	}

	function updateTabCount($row, scope, count) {
		var selectors = {
			broken: '[data-lfa-tab="broken"]',
			redirect: '[data-lfa-tab="redirects"]',
			external: '[data-lfa-tab="external"]',
			suggestions: '[data-lfa-tab="suggestions"]'
		};
		var selector = selectors[scope];

		if (!selector) {
			return;
		}

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

			var counts = {
				broken: data.broken_count,
				redirect: data.redirect_count,
				external: data.external_count
			};
			updateTabCount($row, payload.scope, counts[payload.scope]);
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

	$(document).on('click', '.lfa-accept-suggestion', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();
		var suggestionId = $button.data('suggestion-id') || '';

		if (!suggestionId) {
			setMessage($root, messages.error, true);
			return;
		}

		if (!window.confirm(messages.confirmAccept)) {
			return;
		}

		$row.find('button').prop('disabled', true);
		setMessage($root, messages.accepting, false);

		request({
			action: 'linkflow_auditor_accept_suggestion',
			suggestion_id: suggestionId
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$row.find('button').prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			setMessage($root, data.message || messages.done, false);
			updateTabCount($row, 'suggestions', data.suggestion_count);
			removeRow($row);
		}).fail(function () {
			$row.find('button').prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	});

	$(document).on('click', '.lfa-dismiss-suggestion', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();
		var suggestionId = $button.data('suggestion-id') || '';

		if (!suggestionId) {
			setMessage($root, messages.error, true);
			return;
		}

		if (!window.confirm(messages.confirmDismiss)) {
			return;
		}

		$row.find('button').prop('disabled', true);
		setMessage($root, messages.dismissing, false);

		request({
			action: 'linkflow_auditor_dismiss_suggestion',
			suggestion_id: suggestionId
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$row.find('button').prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			setMessage($root, data.message || messages.done, false);
			updateTabCount($row, 'suggestions', data.suggestion_count);
			removeRow($row);
		}).fail(function () {
			$row.find('button').prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	});

	$(document).on('click', '.lfa-reset-dismissed-suggestions', function () {
		var $button = $(this);
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();

		if (!window.confirm(messages.confirmResetDismissed)) {
			return;
		}

		$button.prop('disabled', true);
		setMessage($root, messages.resetting, false);

		request({
			action: 'linkflow_auditor_reset_dismissed_suggestions'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$button.prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			$root.find('.lfa-dismissed-count').text(data.count || 0);
			setMessage($root, data.message || messages.done, false);
		}).fail(function () {
			$button.prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	});

	$(document).on('click', '.lfa-change-suggestions', function () {
		var $button = $(this);
		var $panel = $button.closest('.lfa-tab-panel');
		var messages = lfaMessages();

		$button.prop('disabled', true);
		setMessage($panel, messages.changingSuggestions, false);

		request({
			action: 'linkflow_auditor_rotate_suggestions',
			current_ids: getCurrentSuggestionIds($panel)
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$button.prop('disabled', false);
				setMessage($panel, data.message || messages.error, true);
				return;
			}

			$panel.find('.lfa-suggestion-results').html(data.html || '');
			setMessage($panel, data.message || '', false);
		}).fail(function () {
			$button.prop('disabled', false);
			setMessage($panel, messages.error, true);
		});
	});

	$(document).on('click', '.lfa-clear-suggestion-rotation', function () {
		var $button = $(this);
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();

		if (!window.confirm(messages.confirmClearSuggestionRecords)) {
			return;
		}

		$button.prop('disabled', true);
		setMessage($root, messages.clearingSuggestionRecords, false);

		request({
			action: 'linkflow_auditor_clear_suggestion_rotation',
			scope: $button.data('scope') || 'normal'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$button.prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			$root.find('.lfa-current-suggestion-ids').val('');
			$button.prop('disabled', false);
			setMessage($root, data.message || messages.done, false);
		}).fail(function () {
			$button.prop('disabled', false);
			setMessage($root, messages.error, true);
		});
	});

	function getManualMode($builder) {
		return $builder.find('input[name="lfa-manual-mode"]:checked').val() || 'phrase';
	}

	function updateManualMode($builder) {
		var mode = getManualMode($builder);

		$builder.find('.lfa-manual-mode-fields--phrase').prop('hidden', mode !== 'phrase');
		$builder.find('.lfa-manual-mode-fields--source').prop('hidden', mode !== 'source_url');
	}

	function runManualSuggestionSearch($button, resetContext) {
		var $panel = $button.closest('.lfa-tab-panel');
		var $builder = $panel.find('[data-lfa-manual-builder]');
		var messages = lfaMessages();
		var mode = getManualMode($builder);
		var anchor = ($builder.find('.lfa-manual-anchor').val() || '').trim();
		var targetUrl = ($builder.find('.lfa-manual-target').val() || '').trim();
		var sourceUrl = ($builder.find('.lfa-manual-source-url').val() || '').trim();
		var sort = $builder.find('.lfa-manual-sort').val() || 'least_links';

		if (mode === 'source_url') {
			if (!sourceUrl) {
				window.alert(messages.emptySourceUrl || messages.emptyUrl || messages.error);
				return;
			}
		} else {
			if (!anchor) {
				window.alert(messages.emptyAnchor || messages.error);
				return;
			}

			if (!targetUrl) {
				window.alert(messages.emptyUrl || messages.error);
				return;
			}
		}

		$button.prop('disabled', true);
		$builder.find('.lfa-spinner').addClass('is-active');
		setMessage($panel, resetContext ? messages.searching : messages.changingSuggestions, false);

		if (resetContext) {
			$panel.find('.lfa-manual-results').empty();
		}

		request({
			action: 'linkflow_auditor_manual_suggestions',
			mode: mode,
			anchor: anchor,
			target_url: targetUrl,
			source_url: sourceUrl,
			sort: sort,
			current_ids: resetContext ? '' : getCurrentSuggestionIds($panel),
			reset_context: resetContext ? '1' : '0'
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setMessage($panel, data.message || messages.error, true);
				return;
			}

			setMessage($panel, data.message || '', false);
			$panel.find('.lfa-manual-results').html(data.html || '');
		}).fail(function () {
			setMessage($panel, messages.error, true);
		}).always(function () {
			$button.prop('disabled', false);
			$builder.find('.lfa-spinner').removeClass('is-active');
		});
	}

	$(document).on('change', 'input[name="lfa-manual-mode"]', function () {
		updateManualMode($(this).closest('[data-lfa-manual-builder]'));
	});

	$(document).on('click', '.lfa-manual-search', function () {
		runManualSuggestionSearch($(this), true);
	});

	$(document).on('click', '.lfa-manual-change-suggestions', function () {
		runManualSuggestionSearch($(this), false);
	});

	$(document).on('click', '.lfa-accept-manual-suggestion', function () {
		var $button = $(this);
		var $row = $button.closest('tr');
		var $root = $button.closest('.lfa-tab-panel, .lfa-widget, .lfa-page');
		var messages = lfaMessages();

		if (!window.confirm(messages.confirmAccept)) {
			return;
		}

		$row.find('button').prop('disabled', true);
		setMessage($root, messages.accepting, false);

		request({
			action: 'linkflow_auditor_accept_manual_suggestion',
			source_id: $button.data('source-id'),
			anchor: $button.data('anchor') || '',
			target_url: $button.data('target-url') || ''
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$row.find('button').prop('disabled', false);
				setMessage($root, data.message || messages.error, true);
				return;
			}

			setMessage($root, data.message || messages.done, false);
			removeRow($row);
		}).fail(function () {
			$row.find('button').prop('disabled', false);
			setMessage($root, messages.error, true);
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

		$('[data-lfa-manual-builder]').each(function () {
			updateManualMode($(this));
		});
	});

	/* ---------- "Who links here" detail toggle ---------- */

	$(document).on('click', '.lfa-count-toggle', function () {
		var $button = $(this);
		var targetId = $button.data('detail-target');
		var $detail = targetId ? $('#' + targetId) : $();
		var expanded = $button.attr('aria-expanded') === 'true';

		if (!$detail.length) {
			return;
		}

		$button.attr('aria-expanded', expanded ? 'false' : 'true');
		$detail.prop('hidden', expanded);
	});

	// Explicit "close" button inside an expanded detail panel.
	$(document).on('click', '.lfa-detail-close', function () {
		var targetId = $(this).data('detail-target');
		var $detail = targetId ? $('#' + targetId) : $(this).closest('.lfa-detail-row');

		$detail.prop('hidden', true);
		$('[data-detail-target="' + targetId + '"].lfa-count-toggle').attr('aria-expanded', 'false');
	});

	/* ---------- Internal link filtering + report + CSV export ---------- */

	function parseBound(value) {
		if (value === '' || value === null || typeof value === 'undefined') {
			return null;
		}

		var num = parseInt(value, 10);

		return isNaN(num) ? null : num;
	}

	function lfaNorm(value) {
		var text = (value === null || typeof value === 'undefined') ? '' : String(value).toLowerCase();

		if (typeof text.normalize === 'function') {
			text = text.normalize('NFC');
		}

		return text.replace(/i\u0307/g, 'i');
	}

	function collapseDetail($row) {
		var $detail = $row.next('.lfa-detail-row');

		if ($detail.length) {
			$detail.prop('hidden', true);
		}

		$row.find('.lfa-count-toggle').attr('aria-expanded', 'false');
	}

	function applyFilter($panel) {
		var $bar = $panel.find('[data-lfa-filter]');

		if (!$bar.length) {
			return;
		}

		var min = parseBound($bar.find('.lfa-filter-min').val());
		var max = parseBound($bar.find('.lfa-filter-max').val());
		var query = lfaNorm(($bar.find('.lfa-filter-search').val() || '').trim());
		var shown = 0;

		$panel.find('tr.lfa-row').each(function () {
			var $row = $(this);
			var sources = parseInt($row.attr('data-incoming-sources'), 10) || 0;
			var title = lfaNorm($row.attr('data-title') || '');
			var visible = true;

			if (min !== null && sources < min) {
				visible = false;
			}

			if (max !== null && sources > max) {
				visible = false;
			}

			if (query && title.indexOf(query) === -1) {
				visible = false;
			}

			if (visible) {
				$row.show();
				shown += 1;
			} else {
				$row.hide();
				collapseDetail($row);
			}
		});

		$bar.find('.lfa-filter-shown').text(shown.toLocaleString());
	}

	function setActivePreset($bar, $preset) {
		$bar.find('.lfa-preset').removeClass('is-active');

		if ($preset && $preset.length) {
			$preset.addClass('is-active');
		}
	}

	$(document).on('click', '.lfa-preset', function () {
		var $preset = $(this);
		var $bar = $preset.closest('[data-lfa-filter]');
		var $panel = $bar.closest('.lfa-tab-panel');

		$bar.find('.lfa-filter-min').val($preset.data('min') === '' ? '' : $preset.data('min'));
		$bar.find('.lfa-filter-max').val($preset.data('max') === '' ? '' : $preset.data('max'));
		setActivePreset($bar, $preset);
		applyFilter($panel);
	});

	$(document).on('input', '.lfa-filter-min, .lfa-filter-max, .lfa-filter-search', function () {
		var $bar = $(this).closest('[data-lfa-filter]');
		var $panel = $bar.closest('.lfa-tab-panel');

		setActivePreset($bar, null);
		applyFilter($panel);
	});

	$(document).on('click', '.lfa-filter-reset', function () {
		var $bar = $(this).closest('[data-lfa-filter]');
		var $panel = $bar.closest('.lfa-tab-panel');

		$bar.find('.lfa-filter-min, .lfa-filter-max, .lfa-filter-search').val('');
		setActivePreset($bar, $bar.find('.lfa-preset').first());
		applyFilter($panel);
	});

	function csvCell(value) {
		var text = (value === null || typeof value === 'undefined') ? '' : String(value);

		if (/[",\n;]/.test(text)) {
			text = '"' + text.replace(/"/g, '""') + '"';
		}

		return text;
	}

	/* ---------- External links search ---------- */

	$(document).on('input', '.lfa-external-search', function () {
		var query = lfaNorm(($(this).val() || '').trim());
		var $panel = $(this).closest('.lfa-tab-panel');

		$panel.find('tr.lfa-external-row').each(function () {
			var haystack = lfaNorm($(this).attr('data-search') || '');
			$(this).toggle(!query || haystack.indexOf(query) !== -1);
		});
	});

	$(document).on('input', '.lfa-suggestion-search', function () {
		var query = lfaNorm(($(this).val() || '').trim());
		var $panel = $(this).closest('.lfa-tab-panel');

		$panel.find('tr.lfa-suggestion-row').each(function () {
			var haystack = lfaNorm($(this).attr('data-search') || '');
			$(this).toggle(!query || haystack.indexOf(query) !== -1);
		});
	});

	$(document).on('click', '.lfa-export-csv', function () {
		var $panel = $(this).closest('.lfa-tab-panel');
		var rows = [[
			'Başlık',
			'URL',
			'Tür',
			'Gelen link (yazı)',
			'Gelen link (toplam)',
			'Çıkan link (hedef)',
			'Çıkan link (toplam)'
		]];

		$panel.find('tr.lfa-row:visible').each(function () {
			var $row = $(this);
			var $first = $row.children('td').eq(0);

			rows.push([
				$first.find('strong').first().text().trim(),
				$first.find('.lfa-url a').first().attr('href') || '',
				$row.find('.lfa-type-badge').first().text().trim(),
				parseInt($row.attr('data-incoming-sources'), 10) || 0,
				parseInt($row.attr('data-incoming-links'), 10) || 0,
				$row.children('td').eq(4).text().trim(),
				$row.children('td').eq(5).text().trim()
			]);
		});

		var csv = rows.map(function (row) {
			return row.map(csvCell).join(',');
		}).join('\r\n');

		// Prepend BOM so Excel reads UTF-8 (Turkish characters) correctly.
		var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
		var url = URL.createObjectURL(blob);
		var stamp = new Date().toISOString().slice(0, 10);
		var $link = $('<a>', { href: url, download: 'linkflow-ic-link-raporu-' + stamp + '.csv' });

		$('body').append($link);
		$link[0].click();
		$link.remove();
		window.setTimeout(function () {
			URL.revokeObjectURL(url);
		}, 1000);
	});
})(jQuery);
