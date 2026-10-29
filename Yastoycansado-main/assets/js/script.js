// JavaScript de interaccion y efectos visuales de la pagina principal.
const menuButton = document.querySelector('#menu-button');
const mainMenu = document.querySelector('#main-menu');
const header = document.querySelector('.site-header');

if (menuButton && mainMenu) {
  menuButton.addEventListener('click', () => {
    mainMenu.classList.toggle('is-open');
  });

  mainMenu.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => mainMenu.classList.remove('is-open'));
  });
}

const revealItems = document.querySelectorAll('.reveal');
if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.18 });

  revealItems.forEach((item) => observer.observe(item));
} else {
  revealItems.forEach((item) => item.classList.add('is-visible'));
}

const heroVisual = document.querySelector('.hero-visual');
if (heroVisual && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
  heroVisual.addEventListener('pointermove', (event) => {
    const rect = heroVisual.getBoundingClientRect();
    const x = (event.clientX - rect.left) / rect.width;
    const y = (event.clientY - rect.top) / rect.height;

    heroVisual.style.transform = `rotateX(${(0.5 - y) * 6}deg) rotateY(${(x - 0.5) * 8}deg)`;
  });

  heroVisual.addEventListener('pointerleave', () => {
    heroVisual.style.transform = 'rotateX(0deg) rotateY(0deg)';
  });
}

window.addEventListener('scroll', () => {
  if (!header) return;
  header.classList.toggle('scrolled', window.scrollY > 12);
});
