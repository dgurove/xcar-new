import { Controller } from '@hotwired/stimulus';

// «Входящие» на телефоне (17.11.2026, владелец: «по нажатию открывать как обычно у нас — окошко с цепочкой писем, как из
// сделки, оно хорошо сделано»): строка открывает окно писем дела на этом письме (`x-mail.window`, событие `letters:open`),
// а не лист справа. Нажатие не доходит до `detail#tap` и Turbo. От 1024 — письмо справа, как было.
const phone = matchMedia('(max-width: 1023.98px)');

export default class extends Controller {
    connect() {
        this.onClick = (e) => {
            if (!phone.matches || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey) return;
            const row = e.target.closest('.inbox-row[data-letters]');
            if (!row || !this.element.contains(row)) return;
            e.preventDefault();
            e.stopPropagation();
            window.dispatchEvent(new CustomEvent('letters:open', { detail: { url: row.dataset.letters } }));
        };
        this.element.addEventListener('click', this.onClick);
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
    }
}
