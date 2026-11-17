// Тема и цвет строки состояния — одним местом. Строка под часами того же цвета, что шапка: мета theme-color берёт
// --color-bar из app.css (сервер ставит тот же цвет до первой отрисовки — $bar в x-ui.layout, сверка в blade-lint).
// Цвета обеих тем сервер кладёт в саму мету (`data-light`, `data-dark`): чтение `--color-bar` из стилей на каждой
// отрисовке было первым принудительным пересчётом стилей новой страницы — 90–120 мс на телефоне (18.11.2026).
export function paintBar() {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const color = (document.documentElement.classList.contains('dark') ? meta.dataset.dark : meta.dataset.light)
        || getComputedStyle(document.documentElement).getPropertyValue('--color-bar').trim();
    if (color && meta.content !== color) meta.content = color;
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
