import { Controller } from '@hotwired/stimulus';

// «Кому показывать» лентой волн (`x-audience.editor`, 15.11.2026, владелец: «невозможно одному менеджеру 5 дней, потом
// остальным», «сначала выбранным 5 дней, потом всем, кроме — 3 дня, потом всем до такого-то числа»). Волна — срок от
// предыдущей («Через 5 дней», «Ещё через 3 дня») и кому: «Всем» (с «кроме») или выбранным группам и менеджерам. Видевший
// видит и дальше: «Всем» — все, кто ещё не видит. Новая волна спрашивает «Кому?» тремя кнопками «Всем | Всем, кроме |
// Выбранным» — выбор только тут, в готовой волне переключателя нет. Конец ленты — поле конца приёма (слот `end`).
//
// Хранится по-прежнему строками «кто → через сколько минут от выхода» (AudienceRules: null — не видит, rest — остальные):
// волны — только вид. `decode` — строки в волны при открытии, `encode` — обратно при каждой правке; итог — JSON в
// скрытом поле и change с него (save_bar показывает «Сохранить изменения»); не трогали — поле как было (пустое — «как у
// вендора»). Сводка — те же слова, что AudienceRules::summary. deal — публикация машины в сделке: супер-покупателей нет.
export default class extends Controller {
    static targets = ['rules', 'summary', 'body', 'pick', 'search', 'option', 'icons', 'pickTitle', 'end'];
    static values = { options: Object, effective: Array, deal: Boolean, base: String };

