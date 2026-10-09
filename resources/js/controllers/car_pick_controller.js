import { Controller } from '@hotwired/stimulus';
import { closeSheet } from '../sheet';

// Выбор ТС шторкой, как поиск в iOS (09.10.2026, владелец: «схалтурил»): поле сверху, машины — строками самого листа
// (`/work/money/cars`: номер, менеджер сделки и где она), выбранная с галкой, внизу при вводе — «Нет в системе: „…“».
// Без поповера: подсказка выпадала и обрезалась низом листа.
// С формой (карточка ссылки): выбор ставит скрытые поля и спрашивает `preview` — что будет («Засчитать 53 000 ₽» на
// кнопке, «останется 5 000 ₽» строкой у машины). Встроенная (`embedded`, «Создать ссылку»): выбор сразу кладёт поля в
// форму снаружи, пишет машину в строку «ТС» и закрывает лист; событие `car-pick:chosen` несёт плательщика сделки.
export default class extends Controller {
    static targets = ['query', 'results', 'offer', 'text', 'submit', 'label', 'check'];
    static values = { url: String, preview: String, embedded: Boolean, label: String };

    connect() {
        this.items = [];
        this.picked = this.offerTarget.value ? { id: Number(this.offerTarget.value), label: this.labelValue, hint: '' } : null;
        this.words = !this.picked && this.textTarget.value ? this.textTarget.value : '';
        this.note = null;
        this.render();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.previewAbort?.abort();
    }

    // Первое открытие (лист открыли или поле получило фокус) — машины со сделками, как подсказка до ввода.
    load() {
        if (this.loaded) return;
        this.loaded = true;
        this.fetch('');
    }

    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetch(this.query), 200);
    }

    // Enter в поле не отправляет форму снаружи («Создать ссылку»), а берёт первую найденную строку — не «Без ТС».
    key(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        this.resultsTarget.querySelector('button:not([data-clear])')?.click();
    }

    get query() {
        return this.queryTarget.value.trim();
    }

    async fetch(q) {
        const url = new URL(this.urlValue, location.href);
        url.searchParams.set('q', q);
        this.abort?.abort();
        this.abort = new AbortController();
        try {
            const res = await fetch(url, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            this.items = await res.json();
            this.render();
        } catch {}
    }

    render() {
        const q = this.query;
        const list = [...this.items];
        // Выбранная при открытии — первой: что сейчас, видно без прокрутки. Выбранная потом остаётся на месте — строка
        // не прыгает из-под пальца.
        if (this.picked && !this.touched) {
            const at = list.findIndex((i) => i.id === this.picked.id);
            list.unshift(at >= 0 ? list.splice(at, 1)[0] : this.picked);
        }
        this.resultsTarget.replaceChildren();
        if (this.embedded && (this.picked || this.words)) {
            const clear = this.row({ label: 'Без ТС', muted: true }, () => this.choose(null));
            clear.dataset.clear = '';
            this.resultsTarget.append(clear);
        }
        for (const item of list) this.resultsTarget.append(this.row(item, () => this.choose(item), this.picked?.id === item.id));
        if (this.words && this.words.toLowerCase() !== q.toLowerCase()) {
            this.resultsTarget.append(this.row({ label: this.words, hint: 'нет в системе' }, () => this.choose(null, this.words), true));
        }
        if (q && !list.some((i) => i.label.toLowerCase() === q.toLowerCase())) {
            this.resultsTarget.append(this.row({ label: `Нет в системе: «${q}»` }, () => this.choose(null, q), false));
        }
        this.resultsTarget.hidden = this.resultsTarget.childElementCount === 0;
    }

    get embedded() {
        return this.embeddedValue;
    }

    row(item, onPick, checked = false) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'row w-full text-left';
        const body = Object.assign(document.createElement('span'), { className: 'min-w-0 flex-1' });
        // Подписи — данные (марка, номер, имя): текстом, не разметкой.
        body.append(Object.assign(document.createElement('span'), { className: 'block truncate' + (item.muted ? ' text-ink-muted' : ''), textContent: item.label }));
        if (item.hint) body.append(Object.assign(document.createElement('span'), { className: 'row-sub', textContent: item.hint }));
        if (checked && this.note) body.append(Object.assign(document.createElement('span'), { className: 'row-sub !text-accent-text', textContent: this.note }));
        b.append(body);
        if (checked && this.hasCheckTarget) b.append(this.checkTarget.content.cloneNode(true));
        b.setAttribute('aria-pressed', checked ? 'true' : 'false');
        b.addEventListener('click', onPick);
        return b;
    }

    async choose(item, words = '') {
        this.touched = true;
        if (item && !this.items.some((i) => i.id === item.id)) this.items.unshift(item);
        this.picked = item;
        this.words = item ? '' : words;
        this.note = null;
        this.offerTarget.value = item ? item.id : '';
        this.textTarget.value = item ? '' : words;
        this.offerTarget.dispatchEvent(new Event('change', { bubbles: true }));
        if (this.embedded) {
            if (this.hasLabelTarget) {
                this.labelTarget.textContent = item ? item.label : words || 'Не указано';
                this.labelTarget.classList.toggle('text-ink-muted', !item && !words);
            }
            this.dispatch('chosen', { detail: item ?? {} });
            closeSheet(this.element.querySelector('dialog'));
            this.queryTarget.value = '';
            this.render();
            return;
        }
        this.render();
        await this.preview();
    }

    async preview() {
        if (!this.previewValue || !this.hasSubmitTarget) return;
        const url = new URL(this.previewValue, location.href);
        if (this.offerTarget.value) url.searchParams.set('offer_id', this.offerTarget.value);
        if (this.textTarget.value) url.searchParams.set('car_title', this.textTarget.value);
        // Выбрали другую машину, пока ответ о прошлой в пути, — прошлый отменяется: его исход лёг бы на новую.
        this.previewAbort?.abort();
        const abort = this.previewAbort = new AbortController();
        this.submitTarget.disabled = true;
        try {
            const res = await fetch(url, { signal: abort.signal, headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const { label, note } = await res.json();
            if (abort.signal.aborted) return;
            this.submitTarget.textContent = label;
            this.note = note;
            this.render();
        } catch {} finally {
            if (this.previewAbort === abort) this.submitTarget.disabled = false;
        }
    }
}
