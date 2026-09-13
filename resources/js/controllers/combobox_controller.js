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
        this.close();
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
        if (this.listTarget.childElementCount === 0) this.close(); else this.show();
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
        if (this.busy) return;
        this.busy = true;
        try { await this.create(name); } finally { this.busy = false; }
    }

    async create(name) {
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
        else this.show();
    }

    // Список — в top layer (popover): поверх открытой шторки и любых overflow, под полем;
    // под клавиатурой места нет — переворачивается над полем.
    show() {
        const list = this.listTarget;
        list.hidden = false;
        if (!list.showPopover) return;
        this.place();
        if (!list.matches(':popover-open')) list.showPopover();
        this.onMove ??= () => this.place();
        addEventListener('scroll', this.onMove, { capture: true, passive: true });
        visualViewport?.addEventListener('resize', this.onMove);
    }

    place() {
        const list = this.listTarget, r = this.inputTarget.getBoundingClientRect();
        const below = (visualViewport?.height ?? innerHeight) - r.bottom - 8;
        const max = Math.max(120, Math.min(260, below >= 160 ? below : r.top - 8));
        list.style.left = `${r.left}px`;
        list.style.width = `${r.width}px`;
        list.style.maxHeight = `${max}px`;
        if (below >= 160) { list.style.top = `${r.bottom + 4}px`; list.style.bottom = ''; }
        else { list.style.top = ''; list.style.bottom = `${(visualViewport?.height ?? innerHeight) - r.top + 4}px`; }
    }

    close() {
        const list = this.listTarget;
        list.hidden = true;
        if (list.matches?.(':popover-open')) list.hidePopover();
        if (this.onMove) { removeEventListener('scroll', this.onMove, { capture: true }); visualViewport?.removeEventListener('resize', this.onMove); }
    }

    // Марка сменилась — модель сбрасывается.
    reset() {
        this.inputTarget.value = '';
        this.hiddenTarget.value = '';
        this.listTarget.innerHTML = '';
    }
}
