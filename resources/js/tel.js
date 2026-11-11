import { openSheet, closeSheet, outside } from './sheet';
import { copyText } from './lib/clipboard';

// Номер телефона — по нажатию окошко «Скопировать / Позвонить» (11.11.2026, владелец: «по нажатию на номер хотят
// скопировать, а не позвонить»; «кнопки „Позвонить“ везде убрать»). Одна дверь на все хосты: любая ссылка `tel:` —
// `x-ui.tel`, номера в чатах и письмах (`Support\Linkify`), что угодно ещё. «Позвонить» внутри — настоящая ссылка с
// data-tel-direct, её не перехватываем. Скопировать — номер как показан («+7 900 555-44-33»).
export function telSheet() {
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest?.('a[href^="tel:"]');
        if (!a || a.hasAttribute('data-tel-direct')) return;
        e.preventDefault();
        e.stopPropagation();
        open(a);
    }, true);
}

function open(a) {
    const href = a.getAttribute('href');
    const shown = a.textContent.replace(/\s+/g, ' ').trim();
    // Свой вид номера у ссылки с подписью (пилюля «Роман К. +7 900 …») — data-tel; иначе текст ссылки, если это номер.
    const number = a.dataset.tel || (/\d{5,}/.test(shown.replace(/\D/g, '')) && shown.length <= 24 ? shown : href.slice(4));
    document.getElementById('tel-sheet')?.remove();
    const d = document.createElement('dialog');
    d.id = 'tel-sheet';
    d.className = 'sheet';
    d.dataset.turboTemporary = '';
    d.innerHTML = `
        <h2 class="mb-5 text-lg nums"></h2>
        <div class="flex flex-col gap-2">
            <button type="button" class="btn btn-accent btn-block" data-copy>Скопировать</button>
            <a class="btn btn-quiet btn-block" data-tel-direct>Позвонить</a>
            <button type="button" class="btn btn-ghost btn-block" data-cancel>Отмена</button>
        </div>`;
    d.querySelector('h2').textContent = number;
    d.querySelector('[data-tel-direct]').setAttribute('href', href);
    d.querySelector('[data-copy]').addEventListener('click', async () => {
        await copyText(number, 'Номер скопирован');
        closeSheet(d);
    });
    d.querySelector('[data-tel-direct]').addEventListener('click', () => setTimeout(() => closeSheet(d), 50));
    d.querySelector('[data-cancel]').addEventListener('click', () => closeSheet(d));
    d.addEventListener('click', (e) => { if (outside(d, e)) closeSheet(d); });
    d.addEventListener('cancel', (e) => { e.preventDefault(); closeSheet(d); });
    d.addEventListener('close', () => d.remove());
    document.body.append(d);
    openSheet(d, { history: false });
}
