import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { debounce } from '../lib/debounce';
import { getJson, request } from '../lib/http';
import * as overlays from '../overlays';
import { confirmSheet } from '../confirm';

// Окно «Фильтр» вкладок колеса (`ui/filter-body` во фрейме карточки, `?peek=filter`, 17.11.2026): параметр со списком — строка,
// варианты грузятся по нажатию (`X-Filter-Pane`) и открываются экраном того же окна (`push`/`pop`); галки — тем же правилом,
// что шторка чипа (`facets_controller`): отмечено всё — без фильтра, снятых меньше — «кроме» (`!a,b`). Числа «от–до» —
// `от~до`; даты — быстрый вариант словом или `с~по`. «Показать N» переспрашивает тот же адрес с `X-Count`. В адрес уходит
// только тронутое и стоявшее в адресе (`url`). Применили — запись окна в истории снимается до перехода (иначе «Назад» после
// закрытия вернул бы прежний список), на ПК окно остаётся рядом со списком, на телефоне закрывается. Сохранить и «Сохранить
// выбранное сюда» — тот же набор в JSON со всеми ключами (`stamp`).
export default class extends Controller {
    static targets = ['main', 'form', 'pane', 'panes', 'submit'];
    static values = { url: String };

    connect() {
        this.seq = 0;
        this.later = debounce(() => this.count(), 200);
    }

    disconnect() {
        this.later.cancel();
        this.pending?.abort();
    }

    // Варианты параметра грузятся, когда его открыли (`X-Filter-Pane`), — по выбору в окне, а не по странице: выбрали марку —
    // в «Модели» только её модели, числа — при прочем выбранном. Выбор с тех пор поменялся — экран спрашивается заново.
    async push({ params: { pane } }) {
        if (pane !== 'saved') {
            const url = this.url().toString();
            const have = this.paneTargets.find((p) => p.dataset.pane === pane);
            if (!have || have.dataset.loaded !== url) {
                const r = await request(url, { headers: { 'X-Filter-Pane': pane }, accept: 'text/html' });
                if (!r || !this.hasPanesTarget) return;
                const html = await r.text();
                this.paneTargets.find((p) => p.dataset.pane === pane)?.remove();
                this.panesTarget.insertAdjacentHTML('beforeend', html);
                const fresh = this.paneTargets.find((p) => p.dataset.pane === pane);
                if (!fresh) return;
                fresh.dataset.loaded = url;
            }
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
        this.element.querySelector('.detail-body')?.scrollTo({ top: 0 });
    }

    // «Сохранить текущий…» и «Переименовать…» на экране «Сохранённые фильтры» — поле названия под списком действий.
    reveal({ params: { edit } }) {
        this.element.querySelectorAll('.filter-edit').forEach((el) => { el.hidden = el.dataset.edit !== edit || !el.hidden; });
        const input = this.element.querySelector(`.filter-edit[data-edit="${edit}"]:not([hidden]) input[name="name"]`);
        input?.focus();
        input?.select();
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
                if (input.value !== pretty) {
                    // Курсор — после того же числа цифр, что и до разрядов: правка в середине не уводит его в конец.
                    const focused = document.activeElement === input;
                    const before = focused ? input.value.slice(0, input.selectionStart ?? input.value.length).replace(/\D+/g, '').length : 0;
                    input.value = pretty;
                    if (focused) {
                        let at = 0;
                        for (let seen = 0; at < pretty.length && seen < before; at++) if (/\d/.test(pretty[at])) seen++;
                        input.setSelectionRange(at, at);
                    }
                }
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
        // Стоявшее в адресе — адрес страницы, а не окна: окно рисуется по выборке, куда контроллер уже подставил умолчания.
        const shown = new URL(location.href).searchParams;
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

    // Перейти к выбранному. Переход Turbo тут — морф страницы, а окно фильтра морф не трогает (`detail_controller`): на ПК
    // своё применение помечается (`data-fresh`) — окно перерисуется свежим рядом с отфильтрованным списком; на телефоне лист
    // сначала закрывается как обычно (запись «Назад» снимается, адрес без ?peek), потом переход — список во весь экран.
    async leave(href) {
        const url = new URL(href, location.href);
        const frame = this.element.closest('turbo-frame');
        const split = frame?.closest('[data-controller~="detail"]');
        const detail = split && this.application.getControllerForElementAndIdentifier(split, 'detail');
        if (matchMedia('(min-width: 1024px)').matches) {
            url.searchParams.set('peek', 'filter');
            if (frame) frame.dataset.fresh = '1';
            await overlays.shut('detail');
        } else {
            url.searchParams.delete('peek');
            if (detail) {
                await detail.close(0);
                for (let i = 0; i < 20 && detail.open; i++) await new Promise((r) => setTimeout(r, 30));
            } else await overlays.shut('detail');
        }
        Turbo.visit(url.toString(), { action: 'replace' });
    }

    // Формы «Мои фильтры» (сохранить, переименовать, сохранить выбранное сюда, удалить) — всей страницей: запись окна «Назад»
    // снимается до отправки, окно помечается на перерисовку (`data-fresh`) — ответ морфом его иначе не тронул бы, и названия
    // стояли бы прежние. Второй проход — та же форма уже без перехвата.
    async send(event) {
        if (this.sending) return;
        event.preventDefault();
        const form = event.target;
        this.stamp(event);
        // «Удалить» — сначала вопрос (`data-confirm`, свой: у Turbo он шёл бы после снятой записи окна): не согласились — всё как было.
        if (form.dataset.confirm && !(await confirmSheet(form.dataset.confirm, { label: form.dataset.confirmLabel || 'Да', danger: true }))) return;
        const frame = this.element.closest('turbo-frame');
        if (frame) frame.dataset.fresh = '1';
        await overlays.shut('detail');
        this.sending = true;
        form.requestSubmit();
        this.sending = false;
    }

    // Сохранить: в скрытое `query` — вкладка и все ключи шторки (пустые явно — при открытии фильтра память не подмешается).
    stamp(event) {
        const field = event.target.querySelector('input[name="query"]');
        if (!field || !this.hasFormTarget) return;
        field.value = JSON.stringify(Object.fromEntries(this.params()));
    }
}
