jQuery(function ($) {
	$('.bloomstyle-image').each(function () {
		var cell = $(this);
		var input = cell.find('input[type=hidden]');
		var preview = cell.find('.bloomstyle-image__preview');

		cell.find('.bloomstyle-image__choose').on('click', function () {
			var frame = wp.media({ title: 'Choose image', library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var image = frame.state().get('selection').first().toJSON();
				var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
				input.val(image.id);
				preview.empty().append($('<img>', { src: size.url, alt: '', css: { maxWidth: '300px', height: 'auto' } }));
			});
			frame.open();
		});

		cell.find('.bloomstyle-image__remove').on('click', function () {
			input.val('0');
			preview.empty();
		});
	});
});
