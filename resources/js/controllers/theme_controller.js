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
        const x = event?.clientX || innerWidth / 2, y = event?.clientY || 0;
        const r = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
        const html = document.documentElement;
        html.dataset.themeSwitch = '1';
        const t = document.startViewTransition(apply);
        t.ready.then(() => html.animate(
            { clipPath: [`circle(0 at ${x}px ${y}px)`, `circle(${r}px at ${x}px ${y}px)`] },
            { duration: 380, easing: 'ease-in', pseudoElement: '::view-transition-new(root)' },
        ));
        t.finished.finally(() => delete html.dataset.themeSwitch);
    }
}

// Одна тема на витрину, CRM и стоянку: cookie на общий домен (.xcar.ru / .xcar.localhost).
function cookieDomain() {
    const parts = location.hostname.split('.');
    return parts.length > 2 ? `; domain=.${parts.slice(-2).join('.')}` : '';
}
