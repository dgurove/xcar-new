// Тема и цвет строки состояния — одним местом. Цвет полосы знает только мета theme-color (data-light / data-dark,
// их ставит layout); строка под часами того же цвета, что шапка (--color-bar).
export function paintBar() {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    meta.content = document.documentElement.classList.contains('dark') ? meta.dataset.dark : meta.dataset.light;
}

// Выбор руками — cookie на общий домен: одна тема на витрину, CRM, парковку и логистику, и сервер рисует её сразу.
export function setTheme(dark, remember = true) {
    document.documentElement.classList.toggle('dark', dark);
    if (remember) document.cookie = `theme=${dark ? 'dark' : 'light'}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}${cookieDomain()}`;
    paintBar();
}

export const chosenTheme = () => document.cookie.match(/(?:^|; )theme=(dark|light)/)?.[1] ?? null;

function cookieDomain() {
    const parts = location.hostname.split('.');
    return parts.length > 2 ? `; domain=.${parts.slice(-2).join('.')}` : '';
}
