import { Controller } from '@hotwired/stimulus';

// «Ссылка уже есть» в форме ручной ссылки на оплату (09.10.2026, владелец: «писал бы, что ссылка уже есть, и перекидывал
// туда»): выбрали ТС — сервер ищет открытую ссылку по этой машине (`MoneyController::existing`). Нашлась — строка с
// «Открыть ссылку» над кнопкой, «Получить ссылку» прячется: вторую не берут, идут к первой. Выбрали ТС со сделкой — менеджер
// ссылки встаёт менеджером сделки (22.11.2026: плательщика форма не спрашивает; так же в «Назначить менеджера» — без `url`).
export default class extends Controller {
    static targets = ['box', 'submit'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
    }

    changed(event) {
        if (!this.hasUrlValue || !event.target.matches('[name="offer_id"]')) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), event.type === 'change' ? 0 : 400);
    }

    chosen(event) {
        const manager = event.detail?.manager_id;
        const select = this.element.querySelector('select[name="manager_id"]');
        if (!select || !manager || ![...select.options].some((o) => o.value === String(manager))) return;
        select.value = String(manager);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async check() {
        const url = new URL(this.urlValue, location.href);
        const offer = this.element.querySelector('[name="offer_id"]')?.value.trim();
        if (offer) url.searchParams.set('offer_id', offer);
        if (url.href === this.last) return;
        this.last = url.href;
        this.abort?.abort();
        this.abort = new AbortController();
        try {
            const res = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'text/html' } });
            if (!res.ok) return;
            this.boxTarget.innerHTML = await res.text();
            if (this.hasSubmitTarget) this.submitTarget.hidden = this.boxTarget.childElementCount > 0;
        } catch {}
    }
}
