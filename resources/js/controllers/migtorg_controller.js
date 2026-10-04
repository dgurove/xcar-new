import { Controller } from '@hotwired/stimulus';

// Мигторг в поле номера убытка (x-offer.migtorg-field): вписали номер — сразу кружок, через полсекунды сервер отвечает
// значком по этому номеру (`/offers/{n}/migtorg?ref=`): лот нашёлся — горит знак Мигторга. Нажали знак — «Это она»:
// номер из поля сохраняется вместе с лотом, данные и фото начинают заполняться (`/offers/{n}/media/migtorg`, ответ —
// страница или карточка строки с тостом). Пока качается — значок переспрашивается раз в три секунды.
export default class extends Controller {
    static targets = ['wait'];
    static values = { url: String, take: String, running: Boolean };

    connect() {
        this.field = this.element.closest('.vin-box')?.querySelector('input');
        this.onInput = () => {
            clearTimeout(this.timer);
            this.waiting(this.field.value.trim() !== '');
            this.timer = setTimeout(() => this.check(), 500);
        };
        this.field?.addEventListener('input', this.onInput);
        this.last = this.field?.value.trim();
        if (this.runningValue) this.poll = setTimeout(() => this.check(true), 3000);
    }

    disconnect() {
        clearTimeout(this.timer);
        clearTimeout(this.poll);
        this.abort?.abort();
        this.field?.removeEventListener('input', this.onInput);
    }

    // Кружок поиска — вместо значка, пока ответ не пришёл.
    waiting(on) {
        this.element.querySelectorAll(':scope > :not([data-migtorg-target])').forEach((el) => { el.hidden = on; });
        this.waitTarget.hidden = !on;
    }

    async check(force = false) {
        const ref = this.field?.value.trim() ?? '';
        if (!force && ref === this.last) { this.waiting(false); return; }
        this.last = ref;
        if (ref === '') { this.waiting(false); this.element.querySelectorAll(':scope > :not([data-migtorg-target])').forEach((el) => el.remove()); return; }
        this.abort?.abort();
        this.abort = new AbortController();
        try {
            const res = await fetch(`${this.urlValue}?ref=${encodeURIComponent(ref)}`, { signal: this.abort.signal, headers: { Accept: 'text/html' } });
            if (res.ok) this.element.outerHTML = await res.text();
        } catch {
            this.waiting(false);
        }
    }

    // Знак нажали: своя форма рядом (поле — внутри формы предложения, вложить форму нельзя); в карточке строки —
    // внутри её фрейма, ответ рисуется там же.
    take() {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = this.takeValue;
        form.hidden = true;
        const add = (name, value) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; form.append(i); };
        add('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
        add('act', 'take');
        add('ref', this.field?.value.trim() ?? '');
        const frame = this.element.closest('turbo-frame');
        if (frame) form.dataset.turboFrame = frame.id;
        (frame ?? document.body).append(form);
        this.waiting(true);
        form.requestSubmit();
    }
}
