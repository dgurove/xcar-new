import { Controller } from '@hotwired/stimulus';

// «Кому показывать» (`x-audience.editor`, 12.11.2026): «Всем | Всем, кроме | Выбранным» над строками «кто → когда»
// (AudienceRules): «кроме» — строки с delay null (чипы «Не видят»), «выбранным» — у «Остальных» null, волны — строки со своим
// временем при «Всем». Группа или менеджер добавляются списком выбора с поиском (отметить можно нескольких), время у
// строки — системный выбор справа. Итог — JSON в скрытом поле и change с него (save_bar показывает «Сохранить изменения»);
// не трогали — поле остаётся как было (пустое — «как у вендора»). «Сохранить» строки-сводки отправляет форму сразу. Сводка —
// те же слова, что AudienceRules::summary. deal — публикация машины в сделке: супер-покупателей нет.
export default class extends Controller {
    static targets = ['rules', 'summary', 'body', 'pick', 'search', 'option', 'mode', 'icons', 'pickTitle'];
    static values = { options: Object, effective: Array, deal: Boolean };

    connect() {
        this.reset();
        // Сохранили — POST и редирект на ту же страницу Turbo делает морфом: контроллер не переподключается, строки, что
        // рисовал он, морф стёр до серверных (пустых) — окно открывалось пустым. Перечитать пришедшее и нарисовать заново.
        // Но морф бывает и посреди выбора (машину поправил другой, живое обновление): несохранённое тогда не теряем —
        // возвращаем его в поле. Сохранили то же, что выбрано, — правки больше нет.
        this.onMorph = () => {
            const mine = JSON.stringify(this.rows);
            const server = JSON.stringify(this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue ?? []));
            if (this.edited && server !== mine) {
                this.rulesTarget.value = mine;
                this.render();
            } else this.reset();
        };
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
    }

    reset() {
        // Значение с сервера: у скрытого поля присвоение value меняет и defaultValue — сравнивать не с чем.
        this.initial = this.rulesTarget.value;
        this.rows = this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue ?? []);
        this.state = this.derive();
        this.picking = null;
        this.edited = false;
        this.autoRest = false;
        this.render();
    }

    // Enter в поиске — отметить первого найденного, а не отправить форму вокруг (шторка публикации, переоценка, вендор).
    enter(e) {
        e.preventDefault();
        const first = this.optionTargets.find((o) => !o.hidden);
        first?.querySelector('input').click();
    }

    parse(json) {
        try {
            const v = JSON.parse(json || 'null');
            return Array.isArray(v) && v.length ? v : null;
        } catch {
            return null;
        }
    }

    // «Остальные» ровно одна и в конце; супер-покупателей у публикации сделки нет.
    clean(rows) {
        const own = rows.filter((r) => r.type !== 'rest' && !(this.dealValue && r.type === 'super'))
            .map((r) => ({ type: r.type, id: Number(r.id), delay: r.delay ?? null }));
        const rest = rows.find((r) => r.type === 'rest');
        return [...own, { type: 'rest', id: null, delay: rest ? rest.delay ?? null : 0 }];
    }

    get rest() {
        return this.rows[this.rows.length - 1];
    }

    get own() {
        return this.rows.slice(0, -1);
    }

    get hidden() {
        return this.own.filter((r) => r.delay === null);
    }

    get shown() {
        return this.own.filter((r) => r.delay !== null);
    }

    derive() {
        if (this.rest.delay === null) return 'only';
        return this.hidden.length ? 'except' : 'all';
    }

    // Сегмент: «Выбранным» — остальные не видят, исключения ни к чему; «Всем» / «Всем, кроме» — остальные сразу, выбранные
    // «сразу» при этом лишние. Пусто там, где нужен выбор, — список выбора открывается сам.
    mode(e) {
        const next = e.target.value;
        if (next === this.state) return;
        if (next === 'only') {
            this.rest.delay = null;
            this.rows = [...this.shown, this.rest];
        } else {
            if (this.rest.delay === null) {
                this.rest.delay = 0;
                this.rows = [...this.own.filter((r) => r.delay !== 0), this.rest];
            }
            if (next === 'all') this.rows = [...this.shown, this.rest];
        }
        this.state = next;
        this.picking = next === 'except' && !this.hidden.length ? 'hidden' : next === 'only' && !this.shown.length ? 'shown' : null;
        this.commit();
    }

    open(kind) {
        this.picking = this.picking === kind ? null : kind;
        this.render();
        if (this.picking) {
            this.pickTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            if (this.hasSearchTarget && matchMedia('(hover: hover)').matches) this.searchTarget.focus({ preventScroll: true });
        }
    }

    close() {
        this.picking = null;
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this.filter();
        this.render();
    }

    filter() {
        const q = this.hasSearchTarget ? this.searchTarget.value.trim().toLowerCase() : '';
        this.optionTargets.forEach((o) => { o.hidden = q !== '' && !o.dataset.name.includes(q); });
    }

    // Отметка в списке выбора: «Не видят» — строка с null, иначе — со временем (0, «сразу»). Был в другой стороне — переезжает.
    toggle(e) {
        const option = e.target.closest('[data-key]');
        const [type, id] = option.dataset.key.split(':');
        const i = this.own.findIndex((r) => r.type === type && r.id === Number(id));
        const want = this.picking === 'hidden' ? null : 0;
        // Первая волна при «всем сразу» — остальным через день: иначе «Москва сразу, остальные сразу» ничего не значит.
        if (want === 0 && this.state !== 'only' && !this.shown.length && this.rest.delay === 0) {
            this.rest.delay = DAY;
            this.autoRest = true;
        }
        if (i >= 0 && (this.rows[i].delay === null) === (want === null)) return this.drop(this.rows[i]);
        if (i >= 0) this.rows[i].delay = want;
        else this.rows.splice(this.rows.length - 1, 0, { type, id: Number(id), delay: want });
        this.commit();
    }

    drop(r) {
        this.rows.splice(this.rows.indexOf(r), 1);
        // Убрали последнюю волну — «остальные» снова «все менеджеры» сразу, как было до первой; только если «через день» поставили
        // мы сами, а не человек.
        if (r.delay !== null && this.autoRest && this.state !== 'only' && !this.shown.length && this.rest.delay === DAY) this.rest.delay = 0;
        if (!this.shown.length) this.autoRest = false;
        this.commit();
    }

    commit() {
        this.edited = true;
        this.rulesTarget.value = JSON.stringify(this.rows);
        this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }

    // «Сохранить» в шторке строки-сводки: та же кнопка «Сохранить изменения» формы (у карточки строки она шлёт `_fields[]`),
    // а без неё — обычная отправка. Ничего не тронуто — save_bar_controller ничего не шлёт.
    save() {
        const form = this.rulesTarget.form;
        if (!form) return;
        // Форма узнаёт о правке по change скрытого поля; если его пропустили (шторка открылась раньше этого куска,
        // 19.10.2026) — сообщаем ещё раз перед отправкой.
        if (this.rulesTarget.value !== this.initial) this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        const button = [...form.elements].find((b) => b.matches('[data-save-bar-button]'));
        form.requestSubmit(button);
    }

    render() {
        this.modeTargets.forEach((m) => { m.checked = m.value === this.state; });
        const pick = this.pickTarget;
        const parts = [];
        // Список выбора раскрывается прямо под кнопкой, что его открыла.
        if (this.state === 'except' || (this.state === 'only' && this.hidden.length)) parts.push(this.chips(), ...(this.picking === 'hidden' ? [pick] : []));
        if (this.state === 'only') {
            if (this.shown.length) parts.push(this.list(this.shown, false));
            parts.push(this.add('shown', this.shown.length ? 'Добавить' : 'Выбрать группы и менеджеров'));
        } else {
            // Волны: строки со своим временем и «Остальные»; без волн и «всем сразу» — только тихое «+ Волна».
            if (this.shown.length || this.rest.delay !== 0) parts.push(this.list(this.shown, true));
            parts.push(this.add('shown', 'Волна', true));
        }
        if (this.picking !== 'hidden') parts.push(pick);
        this.bodyTarget.replaceChildren(...parts);
        pick.hidden = !this.picking;
        if (this.picking) {
            const has = new Set(this.own.filter((r) => (r.delay === null) === (this.picking === 'hidden')).map((r) => `${r.type}:${r.id}`));
            this.optionTargets.forEach((o) => { o.querySelector('input').checked = has.has(o.dataset.key); });
            if (this.hasPickTitleTarget) this.pickTitleTarget.textContent = this.picking === 'hidden' ? 'Кому не показывать' : 'Кому показывать';
            if (this.hasSearchTarget) this.searchTarget.placeholder = this.picking === 'hidden' ? 'Кому не показывать' : 'Найти менеджера или группу';
        }
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary();
    }

    // Кого не показывать — чипами с крестиком и «Добавить» последним.
    chips() {
        const block = el('div', 'audience-block');
        const head = el('div', 'audience-head');
        head.textContent = this.state === 'only' ? 'Кроме' : 'Не видят';
        const chips = el('div', 'audience-chips');
        for (const r of this.hidden) {
            const chip = el('button', 'chip audience-chip');
            chip.type = 'button';
            chip.setAttribute('aria-label', `Убрать: ${this.name(r)}`);
            chip.append(this.name(r), this.icon('x'));
            chip.addEventListener('click', () => this.drop(r));
            chips.append(chip);
        }
        chips.append(this.add('hidden', this.hidden.length ? 'Добавить' : 'Выбрать, кому не показывать'));
        block.append(head, chips);
        return block;
    }

    // Строки со временем: выбранные, а при «Всем» — волны и последней «Остальные» / «Все менеджеры».
    list(rows, withRest) {
        const list = el('div', 'list audience-list');
        for (const r of rows) list.append(this.row(r, true));
        if (withRest) list.append(this.row(this.rest, false));
        return list;
    }

    row(r, removable) {
        const row = el('div', 'row audience-row');
        const name = el('span', 'min-w-0 flex-1');
        const title = el('span', 'block truncate');
        title.textContent = r.type === 'rest' ? (this.shown.length ? 'Остальные' : 'Все менеджеры') : this.name(r);
        name.append(title);
        const group = r.type === 'group' && (this.optionsValue.groups || []).find((g) => g.id === r.id);
        if (group) {
            const sub = el('span', 'row-sub nums');
            sub.textContent = `группа, ${group.count} ${plural(group.count, ['человек', 'человека', 'человек'])}`;
            name.append(sub);
        }
        const when = el('select', 'row-select');
        when.setAttribute('aria-label', `Когда: ${title.textContent}`);
        // Дни — группой «Через несколько дней»: 1–5 одним списком, а не пятью строками вперемешку с часами.
        let days = null;
        for (const [min, label] of this.optionsValue.delays || []) {
            const option = new Option(label, min, false, r.delay === min);
            if (min < DAY) when.append(option);
            else (days ??= when.appendChild(Object.assign(document.createElement('optgroup'), { label: 'Через несколько дней' }))).append(option);
        }
        when.addEventListener('change', () => {
            r.delay = Number(when.value);
            if (r.type === 'rest') this.autoRest = false;
            this.commit();
        });
        row.append(name, when);
        if (removable) {
            const x = el('button', 'audience-x');
            x.type = 'button';
            x.setAttribute('aria-label', `Убрать: ${title.textContent}`);
            x.append(this.icon('x'));
            x.addEventListener('click', () => this.drop(r));
            row.append(x);
        }
        return row;
    }

    add(kind, label, quiet = false) {
        const b = el('button', quiet ? 'audience-more' : 'chip audience-add');
        b.type = 'button';
        b.setAttribute('aria-expanded', String(this.picking === kind));
        b.append(this.icon('plus'), label);
        b.addEventListener('click', () => this.open(kind));
        return b;
    }

    icon(name) {
        return this.iconsTarget.content.querySelector(`[data-icon="${name}"]`).cloneNode(true);
    }

    // Как AudienceRules::summary: кого нет в выборе (уже не менеджер, группа другого вида) — по `names` с сервера.
    name(r) {
        if (r.type === 'super') return this.optionsValue.super || 'Супер-покупатели';
        const list = r.type === 'group' ? this.optionsValue.groups : this.optionsValue.managers;
        return (list || []).find((x) => x.id === r.id)?.name ?? this.optionsValue.names?.[`${r.type}:${r.id}`] ?? null;
    }

    when(delay) {
        const found = (this.optionsValue.delays || []).find(([min]) => min === delay);
        return (found ? found[1] : `через ${delay} минут`).toLowerCase();
    }

    // Те же слова, что AudienceRules::summary.
    summary() {
        const own = this.own.filter((r) => this.name(r) !== null);
        const shown = own.filter((r) => r.delay !== null);
        const hidden = own.filter((r) => r.delay === null);
        const words = (list) => list.map((r) => this.name(r)).join(', ');
        const timed = (list) => list.map((r) => `${this.name(r)} ${this.when(r.delay)}`).join(', ');
        if (this.rest.delay === null) {
            if (!shown.length) return 'Никому';
            return `Только ${shown.every((r) => r.delay === 0) ? words(shown) : timed(shown)}`;
        }
        const except = hidden.length ? `, кроме ${words(hidden)}` : '';
        if (!shown.length) return `Всем${this.rest.delay === 0 && except ? '' : ` ${this.when(this.rest.delay)}`}${except}`;
        const text = `${timed(shown)}, остальные ${this.when(this.rest.delay)}${except}`;
        return text[0].toUpperCase() + text.slice(1);
    }
}

const DAY = 1440;

function el(tag, cls) {
    const e = document.createElement(tag);
    e.className = cls;
    return e;
}

function plural(n, [one, few, many]) {
    const m10 = n % 10;
    const m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
}
