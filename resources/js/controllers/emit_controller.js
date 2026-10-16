import { Controller } from '@hotwired/stimulus';

// Кнопка, которая шлёт событие на window: другой контроллер его ловит
// (deal:open открывает блок цены, chat:open — чат, letters:open — окно писем с url в detail, vendor-pick:open — шторка
// вендора письма).
// С wide — только от этой ширины: уже неё ссылка ведёт куда написано (чат со страницы ТС на телефоне — экраном, не шторкой).
export default class extends Controller {
    send(event) {
        if (event.params.wide && !matchMedia(`(min-width: ${event.params.wide}px)`).matches) return;
        if (event.target.closest('a, button, form, input, label') && event.target.closest('a, button, form, input, label') !== this.element && !this.element.matches('a, button')) return;
        event.preventDefault();
        // Остальные data-emit-*-param — тоже в detail («Выбрать вендора» несёт отправителя, название и домен).
        const { event: name, wide, ...rest } = event.params;
        window.dispatchEvent(new CustomEvent(name, { detail: { ...rest, url: rest.url || null } }));
    }
}
