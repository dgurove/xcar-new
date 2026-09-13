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
        const x = r0.left + r0.width / 2, y = r0.top + r0.height / 2;
        const r = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
        // Центр и радиус — переменными на <html>: их читает keyframe theme-circle в CSS
        // (animate() с pseudoElement в Safari ненадёжен — получался обычный кроссфейд).
        const html = document.documentElement;
        html.style.setProperty('--theme-x', `${x}px`);
        html.style.setProperty('--theme-y', `${y}px`);
        html.style.setProperty('--theme-r', `${r}px`);
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