    connect() {
        this.reset();
        // Сохранили — POST и редирект на ту же страницу Turbo делает морфом: контроллер не переподключается, ленту, что
        // рисовал он, морф стёр — перечитать пришедшее и нарисовать заново. Морф посреди выбора (живое обновление) не теряет
        // несохранённое: возвращаем его в поле.
        this.onMorph = () => {
            const mine = JSON.stringify(this.encode());
            const server = JSON.stringify(this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue ?? []));
            if (this.edited && server !== mine) {
                this.rulesTarget.value = mine;
                this.render();
            } else this.reset();
        };
        document.addEventListener('turbo:morph', this.onMorph);
        // Конец приёма сменили — волны позже него краснеют.
        this.onEnd = () => this.render();
        if (this.hasEndTarget) this.endTarget.addEventListener('change', this.onEnd);
    }

    disconnect() {
        document.removeEventListener('turbo:morph', this.onMorph);
        if (this.hasEndTarget) this.endTarget.removeEventListener('change', this.onEnd);
    }

    reset() {
        // Значение с сервера: у скрытого поля присвоение value меняет и defaultValue — сравнивать не с чем.
        this.initial = this.rulesTarget.value;
        this.waves = this.decode(this.clean(this.parse(this.rulesTarget.value) ?? this.effectiveValue ?? []));
        this.picking = null; // { wave, mode: 'who' | 'except' }
        this.asking = this.waves.findIndex((w) => !w.kind);
        this.edited = false;
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

    // «Остальные» ровно одна и в конце; супер-покупателей у публикации сделки нет.
    clean(rows) {
        const own = rows.filter((r) => r.type !== 'rest' && !(this.dealValue && r.type === 'super'))
            .map((r) => ({ type: r.type, id: Number(r.id), delay: r.delay ?? null }));
        const rest = rows.find((r) => r.type === 'rest');
        return [...own, { type: 'rest', id: null, delay: rest ? rest.delay ?? null : 0 }];
    }

    // Строки → волны. Каждое время — волна: раньше «остальных» — выбранные; время «остальных» — «Всем», «кроме» — кто позже
    // или никогда; позже — выбранные из тех, кого исключили, или «Всем», если после неё не осталось никого. Строки со временем
    // «остальных» (эксклюзив за оценку так пишет) и «никогда» без «остальных» (прежнее «Выбранным, кроме») — едут как были.
    decode(rows) {
        const rest = rows[rows.length - 1].delay;
        const own = rows.slice(0, -1);
        this.never = rest === null ? own.filter((r) => r.delay === null) : [];
        const times = [...new Set([...own.filter((r) => r.delay !== null).map((r) => r.delay), ...(rest === null ? [] : [rest])])].sort((a, b) => a - b);
        const waves = times.map((d) => {
            const at = own.filter((r) => r.delay === d && !(rest !== null && d === rest));
            if (rest !== null && d === rest) {
                return { delay: d, kind: 'all', who: [], except: own.filter((r) => r.delay === null || r.delay > rest).map(key), carry: own.filter((r) => r.delay === d) };
            }
            if (rest !== null && d > rest && !own.some((r) => r.delay === null || r.delay > d)) return { delay: d, kind: 'all', who: [], except: [], carry: [] };
            return { delay: d, kind: 'only', who: at.map(key), except: [], carry: [] };
        });
        return waves.length ? waves : [{ delay: 0, kind: null, who: [], except: [], carry: [] }];
    }

    // Волны → строки (см. decode). Исключили группу, а кто-то из неё уже видит по прежней волне, — ему своя строка с тем
    // временем: «не показывать» у группы сильнее времени у другой (AudienceRules::openings).
    encode() {
        const rows = new Map();
        const pending = new Set();
        let rest = null;
        const seenAt = new Map(); // user:id → когда видит по группе прежней волны
        for (const w of this.waves) {
            if (w.kind === 'only') {
                for (const k of w.who) {
                    if (!rows.has(k) || pending.has(k)) {
                        rows.set(k, w.delay);
                        pending.delete(k);
                    }
                    if (k.startsWith('group:')) for (const u of this.members(k)) if (!seenAt.has(u)) seenAt.set(u, w.delay);
                }
            } else if (w.kind === 'all' && rest === null) {
                rest = w.delay;
                for (const k of w.except) {
                    if (rows.has(k)) continue;
                    rows.set(k, null);
                    pending.add(k);
                    if (k.startsWith('group:')) for (const u of this.members(k)) if (seenAt.has(u) && !rows.has(u)) rows.set(u, seenAt.get(u));
                }
                for (const r of w.carry) if (!rows.has(key(r))) rows.set(key(r), w.delay);
            } else if (w.kind === 'all') {
                for (const k of [...pending]) {
                    if (w.except.includes(k)) continue;
                    rows.set(k, w.delay);
                    pending.delete(k);
                }
            }
        }
        for (const r of this.never) if (!rows.has(key(r))) rows.set(key(r), null);
        const own = [...rows].map(([k, delay]) => {
            const [type, id] = k.split(':');
            return { type, id: Number(id), delay };
        });
        return [...own, { type: 'rest', id: null, delay: rest }];
    }

    members(k) {
        const id = Number(k.split(':')[1]);
        return ((this.optionsValue.members || {})[id] || []).map((u) => `user:${u}`);
    }

    // Кого волна i может назвать: кто к ней ещё не видит. После первой «Всем» — только исключённые и ещё не открытые; до неё —
    // все, кроме названных раньше (и людей из названных раньше групп). Своих отмеченных волна видит всегда.
    candidates(i) {
        const seen = new Set();
        const pending = new Set();
        let all = false;
        for (const w of this.waves.slice(0, i)) {
            if (w.kind === 'only') {
                for (const k of w.who) {
                    seen.add(k);
                    pending.delete(k);
                    if (k.startsWith('group:')) this.members(k).forEach((u) => seen.add(u));
                }
            } else if (w.kind === 'all' && !all) {
                all = true;
                w.except.filter((k) => !seen.has(k)).forEach((k) => pending.add(k));
            } else if (w.kind === 'all') {
                for (const k of [...pending]) if (!w.except.includes(k)) pending.delete(k);
            }
        }
        const own = new Set([...this.waves[i].who, ...this.waves[i].except]);
        return (k) => own.has(k) || (all ? pending.has(k) : !seen.has(k));
    }

    // После первой «Всем» без «кроме» видят все — новой волне некого назвать.
    get everyone() {
        const i = this.waves.findIndex((w) => w.kind === 'all');
        if (i < 0) return false;
        const after = this.waves.slice(i);
        let pending = new Set(after[0].except);
        for (const w of after.slice(1)) {
            if (w.kind === 'only') w.who.forEach((k) => pending.delete(k));
            if (w.kind === 'all') pending = new Set([...pending].filter((k) => w.except.includes(k)));
        }
        return pending.size === 0;
    }

    // «Добавить волну» — на ленту сразу, с вопросом «Кому?»: через день после последней; а если последняя — «Всем» и после неё
    // все видят, новая встаёт перед ней на её место, «Всем» — на день позже («Сразу: Всем» → «Сразу: Кому?», «Через 1 день:
    // Всем» — так собирают «сначала Роману, потом всем»).
    addWave() {
        const last = this.waves[this.waves.length - 1];
        const blank = { kind: null, who: [], except: [], carry: [] };
        if (this.everyone && last.kind === 'all' && !last.except.length) {
            const at = this.waves.length - 1;
            this.waves.splice(at, 0, { ...blank, delay: last.delay });
            last.delay += DAY;
            this.asking = at;
        } else {
            this.waves.push({ ...blank, delay: last.delay + DAY });
            this.asking = this.waves.length - 1;
        }
        this.picking = null;
        this.render();
    }

    removeWave(i) {
        this.waves.splice(i, 1);
        if (this.asking === i) this.asking = -1;
        else if (this.asking > i) this.asking -= 1;
        this.picking = null;
        if (!this.waves.length) this.waves = [{ delay: 0, kind: null, who: [], except: [], carry: [] }];
        this.commit();
    }

    // Ответ на «Кому?»: «Всем» — готово; «Всем, кроме» — сразу список, кому не показывать; «Выбранным» — список, кому.
    // Единственная волна стала выбранными — следом «Через 1 день — Всем»: иначе остальные не увидят никогда (видно, ×).
    answer(i, kind) {
        const w = this.waves[i];
        const was = w.kind;
        if (kind === 'only') {
            if (was !== 'only') Object.assign(w, { kind: 'only', who: [], except: [], carry: [] });
            if (this.waves.length === 1) this.waves.push({ delay: w.delay + DAY, kind: 'all', who: [], except: [], carry: [] });
            this.picking = { wave: i, mode: 'who' };
        } else {
            if (was !== 'all') Object.assign(w, { kind: 'all', who: [], except: [], carry: [] });
            this.picking = kind === 'except' ? { wave: i, mode: 'except' } : null;
        }
        this.asking = kind === 'all' ? -1 : i;
        this.commit();
        if (this.picking) this.focusPick();
    }

    // Время волны: у первой — от выхода, у остальных — от предыдущей; сдвиг двигает и все следующие.
    setDelay(i, value) {
        const shift = value - (this.waves[i].delay - (i ? this.waves[i - 1].delay : 0));
        for (const w of this.waves.slice(i)) w.delay += shift;
        this.commit();
    }

    openPick(i, mode) {
        this.picking = { wave: i, mode };
        this.render();
        this.focusPick();
    }

    focusPick() {
        this.pickTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        if (this.hasSearchTarget && matchMedia('(hover: hover)').matches) this.searchTarget.focus({ preventScroll: true });
    }

    // «Готово» списка: выбранным никого не отметили — волна снова спрашивает «Кому?».
    close() {
        const p = this.picking;
        this.picking = null;
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this.filter();
        if (p) {
            const w = this.waves[p.wave];
            if (w.kind === 'only' && !w.who.length) w.kind = null;
            this.asking = w.kind ? -1 : p.wave;
        }
        this.commit();
    }

    // Enter в поиске — отметить первого найденного, а не отправить форму вокруг (шторка публикации, переоценка, вендор).
    enter(e) {
        e.preventDefault();
        const first = this.optionTargets.find((o) => !o.hidden);
        first?.querySelector('input').click();
    }

    // Поиск (12.11.2026, начальник не нашёл «Артем» — в имени «ё»): без «ё» и по словам — каждое слово запроса где-то в имени
    // («матевосян артем», «арт мат»); имена в `data-name` уже такие же (`x-audience.editor`). Кто волне недоступен — скрыт.
    filter() {
        this.narrow();
        this.optionTargets[0]?.parentElement?.scrollTo?.({ top: 0 });
    }

    narrow() {
        const words = this.hasSearchTarget ? norm(this.searchTarget.value).split(/\s+/).filter(Boolean) : [];
        const allowed = this.picking ? this.candidates(this.picking.wave) : () => true;
        this.optionTargets.forEach((o) => { o.hidden = !allowed(o.dataset.key) || words.some((w) => !o.dataset.name.includes(w)); });
    }

    toggle(e) {
        if (!this.picking) return;
        const k = e.target.closest('[data-key]').dataset.key;
        const w = this.waves[this.picking.wave];
        const list = this.picking.mode === 'except' ? w.except : w.who;
        const at = list.indexOf(k);
        if (at >= 0) list.splice(at, 1);
        else list.push(k);
        this.commit();
    }

    drop(i, list, k) {
        const w = this.waves[i];
        w[list] = w[list].filter((x) => x !== k);
        if (w.kind === 'only' && !w.who.length && !(this.picking && this.picking.wave === i)) {
            w.kind = null;
            this.asking = i;
        }
        this.commit();
    }

    commit() {
        this.edited = true;
        const value = JSON.stringify(this.encode());
        if (this.rulesTarget.value !== value) {
            this.rulesTarget.value = value;
            this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.render();
    }

    // «Сохранить» в шторке строки-сводки: та же кнопка «Сохранить изменения» формы (у карточки строки она шлёт `_fields[]`),
    // а без неё — обычная отправка. Ничего не тронуто — save_bar_controller ничего не шлёт.
    save() {
        const form = this.rulesTarget.form;
        if (!form) return;
        if (this.rulesTarget.value !== this.initial) this.rulesTarget.dispatchEvent(new Event('change', { bubbles: true }));
        const button = [...form.elements].find((b) => b.matches('[data-save-bar-button]'));
        form.requestSubmit(button);
    }

    render() {
        // Список выбора живёт в волне, которую перерисовываем, — сначала вынуть его на место, иначе он уйдёт вместе с ней.
        const pick = this.pickTarget;
        this.bodyTarget.parentElement.append(pick);
        const parts = this.waves.map((w, i) => this.wave(w, i));
        if (this.waves.every((w) => w.kind) && this.asking < 0 && !this.picking) {
            const item = el('div', 'wave wave-add');
            const b = el('button', 'wave-add-btn');
            b.type = 'button';
            b.append(this.icon('plus'), 'Добавить волну');
            b.addEventListener('click', () => this.addWave());
            item.append(b);
            parts.push(item);
        }
        this.bodyTarget.replaceChildren(...parts);
        pick.hidden = !this.picking;
        if (this.picking) {
            this.narrow();
            const w = this.waves[this.picking.wave];
            const has = new Set(this.picking.mode === 'except' ? w.except : w.who);
            this.optionTargets.forEach((o) => { o.querySelector('input').checked = has.has(o.dataset.key); });
            const title = this.picking.mode === 'except' ? 'Кому не показывать' : 'Кому показывать';
            if (this.hasPickTitleTarget) this.pickTitleTarget.textContent = title;
            if (this.hasSearchTarget) this.searchTarget.placeholder = title;
            this.stick();
        }
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary();
    }

    wave(w, i) {
        const item = el('div', 'wave');
        item.append(el('span', 'wave-dot'));
        const head = el('div', 'wave-head');
        head.append(this.when(w, i));
        const at = this.startsAt(w);
        if (at?.text) {
            const date = el('span', 'wave-date');
            date.textContent = at.text;
            head.append(date);
        }
        if (this.waves.length > 1) {
            const x = el('button', 'wave-x');
            x.type = 'button';
            x.setAttribute('aria-label', 'Убрать волну');
            x.append(this.icon('x'));
            x.addEventListener('click', () => this.removeWave(i));
            head.append(x);
        }
        item.append(head);
        if (at?.late) {
            const late = el('p', 'wave-late');
            late.textContent = 'Начнётся после конца приёма';
            item.append(late);
        }
        if (!w.kind || this.asking === i) item.append(this.ask(w, i));
        if (w.kind === 'only') item.append(this.chips(i, 'who', w.who, null, 'Добавить'));
        // «Всем ⌄ кроме Иван × + Ещё» — одной строкой; нажатие на «Всем» — снова «Кому?».
        if (w.kind === 'all') {
            const row = w.except.length ? this.chips(i, 'except', w.except, 'кроме', 'Ещё') : this.chips(i, 'except', [], null, 'Кроме…');
            if (this.asking !== i) {
                const all = el('button', 'wave-all');
                all.type = 'button';
                all.append('Всем', this.icon('chevron'));
                all.addEventListener('click', () => { this.asking = i; this.picking = null; this.render(); });
                row.prepend(all);
            }
            item.append(row);
        }
        if (this.picking && this.picking.wave === i) item.append(this.pickTarget);
        return item;
    }

    // «Кому?» — три кнопки; выбранный ответ (пока открыт его список) — подсвечен.
    ask(w, i) {
        const box = el('div', 'wave-ask');
        if (!w.kind) {
            const q = el('span', 'wave-q');
            q.textContent = 'Кому?';
            box.append(q);
        }
        const row = el('div', 'wave-ask-row');
        const now = this.picking && this.picking.wave === i ? (this.picking.mode === 'except' ? 'except' : 'only') : null;
        for (const [kind, label] of [['all', 'Всем'], ['except', 'Всем, кроме'], ['only', 'Выбранным']]) {
            const b = el('button', 'wave-ask-btn');
            b.type = 'button';
            b.textContent = label;
            b.setAttribute('aria-pressed', String(now === kind));
            b.addEventListener('click', () => this.answer(i, kind));
            row.append(b);
        }
        box.append(row);
        return box;
    }

    // Пилюля срока — системный выбор: у первой «Сразу» и «Через N» от выхода, у следующих — от предыдущей
    // («Через 5 дней», «Ещё через 3 дня»).
    when(w, i) {
        const span = w.delay - (i ? this.waves[i - 1].delay : 0);
        if (i === 0 && span === 0) {
            const t = el('span', 'wave-now');
            t.textContent = 'Сразу';
            return t;
        }
        const prefix = i > 0 && this.waves[i - 1].delay > 0 ? 'Ещё через ' : 'Через ';
        const sel = el('select', 'wave-when');
        sel.setAttribute('aria-label', 'Когда волна');
        const steps = [...(i === 0 ? [0] : []), ...STEPS];
        if (!steps.includes(span)) steps.push(span);
        steps.sort((a, b) => a - b);
        for (const m of steps) sel.append(new Option(m === 0 ? 'Сразу' : prefix + span_words(m), m, false, m === span));
        sel.addEventListener('change', () => this.setDelay(i, Number(sel.value)));
        return sel;
    }

    // Когда волна начнётся датой — только если выход известен (открытое, публикация сделки идёт); позже конца — красным.
    startsAt(w) {
        const end = this.endAt();
        const base = this.baseValue ? new Date(this.baseValue) : null;
        if (!base && !end) return null;
        const start = new Date((base ?? new Date()).getTime() + w.delay * 60000);
        return { text: base && w.delay ? dateText(start) : null, late: !!end && start >= end };
    }

    endAt() {
        if (!this.hasEndTarget) return null;
        const v = this.endTarget.querySelector('input[type=hidden][name]')?.value;
        if (!v) return null;
        const d = new Date(v.length === 16 ? `${v}:00+03:00` : v);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    chips(i, list, keys, lead, more) {
        const row = el('div', 'wave-chips');
        if (lead) {
            const t = el('span', 'wave-lead');
            t.textContent = lead;
            row.append(t);
        }
        for (const k of keys) {
            const chip = el('button', 'chip wave-chip');
            chip.type = 'button';
            chip.setAttribute('aria-label', `Убрать: ${this.name(k)}`);
            chip.append(this.name(k) ?? '—', this.icon('x'));
            chip.addEventListener('click', () => this.drop(i, list, k));
            row.append(chip);
        }
        const open = this.picking && this.picking.wave === i && this.picking.mode === list;
        if (!open && !(this.asking === i)) {
            const add = el('button', 'wave-more');
            add.type = 'button';
            add.append(this.icon('plus'), more);
            add.addEventListener('click', () => this.openPick(i, list));
            row.append(add);
        }
        return row;
    }

    // В шторке поиск прилипает под её заголовком: тот тоже липкий (`.sheet-bar`) и бывает в две строки.
    stick() {
        const sheet = this.element.closest('dialog.sheet');
        const bar = sheet?.querySelector(':scope > .sheet-bar');
        const head = this.pickTarget.querySelector('.audience-pick-head');
        if (!bar || !head) return;
        head.style.top = `${bar.offsetHeight - parseFloat(getComputedStyle(sheet).paddingTop)}px`;
    }

    icon(name) {
        return this.iconsTarget.content.querySelector(`[data-icon="${name}"]`).cloneNode(true);
    }

    // Как AudienceRules::summary: кого нет в выборе (уже не менеджер, группа другого вида) — по `names` с сервера.
    name(k) {
        const [type, id] = k.split(':');
        if (type === 'super') return this.optionsValue.super || 'Супер-покупатели';
        const list = type === 'group' ? this.optionsValue.groups : this.optionsValue.managers;
        return (list || []).find((x) => x.id === Number(id))?.name ?? this.optionsValue.names?.[k] ?? null;
    }

    // Те же слова, что AudienceRules::summary: по времени, «остальные» на своём месте, «кроме» — кто не видит никогда.
    summary() {
        const rows = this.encode();
        const rest = rows[rows.length - 1];
        const own = rows.slice(0, -1).filter((r) => this.name(key(r)) !== null);
        const shown = own.filter((r) => r.delay !== null);
        const hidden = own.filter((r) => r.delay === null);
        const words = (list) => list.map((r) => this.name(key(r))).join(', ');
        if (rest.delay === null) {
            if (!shown.length) return 'Никому';
            if (shown.every((r) => r.delay === 0)) return `Только ${words(shown)}`;
            return `Только ${[...shown].sort((a, b) => a.delay - b.delay).map((r) => `${this.name(key(r))} ${whenWords(r.delay)}`).join(', ')}`;
        }
        const except = hidden.length ? `, кроме ${words(hidden)}` : '';
        if (!shown.length) return `Всем${rest.delay === 0 && except ? '' : ` ${whenWords(rest.delay)}`}${except}`;
        const items = [...shown.map((r) => ({ name: this.name(key(r)), delay: r.delay, rest: false })), { name: 'остальные', delay: rest.delay, rest: true }]
            .sort((a, b) => a.delay - b.delay || a.rest - b.rest);
        const text = `${items.map((x) => `${x.name} ${whenWords(x.delay)}`).join(', ')}${except}`;
        return text[0].toUpperCase() + text.slice(1);
    }
}

const DAY = 1440;
// Сроки волны: часы и дни; своё время (эксклюзив за оценку, прежние правила) встаёт в список само.
const STEPS = [60, 120, 180, 360, 720, 1440, 2880, 4320, 5760, 7200, 8640, 10080, 14400, 20160];
const norm = (s) => s.toLowerCase().replaceAll('ё', 'е').trim();
const key = (r) => `${r.type}:${r.id}`;

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

// «5 дней», «1 день 6 часов», «30 минут» — как AudienceRules::span.
function span_words(m) {
    const d = Math.floor(m / DAY);
    const h = Math.floor((m % DAY) / 60);
    const min = m % 60;
    const parts = [];
    if (d) parts.push(`${d} ${plural(d, ['день', 'дня', 'дней'])}`);
    if (h) parts.push(`${h} ${plural(h, ['час', 'часа', 'часов'])}`);
    if (min) parts.push(`${min} ${plural(min, ['минуту', 'минуты', 'минут'])}`);
    return parts.join(' ');
}

const whenWords = (m) => (m === null ? 'не показывать' : m === 0 ? 'сразу' : `через ${span_words(m)}`);

// «20.11 17:00» по Москве, как Ago::at.
function dateText(d) {
    const f = new Intl.DateTimeFormat('ru-RU', { timeZone: 'Europe/Moscow', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const p = Object.fromEntries(f.formatToParts(d).map((x) => [x.type, x.value]));
    return `${p.day}.${p.month} ${p.hour}:${p.minute}`;
}
