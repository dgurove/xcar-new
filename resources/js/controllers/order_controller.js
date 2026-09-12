import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

// Список, который переставляют за ручку: после перестановки порядок id уходит на сервер.
export default class extends Controller {
    static values = { url: String };

    connect() {
        this.sortable = Sortable.create(this.element, { animation: 150, handle: '[data-handle]', delay: 150, delayOnTouchOnly: true, onEnd: () => this.save() });
    }

    disconnect() { this.sortable?.destroy(); }

    async save() {
        const order = [...this.element.querySelectorAll('[data-id]')].map((el) => el.dataset.id);
        const r = await fetch(this.urlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ order }),
        });
        if (!r.ok) window.toast?.('Порядок не сохранился', 'danger');
    }
}
