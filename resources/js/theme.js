// Тема и цвет строки состояния — одним местом. Строка под часами того же цвета, что шапка: мета theme-color берёт
// --color-bar из app.css (сервер ставит тот же цвет до первой отрисовки — $bar в x-ui.layout, сверка в blade-lint).
export function paintBar() {
    const meta = document.querySelector('meta[name="theme-color"]');
    const color = getComputedStyle(document.documentElement).getPropertyValue('--color-bar').trim();
    if (meta && color) meta.content = color;
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
