import { Controller } from '@hotwired/stimulus';
import { floorFrom } from '../lib/floor';
import { nums, rub, rubles } from '../lib/money';

// Шкала наценки (x-offer.floor-rules, 08.11.2026; начальник: «шкалы красивые, динамичные»). Состояние — ступени
// [до, ₽, %] (последняя — «дальше», до = null), из них рисуются полоса (сегмент на диапазон, ширина по корню от суммы:
// нижние узкие диапазоны не схлопываются), подписи границ, ручки и правка выбранного сегмента. Ручку тянут мышью и пальцем
// (шаг круглый: до 1 млн — 10 000, до 5 млн — 50 000, дальше — 100 000; за соседей не заходит) и стрелками. Метка оценочной
// — по полю `value-floor` рядом (`data-value-floor-target="value"`): едет по полосе, её сегмент горит. Каждая правка — в
// скрытое поле JSON, `input` (для «Сохранить изменения») и `floor-rules:change` (пересчёт закупочной), как раньше.

const STEP = (v) => (v < 1_000_000 ? 10_000 : v < 5_000_000 ? 50_000 : 100_000);
const snap = (v) => Math.max(STEP(v), Math.round(v / STEP(v)) * STEP(v));
const short = (v) => (v >= 1_000_000 ? `${String(+(v / 1_000_000).toFixed(2)).replace('.', ',')} млн` : v >= 1000 ? `${Math.round(v / 1000)} тыс` : String(v));
// В сегменте — коротко («+50 тыс», «+10 %»): узкий нижний диапазон иначе остался бы без подписи.
const label = ([, add, pct]) => (pct ? `+${String(pct).replace('.', ',')} %` : add ? `+${short(add)}` : '+0');

export default class extends Controller {
    static targets = ['input', 'reset', 'track', 'bar', 'edges', 'pin', 'pinText', 'editor', 'below', 'belowBox', 'amount', 'unit', 'remove'];
    static values = { rules: Array, default: Array };

    connect() {
        this.selected = null;
        this.value = this.findValue();
        this.onValue = () => { this.value = rubles(this.valueInput.value) ?? 0; this.max = null; this.draw(); };
        this.valueInput?.addEventListener('input', this.onValue);
        this.resize = new ResizeObserver(() => this.draw());
        this.resize.observe(this.barTarget);
        // Место под метку — только где есть оценочная (у вендора её нет): иначе пустая полоса над шкалой.
        this.trackTarget.classList.toggle('has-pin', !!this.valueInput);
        this.draw();
    }

    disconnect() {
        this.valueInput?.removeEventListener('input', this.onValue);
        this.resize?.disconnect();
    }

    // Поле оценочной рядом (`value-floor`): в редакторе партнёра и в «Ценах» CRM. У шторки вендора его нет — метки нет.
    findValue() {
        const host = this.element.closest('[data-controller~="value-floor"]');
        this.valueInput = host?.querySelector('[data-value-floor-target="value"]') ?? null;
        return this.valueInput ? (rubles(this.valueInput.value) ?? 0) : 0;
    }

    get rules() { return this.rulesValue; }

    // Шкала: 0 … max — последняя граница с запасом, и оценочная влезает. Пока тянут ручку, max не меняется: полоса не ездит.
    scale() {
        if (!this.max) {
            const edges = this.rules.slice(0, -1).map(([b]) => b);
            this.max = Math.max((edges.at(-1) ?? 0) * 2.2, this.value * 1.15, 1_000_000);
        }
        return this.max;
    }

    at(v) { return Math.min(1, Math.sqrt(Math.max(0, v) / this.scale())); }

    from(f) { return Math.min(1, Math.max(0, f)) ** 2 * this.scale(); }

    current() {
        if (!this.value) return null;
        const i = this.rules.findIndex(([b]) => b === null || this.value < b);
        return i < 0 ? this.rules.length - 1 : i;
    }

