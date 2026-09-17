import { Controller } from '@hotwired/stimulus';

// Кнопка, которая шлёт событие на window: другой контроллер его ловит
// (deal:open открывает блок цены, chat:open — чат). С wide — только от этой ширины:
// уже неё ссылка ведёт куда написано (чат со страницы ТС на телефоне — экраном, не шторкой).
export default class extends Controller {
    send(event) {
        if (event.params.wide && !matchMedia(`(min-width: ${event.params.wide}px)`).matches) return;
        event.preventDefault();
        window.dispatchEvent(new CustomEvent(event.params.event));
    }
}
