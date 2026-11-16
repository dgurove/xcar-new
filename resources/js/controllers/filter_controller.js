import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { debounce } from '../lib/debounce';
import { getJson, request } from '../lib/http';
import { closeSheet } from '../sheet';

// Шторка «Фильтр» шапки вкладок колеса (`x-ui.filter-sheet`, 17.11.2026): тело грузится по открытию — тот же адрес списка с
// `X-Filters` (`ui/filter-body`). Параметр со списком — строка, варианты открываются в той же шторке (`push`/`pop`); галки — тем
// же правилом, что шторка чипа (`facets_controller`): отмечено всё — без фильтра, снятых меньше — «кроме» (`!a,b`). Числа
// «от–до» — `от~до`; даты — быстрый вариант словом или `с~по`. «Показать N» переспрашивает тот же адрес с `X-Count`.
// В адрес уходит выбранное; пустое — только у того, что было выбрано (`data-orig`): иначе `ListPrefs` подставит прошлое.
// Сохранить и «Сохранить выбранное сюда» — тот же набор в JSON со всеми ключами (`stamp`).
export default class extends Controller {
    static targets = ['button', 'body', 'main', 'form', 'pane', 'panes', 'submit'];
    static values = { url: String };

    connect() {
        this.seq = 0;
        this.later = debounce(() => this.count(), 200);
    }

    disconnect() {
        this.later.cancel();
        this.pending?.abort();
        this.loading?.abort();
    }

    // Чип выбранного параметра в ряду шапки открывает шторку.
    show() {
        this.buttonTarget.click();
    }

    async load() {
        this.loading?.abort();
        this.loading = new AbortController();
        const r = await request(this.urlValue, { headers: { 'X-Filters': '1' }, accept: 'text/html', signal: this.loading.signal });
        if (!r) return;
        this.bodyTarget.innerHTML = await r.text();
    }

    // Варианты параметра грузятся, когда его открыли (`X-Filter-Pane`), и остаются до закрытия шторки.
    async push({ params: { pane } }) {
        if (!this.paneTargets.some((p) => p.dataset.pane === pane)) {
            const r = await request(this.urlValue, { headers: { 'X-Filters': '1', 'X-Filter-Pane': pane }, accept: 'text/html', signal: this.loading?.signal });
            if (!r || !this.hasPanesTarget) return;
            this.panesTarget.insertAdjacentHTML('beforeend', await r.text());
        }
        this.paneTargets.forEach((p) => { p.hidden = p.dataset.pane !== pane; });
        this.mainTargets.forEach((m) => { m.hidden = true; });
        this.top();
    }

    pop() {
        this.paneTargets.forEach((p) => { p.hidden = p.dataset.pane !== ''; });
        this.mainTargets.forEach((m) => { m.hidden = false; });
        this.top();
    }

    top() {
        const dialog = this.element.querySelector('dialog');
        dialog?.scrollTo?.({ top: 0 });
        this.bodyTarget.closest('.sheet-scroll, dialog')?.scrollTo?.({ top: 0 });
    }

    // «⋯» у сохранённого и «Сохранить фильтр…» — строка правки под строкой.
    more(event) {
        const edit = event.currentTarget.closest('.filter-saved')?.querySelector('.filter-edit');
        if (!edit) return;
        edit.hidden = !edit.hidden;
        if (!edit.hidden) edit.querySelector('input[type="text"]')?.focus();
    }

    change(event) {
        const t = event.target;
        if (t.dataset.filterBox !== undefined) this.boxes(t.dataset.filterBox);
        else if (t.dataset.filterQuick !== undefined) this.quick(t);
        else if (t.dataset.filterDay !== undefined) this.day(t.dataset.filterDay);
        else if (t.dataset.filterEdge !== undefined) this.range(t.dataset.filterEdge);
        else if (!t.closest('[data-filter-target~="form"]')) return;
        this.dirty();
    }

    input(event) {
        const t = event.target;
        if (t.dataset.filterEdge === undefined) return;
        this.range(t.dataset.filterEdge);
        this.dirty();
    }

    value(key) {
        return this.formTarget.querySelector(`input[type="hidden"][name="${key}"]`);
    }

    boxes(key) {
        const boxes = [...this.formTarget.querySelectorAll(`[data-filter-box="${key}"]`)];
        const value = this.value(key);
        if (!value) return;
        if (boxes[0]?.type === 'radio') {
            const picked = boxes.find((b) => b.checked);
            value.value = picked?.value ?? '';
            this.label(key, picked && picked.value !== '' ? picked.dataset.label : 'Все');
            return;
        }
        const on = boxes.filter((b) => b.checked);
        const off = boxes.filter((b) => !b.checked);
        value.value = off.length === 0 ? '' : off.length < on.length ? `!${off.map((b) => b.value).join(',')}` : on.map((b) => b.value).join(',');
        this.label(key, off.length === 0 ? 'Все'
            : on.length === 0 ? 'Ничего'
            : on.length === 1 ? on[0].dataset.label
            : off.length <= 2 && off.length < on.length ? `Кроме ${off.map((b) => b.dataset.label).join(', ')}`
            : `${on.length} из ${boxes.length}`);
        const all = this.formTarget.querySelector(`[data-pane="${key}"] .facet-all`);
        if (all) all.textContent = off.length === 0 ? 'Исключить все' : 'Выбрать все';
    }

    label(key, text) {
        const el = this.formTarget.querySelector(`[data-filter-label="${key}"]`);
        if (el) el.textContent = text;
    }

