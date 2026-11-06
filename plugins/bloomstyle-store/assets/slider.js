( function () {
	var root = document.querySelector( '[data-bs-slider]' );
	if ( ! root ) {
		return;
	}
	var track = root.querySelector( '.bs-slider__track' );
	var slides = Array.prototype.slice.call( track.children );
	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var index = 0;
	var timer = null;

	function go( next ) {
		index = ( next + slides.length ) % slides.length;
		track.scrollTo( { left: slides[ index ].offsetLeft, behavior: calm ? 'auto' : 'smooth' } );
		mark();
	}

	function button( className, label, onClick ) {
		var el = document.createElement( 'button' );
		el.type = 'button';
		el.className = className;
		el.setAttribute( 'aria-label', label );
		el.addEventListener( 'click', onClick );
		return el;
	}

	var dots = document.createElement( 'div' );
	dots.className = 'bs-slider__dots';
	var dotButtons = slides.map( function ( slide, i ) {
		var dot = button( 'bs-slider__dot', root.dataset.slide + ' ' + ( i + 1 ), function () {
			go( i );
		} );
		dots.appendChild( dot );
		return dot;
	} );
	root.appendChild( button( 'bs-slider__arrow bs-slider__arrow--prev', root.dataset.prev, function () {
		go( index - 1 );
	} ) );
	root.appendChild( button( 'bs-slider__arrow bs-slider__arrow--next', root.dataset.next, function () {
		go( index + 1 );
	} ) );
	root.appendChild( dots );

	function mark() {
		dotButtons.forEach( function ( dot, i ) {
			dot.setAttribute( 'aria-current', i === index ? 'true' : 'false' );
		} );
	}
	mark();

	var settle = null;
	track.addEventListener( 'scroll', function () {
		clearTimeout( settle );
		settle = setTimeout( function () {
			index = Math.round( track.scrollLeft / track.clientWidth );
			mark();
		}, 80 );
	} );

	function stop() {
		clearInterval( timer );
		timer = null;
	}
	function start() {
		if ( ! calm && ! timer ) {
			timer = setInterval( function () {
				go( index + 1 );
			}, 6000 );
		}
	}
	root.addEventListener( 'mouseenter', stop );
	root.addEventListener( 'mouseleave', start );
	root.addEventListener( 'focusin', stop );
	root.addEventListener( 'focusout', start );
	root.addEventListener( 'touchstart', stop, { passive: true } );
	start();
}() );
