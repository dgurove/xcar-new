import { Controller } from '@hotwired/stimulus';

// Поле с поиском по справочнику и созданием записи на месте.
// data-combobox-url-value — откуда брать подсказки (?q=), data-combobox-create-value —
// куда слать новое название, data-combobox-depends-value — селектор поля, чьё
// значение уходит параметром (модель зависит от марки).
export default class extends Controller {
    static targets = ['input', 'hidden', 'list'];
    static values = { url: String, create: String, depends: String, resets: String, param: { type: String, default: 'brand' } };

    connect() {
        this.onDocumentClick = (e) => { if (!this.element.contains(e.target)) this.close(); };
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        clearTimeout(this.timer);
    }

    search() {
        clearTimeout(this.timer);
        this.hiddenTarget.value = '';
        this.timer = setTimeout(() => this.load(), 150);
    }

    async load() {
        const q = this.inputTarget.value.trim();
        const url = new URL(this.urlValue, location.origin);
        url.searchParams.set('q', q);
        if (this.dependsValue) {
            const dep = document.querySelector(this.dependsValue)?.value;
            if (!dep) { this.render([], q); return; }
            url.searchParams.set(this.paramValue, dep);
        }
        const items = await fetch(url, { headers: { Accept: 'application/json' } }).then((r) => r.json());
        this.render(items, q);
    }

    render(items, q) {
        const exact = items.some((i) => i.label.toLowerCase() === q.toLowerCase());
        this.listTarget.innerHTML = '';
        for (const item of items) this.listTarget.append(this.option(item.label, item.hint, () => this.choose(item)));
        if (q && !exact && this.createValue) {
            this.listTarget.append(this.option(`Добавить «${q}»`, null, () => this.createNew(q), true));
        }
        this.listTarget.hidden = this.listTarget.childElementCount === 0;
    }

    option(label, hint, onPick, isCreate = false) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'combobox-option' + (isCreate ? ' combobox-create' : '');
        b.innerHTML = `<span>${label}</span>` + (hint ? `<span class="text-ink-muted text-sm">${hint}</span>` : '');
        b.addEventListener('click', onPick);
        return b;
    }

    choose(item) {
        this.inputTarget.value = item.label;
        this.hiddenTarget.value = item.id;
        this.close();
        this.hiddenTarget.dispatchEvent(new Event('change', { bubbles: true }));
        if (this.resetsValue) document.querySelector(this.resetsValue)?.dispatchEvent(new CustomEvent('combobox:reset'));
    }

    async createNew(name) {
        const body = { name };
        if (this.dependsValue) body[this.paramValue] = document.querySelector(this.dependsValue)?.value;
        const r = await fetch(this.createValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify(body),
        });
        if (r.ok) this.choose(await r.json());
    }

    open() {
        if (this.listTarget.childElementCount === 0) this.load();
        else this.listTarget.hidden = false;
    }

    close() {
        this.listTarget.hidden = true;
    }

    // Марка сменилась — модель сбрасывается.
    reset() {
        this.inputTarget.value = '';
        this.hiddenTarget.value = '';
        this.listTarget.innerHTML = '';
    }
}