    draw() {
        const rules = this.rules;
        const edges = [0, ...rules.slice(0, -1).map(([b]) => b), null];
        const width = this.barTarget.clientWidth || 300;
        const on = this.current();
        // Правится сегмент, где лежит оценочная, пока сегмент не выбрали нажатием или ручкой.
        if (!this.picked || this.selected === null || this.selected >= rules.length) this.selected = on ?? (this.selected ?? 0);
        if (this.selected >= rules.length) this.selected = rules.length - 1;

        this.barTarget.replaceChildren(...rules.map((r, i) => {
            const left = this.at(edges[i]);
            const right = edges[i + 1] === null ? 1 : this.at(edges[i + 1]);
            const seg = document.createElement('button');
            seg.type = 'button';
            seg.className = 'floor-scale-seg';
            seg.style.left = `${left * 100}%`;
            seg.style.width = `${(right - left) * 100}%`;
            seg.classList.toggle('is-on', i === on);
            seg.classList.toggle('is-picked', i === this.selected);
            seg.dataset.index = i;
            seg.dataset.action = 'floor-rules#pick';
            const text = label(r);
            seg.setAttribute('aria-label', `${i === rules.length - 1 ? 'Дальше' : `До ${nums(r[0])} ₽`}: ${r[2] ? text : `+${nums(r[1])} ₽`}`);
            if ((right - left) * width > text.length * 7 + 16) seg.textContent = text;
            return seg;
        }), ...rules.slice(0, -1).map(([b], i) => {
            const knob = document.createElement('span');
            knob.className = 'floor-scale-knob';
            knob.style.left = `${this.at(b) * 100}%`;
            knob.tabIndex = 0;
            knob.role = 'slider';
            knob.dataset.index = i;
            knob.setAttribute('aria-label', 'Граница диапазона');
            knob.setAttribute('aria-valuenow', b);
            knob.dataset.action = 'pointerdown->floor-rules#grab keydown->floor-rules#nudge';
            return knob;
        }));

        // Подписи границ, что легли бы друг на друга (оценочная далеко за последней границей), — через одну.
        let shown = -Infinity;
        this.edgesTarget.replaceChildren(...edges.slice(1, -1).map((b) => {
            const s = document.createElement('span');
            s.style.left = `${this.at(b) * 100}%`;
            s.textContent = short(b);
            const x = this.at(b) * width;
            if (x - shown < 56) s.hidden = true; else shown = x;
            return s;
        }));

        this.pinTarget.hidden = !this.value;
        if (this.value) {
            this.pinTarget.style.left = `${this.at(this.value) * 100}%`;
            this.pinTextTarget.textContent = `${nums(this.value)} → ${rub(floorFrom(this.value, rules))}`;
            // Метка у края — текст внутрь полосы, а не за её край.
            const f = this.at(this.value);
            this.pinTarget.dataset.side = f < 0.2 ? 'start' : f > 0.8 ? 'end' : 'mid';
        }
        this.drawEditor();
    }

    drawEditor() {
        const i = this.selected;
        const r = this.rules[i];
        const last = i === this.rules.length - 1;
        this.belowBoxTarget.hidden = last;
        if (document.activeElement !== this.belowTarget) this.belowTarget.value = last ? '' : nums(r[0]);
        if (document.activeElement !== this.amountTarget) this.amountTarget.value = r[2] ? String(r[2]).replace('.', ',') : (r[1] ? nums(r[1]) : '0');
        this.unitTargets.forEach((u) => u.setAttribute('aria-pressed', String(u.dataset.unit === this.unit())));
        this.removeTarget.hidden = this.rules.length < 2;
    }

    pick(e) {
        this.selected = Number(e.currentTarget.dataset.index);
        this.picked = true;
        this.unitPick = null;
        this.draw();
    }

    // Ступени поменялись: проверка, как на сервере (`Pricing::clean`), поле, события.
    commit(rules) {
        const edges = rules.slice(0, -1).map(([b]) => b);
        if (edges.some((b, i) => !b || (i && b <= edges[i - 1])) || rules.some(([, , p]) => p > 100)) return false;
        this.rulesValue = rules;
        const json = JSON.stringify(rules);
        if (this.inputTarget.value !== json) {
            this.inputTarget.value = json;
            this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
            this.dispatch('change', { detail: { rules }, prefix: 'floor-rules' });
        }
        if (this.hasResetTarget) this.resetTarget.hidden = json === JSON.stringify(this.defaultValue);
        this.draw();
        return true;
    }

    copy() { return this.rules.map((r) => [...r]); }

