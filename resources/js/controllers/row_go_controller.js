import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

// Таблица, где строка ведёт на страницу, а не в карточку рядом (гараж на сайте): нажатие мимо ссылки по строке — переход
// по ссылке названия (`a.row-link`). Ссылки, кнопки и копирование в строке работают сами.
export default class extends Controller {
    go(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        if (event.target.closest('a, button, input, label, [data-controller~="copy"]')) return;
        if (window.getSelection()?.toString()) return;
        const link = event.target.closest('tr')?.querySelector('a.row-link[href]');
        if (link) Turbo.visit(link.href);
    }
}
