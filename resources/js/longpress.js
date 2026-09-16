import { openSheet, closeSheet } from './sheet';

// Долгое нажатие по карточке — своё меню шторкой вместо листа Safari или меню
// Chrome: открыть, в избранное, поделиться, чат. Пункты берутся из самой
// карточки (ссылка, форма закладки, кнопка чата) — разметка не меняется.
// iOS не шлёт contextmenu на долгое касание, поэтому таймер; Android — contextmenu.
export function longPressMenu() {
    let timer = null, start = null, fired = false;

    const cancel = () => { clearTimeout(timer); timer = null; };
    document.addEventListener('touchstart', (e) => {
        const card = e.target.closest('.card');
        if (!card || e.touches.length !== 1) return;
        const t = e.touches[0];
        start = { x: t.clientX, y: t.clientY };
        fired = false;
        timer = setTimeout(() => { fired = true; open(card); }, 480);
    }, { passive: true });
    document.addEventListener('touchmove', (e) => {
        if (!timer) return;
        const t = e.touches[0];
        if (Math.hypot(t.clientX - start.x, t.clientY - start.y) > 8) cancel();
    }, { passive: true });
    document.addEventListener('touchend', () => { cancel(); setTimeout(() => { fired = false; }, 700); }, { passive: true });
    document.addEventListener('touchcancel', cancel, { passive: true });
    addEventListener('scroll', cancel, { passive: true });
    // Тап после сработавшего долгого нажатия — не переход.
    document.addEventListener('click', (e) => { if (fired && e.target.closest('.card')) { e.preventDefault(); e.stopPropagation(); fired = false; } }, true);
    document.addEventListener('contextmenu', (e) => {
        const card = e.target.closest('.card');
        if (!card || matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        e.preventDefault();
        open(card);
    });
}

function open(card) {
    const link = card.querySelector('.card-title a, a.card-media, .card-strip');
    const favorite = card.querySelector('form[action$="/favorites"] button');
    const chat = card.querySelector('a[aria-label="Написать в чат"]');
    const select = card.querySelector('.card-check');
    const title = card.querySelector('.card-title')?.textContent.trim() || '';
    if (!link) return;
    document.getElementById('card-menu')?.remove();
    const d = document.createElement('dialog');
    d.id = 'card-menu';
    d.className = 'sheet';
    d.dataset.turboTemporary = '';
    const items = [
        ['Открыть', () => window.Turbo.visit(link.href)],
        favorite && [favorite.classList.contains('is-on') ? 'Убрать из избранного' : 'В избранное', () => favorite.click()],
        navigator.share && [
            'Поделиться',
            () => navigator.share({ title, url: new URL(link.href, location.href).href }).catch(() => {}),
        ],
        chat && ['Написать в чат', () => window.Turbo.visit(chat.href)],
        select && ['Выбрать несколько', () => { window.dispatchEvent(new CustomEvent('selection:start', { detail: { card } })); }],
    ].filter(Boolean);
    d.innerHTML = `<h2 class="mb-4 text-lg"></h2><div class="flex flex-col gap-2"></div>`;
    d.querySelector('h2').textContent = title;
    const list = d.querySelector('div');
    for (const [label, run] of items) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-quiet btn-block';
        b.textContent = label;
        b.addEventListener('click', () => closeSheet(d).then(run));
        list.append(b);
    }
    // Палец ещё на экране: клик от его отпускания приходит уже в шторку — не закрывать.
    const openedAt = Date.now();
    d.addEventListener('click', (e) => { if (e.target === d && Date.now() - openedAt > 600) closeSheet(d); });
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    openSheet(d, { history: false });
}
