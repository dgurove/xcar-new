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
        // Фокус ушёл из поля («Готово» или «Далее» на клавиатуре iPhone) — список закрывается: иначе он оставался поверх
        // соседних полей, и нажатие по «Году» под ним выбирало модель из списка (07.10.2026). Нажатие по подсказке уводит
        // фокус раньше щелчка — закрытие ждёт 150 мс, подсказка успевает выбраться. preventDefault на pointerdown у подсказки
        // нельзя: WebKit тогда не даёт щелчка от пальца.
        this.onFocusOut = (e) => {
            if (this.element.contains(e.relatedTarget)) return;
            clearTimeout(this.blurTimer);
            this.blurTimer = setTimeout(() => { if (!this.element.contains(document.activeElement)) this.close(); }, 150);
        };
        this.element.addEventListener('focusout', this.onFocusOut);
    }

    disconnect() {
        this.close();
        document.removeEventListener('click', this.onDocumentClick);
        this.element.removeEventListener('focusout', this.onFocusOut);
        clearTimeout(this.timer);
        clearTimeout(this.blurTimer);
    }

    search() {
        clearTimeout(this.timer);
        const had = this.hiddenTarget.value !== '';
        this.hiddenTarget.value = '';
        if (had) this.hiddenTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.timer = setTimeout(() => this.load(), 150);
    }

    async load() {
        const q = this.inputTarget.value.trim();
        const url = new URL(this.urlValue, location.origin);
        url.searchParams.set('q', q);
        if (this.dependsValue) {
            const dep = this.near(this.dependsValue)?.value;
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
        // Длинная подсказка (район и регион у места) — строкой под названием; короткая (русское имя марки) — справа.
        b.className = 'combobox-option' + (isCreate ? ' combobox-create' : '') + (hint && hint.length > 16 ? ' combobox-option-stack' : '');
        // Подписи — данные (марка, имя, номер): текстом, не разметкой.
        const name = document.createElement('span');
        name.textContent = label;
        b.append(name);
        if (hint) {
            const sub = Object.assign(document.createElement('span'), { className: 'text-ink-muted text-sm', textContent: hint });
            b.append(sub);
        }
        b.addEventListener('click', onPick);
        return b;
    }

    choose(item) {
        this.inputTarget.value = item.label;
        this.hiddenTarget.value = item.id;
        this.close();
        this.hiddenTarget.dispatchEvent(new Event('change', { bubbles: true }));
        // Выбор целиком (подсказки ответа — плательщик ТС и прочее) — тем, кто рядом слушает.
        this.element.dispatchEvent(new CustomEvent('combobox:chosen', { detail: item, bubbles: true }));
        if (this.resetsValue) this.near(this.resetsValue)?.dispatchEvent(new CustomEvent('combobox:reset'));
    }

    async createNew(name) {
        if (this.busy) return;
        this.busy = true;
        try { await this.create(name); } finally { this.busy = false; }
    }

    async create(name) {
        const body = { name };
        if (this.dependsValue) body[this.paramValue] = this.near(this.dependsValue)?.value;
        const r = await fetch(this.createValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify(body),
        });
        if (r.ok) this.choose(await r.json());
    }

    // Соседнее поле (марка у модели) — сначала в своей форме: на странице их бывает две (окно приёма парковки над
    // карточкой ТС), и document.querySelector брал марку чужой формы.
    near(selector) {
        return this.element.closest('form')?.querySelector(selector) ?? document.querySelector(selector);
    }

    open() {
        if (this.listTarget.childElementCount === 0) this.load();
        else this.show();
    }

    // Телефон (15.10.2026, владелец: «менеджера невозможно выбрать — список за клавиатурой, листаешь — едет всё окно»):
    // подсказки — строками прямо под полем, в потоке формы, как у «ТС» ссылки (car_field_controller). Они листаются
    // вместе со своим листом, а поле встаёт к его верху — строки ложатся над клавиатурой. Лист на это время постоянной
    // высоты (.sheet-picking), иначе он рос бы вверх с каждой строкой и уносил поле из-под пальца.
    // ПК — список в top layer (popover): поверх открытой шторки и любых overflow, под полем; места нет — над полем.
    inline() {
        return matchMedia('(hover: none) and (max-width: 1023.98px)').matches;
    }

    show() {
        const list = this.listTarget;
        if (this.inline() || !list.showPopover) {
            // Закрытый popover браузер не рисует вовсе (display: none) — в потоке формы список без него.
            if (list.hasAttribute('popover')) { if (list.matches(':popover-open')) list.hidePopover(); list.removeAttribute('popover'); }
            list.classList.add('is-inline');
            list.hidden = false;
            this.sheet = this.element.closest('dialog.sheet');
            this.sheet?.classList.add('sheet-picking');
            requestAnimationFrame(() => this.lift());
            return;
        }
        list.hidden = false;
        if (!list.hasAttribute('popover')) list.setAttribute('popover', 'manual');
        this.place();
        if (!list.matches(':popover-open')) list.showPopover();
        this.onMove ??= () => this.place();
        addEventListener('scroll', this.onMove, { capture: true, passive: true });
        visualViewport?.addEventListener('resize', this.onMove);
        visualViewport?.addEventListener('scroll', this.onMove);
    }

    // Поле — к верху своей прокрутки (лист, карточка строки), под липкую шапку листа: прокруткой листа, не страницы.
    lift() {
        const field = this.element;
        let box = field.parentElement;
        for (; box && box !== document.body; box = box.parentElement) {
            if (box.scrollHeight > box.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(box).overflowY)) break;
        }
        if (!box || box === document.body) return;
        const bar = box.querySelector(':scope > .sheet-bar');
        const top = (bar ? bar.getBoundingClientRect().bottom : box.getBoundingClientRect().top) + 8;
        box.scrollTop += field.getBoundingClientRect().top - top;
    }

    // Координаты fixed-слоя — от всего экрана (layout viewport), видимая часть над клавиатурой — от offsetTop до
    // offsetTop + height: клавиатура iOS экран не ужимает. Прежняя формула мешала одно с другим, и список вставал
    // на высоту клавиатуры ниже — под неё.
    place() {
        const list = this.listTarget, r = this.inputTarget.getBoundingClientRect();
        const vv = visualViewport;
        const top = vv?.offsetTop ?? 0, bottom = top + (vv?.height ?? innerHeight);
        const below = bottom - r.bottom - 8;
        const max = Math.max(120, Math.min(260, below >= 160 ? below : r.top - top - 8));
        list.style.left = `${r.left}px`;
        list.style.width = `${r.width}px`;
        list.style.maxHeight = `${max}px`;
        if (below >= 160) { list.style.top = `${r.bottom + 4}px`; list.style.bottom = ''; }
        else { list.style.top = ''; list.style.bottom = `${innerHeight - r.top + 4}px`; }
    }

    close() {
        const list = this.listTarget;
        list.hidden = true;
        list.classList.remove('is-inline');
        this.sheet?.classList.remove('sheet-picking');
        this.sheet = null;
        if (list.matches?.(':popover-open')) list.hidePopover();
        if (this.onMove) {
            removeEventListener('scroll', this.onMove, { capture: true });
            visualViewport?.removeEventListener('resize', this.onMove);
            visualViewport?.removeEventListener('scroll', this.onMove);
        }
    }

    // Марка сменилась — модель сбрасывается.
    reset() {
        this.inputTarget.value = '';
        this.hiddenTarget.value = '';
        this.listTarget.innerHTML = '';
    }
}
