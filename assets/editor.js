(function ($) {
	'use strict';

	function config() {
		return window.LinkFlowAuditorEditor || { messages: {} };
	}

	function messages() {
		return config().messages || {};
	}

	function setBusy($box, busy) {
		$box.find('.lfa-editor-scan').prop('disabled', busy);
		$box.find('.lfa-editor-spinner').toggleClass('is-active', busy);
	}

	function setMessage($box, message, isError) {
		$box.find('.lfa-editor-message')
			.first()
			.toggleClass('lfa-error', Boolean(isError))
			.text(message || '');
	}

	function request(data) {
		return $.ajax({
			url: config().ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({}, data, { nonce: config().nonce })
		});
	}

	function currentSuggestionIds($box) {
		var value = ($box.find('.lfa-current-suggestion-ids').first().val() || '').trim();

		if (!value) {
			return '';
		}

		return value.split(',').filter(Boolean).join(',');
	}

	function runScan($box, reset) {
		var postId = $box.data('post-id');
		var sort = $box.find('.lfa-editor-sort').val() || 'least_links';
		var excludeWords = ($box.find('.lfa-editor-exclude').val() || '').trim();

		if (!postId) {
			setMessage($box, messages().error, true);
			return;
		}

		setBusy($box, true);
		setMessage($box, reset ? messages().scanning : messages().changing, false);

		if (reset) {
			$box.find('.lfa-editor-results').empty();
		}

		request({
			action: 'linkflow_auditor_editor_suggestions',
			post_id: postId,
			sort: sort,
			exclude_words: excludeWords,
			current_ids: reset ? '' : currentSuggestionIds($box)
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				setMessage($box, data.message || messages().error, true);
				return;
			}

			setMessage($box, data.message || '', false);
			$box.find('.lfa-editor-results').html(data.html || '');
		}).fail(function () {
			setMessage($box, messages().error, true);
		}).always(function () {
			setBusy($box, false);
		});
	}

	$(document).on('click', '.lfa-editor-box .lfa-editor-scan', function () {
		runScan($(this).closest('.lfa-editor-box'), true);
	});

	$(document).on('click', '.lfa-editor-box .lfa-manual-change-suggestions', function () {
		runScan($(this).closest('.lfa-editor-box'), false);
	});

	$(document).on('click', '.lfa-editor-box .lfa-accept-manual-suggestion', function () {
		var $button = $(this);
		var $box = $button.closest('.lfa-editor-box');
		var $row = $button.closest('tr');

		if (!window.confirm(messages().confirmAccept)) {
			return;
		}

		$row.find('button').prop('disabled', true);
		setMessage($box, messages().accepting, false);

		request({
			action: 'linkflow_auditor_editor_accept',
			post_id: $box.data('post-id'),
			anchor: $button.data('anchor') || '',
			target_url: $button.data('target-url') || ''
		}).done(function (response) {
			var data = response && response.data ? response.data : {};

			if (!response || !response.success) {
				$row.find('button').prop('disabled', false);
				setMessage($box, data.message || messages().error, true);
				return;
			}

			// The link is saved directly into the post content in the database.
			// Reload the editor so it re-loads the updated content; otherwise the
			// open block editor still holds the old content and would overwrite the
			// newly added link on the next save.
			$row.addClass('lfa-suggestion-applied');
			$row.find('button').prop('disabled', true);
			setMessage($box, data.message || messages().accepted || '', false);

			window.setTimeout(function () {
				window.location.reload();
			}, 1300);
		}).fail(function () {
			$row.find('button').prop('disabled', false);
			setMessage($box, messages().error, true);
		});
	});

	function selectedSuggestions($box) {
		return $box.find('.lfa-suggestion-select:checked');
	}

	function updateBulkState($box) {
		var $all = $box.find('.lfa-suggestion-select');
		var selected = $box.find('.lfa-suggestion-select:checked').length;

		// The apply button stays enabled at all times; clicking it with nothing
		// selected simply shows a hint. Only the live count and the select-all
		// checkbox state are updated here.
		$box.find('.lfa-selected-count').text(selected);
		$box.find('.lfa-suggestion-select-all').prop('checked', $all.length > 0 && selected === $all.length);
	}

	$(document).on('change', '.lfa-editor-box .lfa-suggestion-select', function () {
		updateBulkState($(this).closest('.lfa-editor-box'));
	});

	$(document).on('change', '.lfa-editor-box .lfa-suggestion-select-all', function () {
		var $box = $(this).closest('.lfa-editor-box');
		$box.find('.lfa-suggestion-select').prop('checked', $(this).prop('checked'));
		updateBulkState($box);
	});

	$(document).on('click', '.lfa-editor-box .lfa-manual-apply-selected', function () {
		var $box = $(this).closest('.lfa-editor-box');
		var $checks = selectedSuggestions($box);

		if (!$checks.length) {
			setMessage($box, messages().noSelection, true);
			return;
		}

		if (!window.confirm(messages().confirmApplySelected || messages().confirmAccept)) {
			return;
		}

		var items = [];
		$checks.each(function () {
			items.push({
				anchor: $(this).data('anchor') || '',
				target_url: $(this).data('target-url') || ''
			});
		});

		var postId = $box.data('post-id');
		var total = items.length;
		var applied = 0;
		var failed = 0;

		// Lock the whole panel while the selected suggestions are applied one by one.
		setBusy($box, true);
		$box.find('.lfa-editor-results').find('input, button').prop('disabled', true);

		function applyNext(index) {
			if (index >= total) {
				var summary = (messages().appliedBulk || '') + ' (' + applied + '/' + total + ')';
				setMessage($box, summary, failed > 0);
				window.setTimeout(function () {
					window.location.reload();
				}, 1300);
				return;
			}

			setMessage($box, (messages().applying || messages().accepting || '') + ' (' + (index + 1) + '/' + total + ')', false);

			request({
				action: 'linkflow_auditor_editor_accept',
				post_id: postId,
				anchor: items[index].anchor,
				target_url: items[index].target_url
			}).done(function (response) {
				if (response && response.success) {
					applied++;
				} else {
					failed++;
				}
			}).fail(function () {
				failed++;
			}).always(function () {
				// Apply sequentially so each link is saved before the next lookup
				// runs against the freshly updated post content.
				applyNext(index + 1);
			});
		}

		applyNext(0);
	});
})(jQuery);
