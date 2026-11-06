// Filters stay open on desktop and start collapsed on phones.
if (window.matchMedia('(min-width: 922px)').matches) {
	document.querySelectorAll('.bs-filters').forEach(function (panel) {
		panel.open = true;
	});
}
