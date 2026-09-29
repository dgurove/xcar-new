import { Controller } from '@hotwired/stimulus';

// «Кому показывать» — список «кто → когда»: строки групп и менеджеров, последней «Остальные». Время у строки —
// системный выбор справа (минуты от публикации, «Не показывать» — null, у своих строк ещё «Убрать»). Чип шаблона
// подставляет его строки; правка строки делает настройки своими (audience_id пустеет). Итог — JSON в скрытом
// поле и change с него: окошко сохраняет само, форма настроек — кнопкой. Сводка — те же слова, что AudienceRules::summary.
export default class extends Controller {
    static targets = ['rules', 'audience', 'summary', 'list', 'add', 'preset'];
    static values = { options: Object, effective: Array };

    connect() {
        this.rows = this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue);
        this.render();
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

    preset({ params: { id } }) {
        const preset = (this.optionsValue.presets || []).find((p) => p.id === id);
        if (!preset) return;
        this.rows = this.clean(preset.rules);
        if (this.hasAudienceTarget) this.audienceTarget.value = id;
        this.commit();
    }

    add() {
        const [type, id] = this.addTarget.value.split(':');
        this.addTarget.value = '';
        if (!type) return;
        this.rows.splice(this.rows.length - 1, 0, { type, id: Number(id), delay: 0 });
        this.changed();
    }

    // Правка строк: настройки становятся своими.
    changed() {
        if (this.hasAudienceTarget) this.audienceTarget.value = '';
        this.commit();
    }

    commit() {
        this.rulesTarget.value = JSON.stringify(this.rows);
        this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }

    render() {
        this.listTarget.replaceChildren(...this.rows.map((r, i) => this.row(r, i)));
        this.fillAdd();
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary();
        // Подсвечен шаблон, с которым строки совпадают сейчас, — а не тот, с которого когда-то начали.
        const now = JSON.stringify(this.rows);
        const same = (this.optionsValue.presets || []).find((p) => JSON.stringify(this.clean(p.rules)) === now);
        this.presetTargets.forEach((p) => p.setAttribute('aria-pressed', String(same?.id === Number(p.dataset.audienceIdParam))));
    }

    row(r, i) {
        const row = el('label', 'row audience-row');
        const name = el('span', 'min-w-0 flex-1 truncate');
        name.textContent = this.name(r);
        const when = el('select', 'row-select');
        when.setAttribute('aria-label', `Когда: ${this.name(r)}`);
        for (const [min, label] of this.optionsValue.delays || []) when.append(new Option(label, min, false, r.delay === min));
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
        for (const [type, label, items] of [['group', 'Группы', this.optionsValue.groups], ['user', 'Менеджеры', this.optionsValue.managers]]) {
            const free = (items || []).filter((x) => !taken.has(`${type}:${x.id}`));
            if (!free.length) continue;
            const og = document.createElement('optgroup');
            og.label = label;
            free.forEach((x) => og.append(new Option(x.name, `${type}:${x.id}`)));
            select.append(og);
        }
        select.closest('.add-select').hidden = select.options.length < 2;
    }

    name(r) {
        if (r.type === 'rest') return this.rows.length > 1 ? 'Остальные' : 'Все менеджеры';
        const list = r.type === 'group' ? this.optionsValue.groups : this.optionsValue.managers;
        return (list || []).find((x) => x.id === r.id)?.name ?? (r.type === 'group' ? 'Группа' : 'Менеджер');
    }

    when(delay) {
        if (delay === null) return 'не показывать';
        const found = (this.optionsValue.delays || []).find(([min]) => min === delay);
        return (found ? found[1] : `через ${delay} минут`).toLowerCase();
    }

    summary() {
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

function el(tag, cls) {
    const e = document.createElement(tag);
    e.className = cls;
    return e;
}
