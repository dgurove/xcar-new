import { Controller } from '@hotwired/stimulus';
import { closeSheet } from '../sheet';
import { request } from '../lib/http';

// Шторки действий окна машины приезжают по первому требованию (`data-lazy-sheets`, `OfferController::windowSheets`): в окне
// их до десяти, а открывают редко. Слушателя события на странице ещё нет — дозагружаем шторки на их место и ждём, пока
// Stimulus подключит контроллеры; потом событие идёт как обычно.
async function ensureSheets(from, name) {
    if (document.querySelector(`[data-action*="${name.replace(/"/g, '')}@window"]`)) return;
    // Место, что называет свои события (`data-lazy-sheets-for`: шторка вендора письма), — по имени; иначе — место в окне,
    // откуда нажали (шторки действий машины: их события с номером, заранее не назвать).
    const named = [...document.querySelectorAll('[data-lazy-sheets][data-lazy-sheets-for]')].find((h) => h.dataset.lazySheetsFor.split(' ').includes(name));
    const holder = named ?? (from.closest('turbo-frame') ?? document).querySelector('[data-lazy-sheets]:not([data-lazy-sheets-for])');
    const url = holder?.dataset.lazySheets;
    if (!url) return;
    holder.removeAttribute('data-lazy-sheets');
    const response = await request(url, { accept: 'text/html' });
    if (!response) { holder.dataset.lazySheets = url; return; }
    holder.innerHTML = await response.text();
    await new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)));
}

export default class extends Controller {
    async send(event) {
        if (event.params.wide && !matchMedia(`(min-width: ${event.params.wide}px)`).matches) return;
        if (event.target.closest('a, button, form, input, label') && event.target.closest('a, button, form, input, label') !== this.element && !this.element.matches('a, button')) return;
        event.preventDefault();
        // Остальные data-emit-*-param — тоже в detail («Выбрать вендора» несёт отправителя, название и домен).
        const { event: name, wide, ...rest } = event.params;
        // Пункт шторки действий «⋯» (`x-ui.more`, `.sheet-actions-list`) — так же (12.11.2026, начальник: «Отдать на оценку»
        // перестал работать): `sheet#item` закрывал «⋯» после события, и закрытие снимало запись только что открытой шторки.
        // Закрывается своя шторка «⋯», а не окно вокруг неё: на ПК она поповер (`showPopover`, без атрибута open), и
        // `closest('dialog[open]')` находил окно писем, в котором стоит «⋯», — его и закрывал.
        const actions = this.element.closest('.sheet-actions-list')?.closest('dialog');
        const sheet = actions ?? ((this.element.dataset.action || '').includes('sheet#close') ? this.element.closest('dialog[open]') : null);
        if (sheet?.matches(':popover-open')) {
            event.stopImmediatePropagation();
            sheet.hidePopover();
        } else if (sheet?.open) {
            event.stopImmediatePropagation();
            await closeSheet(sheet);
        }
        await ensureSheets(this.element, name);
        window.dispatchEvent(new CustomEvent(name, { detail: { ...rest, url: rest.url || null } }));
    }
}
