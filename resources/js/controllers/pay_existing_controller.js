import { Controller } from '@hotwired/stimulus';

// «Ссылка уже есть» в форме ручной ссылки на оплату (09.10.2026, владелец: «писал бы, что ссылка уже есть, и перекидывал
// туда»): выбрали ТС или вписали плательщика — сервер ищет открытую ссылку по этой машине или этому человеку
// (`MoneyController::existing`). Нашлась — строка с «Открыть ссылку» над кнопкой, «Получить ссылку» прячется: вторую не
// берут, идут к первой. Выбрали ТС со сделкой — плательщик подставляется её менеджером, если поле ещё пустое.
export default class extends Controller {
    static targets = ['box', 'submit'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
    }

    changed(event) {
        if (!event.target.matches('[name="offer_id"], [name="party_id"], [name="party_name"]')) return;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), event.type === 'change' ? 0 : 400);
    }

    chosen(event) {
        const { party_id: party, payer } = event.detail ?? {};
        const select = this.element.querySelector('[name="party_id"]');
        const name = this.element.querySelector('[name="party_name"]');
        if (!select || select.value !== 'new' || name?.value.trim()) return;
        if (party && [...select.options].some((o) => o.value === String(party))) {
            select.value = String(party);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        } else if (payer && name) {
            name.value = payer;
            name.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    async check() {
        const url = new URL(this.urlValue, location.href);
        for (const field of ['offer_id', 'party_id', 'party_name']) {
            const value = this.element.querySelector(`[name="${field}"]`)?.value.trim();
            if (value && !(field === 'party_id' && value === 'new')) url.searchParams.set(field, value);
        }
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
