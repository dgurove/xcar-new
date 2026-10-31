import { Controller } from '@hotwired/stimulus';
import { Viewport } from '../park/plan/viewport';
import { AISLE, L, MAX_N, W, aisle, bounds, box, magnet, newId, nextName, snap, spots } from '../park/plan/geometry';
import { request } from '../lib/http';
import { confirmSheet } from '../confirm';

// Редактор схемы парковки (31.10.2026, владелец: «двигать места, менять размер, добавлять, убирать»). Схема — прямые ряды
// (`geometry.js`, `App\Park\Plan`), рисуется здесь из JSON, сохраняется целиком (`PUT /yards/{id}/plan`, `SavePlan`).
// Нажали ряд — выбран: за тело его тащат (мышью — любой ряд сразу), ручка на конце — число мест, ручка сбоку — длина места,
// нажатие на место выбранного ряда — убрать или вернуть его. Ряд прилипает к краям и серединам соседних, видно проезд до
// соседнего ряда. Поля выбранного ряда — плашкой внизу. Отмена и повтор — в памяти. Место, где стоит ТС, не убрать.
export default class extends Controller {
    static targets = ['svg', 'panel', 'save', 'undo', 'redo', 'empty', 'name', 'from', 'n', 'w', 'l', 'dir', 'rev', 'remove'];
    static values = { rows: Array, taken: Object, rev: String, url: String, back: String };

