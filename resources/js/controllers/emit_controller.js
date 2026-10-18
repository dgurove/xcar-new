import { Controller } from '@hotwired/stimulus';
import { closeSheet } from '../sheet';

// Кнопка, которая шлёт событие на window: другой контроллер его ловит
// (deal:open открывает блок цены, chat:open — чат, letters:open — окно писем с url в detail, vendor-pick:open — шторка
// вендора письма).
// С wide — только от этой ширины: уже неё ссылка ведёт куда написано (чат со страницы ТС на телефоне — экраном, не шторкой).
// Кнопка в шторке с «sheet#close» («···» редактора → «Отдать…», «Срочно…», «Вернуть в продажу»): сначала закрывается её
// шторка, потом событие — иначе закрытие снимало из истории (overlays.js) и запись только что открытой шторки, и она
// тут же закрывалась.
export default class extends Controller {
    async send(event) {
        if (event.params.wide && !matchMedia(`(min-width: ${event.params.wide}px)`).matches) return;
        if (event.target.closest('a, button, form, input, label') && event.target.closest('a, button, form, input, label') !== this.element && !this.element.matches('a, button')) return;
        event.preventDefault();
        // Остальные data-emit-*-param — тоже в detail («Выбрать вендора» несёт отправителя, название и домен).
        const { event: name, wide, ...rest } = event.params;
        const sheet = (this.element.dataset.action || '').includes('sheet#close') ? this.element.closest('dialog[open]') : null;
        if (sheet) {
            event.stopImmediatePropagation();
            await closeSheet(sheet);
        }
        window.dispatchEvent(new CustomEvent(name, { detail: { ...rest, url: rest.url || null } }));
    }
}