    // Граница перетаскиванием: между соседями с шагом, max шкалы на время жеста стоит.
    grab(e) {
        const knob = e.currentTarget;
        const i = Number(knob.dataset.index);
        knob.setPointerCapture(e.pointerId);
        e.preventDefault();
        this.element.classList.add('is-dragging');
        this.selected = i;
        this.picked = true;
        this.unitPick = null;
        const box = this.barTarget.getBoundingClientRect();
        const move = (ev) => {
            const rules = this.copy();
            const lo = i ? rules[i - 1][0] : 0;
            const hi = rules[i + 1][0] ?? this.scale();
            const v = snap(this.from((ev.clientX - box.left) / box.width));
            const step = STEP(v);
            rules[i][0] = Math.min(Math.max(v, lo + step), hi - step);
            this.commit(rules);
        };
        const up = () => {
            knob.removeEventListener('pointermove', move);
            knob.removeEventListener('pointerup', up);
            knob.removeEventListener('pointercancel', up);
            this.element.classList.remove('is-dragging');
            this.max = null;
            this.draw();
            this.element.querySelector(`.floor-scale-knob[data-index="${i}"]`)?.focus({ preventScroll: true });
        };
        knob.addEventListener('pointermove', move);
        knob.addEventListener('pointerup', up);
        knob.addEventListener('pointercancel', up);
    }

    nudge(e) {
        const dir = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1 }[e.key];
        if (!dir) return;
        e.preventDefault();
        const i = Number(e.currentTarget.dataset.index);
        const rules = this.copy();
        rules[i][0] = snap(rules[i][0] + dir * STEP(rules[i][0]));
        this.selected = i;
        this.picked = true;
        this.commit(rules);
        this.element.querySelector(`.floor-scale-knob[data-index="${i}"]`)?.focus({ preventScroll: true });
    }

    setBelow() {
        const v = rubles(this.belowTarget.value);
        const r = this.copy();
        if (v) r[this.selected][0] = v;
        this.max = null;
        // Граница за соседней — не принять: поле вернётся к прежней.
        if (!v || !this.commit(r)) { this.belowTarget.blur(); this.draw(); }
    }

    // Вид наценки выбранного сегмента: из ступени (процент стоит — «%»), а только что переключённый — как выбрали, даже
    // пока число пустое.
    unit() {
        return this.unitPick ?? (this.rules[this.selected][2] ? 'pct' : 'rub');
    }

    setAmount() {
        const r = this.copy();
        const pct = this.unit() === 'pct';
        const raw = this.amountTarget.value.trim();
        r[this.selected][1] = pct ? 0 : (rubles(raw) ?? 0);
        r[this.selected][2] = pct ? Math.min(100, parseFloat(raw.replace(',', '.')) || 0) : 0;
        // Рубли — с разрядами на ходу; процент — как вписан.
        if (!pct && r[this.selected][1]) this.amountTarget.value = nums(r[this.selected][1]);
        this.commit(r);
    }

    // ₽ ↔ %: число наценки другого вида не переводится — встаёт пустым, его вписывают.
    setUnit(e) {
        const unit = e.currentTarget.dataset.unit;
        if (unit === this.unit()) return;
        this.unitPick = unit;
        const r = this.copy();
        r[this.selected][1] = 0;
        r[this.selected][2] = 0;
        this.commit(r);
        this.amountTarget.value = '';
        this.amountTarget.focus();
    }

    // Разделить выбранный сегмент пополам по круглой границе; половины — с той же наценкой.
    split() {
        this.unitPick = null;
        const r = this.copy();
        const i = this.selected;
        const lo = i ? r[i - 1][0] : 0;
        const hi = r[i][0] ?? Math.max(lo * 2, lo + 1_000_000);
        const mid = snap((lo + hi) / 2);
        if (mid <= lo || mid >= hi) return;
        r.splice(i, 0, [mid, r[i][1], r[i][2]]);
        this.max = null;
        this.commit(r);
    }

    // Убрать сегмент: его место забирает следующий; последний — предыдущий становится «дальше».
    remove() {
        this.unitPick = null;
        const r = this.copy();
        if (r.length < 2) return;
        const i = this.selected;
        if (i === r.length - 1) { r.splice(i, 1); r[i - 1][0] = null; } else r.splice(i, 1);
        this.selected = Math.min(i, r.length - 1);
        this.max = null;
        this.commit(r);
    }

    reset() {
        this.unitPick = null;
        this.max = null;
        this.selected = null;
        this.picked = false;
        this.commit(this.defaultValue.map((x) => [...x]));
    }
}
