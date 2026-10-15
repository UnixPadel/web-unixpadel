/* Reference editor (WP admin): gallery picker, court type limit, character counters. Loaded by includes/refs-admin.php. */
(function ($) {
	'use strict';

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
			var n = $f.val().length;
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
})(jQuery);
