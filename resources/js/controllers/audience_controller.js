import { Controller } from '@hotwired/stimulus';

// «Кому показывать» — список «кто → когда»: строки групп и менеджеров, последней «Остальные»; супер-покупатели — одной
// строкой `super`, как один менеджер (AudienceRules::SUPER). Время у строки —
// системный выбор справа (минуты от публикации, «Не показывать» — null, у своих строк ещё «Убрать»). Итог — JSON в
// скрытом поле и change с него (save_bar показывает «Сохранить изменения»), «Сохранить» шторки отправляет форму сразу. Сводка — те же слова, что
// AudienceRules::summary.
// Режим `list` — «Кому показывать» сделки (модуль «Показ», 12.10.2026): тот же список без времени — строка и «Убрать»,
// без «Остальных» и супер-покупателей, итог `[{type, id}]`, пусто — «Никому» (DealAudience::summary).
export default class extends Controller {
    static targets = ['rules', 'summary', 'list', 'add'];
    static values = { options: Object, effective: Array, mode: { type: String, default: 'waves' } };

    connect() {
        // Значение с сервера: у скрытого поля присвоение value меняет и defaultValue — сравнивать не с чем.
        this.initial = this.rulesTarget.value;
        this.rows = this.isList
            ? (this.parse(this.rulesTarget.value) ?? []).map((r) => ({ type: r.type, id: Number(r.id) }))
            : this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue);
        this.render();
    }

    get isList() {
        return this.modeValue === 'list';
    }

    parse(json) {
        try {
            const v = JSON.parse(json || 'null');
            return Array.isArray(v) && v.length ? v : null;
        } catch {
            return null;
        }
    }

    // «Остальные» ровно одна и в конце.
    clean(rows) {
        const own = rows.filter((r) => r.type !== 'rest').map((r) => ({ type: r.type, id: Number(r.id), delay: r.delay ?? null }));
        const rest = rows.find((r) => r.type === 'rest');
        return [...own, { type: 'rest', id: null, delay: rest ? rest.delay ?? null : 0 }];
    }

    add() {
        const [type, id] = this.addTarget.value.split(':');
        this.addTarget.value = '';
        if (!type) return;
        if (this.isList) this.rows.push({ type, id: Number(id) });
        else this.rows.splice(this.rows.length - 1, 0, { type, id: Number(id), delay: 0 });
        this.changed();
    }

    changed() {
        this.commit();
    }

    commit() {
        this.rulesTarget.value = JSON.stringify(this.rows);
        this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }

    // «Сохранить» в шторке: та же кнопка «Сохранить изменения» формы (у карточки строки она шлёт `_fields[]`), а без неё —
    // обычная отправка. Ничего не тронуто — save_bar_controller ничего не шлёт.
    save() {
        const form = this.rulesTarget.form;
        if (!form) return;
        // Форма узнаёт о правке по change скрытого поля; если его пропустили (шторка открылась раньше этого куска,
        // 19.10.2026: значение в поле новое, а форма «не тронута» и «Сохранить» молчал) — сообщаем ещё раз перед отправкой.
        if (this.rulesTarget.value !== this.initial) this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        const button = [...form.elements].find((b) => b.matches('[data-save-bar-button]'));
        form.requestSubmit(button);
    }

    render() {
        this.listTarget.replaceChildren(...this.rows.map((r, i) => this.row(r, i)));
        this.fillAdd();
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary();
    }

    row(r, i) {
        // В списке без времени — div: label отдал бы нажатие по имени кнопке «Убрать».
        const row = el(this.isList ? 'div' : 'label', 'row audience-row');
        const name = el('span', 'min-w-0 flex-1 truncate');
        name.textContent = this.name(r);
        if (this.isList) {
            const drop = el('button', 'shrink-0 text-sm text-danger');
            drop.type = 'button';
            drop.textContent = 'Убрать';
            drop.addEventListener('click', () => {
                this.rows.splice(i, 1);
                this.changed();
            });
            row.append(name, drop);
            return row;
        }
        const when = el('select', 'row-select');
        when.setAttribute('aria-label', `Когда: ${this.name(r)}`);
        // Дни — группой «Через несколько дней»: 1–5 одним списком, а не пятью строками «Через N дней» вперемешку с часами.
        let days = null;
        for (const [min, label] of this.optionsValue.delays || []) {
            if (min < DAY) {
                when.append(new Option(label, min, false, r.delay === min));
                continue;
            }
            days ??= when.appendChild(Object.assign(document.createElement('optgroup'), { label: 'Через несколько дней' }));
            days.append(new Option(label, min, false, r.delay === min));
        }
        when.append(new Option('Не показывать', 'never', false, r.delay === null));
        if (r.type !== 'rest') when.append(new Option('Убрать', 'drop'));
        when.addEventListener('change', () => {
            if (when.value === 'drop') this.rows.splice(i, 1);
            else r.delay = when.value === 'never' ? null : Number(when.value);
            this.changed();
        });
        row.append(name, when);
        return row;
    }

    // Добавить можно тех, кого ещё нет в списке: сначала группы, потом люди.
    fillAdd() {
        const taken = new Set(this.rows.map((r) => `${r.type}:${r.id}`));
        const select = this.addTarget;
        select.replaceChildren(new Option('', '', true, true));
        const people = [...(this.optionsValue.super && !this.isList ? [{ id: 0, name: this.optionsValue.super, type: 'super' }] : []), ...(this.optionsValue.managers || [])];
        for (const [type, label, items] of [['group', 'Группы', this.optionsValue.groups], ['user', 'Менеджеры', people]]) {
            const free = (items || []).map((x) => ({ ...x, type: x.type || type })).filter((x) => !taken.has(`${x.type}:${x.id}`));
            if (!free.length) continue;
            const og = document.createElement('optgroup');
            og.label = label;
            free.forEach((x) => og.append(new Option(x.name, `${x.type}:${x.id}`)));
            select.append(og);
        }
        select.closest('.add-select').hidden = select.options.length < 2;
    }

    name(r) {
        if (r.type === 'rest') return this.rows.length > 1 ? 'Остальные' : 'Все менеджеры';
        if (r.type === 'super') return this.optionsValue.super || 'Супер-покупатели';
        const list = r.type === 'group' ? this.optionsValue.groups : this.optionsValue.managers;
        return (list || []).find((x) => x.id === r.id)?.name ?? (r.type === 'group' ? 'Группа' : 'Менеджер');
    }

    when(delay) {
        if (delay === null) return 'не показывать';
        const found = (this.optionsValue.delays || []).find(([min]) => min === delay);
        return (found ? found[1] : `через ${delay} минут`).toLowerCase();
    }

    summary() {
        if (this.isList) {
            const text = this.rows.map((r) => this.name(r)).join(', ');
            return text ? text[0].toUpperCase() + text.slice(1) : 'Никому';
        }
        const rest = this.rows[this.rows.length - 1];
        if (this.rows.length === 1) return rest.delay === null ? 'Никому' : `Всем ${this.when(rest.delay)}`;
        const text = this.rows.map((r) => {
            const name = r.type === 'rest' ? 'остальные' : this.name(r);
            const many = r.type !== 'user';
            return `${name} ${r.delay === null ? (many ? 'не видят' : 'не видит') : this.when(r.delay)}`;
        }).join(', ');
        return text[0].toUpperCase() + text.slice(1);
    }
}

const DAY = 1440;

function el(tag, cls) {
    const e = document.createElement(tag);
    e.className = cls;
    return e;
}