    connect() {
        this.rows = this.rowsValue.map((r) => ({ ...r, holes: [...r.holes] }));
        // Занятые места — по ряду и порядку в нём: переименование ряда их не сдвигает (`SavePlan` переписывает место у ТС).
        this.taken = new Map();
        for (const r of this.rows) {
            for (const s of spots(r)) {
                if (this.takenValue[s.code] !== undefined) {
                    if (!this.taken.has(r.id)) this.taken.set(r.id, new Map());
                    this.taken.get(r.id).set(s.k, this.takenValue[s.code]);
                }
            }
        }
        this.past = [];
        this.future = [];
        this.selected = null;
        this.dirty = false;
        this.view = new Viewport(this.svgTarget, { onTap: (e) => this.tap(e), grab: (e) => this.grab(e), min: 4, onChange: () => this.draw() });
        this.view.reset(this.full());
        this.onKey = (e) => this.key(e);
        addEventListener('keydown', this.onKey);
        this.onLeave = (e) => this.leave(e);
        document.addEventListener('turbo:before-visit', this.onLeave);
        this.onUnload = (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } };
        addEventListener('beforeunload', this.onUnload);
        this.render();
    }

    disconnect() {
        this.view?.detach();
        removeEventListener('keydown', this.onKey);
        document.removeEventListener('turbo:before-visit', this.onLeave);
        removeEventListener('beforeunload', this.onUnload);
    }

    // Пределы холста: схема и поле вокруг; пустая — 40 × 30 м.
    full() {
        const b = bounds(this.rows);
        if (!b) return [-5, -5, 40, 30];
        const pad = 8;
        return [b.x0 - pad, b.y0 - pad, b.x1 - b.x0 + 2 * pad, b.y1 - b.y0 + 2 * pad];
    }

    row(id = this.selected) {
        return this.rows.find((r) => r.id === id);
    }

    // Последнее занятое место ряда по порядку (−1 — свободен): короче него ряд не сделать.
    lastTaken(id) {
        const t = this.taken.get(id);
        return t ? Math.max(...t.keys()) : -1;
    }

    // --- изменения и история ---

    snapshot() {
        return JSON.stringify(this.rows);
    }

    change(fn) {
        const before = this.snapshot();
        fn();
        if (this.snapshot() === before) return;
        this.commit(before);
    }

    commit(before) {
        this.past.push(before);
        if (this.past.length > 100) this.past.shift();
        this.future = [];
        this.dirty = true;
        this.view.bound(this.full());
        this.render();
    }

    undo() {
        if (!this.past.length) return;
        this.future.push(this.snapshot());
        this.rows = JSON.parse(this.past.pop());
        this.afterHistory();
    }

    redo() {
        if (!this.future.length) return;
        this.past.push(this.snapshot());
        this.rows = JSON.parse(this.future.pop());
        this.afterHistory();
    }

    afterHistory() {
        if (!this.row()) this.selected = null;
        this.dirty = true;
        this.view.bound(this.full());
        this.render();
    }

    // --- ряды ---

    add() {
        const b = bounds(this.rows);
        const c = this.view.center();
        const row = { id: newId(), name: nextName(this.rows), from: 1, n: 10, x: b ? snap(b.x0) : snap(c.x - 12.5), y: b ? snap(b.y1 + AISLE) : snap(c.y - L / 2), dir: 'h', w: W, l: L, holes: [], rev: false };
        this.change(() => this.rows.push(row));
        this.select(row.id);
        this.view.focus({ x: row.x, y: row.y, width: row.n * row.w, height: row.l }, 30);
    }

    duplicate() {
        const r = this.row();
        if (!r) return;
        const copy = { ...r, id: newId(), name: nextName(this.rows), holes: [...r.holes], ...(r.dir === 'h' ? { y: r.y + r.l + AISLE } : { x: r.x + r.l + AISLE }) };
        this.change(() => this.rows.push(copy));
        this.select(copy.id);
    }

    remove() {
        const r = this.row();
        if (!r) return;
        if (this.taken.has(r.id)) { this.say(`В ряду ${r.name || 'без буквы'} стоят машины, сначала переставьте их`); return; }
        this.change(() => { this.rows = this.rows.filter((x) => x !== r); });
        this.select(null);
    }

    turn() {
        const r = this.row();
        if (r) this.change(() => { r.dir = r.dir === 'h' ? 'v' : 'h'; });
    }

    reverse() {
        const r = this.row();
        if (r) this.change(() => { r.rev = !r.rev; });
    }

    // Поле плашки: буква, первый номер, мест, ширина и длина места.
    field(e) {
        const r = this.row();
        if (!r) return;
        const key = e.target.dataset.field;
        const raw = e.target.value.trim();
        this.change(() => {
            if (key === 'name') { r.name = raw.toUpperCase().replace(/[^\p{Lu}\d]/gu, '').slice(0, 3); return; }
            const v = Number(raw.replace(',', '.'));
            if (!Number.isFinite(v) || raw === '') return;
            if (key === 'from') r.from = Math.max(1, Math.min(9999, Math.round(v)));
            if (key === 'n') r.n = Math.max(this.lastTaken(r.id) + 1, 1, Math.min(MAX_N, Math.round(v)));
            if (key === 'w') r.w = Math.max(1.5, Math.min(8, snap(v)));
            if (key === 'l') r.l = Math.max(2.5, Math.min(25, snap(v)));
            r.holes = r.holes.filter((h) => h >= r.from && h < r.from + r.n);
        });
        if (e.type === 'change') this.fill();
    }

    toggleHole(r, s) {
        if (this.taken.get(r.id)?.has(s.k)) { this.say(`На месте ${s.code} стоит ${this.taken.get(r.id).get(s.k)}`); return; }
        if (!s.hole && r.holes.length >= r.n - 1) return;
        this.change(() => { r.holes = s.hole ? r.holes.filter((h) => h !== s.num) : [...r.holes, s.num]; });
    }

    select(id) {
        this.selected = id;
        this.render();
    }

    // --- указатель ---

    tap(e) {
        const g = e.target.closest?.('[data-row]');
        if (!g) { if (this.selected) this.select(null); return; }
        if (g.dataset.row !== this.selected) { this.select(g.dataset.row); return; }
        const k = e.target.closest('[data-k]')?.dataset.k;
        const r = this.row();
        if (k !== undefined && r) this.toggleHole(r, spots(r)[Number(k)]);
    }

    // Ручку и выбранный ряд тянет редактор (мышью — любой ряд), остальное — сдвиг холста.
    grab(e) {
        if (e.type !== 'pointerdown') return false;
        const handle = e.target.closest?.('[data-handle]');
        const g = e.target.closest?.('[data-row]');
        if (!handle && !(g && (g.dataset.row === this.selected || e.pointerType === 'mouse'))) return false;
        const id = handle ? this.selected : g.dataset.row;
        const wasSelected = id === this.selected;
        if (!wasSelected) this.select(id);
        const r = this.row(id);
        this.gesture = { kind: handle?.dataset.handle || 'move', start: this.view.point(e), x: e.clientX, y: e.clientY, orig: { ...r }, before: this.snapshot(), moved: false, wasSelected, target: e.target };
        this.svgTarget.setPointerCapture?.(e.pointerId);
        const move = (ev) => { if (ev.pointerId === e.pointerId) this.drag(ev); };
        const up = (ev) => {
            if (ev.pointerId !== e.pointerId) return;
            removeEventListener('pointermove', move);
            removeEventListener('pointerup', up);
            removeEventListener('pointercancel', up);
            this.drop(ev);
        };
        addEventListener('pointermove', move);
        addEventListener('pointerup', up);
        addEventListener('pointercancel', up);
        return true;
    }

    drag(e) {
        const g = this.gesture;
        if (!g.moved && Math.hypot(e.clientX - g.x, e.clientY - g.y) < 5) return;
        g.moved = true;
        const r = this.row();
        const p = this.view.point(e);
        const o = g.orig;
        if (g.kind === 'move') {
            Object.assign(r, { x: snap(o.x + p.x - g.start.x), y: snap(o.y + p.y - g.start.y) });
            const others = this.rows.filter((x) => x !== r);
            const m = magnet(r, others, Math.max(0.35, this.view.scale() * 10));
            r.x += m.dx;
            r.y += m.dy;
            this.guides = m.guides;
            this.gap = aisle(r, others);
        } else if (g.kind === 'count') {
            const along = r.dir === 'h' ? p.x - r.x : p.y - r.y;
            r.n = Math.max(1, this.lastTaken(r.id) + 1, Math.min(MAX_N, Math.round(along / r.w)));
            r.holes = o.holes.filter((h) => h >= r.from && h < r.from + r.n);
        } else if (g.kind === 'depth') {
            r.l = Math.max(2.5, Math.min(25, snap(r.dir === 'h' ? p.y - r.y : p.x - r.x)));
        }
        this.schedule();
    }

    drop(e) {
        const g = this.gesture;
        this.gesture = null;
        this.guides = null;
        this.gap = null;
        if (!g.moved) {
            // Нажатие без сдвига: на место уже выбранного ряда — убрать или вернуть его.
            const k = g.target.closest?.('[data-k]')?.dataset.k;
            const r = this.row();
            if (g.wasSelected && g.kind === 'move' && k !== undefined && r) this.toggleHole(r, spots(r)[Number(k)]);
            else this.render();
            return;
        }
        if (this.snapshot() !== g.before) this.commit(g.before);
        else this.render();
    }

    key(e) {
        if (e.target.closest?.('input, textarea, select, [contenteditable]')) return;
        const mod = e.metaKey || e.ctrlKey;
        if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) this.redo(); else this.undo(); return; }
        if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); this.redo(); return; }
        if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); this.duplicate(); return; }
        if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); this.save(); return; }
        const r = this.row();
        if (!r) return;
        if (e.key === 'Escape') { this.select(null); return; }
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.remove(); return; }
        const step = e.shiftKey ? 1 : 0.25;
        const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
        if (d) { e.preventDefault(); this.change(() => { r.x = snap(r.x + d[0]); r.y = snap(r.y + d[1]); }); }
    }

    zoomIn() { this.view.zoom(1.6); }
    zoomOut() { this.view.zoom(1 / 1.6); }
    fit() { this.view.fit(); }

    // --- сохранение ---

    async save() {
        if (!this.dirty || this.saving) return;
        this.saving = true;
        this.saveTarget.disabled = true;
        const r = await request(this.urlValue, { method: 'PUT', json: { rows: this.rows, rev: this.revValue }, statuses: [422] });
        this.saving = false;
        if (!r) { this.render(); return; }
        const data = await r.json().catch(() => ({}));
        if (r.status === 422) {
            this.say(Object.values(data.errors || {})[0]?.[0] || data.message || 'Не сохранилось');
            this.render();
            return;
        }
        this.revValue = data.rev;
        this.dirty = false;
        window.toast?.('Схема сохранена');
        this.render();
    }

    leave(e) {
        if (!this.dirty) return;
        e.preventDefault();
        const url = e.detail.url;
        confirmSheet('Схема не сохранена', { label: 'Уйти', cancel: 'Остаться', danger: true }).then((ok) => {
            if (!ok) return;
            this.dirty = false;
            window.Turbo?.visit(url);
        });
    }

    say(text) {
        window.toast?.(text, 'danger');
    }

    // --- рисование ---

    schedule() {
        if (this.frame) return;
        this.frame = requestAnimationFrame(() => { this.frame = null; this.draw(); });
    }

    render() {
        this.draw();
        this.fill();
        this.saveTarget.disabled = !this.dirty || this.saving;
        this.undoTarget.disabled = !this.past.length;
        this.redoTarget.disabled = !this.future.length;
        this.emptyTarget.hidden = this.rows.length > 0;
    }

    draw() {
        if (!this.view) return;
        const k = this.view.scale();
        const out = [];
        // Точки сетки — метровые, под всей видимой частью.
        out.push('<defs><pattern id="plan-grid" width="1" height="1" patternUnits="userSpaceOnUse"><circle cx="0" cy="0" r="0.06" class="egrid"/></pattern></defs>');
        const [vx, vy, vw, vh] = this.view.box;
        out.push(`<rect x="${vx - 2}" y="${vy - 2}" width="${vw + 4}" height="${vh + 4}" fill="url(#plan-grid)"/>`);
        for (const r of this.rows) {
            const sel = r.id === this.selected;
            const b = box(r);
            const taken = this.taken.get(r.id);
            out.push(`<g class="erow${sel ? ' is-sel' : ''}" data-row="${r.id}">`);
            out.push(`<rect class="erow-hit" x="${b.x0}" y="${b.y0}" width="${b.x1 - b.x0}" height="${b.y1 - b.y0}"/>`);
            for (const s of spots(r)) {
                const em = Math.min(s.w, s.h) * 0.3;
                const car = taken?.get(s.k);
                const cls = s.hole ? ' is-hole' : car !== undefined ? ' is-taken' : '';
                out.push(`<g class="espot${cls}" data-k="${s.k}"><rect x="${s.x + 0.08}" y="${s.y + 0.08}" width="${s.w - 0.16}" height="${s.h - 0.16}" rx="0.35"/>`);
                if (s.hole) {
                    const c = 0.5;
                    out.push(`<path class="espot-x" d="M${s.x + s.w / 2 - c} ${s.y + s.h / 2 - c}l${2 * c} ${2 * c}m0 ${-2 * c}l${-2 * c} ${2 * c}"/>`);
                } else {
                    out.push(`<text x="${s.x + s.w / 2}" y="${s.y + s.h / 2 + em * 0.35}" font-size="${em}">${s.num}</text>`);
                }
                out.push('</g>');
            }
            if (r.name) {
                const t = r.dir === 'h' ? [b.x0 - 0.9, (b.y0 + b.y1) / 2] : [(b.x0 + b.x1) / 2, b.y0 - 0.9];
                out.push(`<text class="erow-name" x="${t[0]}" y="${t[1]}">${r.name}</text>`);
            }
            out.push('</g>');
        }
        const r = this.row();
        if (r) {
            const b = box(r);
            const pad = 0.15;
            out.push(`<rect class="esel" x="${b.x0 - pad}" y="${b.y0 - pad}" width="${b.x1 - b.x0 + 2 * pad}" height="${b.y1 - b.y0 + 2 * pad}" rx="0.5" stroke-width="${2 * k}"/>`);
            const R = Math.max(0.45, 11 * k);
            const hit = Math.max(R, 22 * k);
            const count = r.dir === 'h' ? [b.x1 + R * 0.6, (b.y0 + b.y1) / 2] : [(b.x0 + b.x1) / 2, b.y1 + R * 0.6];
            const depth = r.dir === 'h' ? [(b.x0 + b.x1) / 2, b.y1 + R * 0.6] : [b.x1 + R * 0.6, (b.y0 + b.y1) / 2];
            const arrow = (at, horizontal) => {
                const a = R * 0.45;
                return horizontal ? `M${at[0] - a} ${at[1]}h${2 * a}m${-a * 0.6} ${-a * 0.6}l${a * 0.6} ${a * 0.6}l${-a * 0.6} ${a * 0.6}M${at[0] - a} ${at[1]}l${a * 0.6} ${-a * 0.6}M${at[0] - a} ${at[1]}l${a * 0.6} ${a * 0.6}`
                    : `M${at[0]} ${at[1] - a}v${2 * a}m${-a * 0.6} ${-a * 0.6}l${a * 0.6} ${a * 0.6}l${a * 0.6} ${-a * 0.6}M${at[0]} ${at[1] - a}l${-a * 0.6} ${a * 0.6}M${at[0]} ${at[1] - a}l${a * 0.6} ${a * 0.6}`;
            };
            out.push(`<g class="ehandle" data-handle="count"><circle class="ehandle-hit" cx="${count[0]}" cy="${count[1]}" r="${hit}"/><circle cx="${count[0]}" cy="${count[1]}" r="${R}"/><path d="${arrow(count, r.dir === 'h')}" stroke-width="${2 * k}"/></g>`);
            out.push(`<g class="ehandle" data-handle="depth"><circle class="ehandle-hit" cx="${depth[0]}" cy="${depth[1]}" r="${hit}"/><circle cx="${depth[0]}" cy="${depth[1]}" r="${R}"/><path d="${arrow(depth, r.dir !== 'h')}" stroke-width="${2 * k}"/></g>`);
        }
        for (const g of this.guides || []) out.push(`<line class="eguide" x1="${g.x1}" y1="${g.y1}" x2="${g.x2}" y2="${g.y2}" stroke-width="${1.5 * k}" stroke-dasharray="${6 * k} ${4 * k}"/>`);
        if (this.gap) {
            const g = this.gap;
            const fs = 13 * k;
            const mx = (g.x1 + g.x2) / 2;
            const my = (g.y1 + g.y2) / 2;
            const label = `${String(Math.round(g.gap * 100) / 100).replace('.', ',')} м`;
            out.push(`<line class="egap" x1="${g.x1}" y1="${g.y1}" x2="${g.x2}" y2="${g.y2}" stroke-width="${1.5 * k}"/>`);
            out.push(`<rect class="egap-bg" x="${mx - fs * 1.8}" y="${my - fs * 0.8}" width="${fs * 3.6}" height="${fs * 1.6}" rx="${fs * 0.8}"/><text class="egap-text" x="${mx}" y="${my + fs * 0.35}" font-size="${fs}">${label}</text>`);
        }
        this.svgTarget.innerHTML = out.join('');
    }

    // Плашка выбранного ряда: поля — значениями ряда, кроме того, где сейчас курсор.
    fill() {
        const r = this.row();
        this.panelTarget.hidden = !r;
        if (!r) return;
        const set = (t, v) => { if (document.activeElement !== t) t.value = v; };
        set(this.nameTarget, r.name);
        set(this.fromTarget, r.from);
        set(this.nTarget, r.n);
        set(this.wTarget, String(r.w).replace('.', ','));
        set(this.lTarget, String(r.l).replace('.', ','));
        this.dirTarget.setAttribute('aria-pressed', r.dir === 'v');
        this.dirTarget.querySelector('[data-dir-label]').textContent = r.dir === 'h' ? 'Вправо' : 'Вниз';
        this.revTarget.setAttribute('aria-pressed', r.rev);
        this.removeTarget.disabled = this.taken.has(r.id);
    }
}
