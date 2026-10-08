(function () {
  const hero = document.querySelector('.landing-hero');
  const scene = document.querySelector('.hero-scene');
  const cards = document.querySelectorAll('.feature-card');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (!hero || !scene) return;

  const applyCardTilt = function (card, event) {
    if (reducedMotion || event.pointerType === 'touch') return;
    const bounds = card.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width - 0.5;
    const y = (event.clientY - bounds.top) / bounds.height - 0.5;
    card.classList.add('is-tilting');
    card.style.transform = 'translateY(0) rotateY(' + (x * 7).toFixed(2) + 'deg) rotateX(' + (y * -5).toFixed(2) + 'deg)';
  };

  cards.forEach(function (card) {
    card.addEventListener('pointermove', function (event) { applyCardTilt(card, event); });
    card.addEventListener('pointerleave', function () {
      card.classList.remove('is-tilting');
      card.style.transform = '';
    });
  });

  if (reducedMotion) {
    cards.forEach(function (card) { card.classList.add('is-visible'); });
    return;
  }

  const revealCards = new IntersectionObserver(function (entries, observer) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.2 });

  cards.forEach(function (card) { revealCards.observe(card); });

  hero.addEventListener('pointermove', function (event) {
    const bounds = hero.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width - 0.5;
    const y = (event.clientY - bounds.top) / bounds.height - 0.5;
    scene.style.transform = 'rotateY(' + (x * 7).toFixed(2) + 'deg) rotateX(' + (y * -5).toFixed(2) + 'deg)';
  });

  hero.addEventListener('pointerleave', function () {
    scene.style.transform = '';
  });

  hero.addEventListener('pointerdown', function (event) {
    if (event.pointerType === 'mouse') return;
    hero.setPointerCapture(event.pointerId);
  });
}());