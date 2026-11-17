import { Controller } from '@hotwired/stimulus';

// Разделы крупными заголовками (`x-ui.big-tabs`, 17.11.2026; прежде — заголовки «Работы», 16.11.2026): нажатое слово становится
// текущим сразу, до ответа сервера, — переключение ощущается мгновенным. Сам переход — обычная ссылка Turbo.
export default class extends Controller {
    pick(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        this.element.querySelectorAll('.big-tab').forEach((a) => {
            if (a === event.currentTarget) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
    }
}
