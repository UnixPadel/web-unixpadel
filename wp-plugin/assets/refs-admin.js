/* Reference editor (WP admin): gallery picker, court type limit, character counters, Instagram Import, AI Quick Draft & Translation. */
(function ($) {
	'use strict';

	var isIgPost = Boolean(window.upxAiVars && window.upxAiVars.isIg) || $('.upx-ref').attr('data-is-ig') === '1';
	var genMeta = (window.upxAiVars && window.upxAiVars.generatedMeta) ? window.upxAiVars.generatedMeta : null;

	// TinyMCE helper to set/get content across visual and text modes.
	function setEditorContent(id, content) {
		if (window.tinymce && window.tinymce.get(id)) {
			window.tinymce.get(id).setContent(content);
		}
		$('#' + id).val(content);
	}

	function getEditorContent(id) {
		if (window.tinymce && window.tinymce.get(id)) {
			return window.tinymce.get(id).getContent();
		}
		return $('#' + id).val() || '';
	}

	function getAjaxUrl() {
		return (window.upxAiVars && window.upxAiVars.ajaxUrl) ? window.upxAiVars.ajaxUrl : window.ajaxurl;
	}

	function getAiNonce() {
		return (window.upxAiVars && window.upxAiVars.nonce) ? window.upxAiVars.nonce : $('#upx_ai_nonce').val();
	}

	function getPostId() {
		if (window.upxAiVars && window.upxAiVars.postId) {
			return parseInt(window.upxAiVars.postId, 10) || 0;
		}
		return parseInt($('.upx-ai-box').attr('data-post-id') || $('#post_ID').val(), 10) || 0;
	}

	// Gallery: media library multi-select, drag to reorder, remove. The order is kept in a hidden comma list of IDs.
	var $list = $('#upx-gallery-list');
	var $input = $('#upx-gallery');
	var frame;
	function sync() {
		$input.val($list.children('li').map(function () { return $(this).data('id'); }).get().join(','));
	}
	if ($list.length) {
		$list.sortable({ update: sync });
		$list.on('click', '.upx-gallery__remove', function () {
			$(this).closest('li').remove();
			sync();
		});
		$('#upx-gallery-add').on('click', function (e) {
			e.preventDefault();
			if (!frame) {
				frame = wp.media({ title: 'Gallery photos', button: { text: 'Add to gallery' }, library: { type: 'image' }, multiple: 'add' });
				frame.on('select', function () {
					var have = $input.val() ? $input.val().split(',') : [];
					frame.state().get('selection').each(function (att) {
						var id = String(att.id);
						if (have.indexOf(id) !== -1) return;
						have.push(id);
						var sizes = att.get('sizes') || {};
						var url = (sizes.thumbnail || sizes.medium || { url: att.get('url') }).url;
						$('<li>').attr('data-id', id)
							.append($('<img alt="">').attr('src', url))
							.append('<button type="button" class="upx-gallery__remove" aria-label="Remove">×</button>')
							.appendTo($list);
					});
					sync();
				});
			}
			frame.open();
		});
	}

	// Court types: at most data-max boxes ticked.
	$('.upx-ref__types').each(function () {
		var $set = $(this);
		var max = parseInt($set.data('max'), 10) || 2;
		function limit() {
			var full = $set.find('input:checked').length >= max;
			$set.find('input:not(:checked)').prop('disabled', full);
		}
		$set.on('change', 'input', limit);
		limit();
	});

	// Character counters on SEO fields (search engines cut longer titles and descriptions).
	$('[data-count]').each(function () {
		var $f = $(this);
		var max = parseInt($f.data('count'), 10);
		var $c = $('<span class="upx-count">').insertAfter($f);
		function update() {
			var n = ($f.val() || '').length;
			$c.text(n + ' / ' + max).toggleClass('is-over', n > max);
		}
		$f.on('input', update);
		update();
	});

	// Address: only a–z, 0–9 and hyphens while typing.
	$('#upx-slug').on('input', function () {
		var v = this.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-');
		if (v !== this.value) this.value = v;
	});

	// Auto-detect Country & Court Types from text heuristics (for non-Instagram manual entries).
	var countryRules = [
		['turkiye', /t[uü]rk(ey|iye)|antalya|bodrum|marmaris|[cç]e[sş]me|diyarbak[iı]r|kilyos|belek|izmir|istanbul|bosphorus|adıyaman/i],
		['germany', /german|almanya|w[uü]rzburg|cologne|fsb/i],
		['austria', /austria|avusturya|viyana|vienna|hagenberg/i],
		['bosnia-herzegovina', /bosnia|bosna|saraybosna|sarajevo/i],
		['italy', /ital|italya|bari|vicenza|vincenza/i],
		['greece', /greece|yunanistan|halkida|chalkida/i],
		['romania', /romania|romanya/i],
		['denmark', /danimarka|denmark/i],
		['united-kingdom', /england|ingiltere|birleşik krallık|\buk\b|united kingdom/i],
		['netherlands', /netherlands|hollanda|holland/i],
		['lebanon', /lebanon|lübnan/i],
		['azerbaijan', /azerbaijan|azerbaycan|bakü|baku/i],
		['saudi-arabia', /saudi|suudi|dammam/i],
		['oman', /oman|umman/i],
		['northern-cyprus', /cyprus|kıbrıs/i],
		['south-africa', /south[\s-]africa|güney afrika/i],
		['usa', /\busa\b|united states| abd |amerika|virginia/i]
	];

	var courtRules = [
		['/single-full-panoramic-padel-court/', /single[\s-]full[\s-]panoramic/i],
		['/single-panoramic-padel-court/', /single[\s-]panoramic/i],
		['/full-panoramic-padel-court/', /full[\s-]panoramic/i],
		['/panaromic-plate-padel-court/', /panoramic plate/i],
		['/plate-standard-padel-court/', /\bplate standard\b|\bplate\b/i],
		['/panoramic-padel-court/', /panoramic/i],
		['/wp-challenge-padel-court/', /wp[\s-]challenge/i],
		['/padel-court-cover/', /padel[\s-]court[\s-]cover/i],
		['/portable-padel-court/', /portable/i],
		['/innovative-courts/flat-floor-padel-court/', /flat[\s-]floor/i],
		['/floating-floor-padel-court/', /floating/i],
		['/mobilfold-padel-court/', /mobil\s?fold/i],
		['/standard-padel-court/', /standard (?:padel )?courts?/i],
		['/single-padel-court/', /single (?:padel )?courts?/i],
		['/padbols/', /padbol/i],
		['/our-courts/pickleball-court/', /pickleball/i],
		['/full-membrane-cover/', /membrane/i],
		['/sandwich-panel-full-covered/', /sandwich[\s-]panel/i],
		['/retractable-roof-cover-system/', /retractable/i],
		['/roof-top-canopy/', /roof[\s-]?top/i],
		['/half-close-canopy/', /half[\s–-]+close/i]
	];

	function detectAndSetMetadata(text) {
		if (isIgPost) return; // Instagram drafts require explicit review/selection in Phase 5.
		var $country = $('#upx-country');
		if ($country.length && !$country.val()) {
			for (var i = 0; i < countryRules.length; i++) {
				if (countryRules[i][1].test(text)) {
					$country.val(countryRules[i][0]);
					break;
				}
			}
		}

		var $typeBoxes = $('.upx-ref__types input[type="checkbox"]');
		if ($typeBoxes.length && $typeBoxes.filter(':checked').length === 0) {
			var hits = [];
			for (var j = 0; j < courtRules.length; j++) {
				if (courtRules[j][1].test(text) && hits.indexOf(courtRules[j][0]) === -1) {
					hits.push(courtRules[j][0]);
					if (hits.length >= 2) break;
				}
			}
			if (hits.length) {
				hits.forEach(function (url) {
					$typeBoxes.filter('[value="' + url + '"]').prop('checked', true).trigger('change');
				});
			}
		}
	}

	$('#title').on('input', function () {
		detectAndSetMetadata(this.value);
	});

	/* ==========================================================================
	 * PHASE 1, 2, 3 — Instagram Import Tabs, URL Fetch & Preview Tray
	 * ========================================================================== */
	var $igTray = $('#upx-ig-tray');
	var $igCaption = $('#upx-ig-caption');
	var $igGenBtn = $('#upx-ig-generate-btn');
	var igMediaFrame;

	function switchAiTab(tabName) {
		$('.upx-ai-tab').removeClass('is-active');
		$('.upx-ai-tab[data-tab="' + tabName + '"]').addClass('is-active');
		$('.upx-ai-pane').removeClass('is-active');

		if (tabName === 'ig-link') {
			$('#upx-pane-ig-link').addClass('is-active');
			$('#upx-ig-input-mode').val('link');
			// Keep preview visible if it already has content or images.
			if ($.trim($igCaption.val()) !== '' || $igTray.children('li').length > 0) {
				$('#upx-ig-preview').show();
			} else {
				$('#upx-ig-preview').hide();
			}
		} else if (tabName === 'ig-manual') {
			$('#upx-pane-ig-manual').addClass('is-active');
			$('#upx-ig-input-mode').val('manual');
			$('#upx-ig-preview').show();
		} else if (tabName === 'quick-notes') {
			$('#upx-pane-quick-notes').addClass('is-active');
			$('#upx-ig-preview').hide();
		}
	}

	$('.upx-ai-tab').on('click', function () {
		switchAiTab($(this).data('tab'));
	});

	function updatePreviewState() {
		var count = $igTray.children('li').length;
		$('#upx-ig-tray-empty').toggle(count === 0);

		// Ensure exactly one item has .is-cover when count > 0.
		if (count > 0 && $igTray.children('li.is-cover').length === 0) {
			$igTray.children('li').first().addClass('is-cover');
		}
		$igTray.children('li').each(function () {
			var $li = $(this);
			var isCov = $li.hasClass('is-cover');
			$li.find('.upx-ig-tray__cover-btn').text(isCov ? '★ Cover' : 'Set as cover').toggleClass('is-active', isCov);
		});

		var hasCap = $.trim($igCaption.val()).length > 0;
		$igGenBtn.prop('disabled', !(hasCap && count > 0));
	}

	function addTrayItem(item) {
		// item: { type: 'url'|'attachment', id: int, url: string, thumb: string, orig_index: int, is_video: bool }
		var $li = $('<li>')
			.attr('data-type', item.type || 'url')
			.attr('data-id', item.id || 0)
			.attr('data-url', item.url || '')
			.attr('data-orig-index', typeof item.orig_index === 'number' ? item.orig_index : $igTray.children('li').length)
			.attr('data-is-video', item.is_video ? '1' : '0');

		if ($igTray.children('li').length === 0) {
			$li.addClass('is-cover');
		}

		$('<img>').attr('src', item.thumb || item.url).attr('alt', '').appendTo($li);

		if (item.is_video) {
			$('<span class="upx-ig-tray__video-badge" title="Video — thumbnail only, upload a photo if needed">Video thumb</span>').appendTo($li);
		}

		$('<button type="button" class="upx-ig-tray__cover-btn">Set as cover</button>').appendTo($li);
		$('<button type="button" class="upx-ig-tray__remove" aria-label="Remove">×</button>').appendTo($li);

		$igTray.append($li);
		updatePreviewState();
	}

	if ($igTray.length) {
		$igTray.sortable({
			update: function () {
				updatePreviewState();
			}
		});

		$igTray.on('click', '.upx-ig-tray__remove', function () {
			$(this).closest('li').remove();
			updatePreviewState();
		});

		$igTray.on('click', '.upx-ig-tray__cover-btn', function () {
			$igTray.children('li').removeClass('is-cover');
			$(this).closest('li').addClass('is-cover');
			updatePreviewState();
		});

		$igCaption.on('input', updatePreviewState);

		$('#upx-ig-add-images').on('click', function (e) {
			e.preventDefault();
			if (!igMediaFrame) {
				igMediaFrame = wp.media({
					title: 'Select or Upload Instagram Photos',
					button: { text: 'Add to preview tray' },
					library: { type: 'image' },
					multiple: 'add'
				});
				igMediaFrame.on('select', function () {
					igMediaFrame.state().get('selection').each(function (att) {
						var id = att.id;
						if ($igTray.children('li[data-type="attachment"][data-id="' + id + '"]').length > 0) {
							return;
						}
						var sizes = att.get('sizes') || {};
						var thumbUrl = (sizes.medium || sizes.thumbnail || { url: att.get('url') }).url;
						addTrayItem({
							type: 'attachment',
							id: id,
							url: att.get('url'),
							thumb: thumbUrl,
							orig_index: $igTray.children('li').length,
							is_video: false
						});
					});
				});
			}
			igMediaFrame.open();
		});
	}

	function normalizeIgUrlClient(raw) {
		var s = $.trim(raw || '');
		if (!s) return null;
		if (!/^https?:\/\//i.test(s) && /^(?:www\.)?(?:instagram\.com|instagr\.am)\//i.test(s)) {
			s = 'https://' + s;
		}
		var m = s.match(/^https?:\/\/(?:www\.)?(?:instagram\.com|instagr\.am)\/(?:(?!p\/|reel\/|tv\/)[A-Za-z0-9_.]+\/)?(p|reel|tv)\/([A-Za-z0-9_-]+)/i);
		if (!m) return null;
		return {
			kind: m[1].toLowerCase(),
			shortcode: m[2],
			permalink: 'https://www.instagram.com/p/' + m[2] + '/'
		};
	}

	function doIgFetch(confirmDuplicate) {
		var rawUrl = $('#upx-ig-url').val();
		var $status = $('#upx-ig-fetch-status');
		var $btn = $('#upx-ig-fetch-btn');
		var $dupBox = $('#upx-ig-duplicate-notice');
		var $banner = $('#upx-ig-preview-banner');

		var norm = normalizeIgUrlClient(rawUrl);
		if (!norm) {
			$status.attr('class', 'upx-ai-status is-error').text('Invalid Instagram URL. Use https://www.instagram.com/p/{shortcode}/ (or /reel/ or /tv/).');
			$('#upx-ig-url').focus();
			return;
		}

		// Normalize URL input visibly for the admin.
		$('#upx-ig-url').val(norm.permalink);
		$dupBox.hide();
		$btn.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text('Fetching post from Instagram...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ig_fetch',
				nonce: getAiNonce(),
				url: norm.permalink,
				post_id: getPostId(),
				confirm_duplicate: confirmDuplicate ? 1 : 0
			}
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not fetch Instagram post.';
				$status.attr('class', 'upx-ai-status is-error').text(msg);
				return;
			}

			var d = res.data;
			if (d.duplicate && d.existing) {
				$status.attr('class', 'upx-ai-status').text('');
				$('#upx-ig-dup-link').attr('href', d.existing.edit_url).text(d.existing.title + ' (#' + d.existing.id + ')');
				$('#upx-ig-dup-status').text(d.existing.status);
				$dupBox.show();
				return;
			}

			$('#upx-ig-shortcode').val(d.shortcode || norm.shortcode);
			$('#upx-ig-permalink').val(d.permalink || norm.permalink);
			$('#upx-ig-location').val(d.location_name || '');
			$('#upx-ig-preview').show();

			if (d.caption) {
				$igCaption.val(d.caption);
			}

			if (Array.isArray(d.images) && d.images.length > 0) {
				$igTray.empty();
				d.images.forEach(function (img, idx) {
					addTrayItem({
						type: 'url',
						id: 0,
						url: img.url,
						thumb: img.url,
						orig_index: idx,
						is_video: Boolean(img.is_video)
					});
				});
			}

			if (d.status === 'complete') {
				$status.attr('class', 'upx-ai-status is-success').text('✓ Fetched!');
				$banner.attr('class', 'upx-ig-banner is-success').text(d.reason || 'Caption and images fetched. Review below and click Generate reference.').show();
				$('#upx-ig-input-mode').val('link');
			} else if (d.status === 'partial') {
				$status.attr('class', 'upx-ai-status').text('Partial fetch — see notice below.');
				$banner.attr('class', 'upx-ig-banner is-warning').text(d.reason || 'Partial data fetched. Please fill in what is missing below.').show();
				$('#upx-ig-input-mode').val('link');
			} else {
				// Automatic fallback to Mode B with shortcode/permalink retained!
				$status.attr('class', 'upx-ai-status').text('');
				switchAiTab('ig-manual');
				$('#upx-ig-input-mode').val('manual');
				$banner.attr('class', 'upx-ig-banner is-warning').text(d.reason || 'Could not fetch automatically from Instagram. Please paste the caption and upload the photos below.').show();
				$igCaption.focus();
			}

			updatePreviewState();
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Request failed.';
			$status.attr('class', 'upx-ai-status is-error').text(err);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	$('#upx-ig-fetch-btn').on('click', function () {
		doIgFetch(false);
	});

	$('#upx-ig-dup-continue').on('click', function () {
		$('#upx-ig-duplicate-notice').hide();
		doIgFetch(true);
	});

	/* ==========================================================================
	 * PHASE 4 — Generate Reference from Preview Step
	 * ========================================================================== */
	$igGenBtn.on('click', function () {
		var caption = $.trim($igCaption.val());
		var $items = $igTray.children('li');
		var $status = $('#upx-ig-generate-status');

		if (!caption || $items.length === 0) {
			$status.attr('class', 'upx-ai-status is-error').text('Caption and at least 1 image are required.');
			return;
		}

		var coverIndex = 0;
		var images = [];
		$items.each(function (idx) {
			var $li = $(this);
			if ($li.hasClass('is-cover')) {
				coverIndex = idx;
			}
			images.push({
				type: $li.attr('data-type') || 'url',
				id: parseInt($li.attr('data-id'), 10) || 0,
				url: $li.attr('data-url') || '',
				orig_index: parseInt($li.attr('data-orig-index'), 10) || idx,
				is_video: $li.attr('data-is-video') === '1' ? 1 : 0
			});
		});

		$igGenBtn.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text('Generating English reference, validating consistency, sideloading photos & translating 5 languages...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ig_generate',
				nonce: getAiNonce(),
				post_id: getPostId(),
				input_mode: $('#upx-ig-input-mode').val() || 'manual',
				shortcode: $('#upx-ig-shortcode').val() || '',
				permalink: $('#upx-ig-permalink').val() || '',
				location_name: $('#upx-ig-location').val() || '',
				caption: caption,
				cover_index: coverIndex,
				images: images
			}
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Generation failed.';
				$status.attr('class', 'upx-ai-status is-error').text('Error: ' + msg);
				$igGenBtn.prop('disabled', false);
				return;
			}

			$status.attr('class', 'upx-ai-status is-success').text('✓ Reference draft created! Redirecting to review screen...');
			if (res.data.edit_url) {
				window.onbeforeunload = null;
				window.location.href = res.data.edit_url;
			} else {
				window.location.reload();
			}
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Server error during generation.';
			$status.attr('class', 'upx-ai-status is-error').text('Error: ' + err);
			$igGenBtn.prop('disabled', false);
		});
	});

	/* ==========================================================================
	 * PHASE 5 & 6 — Review, Apply Suggestions, Stale Check & Publish Gate
	 * ========================================================================== */
	function getSelectedCourtTypes() {
		var urls = [];
		$('.upx-ref__types input[type="checkbox"]:checked').each(function () {
			urls.push(String($(this).val()));
		});
		urls.sort();
		return urls;
	}

	function formatSelectionLabel(kind, country, types) {
		var cMap = (window.upxAiVars && window.upxAiVars.countryLabels) ? window.upxAiVars.countryLabels : {};
		var pMap = (window.upxAiVars && window.upxAiVars.productLabels) ? window.upxAiVars.productLabels : {};
		var parts = [];

		if (kind === 'project') parts.push('Project');
		else if (kind === 'news') parts.push('Company news');
		else parts.push('No type');

		if (country && cMap[country]) parts.push(cMap[country]);
		else if (country) parts.push(country);
		else parts.push('No country');

		if (Array.isArray(types) && types.length > 0) {
			parts.push(types.map(function (u) { return pMap[u] || u; }).join(' + '));
		} else {
			parts.push('No court type');
		}
		return parts.join(' · ');
	}

	function isSameSelection(a, b) {
		if (!a || !b) return false;
		var k1 = a.kind || '';
		var k2 = b.kind || '';
		var c1 = a.country || '';
		var c2 = b.country || '';
		var t1 = Array.isArray(a.types) ? a.types.slice().sort() : [];
		var t2 = Array.isArray(b.types) ? b.types.slice().sort() : [];
		return k1 === k2 && c1 === c2 && t1.join('|') === t2.join('|');
	}

	function evaluateIgConsistency() {
		if (!isIgPost) return;

		var curKind = $('#upx-kind').val() || '';
		var curCountry = $('#upx-country').val() || '';
		var curTypes = getSelectedCourtTypes();

		var anySelected = (curKind !== '' || curCountry !== '' || curTypes.length > 0);
		var allSelected = (curKind !== '' && curCountry !== '' && curTypes.length > 0);
		var isStale = false;

		if (anySelected && genMeta) {
			isStale = !isSameSelection({ kind: curKind, country: curCountry, types: curTypes }, genMeta);
		}

		var $staleBar = $('#upx-ig-stale-bar');
		if (isStale && genMeta) {
			$('#upx-ig-stale-gen-label').text(formatSelectionLabel(genMeta.kind, genMeta.country, genMeta.types));
			$('#upx-ig-stale-cur-label').text(formatSelectionLabel(curKind, curCountry, curTypes));
			$staleBar.slideDown(150);
		} else {
			$staleBar.slideUp(150);
		}

		// Phase 6: Client-side Publish Gate Checklist & Button Lock.
		var $gate = $('#upx-ig-publish-gate');
		if ($gate.length) {
			var items = [
				{ ok: curKind !== '', text: '1. Select Type (Project or Company news)' },
				{ ok: curCountry !== '', text: '2. Select Country' },
				{ ok: curTypes.length > 0, text: '3. Select at least 1 Court type' },
				{ ok: allSelected && !isStale, text: '4. Content matches selections (not stale)' }
			];
			var html = '<strong>Publish Readiness Checklist:</strong><ul class="upx-ig-checklist">';
			items.forEach(function (it) {
				html += '<li class="' + (it.ok ? 'is-ok' : 'is-pending') + '">' + (it.ok ? '✓ ' : '○ ') + it.text + '</li>';
			});
			html += '</ul>';
			$gate.html(html);
		}

		var canPublish = allSelected && !isStale;
		var $pubBtn = $('#publish');
		if ($pubBtn.length && $pubBtn.val() !== 'Update') {
			$pubBtn.toggleClass('upx-publish-locked', !canPublish);
		}
	}

	// Apply single suggestion button: [Apply]
	$(document).on('click', '.upx-apply-single', function (e) {
		e.preventDefault();
		var field = $(this).data('field');
		var val = $(this).attr('data-value');

		if (field === 'type' && val) {
			$('#upx-kind').val(val).trigger('change');
		} else if (field === 'country' && val) {
			$('#upx-country').val(val).trigger('change');
		} else if (field === 'types' && val) {
			try {
				var urls = JSON.parse(val);
				if (Array.isArray(urls)) {
					$('.upx-ref__types input[type="checkbox"]').prop('checked', false);
					urls.forEach(function (u) {
						$('.upx-ref__types input[value="' + u + '"]').prop('checked', true);
					});
					$('.upx-ref__types input:first').trigger('change');
				}
			} catch (err) {}
		}
		evaluateIgConsistency();
	});

	// Apply all suggestions button
	$('#upx-ig-apply-all').on('click', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var t = $btn.attr('data-type');
		var c = $btn.attr('data-country');
		var typesRaw = $btn.attr('data-types');

		if (t) $('#upx-kind').val(t);
		if (c) $('#upx-country').val(c);
		if (typesRaw) {
			try {
				var urls = JSON.parse(typesRaw);
				if (Array.isArray(urls) && urls.length > 0) {
					$('.upx-ref__types input[type="checkbox"]').prop('checked', false);
					urls.forEach(function (u) {
						$('.upx-ref__types input[value="' + u + '"]').prop('checked', true);
					});
					$('.upx-ref__types input:first').trigger('change');
				}
			} catch (err) {}
		}
		evaluateIgConsistency();
	});

	$('#upx-kind, #upx-country').on('change', evaluateIgConsistency);
	$('.upx-ref__types').on('change', 'input[type="checkbox"]', evaluateIgConsistency);

	// Phase 5: "Regenerate text" button
	$('#upx-ig-btn-regenerate').on('click', function () {
		var curKind = $('#upx-kind').val() || '';
		var curCountry = $('#upx-country').val() || '';
		var curTypes = getSelectedCourtTypes();
		var $status = $('#upx-ig-stale-status');
		var $btns = $('#upx-ig-btn-regenerate, #upx-ig-btn-ack-stale');

		if (!curKind || !curCountry || curTypes.length === 0) {
			$status.attr('class', 'upx-ai-status is-error').text('Please select Type, Country, and at least 1 Court type first.');
			return;
		}

		$btns.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text('Regenerating English text & 5 translations for your selections...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ig_regenerate',
				nonce: getAiNonce(),
				post_id: getPostId(),
				kind: curKind,
				country: curCountry,
				types: curTypes
			}
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Regeneration failed.';
				$status.attr('class', 'upx-ai-status is-error').text('Error: ' + msg);
				return;
			}
			var d = res.data;
			if (d.title) $('#title').val(d.title);
			if (d.slug) $('#upx-slug').val(d.slug);
			if (d.excerpt) $('#excerpt').val(d.excerpt);
			if (d.body_html) setEditorContent('content', d.body_html);
			if (d.seo_title) $('#upx-seo-title').val(d.seo_title).trigger('input');
			if (d.seo_desc) $('#upx-seo-desc').val(d.seo_desc).trigger('input');
			if (d.focus_keyphrase) $('#upx-focus-keyphrase').val(d.focus_keyphrase);

			if (d.translations && typeof d.translations === 'object') {
				Object.keys(d.translations).forEach(function (lang) {
					var t = d.translations[lang];
					if (!t) return;
					$('input[name="upx_l10n[' + lang + '][title]"]').val(t.title || '');
					$('textarea[name="upx_l10n[' + lang + '][excerpt]"]').val(t.excerpt || '');
					$('input[name="upx_l10n[' + lang + '][seo_title]"]').val(t.seo_title || '');
					$('textarea[name="upx_l10n[' + lang + '][seo_desc]"]').val(t.seo_desc || '');
					if (t.body) setEditorContent('upxbody' + lang, t.body);
				});
			}

			genMeta = { kind: curKind, country: curCountry, types: curTypes.slice() };
			$status.attr('class', 'upx-ai-status is-success').text('✓ Text & translations regenerated!');
			setTimeout(function () {
				$status.text('');
				evaluateIgConsistency();
			}, 1200);
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Server error.';
			$status.attr('class', 'upx-ai-status is-error').text('Error: ' + err);
		}).always(function () {
			$btns.prop('disabled', false);
		});
	});

	// Phase 5: "I have checked the text" button
	$('#upx-ig-btn-ack-stale').on('click', function () {
		var curKind = $('#upx-kind').val() || '';
		var curCountry = $('#upx-country').val() || '';
		var curTypes = getSelectedCourtTypes();
		var $status = $('#upx-ig-stale-status');
		var $btns = $('#upx-ig-btn-regenerate, #upx-ig-btn-ack-stale');

		if (!curKind || !curCountry || curTypes.length === 0) {
			$status.attr('class', 'upx-ai-status is-error').text('Please select Type, Country, and at least 1 Court type first.');
			return;
		}

		$btns.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text('Confirming...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ig_ack_stale',
				nonce: getAiNonce(),
				post_id: getPostId(),
				kind: curKind,
				country: curCountry,
				types: curTypes
			}
		}).done(function (res) {
			if (res && res.success) {
				genMeta = { kind: curKind, country: curCountry, types: curTypes.slice() };
				$status.attr('class', 'upx-ai-status').text('');
				evaluateIgConsistency();
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not confirm.';
				$status.attr('class', 'upx-ai-status is-error').text(msg);
			}
		}).always(function () {
			$btns.prop('disabled', false);
		});
	});

	// Intercept Publish click on Instagram references if required selections are missing or content is stale.
	$('#publish').on('click', function (e) {
		if (!isIgPost) return;
		var curKind = $('#upx-kind').val() || '';
		var curCountry = $('#upx-country').val() || '';
		var curTypes = getSelectedCourtTypes();
		var isStale = genMeta ? !isSameSelection({ kind: curKind, country: curCountry, types: curTypes }, genMeta) : false;

		var missing = [];
		if (!curKind) missing.push('• Type (Project or Company news)');
		if (!curCountry) missing.push('• Country');
		if (curTypes.length === 0) missing.push('• At least 1 Court type');
		if (curKind && curCountry && curTypes.length > 0 && isStale) {
			missing.push('• Content is stale (click "Regenerate text" or "I have checked the text")');
		}

		if (missing.length > 0) {
			e.preventDefault();
			window.alert('Cannot publish yet. Please complete the following in "Reference details":\n\n' + missing.join('\n'));
			$('html, body').animate({ scrollTop: $('#upx-ref-details').offset().top - 40 }, 250);
		}
	});

	// Run initial consistency evaluation on page load.
	evaluateIgConsistency();

	/* ==========================================================================
	 * Existing AI Assistant: Quick Draft (Tab 3) & Translate All
	 * ========================================================================== */
	function handleAiDraft(translateAll) {
		var notes = $.trim($('#upx-ai-notes').val());
		var $status = $('#upx-ai-draft-status');
		var $btns = $('#upx-ai-btn-draft, #upx-ai-btn-english-only');

		if (!notes || notes.length < 3) {
			$status.attr('class', 'upx-ai-status is-error').text('Please enter brief notes first.');
			$('#upx-ai-notes').focus();
			return;
		}

		$btns.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text(translateAll ? 'Writing English draft and translating 5 languages...' : 'Writing English draft...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ai_draft',
				nonce: getAiNonce(),
				notes: notes,
				kind: $('#upx-kind').val() || 'project',
				translate: translateAll ? 1 : 0
			}
		}).done(function (res) {
			if (!res || !res.success || !res.data) {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Draft generation failed.';
				$status.attr('class', 'upx-ai-status is-error').text('Error: ' + msg);
				return;
			}

			var d = res.data;
			if (d.title) {
				$('#title').val(d.title).trigger('input');
				$('#title-prompt-text').addClass('screen-reader-text');
			}
			if (d.body) {
				setEditorContent('content', d.body);
			}
			if (d.excerpt) {
				$('#excerpt').val(d.excerpt).trigger('input');
			}
			if (d.seo_title) {
				$('#upx-seo-title').val(d.seo_title).trigger('input');
			}
			if (d.seo_desc) {
				$('#upx-seo-desc').val(d.seo_desc).trigger('input');
			}
			if (d.country) {
				$('#upx-country').val(d.country);
			}
			if (d.types && Array.isArray(d.types)) {
				$('.upx-ref__types input[type="checkbox"]').prop('checked', false);
				d.types.forEach(function (url) {
					$('.upx-ref__types input[value="' + url + '"]').prop('checked', true);
				});
				$('.upx-ref__types input:first').trigger('change');
			}

			if (d.translations && typeof d.translations === 'object') {
				Object.keys(d.translations).forEach(function (lang) {
					var t = d.translations[lang];
					if (!t) return;
					$('input[name="upx_l10n[' + lang + '][title]"]').val(t.title || '');
					$('textarea[name="upx_l10n[' + lang + '][excerpt]"]').val(t.excerpt || '');
					$('input[name="upx_l10n[' + lang + '][seo_title]"]').val(t.seo_title || '');
					$('textarea[name="upx_l10n[' + lang + '][seo_desc]"]').val(t.seo_desc || '');
					if (t.body) {
						setEditorContent('upxbody' + lang, t.body);
					}
				});
			}

			$status.attr('class', 'upx-ai-status is-success').text(translateAll ? '✓ Complete! Draft and 5 translations generated.' : '✓ English draft generated.');
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Server error occurred.';
			$status.attr('class', 'upx-ai-status is-error').text('Error: ' + err);
		}).always(function () {
			$btns.prop('disabled', false);
		});
	}

	$('#upx-ai-btn-draft').on('click', function () {
		handleAiDraft(true);
	});

	$('#upx-ai-btn-english-only').on('click', function () {
		handleAiDraft(false);
	});

	/* ---------- AI Assistant: Translate All ---------- */
	$('#upx-ai-btn-translate-all').on('click', function () {
		var $btn = $(this);
		var $status = $('#upx-ai-translate-status');
		var title = $.trim($('#title').val());
		var excerpt = $.trim($('#excerpt').val());
		var body = $.trim(getEditorContent('content'));
		var overwrite = $('#upx-ai-overwrite').is(':checked');
		var allLangs = (window.upxAiVars && window.upxAiVars.langs) ? window.upxAiVars.langs : ['tr', 'de', 'fr', 'ar', 'ru'];

		if (!title || !body) {
			$status.attr('class', 'upx-ai-status is-error').text('Please enter English title and text first.');
			return;
		}

		var targetLangs = [];
		allLangs.forEach(function (l) {
			var hasTitle = $.trim($('input[name="upx_l10n[' + l + '][title]"]').val()) !== '';
			var hasBody = $.trim(getEditorContent('upxbody' + l)) !== '';
			if (overwrite || !hasTitle || !hasBody) {
				targetLangs.push(l);
			}
		});

		if (targetLangs.length === 0) {
			$status.attr('class', 'upx-ai-status is-success').text('All languages already filled. Check "Overwrite" to re-translate.');
			return;
		}

		$btn.prop('disabled', true);
		$status.attr('class', 'upx-ai-status is-loading').text('Translating to ' + targetLangs.map(function (s) { return s.toUpperCase(); }).join(', ') + '...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ai_translate',
				nonce: getAiNonce(),
				title: title,
				excerpt: excerpt,
				body: body,
				langs: targetLangs
			}
		}).done(function (res) {
			if (!res || !res.success || !res.data || !res.data.translations) {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Translation failed.';
				$status.attr('class', 'upx-ai-status is-error').text('Error: ' + msg);
				return;
			}

			var trans = res.data.translations;
			targetLangs.forEach(function (lang) {
				var t = trans[lang];
				if (!t) return;
				$('input[name="upx_l10n[' + lang + '][title]"]').val(t.title || '');
				$('textarea[name="upx_l10n[' + lang + '][excerpt]"]').val(t.excerpt || '');
				$('input[name="upx_l10n[' + lang + '][seo_title]"]').val(t.seo_title || '');
				$('textarea[name="upx_l10n[' + lang + '][seo_desc]"]').val(t.seo_desc || '');
				if (t.body) {
					setEditorContent('upxbody' + lang, t.body);
				}
			});

			$status.attr('class', 'upx-ai-status is-success').text('✓ Translated ' + targetLangs.length + ' language(s) successfully!');
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Server error occurred.';
			$status.attr('class', 'upx-ai-status is-error').text('Error: ' + err);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* ---------- Settings Page: Test Gemini Connection ---------- */
	$('#upx-test-gemini').on('click', function () {
		var $btn = $(this);
		var $result = $('#upx-test-gemini-result');
		var key = $.trim($('#upx-gemini-key').val());
		var model = $('#upx-gemini-model').val();

		$btn.prop('disabled', true);
		$result.css({ color: '#50575e' }).text('Connecting to Google Gemini...');

		$.ajax({
			url: getAjaxUrl(),
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'upx_ai_test',
				nonce: $btn.data('nonce'),
				key: key,
				model: model
			}
		}).done(function (res) {
			if (res && res.success) {
				$result.css({ color: '#007017', fontWeight: '600' }).text(res.data.message);
			} else {
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Connection test failed.';
				$result.css({ color: '#d63638', fontWeight: '600' }).text('Error: ' + msg);
			}
		}).fail(function (xhr) {
			var err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Connection failed.';
			$result.css({ color: '#d63638', fontWeight: '600' }).text('Error: ' + err);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

})(jQuery);