    all({ params: { key } }) {
        const boxes = [...this.formTarget.querySelectorAll(`[data-pane="${key}"] [data-filter-item]:not([hidden]) [data-filter-box]`)];
        const on = !boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = on; });
        this.boxes(key);
        this.dirty();
    }

    find(event) {
        const q = event.target.value.trim().toLowerCase();
        event.target.closest('.filter-pane')?.querySelectorAll('[data-filter-item]').forEach((el) => { el.hidden = q !== '' && !el.dataset.name.includes(q); });
    }

    stop(event) {
        event.preventDefault();
        event.target.blur();
    }

    // Даты: быстрый вариант снимает свои края и наоборот.
    quick(radio) {
        const key = radio.dataset.filterQuick;
        this.clearing = true;
        this.formTarget.querySelectorAll(`[data-filter-day="${key}"]`).forEach((d) => {
            if (d.value === '') return;
            d.value = '';
            d.dispatchEvent(new Event('change', { bubbles: true }));
        });
        this.clearing = false;
        this.value(key).value = radio.value;
        this.label(key, radio.dataset.label);
    }

    day(key) {
        if (this.clearing) return;
        this.formTarget.querySelectorAll(`[data-filter-quick="${key}"]`).forEach((q) => { q.checked = false; });
        const [from, to] = [...this.formTarget.querySelectorAll(`input[data-filter-day="${key}"]`)].map((d) => d.value);
        this.value(key).value = from || to ? `${from}~${to}` : '';
        this.label(key, from || to ? [from, to].map((d) => (d ? d.split('-').reverse().slice(0, 2).join('.') : '…')).join('–') : 'Все');
    }

    clear({ params: { key } }) {
        this.formTarget.querySelectorAll(`[data-filter-quick="${key}"]`).forEach((q) => { q.checked = false; });
        this.clearing = true;
        this.formTarget.querySelectorAll(`[data-filter-day="${key}"]`).forEach((d) => {
            d.value = '';
            d.dispatchEvent(new Event('change', { bubbles: true }));
        });
        this.clearing = false;
        this.value(key).value = '';
        this.label(key, 'Все');
        this.dirty();
    }

    // Числа «от–до»: только цифры, деньги — с разрядами по ходу набора.
    range(key) {
        const [from, to] = [...this.formTarget.querySelectorAll(`[data-filter-edge="${key}"]`)].map((input) => {
            const digits = input.value.replace(/\D+/g, '').slice(0, 12);
            if (input.dataset.money !== undefined) {
                const pretty = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
                if (input.value !== pretty) input.value = pretty;
            }
            return digits;
        });
        this.value(key).value = from || to ? `${from}~${to}` : '';
    }

    dirty() {
        this.later.cancel();
        this.pending?.abort();
        this.seq++;
        if (this.hasSubmitTarget) this.submitTarget.setAttribute('aria-busy', 'true');
        this.later();
    }

    // Все ключи шторки (пустые — тоже) и параметры вкладки; последнее значение ключа побеждает (переключатель: скрытое «» и галка).
    params() {
        const out = new Map();
        for (const [k, v] of new FormData(this.formTarget)) out.set(k, typeof v === 'string' ? v : '');
        return out;
    }

    // Не тронутое и не стоявшее в адресе не отправляется: его и так подставят память и умолчание экрана — иначе «Кроме
    // Каркаде» по умолчанию после «Показать» стало бы выбранным, и «Фильтр» считал бы его.
    url() {
        const url = new URL(this.formTarget.action, location.href);
        const shown = new URL(this.urlValue, location.href).searchParams;
        const orig = new Map();
        this.formTarget.querySelectorAll('[data-orig]').forEach((i) => { if (!orig.has(i.name)) orig.set(i.name, i.dataset.orig); });
        for (const [k, v] of this.params()) {
            if (orig.has(k) && v === orig.get(k) && !shown.has(k)) continue;
            if (v === '' && !orig.get(k)) continue;
            url.searchParams.set(k, v);
        }
        return url;
    }

    async count() {
        const url = this.url();
        this.pending = new AbortController();
        const seq = ++this.seq;
        const data = await getJson(url, { headers: { 'X-Count': '1' }, signal: this.pending.signal, quiet: true });
        if (seq !== this.seq || !this.hasSubmitTarget) return;
        const n = data?.count ?? null;
        const button = this.submitTarget;
        button.removeAttribute('aria-busy');
        button.disabled = n === 0;
        button.innerHTML = n === 0 ? 'Ничего не найдено' : n === null ? 'Показать' : `Показать <span class="nums">${n}</span>`;
    }

    submit(event) {
        event.preventDefault();
        this.later.cancel();
        this.leave(this.url().toString());
    }

    // Ссылки шторки: сохранённый фильтр, «Сбросить».
    go(event) {
        event.preventDefault();
        this.leave(event.currentTarget.href);
    }

    // Сначала закрыть шторку (её запись в истории снимается шагом назад), потом перейти: иначе запоздалое закрытие уводило
    // «Назад» на прежний адрес, и выбор пропадал.
    async leave(url) {
        const dialog = this.element.querySelector('dialog');
        if (dialog?.matches(':popover-open')) dialog.hidePopover();
        else if (dialog?.open) await closeSheet(dialog);
        Turbo.visit(url, { action: 'replace' });
    }

    // Сохранить: в скрытое `query` — вкладка и все ключи шторки (пустые явно — при открытии фильтра память не подмешается).
    stamp(event) {
        const field = event.target.querySelector('input[name="query"]');
        if (!field || !this.hasFormTarget) return;
        field.value = JSON.stringify(Object.fromEntries(this.params()));
    }
}
