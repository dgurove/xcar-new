import { Controller } from '@hotwired/stimulus';

// «Кому показывать»: волны [{delay, all, groups, users}] — через сколько минут после публикации (null — не
// показывать) и кому (все, группы, люди). Шаблон сверху шторки подставляет свои волны целиком; правка волны
// делает правила своими (audience_id пустеет). Итог — JSON в скрытом поле и change с него: окошко сохраняет
// само, форма настроек — кнопкой. Сводка в строке считается так же, как AudienceRules::summary.
const DELAYS = [[0, 'Сразу'], [30, 'Через 30 мин'], [60, 'Через 1 ч'], [120, 'Через 2 ч'], [180, 'Через 3 ч'], [360, 'Через 6 ч'], [720, 'Через 12 ч'], [1440, 'Через 1 д'], [2880, 'Через 2 д'], ['', 'Не показывать']];

export default class extends Controller {
    static targets = ['rules', 'audience', 'summary', 'waves'];
    static values = { options: Object, effective: Array };

    connect() {
        this.waves = this.parse(this.rulesTarget.value) ?? this.effectiveValue.map((w) => ({ ...w }));
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

    preset({ params: { id } }) {
        const preset = (this.optionsValue.presets || []).find((p) => p.id === id);
        if (!preset) return;
        this.waves = preset.rules.map((w) => ({ ...w, groups: [...w.groups], users: [...w.users] }));
        if (this.hasAudienceTarget) this.audienceTarget.value = id;
        this.commit(false);
    }

    add() {
        this.waves.push({ delay: this.waves.length ? 60 : 0, all: false, groups: [], users: [] });
        this.render();
    }

    // Правка волн: правила становятся своими.
    changed() {
        if (this.hasAudienceTarget) this.audienceTarget.value = '';
        this.commit(true);
    }

    commit(own) {
        const clean = this.waves.filter((w) => w.all || w.groups.length || w.users.length);
        this.rulesTarget.value = clean.length ? JSON.stringify(clean) : '';
        this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        if (!own) this.render();
        else this.paintSummary();
    }

    render() {
        const box = this.wavesTarget;
        box.replaceChildren(...this.waves.map((w, i) => this.row(w, i)));
        this.paintSummary();
    }

    paintSummary() {
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary(this.waves);
        const current = this.hasAudienceTarget ? this.audienceTarget.value : '';
        this.element.querySelectorAll('[data-audience-mark]').forEach((m) => m.toggleAttribute('hidden', m.dataset.audienceMark !== String(current)));
    }

    row(w, i) {
        const row = el('div', 'box-nested flex flex-col gap-2');
        const head = el('div', 'flex items-center gap-2');
        const delay = el('select', 'field-input field-s w-auto');
        delay.setAttribute('aria-label', 'Когда');
        for (const [v, label] of DELAYS) delay.append(new Option(label, v, false, String(w.delay ?? '') === String(v)));
        delay.addEventListener('change', () => { w.delay = delay.value === '' ? null : Number(delay.value); this.changed(); });
        const drop = el('button', 'sheet-close ml-auto');
        drop.type = 'button';
        drop.setAttribute('aria-label', 'Убрать волну');
        drop.textContent = '×';
        drop.addEventListener('click', () => { this.waves.splice(i, 1); this.render(); this.changed(); });
        head.append(delay, drop);

        const chips = el('div', 'flex flex-wrap items-center gap-1.5');
        const chip = (text, remove) => {
            const c = el('button', 'chip');
            c.type = 'button';
            c.textContent = `${text} ×`;
            c.addEventListener('click', () => { remove(); this.render(); this.changed(); });
            return c;
        };
        if (w.all) chips.append(chip('Все', () => { w.all = false; }));
        w.groups.forEach((id) => chips.append(chip(this.groupName(id), () => { w.groups = w.groups.filter((g) => g !== id); })));
        w.users.forEach((id) => chips.append(chip(this.userName(id), () => { w.users = w.users.filter((u) => u !== id); })));

        const pick = el('select', 'choice-input w-auto');
        pick.setAttribute('aria-label', 'Добавить');
        pick.append(new Option('+ Кому', '', true, true));
        if (!w.all) pick.append(new Option('Все', 'all'));
        const groups = (this.optionsValue.groups || []).filter((g) => !w.groups.includes(g.id));
        if (groups.length) {
            const og = document.createElement('optgroup');
            og.label = 'Группы';
            groups.forEach((g) => og.append(new Option(g.name, `g${g.id}`)));
            pick.append(og);
        }
        const people = (this.optionsValue.managers || []).filter((m) => !w.users.includes(m.id));
        if (people.length) {
            const og = document.createElement('optgroup');
            og.label = 'Менеджеры';
            people.forEach((m) => og.append(new Option(m.name, `u${m.id}`)));
            pick.append(og);
        }
        pick.addEventListener('change', () => {
            const v = pick.value;
            if (v === 'all') w.all = true;
            else if (v.startsWith('g')) w.groups.push(Number(v.slice(1)));
            else if (v.startsWith('u')) w.users.push(Number(v.slice(1)));
            this.render();
            this.changed();
        });
        chips.append(pick);
        row.append(head, chips);
        return row;
    }

    groupName(id) {
        return (this.optionsValue.groups || []).find((g) => g.id === id)?.name ?? 'Группа';
    }

    userName(id) {
        return (this.optionsValue.managers || []).find((m) => m.id === id)?.name ?? 'Менеджер';
    }

    when(delay) {
        if (delay === null || delay === undefined) return 'не видят';
        if (delay === 0) return 'сразу';
        if (delay < 60) return `через ${delay} мин`;
        if (delay < 1440) return `через ${Math.round(delay / 60)} ч`;
        return `через ${Math.round(delay / 1440)} д`;
    }

    summary(waves) {
        const parts = waves.map((w) => {
            const who = w.all ? ['все'] : [...w.groups.map((g) => this.groupName(g)), ...w.users.map((u) => this.userName(u))];
            return who.length ? `${who.join(', ')} ${this.when(w.delay)}` : null;
        }).filter(Boolean);
        if (!parts.length) return 'Как у вендора';
        const text = parts.join(', ');
        return text[0].toUpperCase() + text.slice(1);
    }
}

function el(tag, cls) {
    const e = document.createElement(tag);
    e.className = cls;
    return e;
}
