/**
 * DW Wholesale — Carousel (vanilla JS, no dependencies)
 */
(function () {
	'use strict';

	function initCarousel(el) {
		var viewport = el.querySelector('.dwcat-carousel-viewport');
		var track = el.querySelector('.dwcat-carousel-track');
		var prev = el.querySelector('.dwcat-prev');
		var next = el.querySelector('.dwcat-next');
		if (!viewport || !track || !prev || !next) return;

		var autoplay = el.dataset.autoplay === '1';
		var interval = parseInt(el.dataset.interval, 10) || 5000;
		var perSlide = parseInt(el.dataset.perSlide, 10) || 3;
		var slides = track.children;
		var total = slides.length;
		var index = 0;
		var timer = null;

		function getSlideWidth() {
			var slide = slides[0];
			if (!slide) return 0;
			var style = window.getComputedStyle(slide);
			var gap = parseInt(window.getComputedStyle(track).gap, 10) || 0;
			return slide.offsetWidth + gap;
		}

		function maxIndex() {
			return Math.max(0, total - perSlide);
		}

		function update() {
			var offset = -index * getSlideWidth();
			track.style.transform = 'translateX(' + offset + 'px)';
			prev.disabled = index <= 0;
			next.disabled = index >= maxIndex();
		}

		function goPrev() {
			index = index > 0 ? index - 1 : maxIndex();
			update();
		}

		function goNext() {
			index = index < maxIndex() ? index + 1 : 0;
			update();
		}

		function startAuto() {
			if (!autoplay || total <= perSlide) return;
			stopAuto();
			timer = setInterval(goNext, interval);
		}

		function stopAuto() {
			if (timer) {
				clearInterval(timer);
				timer = null;
			}
		}

		prev.addEventListener('click', function () {
			goPrev();
			startAuto();
		});
		next.addEventListener('click', function () {
			goNext();
			startAuto();
		});

		el.addEventListener('mouseenter', stopAuto);
		el.addEventListener('mouseleave', startAuto);

		window.addEventListener('resize', update);

		update();
		startAuto();
	}

	function initAll() {
		var carousels = document.querySelectorAll('.dwcat-carousel');
		carousels.forEach(initCarousel);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
