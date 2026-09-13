import { Controller } from '@hotwired/stimulus';

// Тема расходится кругом от кнопки (View Transitions), где их нет — щелчком.
export default class extends Controller {
    toggle(event) {
        const dark = !document.documentElement.classList.contains('dark');
        const apply = () => {
            document.documentElement.classList.toggle('dark', dark);
            try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch {}
            document.cookie = `theme=${dark ? 'dark' : 'light'}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}${cookieDomain()}`;
            document.querySelector('meta[name="theme-color"]').content = dark ? '#121212' : '#ffffff';
        };
        if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) return apply();
        // Круг идёт из самой кнопки, а не из точки события: тап, клавиатура и .click() — одинаково.
        const r0 = this.element.getBoundingClientRect();
        // Центр — в процентах от окна: при масштабе страницы в Chrome пиксели clip-path у
        // псевдоэлемента перехода не совпадают с CSS-пикселями кнопки, и круг съезжал к
        // середине; проценты считаются от бокса самого псевдоэлемента. Радиус 145 % —
        // процент у circle() берётся от √(w²+h²)/√2, из угла до угла нужно √2.
        const x = ((r0.left + r0.width / 2) / innerWidth * 100).toFixed(2);
        const y = ((r0.top + r0.height / 2) / innerHeight * 100).toFixed(2);
        // Keyframes с литеральными числами: псевдоэлементы перехода в Safari не
        // наследуют custom properties с <html>, а animate() с pseudoElement там же
        // даёт обычный кроссфейд. Правило animation — в app.css (html[data-theme-switch]).
        const html = document.documentElement;
        let style = document.getElementById('theme-circle');
        if (!style) { style = document.createElement('style'); style.id = 'theme-circle'; document.head.append(style); }
        style.textContent = `@keyframes theme-circle { from { clip-path: circle(0 at ${x}% ${y}%); } to { clip-path: circle(145% at ${x}% ${y}%); } }`;
        html.dataset.themeSwitch = '1';
        const t = document.startViewTransition(apply);
        t.finished.finally(() => { delete html.dataset.themeSwitch; });
    }
}

// Одна тема на витрину, CRM и стоянку: cookie на общий домен (.xcar.ru / .xcar.localhost).
function cookieDomain() {
    const parts = location.hostname.split('.');
    return parts.length > 2 ? `; domain=.${parts.slice(-2).join('.')}` : '';
}
