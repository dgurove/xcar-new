import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { confirmSheet } from '../confirm';
import { loadSortable } from '../lib/sortable';
import { openLightbox } from '../lightbox';
import { openSheet, closeSheet } from '../sheet';

// Фотографии: загрузка по одному файлу с прогрессом (выбор или drop на карточку), перестановка перетаскиванием,
// действия глаз, поворот, корзина на плитке и в просмотрщике.
// Сервер на каждое действие возвращает turbo-stream с новой полосой; свою полосу контроллер морфит сам (не через
// Turbo): плитки и картинки остаются теми же узлами, лента не прыгает. Глаз и поворот — сразу на экране, сервер
// догоняет в фоне (hide, rotate, queue). readonly — только смотреть (кадры из письма рядом с приёмом): без
// перестановки, без действий на плитке и в просмотрщике. any — зона принимает любой файл (предложение CRM): документ
// и архив сервер разложит сам, картинку из буфера кладёт ⌘V в любом месте страницы.
// Открытый просмотр — один на страницу; действие в нём делает карточка, чей кадр на экране (группа карточек ТС),
// а показывает — тот, кто его открыл.
let shown = null;

export default class extends Controller {
    static targets = ['input', 'progress', 'grid', 'all'];
    static values = { url: String, collection: { type: String, default: 'photos' }, stage: String, readonly: Boolean, reload: Boolean, group: String, any: Boolean, mark: Boolean };

    connect() {
        if (this.readonlyValue) return;
        // Файлы можно бросить на всю карточку — не целясь в плитку.
        this.element.addEventListener('dragover', this.over = (e) => { if (hasFiles(e)) { e.preventDefault(); this.element.classList.add('is-dropping'); } });
        this.element.addEventListener('dragleave', this.leave = (e) => { if (!this.element.contains(e.relatedTarget)) this.element.classList.remove('is-dropping'); });
        this.element.addEventListener('drop', this.drop = (e) => {
            if (!hasFiles(e)) return;
            e.preventDefault();
            this.element.classList.remove('is-dropping');
            this.uploadFiles([...e.dataTransfer.files]);
        });
        // Скриншот или кадр, скопированный с портала страховой или из мессенджера: ⌘V — и он в фото. В поле ввода
        // вставка своя: кусок страницы портала несёт и текст, и картинку — человеку нужен текст. Скрытое карточка
        // (закрытая карточка строки) кадры не ловит.
        if (this.anyValue) {
            document.addEventListener('paste', this.paste = (e) => {
                const files = [...(e.clipboardData?.files || [])].filter(isImage);
                const typing = e.target.closest?.('input, textarea, select, [contenteditable]');
                if (!files.length || typing || !this.element.checkVisibility?.()) return;
                e.preventDefault();
                this.uploadFiles(files);
            });
        }
    }

    async gridTargetConnected(grid) {
        this.syncAll();
        if (this.readonlyValue) return;
        const Sortable = await loadSortable();
        if (!grid.isConnected) return;
        this.sortable?.destroy();
        this.sortable = Sortable.create(grid, {
            animation: 150,
            delay: 150,
            delayOnTouchOnly: true,
            direction: 'horizontal',
            filter: '.photo-actions, .photo-star',
            preventOnFilter: false,
            onEnd: () => this.reorder(),
        });
    }

    disconnect() {
        this.element.removeEventListener('dragover', this.over);
        this.element.removeEventListener('dragleave', this.leave);
        this.element.removeEventListener('drop', this.drop);
        if (this.paste) document.removeEventListener('paste', this.paste);
        this.sortable?.destroy();
        // Просмотр не гасим: ответ на действие из него подменяет карточку вместе с этим контроллером.
    }

    pick() { this.inputTarget.click(); }

    // «Показать все» / «Скрыть все» (владелец, 04.10.2026): хоть один кадр скрыт — показать все, иначе скрыть все.
    // Кадры с глазом этого ряда; меньше двух — кнопки нет.
    eyeCells() { return [...this.element.querySelectorAll('.photo-cell[data-id]')].filter((c) => c.querySelector('[data-act="hide"]')); }

    allTargetConnected() { this.syncAll(); }

    syncAll() {
        if (!this.hasAllTarget) return;
        const cells = this.eyeCells();
        const any = cells.some((c) => c.dataset.hidden === '1');
        this.allTargets.forEach((b) => { b.hidden = cells.length < 2; b.textContent = any ? 'Показать все' : 'Скрыть все'; });
    }

    async all() {
        const cells = this.eyeCells();
        if (!cells.length || this.busy) return;
        const hide = !cells.some((c) => c.dataset.hidden === '1');
        const paint = (to) => cells.forEach((c) => {
            c.dataset.hidden = to ? '1' : '0';
            c.classList.toggle('is-hidden', to);
            c.querySelector('[data-act="hide"]')?.setAttribute('aria-label', to ? 'Показать' : 'Скрыть');
        });
        paint(hide);
        this.syncAll();
        this.busy = true;
        try {
            const body = `hidden=${hide ? 1 : 0}` + (this.stageValue ? `&stage=${encodeURIComponent(this.stageValue)}` : '');
            const r = await this.post(`${this.urlValue}/visibility`, body, 'application/x-www-form-urlencoded');
            if (!r.ok) { paint(!hide); this.syncAll(); window.toast?.('Не получилось', 'danger'); return; }
            // Карточку только для просмотра ответ не перерисовывает: он вернул бы ей плитку «добавить».
            if (!this.readonlyValue) this.apply(await r.text());
        } finally { this.busy = false; }
    }

    upload() {
        const files = [...this.inputTarget.files];
        this.inputTarget.value = '';
        return this.uploadFiles(files);
    }

    // Превью выбранных кадров встают в ленту сразу, с кольцом прогресса; кадр
    // ужимается до 1600 px ещё в телефоне (4 МБ → ~300 КБ), отпечаток исходника
    // считается здесь же, чтобы дубли из писем и архивов по-прежнему отсекались.
    // Экран не гаснет, пока идёт очередь; неудавшийся кадр — повтор тапом.
    // Кадры идут по три сразу: ответы приходят вразнобой, и ряд из ответа, где кадров меньше уже показанного, устарел —
    // его пропускаем (последний сохранённый кадр приносит полный ряд). Документы и архивы зоны any — полосой следом.
    async uploadFiles(files) {
        if (this.uploading || !files.length) return;
        if (!this.hasGridTarget || this.collectionValue !== 'photos') return this.uploadWithBar(files);
        const other = this.anyValue ? files.filter((f) => !isImage(f)) : [];
        files = this.anyValue ? files.filter(isImage) : files;
        if (!files.length) return this.uploadWithBar(other);
        this.uploading = true;
        this.pendingCells ??= new Map();
        this.shown = this.gridTarget.querySelectorAll('.photo-cell[data-id]').length;
        const queue = files.map((file) => ({ file, cell: this.pendingCell(file) }));
        const wake = await navigator.wakeLock?.request?.('screen').catch(() => null);
        const worker = async () => {
            for (let job = queue.shift(); job; job = queue.shift()) {
                try {
                    const html = await this.send(await this.prepare(job.file), (p) => job.cell.style.setProperty('--p', p), () => job.cell.classList.add('is-processing'));
                    this.pendingCells.delete(job.cell);
                    job.cell.remove();
                    if (this.fresher(html)) {
                        this.apply(html);
                        this.restorePending();
                    }
                } catch (e) {
                    job.cell.classList.replace('is-processing', 'is-failed') || job.cell.classList.add('is-failed');
                    job.cell.title = e.message || 'Не загрузилось';
                }
            }
        };
        await Promise.all(Array.from({ length: Math.min(3, queue.length) }, worker));
        wake?.release?.().catch(() => {});
        this.uploading = false;
        if (other.length) await this.uploadWithBar(other);
    }

    fresher(html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const n = [...doc.querySelectorAll('turbo-stream template')].reduce((sum, t) => sum + t.content.querySelectorAll('.photo-cell[data-id]').length, 0);
        if (n < this.shown) return false;
        this.shown = n;
        return true;
    }

    // Документы и файлы без ленты кадров — полоса «n из N», как раньше.
    async uploadWithBar(files) {
        this.uploading = true;
        let n = 0;
        for (const file of files) {
            n++;
            this.showProgress(`${n} из ${files.length}`, 0);
            try {
                this.apply(await this.send(await this.prepare(file), (p) => this.showProgress(`${n} из ${files.length}`, p)));
                if (/\.(zip|7z|rar)$/i.test(file.name)) window.toast?.('Архив разбирается, кадры появятся сами');
            } catch (e) {
                window.toast?.(e.message || 'Файл не загрузился', 'danger');
            }
        }
        this.hideProgress();
        this.uploading = false;
        // Загрузка из шторки «⋯» (документ к делу): списка на странице ещё нет — перечитать страницу.
        if (this.reloadValue) Turbo.visit(location.href, { action: 'replace' });
    }

    pendingCell(file) {
        const cell = document.createElement('div');
        cell.className = 'photo-cell is-uploading';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.alt = '';
        img.onload = () => URL.revokeObjectURL(img.src);
        cell.append(img);
        cell.addEventListener('click', () => { if (cell.classList.contains('is-failed')) { cell.remove(); this.pendingCells.delete(cell); this.uploadFiles([file]); } });
        this.pendingCells.set(cell, file);
        this.gridTarget.append(cell);
        return cell;
    }

    // Ответ сервера подменяет ряд целиком — ещё не отправленные превью возвращаются.
    restorePending() {
        for (const cell of this.pendingCells.keys()) this.gridTarget.append(cell);
    }

    async prepare(file) {
        const sha = await this.sha(file);
        if (!file.type.startsWith('image/') || this.collectionValue !== 'photos') return { file, sha };
        try {
            const bitmap = await createImageBitmap(file);
            const max = Math.max(bitmap.width, bitmap.height);
            if (max <= 1600 && file.size < 600_000) { bitmap.close(); return { file, sha }; }
            const scale = Math.min(1, 1600 / max);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close();
            const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', .85));
            if (!blob) return { file, sha };
            return { file: new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }), sha };
        } catch {
            return { file, sha }; // HEIC на Android и прочее, что телефон не декодирует — как есть
        }
    }

    async sha(file) {
        try {
            const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
        } catch { return ''; }
    }

    // onSent — файл ушёл целиком, дальше сервер пережимает кадр и снимает чужой знак (секунда-две на кадр)
    send({ file, sha }, onProgress, onSent) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const form = new FormData();
            form.append('file', file);
            if (sha) form.append('sha', sha);
            form.append('collection', this.collectionValue);
            // Стадия карточки: кадр ложится туда, откуда его добавили. Слоты приёма шлют свою через extra.
            if (this.stageValue) form.append('stage', this.stageValue);
            for (const [k, v] of Object.entries(this.extra || {})) if (v) form.append(k, v);
            form.append('_token', this.token);
            xhr.open('POST', this.urlValue);
            xhr.setRequestHeader('Accept', 'text/vnd.turbo-stream.html');
            if (this.rowId) xhr.setRequestHeader('X-Photos-Target', this.rowId);
            xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
            xhr.upload.onload = () => onSent?.();
            xhr.onload = () => {
                if (xhr.status < 300) resolve(xhr.responseText);
                else {
                    let message = 'Файл не загрузился';
                    try { message = JSON.parse(xhr.responseText).message || message; } catch {}
                    reject(new Error(message));
                }
            };
            xhr.onerror = () => reject(new Error('Нет связи'));
            xhr.send(form);
        });
    }

    async reorder() {
        const order = this.cells().map((el) => el.dataset.id);
        const r = await this.post(this.urlValue + '/order', JSON.stringify({ order }), 'application/json');
        if (r.ok) this.apply(await r.text());
    }

    // Звезда главного: у главного — открыть выбор (контурные звёзды на остальных), у контурной — сделать этот кадр
    // главным: он встаёт первым, дальше тот же порядок, что после перетаскивания. Нажали мимо — выбор закрывается.
    star(event) {
        event.preventDefault();
        event.stopPropagation();
        const button = event.currentTarget, grid = this.gridTarget;
        if (button.classList.contains('photo-star--main')) {
            const on = grid.classList.toggle('is-choosing');
            if (on) setTimeout(() => document.addEventListener('click', this.stopChoosing ??= (e) => {
                if (!e.target.closest?.('.photo-star') && this.hasGridTarget) this.gridTarget.classList.remove('is-choosing');
                document.removeEventListener('click', this.stopChoosing);
            }), 0);
            return;
        }
        grid.classList.remove('is-choosing');
        grid.querySelector('.photo-star--main')?.classList.replace('photo-star--main', 'photo-star--pick');
        button.classList.replace('photo-star--pick', 'photo-star--main');
        grid.prepend(button.closest('.photo-cell'));
        this.reorder();
    }

    // Кнопка на плитке: hide / rotate / delete.
    act(event) {
        const button = event.currentTarget;
        this.perform(button.closest('.photo-cell').dataset.id, button.dataset.act, button.dataset.confirm);
    }

    // Глаз и поворот — сразу на экране, запрос в фоне, у каждого кадра своя очередь: второй глаз или три поворота
    // подряд не теряются за общим замком. Удаление ждёт ответа: ряд без кадра рисует сервер.
    async perform(id, act, confirm) {
        if (act === 'hide') return this.hide(id);
        if (act === 'rotate') return this.rotate(id);
        if (this.busy) return false;
        if (confirm && !(await confirmSheet(confirm, { danger: act === 'delete' }))) return false;
        this.busy = true;
        try { return await this.run(id, act); } finally { this.busy = false; }
    }

    async run(id, act) {
        const url = `${this.urlValue}/${id}${act === 'delete' ? '' : '/' + act}`;
        const r = await this.post(url, act === 'delete' ? '_method=delete' : '', 'application/x-www-form-urlencoded');
        if (!r.ok) { window.toast?.('Не получилось', 'danger'); return false; }
        this.apply(await r.text());
        return true;
    }

    // Кадр в полёте: ops — запросов ещё идёт, pending — четверти нажаты, но не отправлены, sent — отправлены, но
    // повёрнутого файла на экране ещё нет. На экране всегда pending + sent.
    flight(id) {
        this.flights ??= new Map();
        if (!this.flights.has(id)) this.flights.set(id, { ops: 0, pending: 0, sent: 0, waiting: false, chain: Promise.resolve() });
        return this.flights.get(id);
    }

    hide(id) {
        const cell = this.cell(id);
        if (!cell) return false;
        const flip = () => {
            const hidden = cell.dataset.hidden !== '1';
            cell.dataset.hidden = hidden ? '1' : '0';
            cell.classList.toggle('is-hidden', hidden);
            cell.querySelector('[data-act="hide"]')?.setAttribute('aria-label', hidden ? 'Показать' : 'Скрыть');
        };
        flip();
        this.syncAll();
        shown?.lb.redraw();
        this.queue(id, () => this.post(`${this.urlValue}/${id}/hide`, '', 'application/x-www-form-urlencoded'), () => { flip(); this.syncAll(); shown?.lb.redraw(); });
        return true;
    }

    rotate(id) {
        const f = this.flight(id);
        f.pending++;
        this.spin(id, f.pending + f.sent);
        // Нажатия, пока запрос в пути, копятся и уходят одним следующим запросом: один проход перекодирования.
        if (f.waiting) return true;
        f.waiting = true;
        this.queue(id, () => {
            const turns = f.pending;
            f.waiting = false;
            f.pending = 0;
            f.sent += turns;
            return this.post(`${this.urlValue}/${id}/rotate`, `turns=${turns % 4}`, 'application/x-www-form-urlencoded');
        }, () => { f.pending = f.sent = 0; this.spin(id, 0); });
        return true;
    }

    // Запрос кадра встаёт за предыдущими; ответ применяется, только когда за ним в очереди пусто, — иначе ряд из
    // ответа откатил бы то, что человек уже нажал после. undo — вернуть экран при ошибке.
    queue(id, send, undo) {
        const f = this.flight(id);
        f.ops++;
        f.chain = f.chain.then(async () => {
            let r = null;
            try { r = await send(); } catch {}
            f.ops--;
            if (!r?.ok) {
                undo?.();
                window.toast?.('Не получилось', 'danger');
                return;
            }
            const html = await r.text();
            if (f.ops > 0) return;
            const turned = f.sent > 0;
            await this.apply(html, true);
            // Повёрнутый файл на экране: морф снял поворот CSS, остаются только нажатия, сделанные за время ответа.
            f.sent = 0;
            if (f.pending) this.spin(id, f.pending);
            if (turned && shown) shown.lb.reload(shown.items());
        });
        return f.chain;
    }

    // Поворот на экране, пока сервер крутит файл: плитка и открытый просмотр. turns — четверти сверх того, что уже
    // в файле; 0 — снять (повёрнутый файл пришёл).
    spin(id, turns) {
        const cell = this.cell(id), img = cell?.querySelector('img');
        if (img) {
            const odd = turns % 2 === 1, w = img.offsetWidth, h = img.offsetHeight;
            cell.classList.toggle('is-turning', turns !== 0);
            cell.style.setProperty('--turn', turns);
            cell.style.setProperty('--fit', odd && w && h ? Math.min(w, h) / Math.max(w, h) : 1);
        }
        shown?.lb.spin(id, turns);
    }

    post(url, body, type) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': type, Accept: 'text/vnd.turbo-stream.html', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest', ...(this.rowId ? { 'X-Photos-Target': this.rowId } : {}) },
            body,
        });
    }

    // Какой ряд кадров перерисовать ответом: у редактора и у карточки они разные (id у ряда свой).
    get rowId() {
        return this.element.querySelector('.photo-row[id], .photo-grid[id]')?.id ?? '';
    }

    // Применить turbo-stream прямо сейчас (replace / append / prepend / update). replace — морфом: те же плитки и
    // картинки остаются на месте, лента не прыгает в начало, Sortable и просмотр не пересобираются. quiet — ответ на
    // глаз или поворот: сначала догрузить новые адреса кадров, потом подменить разом, чтобы плитка не мигнула пустой.
    async apply(html, quiet = false) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const streams = [...doc.querySelectorAll('turbo-stream')];
        if (quiet) {
            const fresh = new Set(streams.flatMap((s) => [...(s.querySelector('template')?.content.querySelectorAll('.photo-cell img') ?? [])].map((i) => i.getAttribute('src'))));
            document.querySelectorAll('.photo-cell img').forEach((i) => fresh.delete(i.getAttribute('src')));
            await Promise.all([...fresh].map((src) => withTimeout(preload(src), 4000)));
        }
        streams.forEach((stream) => {
            const target = document.getElementById(stream.getAttribute('target'));
            const content = stream.querySelector('template')?.content.cloneNode(true);
            if (!target || !content) return;
            switch (stream.getAttribute('action')) {
                case 'append': target.append(content); break;
                case 'prepend': target.prepend(content); break;
                case 'update': target.replaceChildren(content); break;
                default: {
                    // Морф, если контроллеры внутри остались теми же (карточки стадий парковки: та же камера, то же «только
                    // чтение»); сменились значения — подменить целиком, чтобы контроллер переподключился с новыми.
                    const next = content.firstElementChild;
                    if (next && content.childElementCount === 1 && next.id === target.id && controllers(target) === controllers(next)) Turbo.morphElements(target, next);
                    else target.replaceWith(content);
                }
            }
        });
        if (this.pendingCells?.size) this.restorePending();
        if (quiet) shown?.lb.redraw();
    }

    // После поворота у файла тот же адрес: добавить метку времени, чтобы браузер не показал старый кадр.
    bust(id) {
        const img = this.hasGridTarget ? this.cell(id)?.querySelector('img') : null;
        if (!img) return;
        const stamp = (u) => u + (u.includes('?') ? '&' : '?') + 't=' + Date.now();
        img.src = stamp(img.src);
        img.dataset.full = stamp(img.dataset.full);
    }

    // Только кадры с номером: заглушки качающихся (лот Мигторга) и плитки в загрузке в порядок не идут.
    cells() { return [...this.gridTarget.querySelectorAll('.photo-cell[data-id]')]; }

    cell(id) { return this.gridTarget.querySelector(`.photo-cell[data-id="${id}"]`); }

    // ---- просмотрщик (lightbox.js): глаз, поворот, корзина и «Скачать» прямо в нём. group — карточки одной ТС
    // листаются одним просмотром (от страховой, приём, выдача); действие делает карточка, чей кадр на экране.

    open(event) {
        const img = event.currentTarget.closest('.photo-cell')?.querySelector('img') ?? event.currentTarget;
        const items = this.items();
        return this.show(Math.max(0, items.findIndex((it) => it.el === img)), items);
    }

    // Карточки группы — по стадиям (data-photos-order), не по месту на странице; из двух карточек одной стадии
    // первой та, где кадры можно править.
    owners() {
        if (!this.groupValue) return [this];
        const ro = (el) => (el.dataset.photosReadonlyValue === 'true' ? 1 : 0);
        return [...document.querySelectorAll(`[data-photos-group-value="${CSS.escape(this.groupValue)}"]`)]
            .sort((a, b) => (a.dataset.photosOrder ?? 9) - (b.dataset.photosOrder ?? 9) || ro(a) - ro(b))
            .map((el) => this.application.getControllerForElementAndIdentifier(el, 'photos')).filter(Boolean);
    }

    // Кадры по порядку карточек, каждый один раз (кадр стадии бывает и в шаге, и карточкой справа); превью
    // в очереди загрузки (без data-full) не смотрятся.
    items() {
        const seen = new Set();
        return this.owners().flatMap((owner) => [...owner.element.querySelectorAll('img[data-full]')].map((img) => ({
            src: img.dataset.full, mid: img.dataset.mid, el: img, thumb: img.src, download: img.dataset.download,
            owner, id: img.dataset.id ?? img.closest('[data-id]')?.dataset.id,
        }))).filter((it) => !it.id || (!seen.has(it.id) && seen.add(it.id)));
    }

    async show(index, items) {
        const can = (i, act) => {
            const it = (this.viewer?.items ?? items)[i];
            if (!it?.id) return false;
            // Карточка только для просмотра (дело ТС парковки) — один глаз: показ в продаже.
            if (it.owner.readonlyValue) return act === 'hide' && !!it.owner.element.querySelector(`.photo-cell[data-id="${it.id}"] [data-act="hide"]`);
            if (act === 'mark') return it.owner.markValue;
            return act !== 'hide' || !!it.owner.element.querySelector(`.photo-cell[data-id="${it.id}"] [data-act="hide"]`);
        };
        const act = (act, confirm) => async (i) => {
            const it = this.viewer.items[i];
            if (!can(i, act) || !(await it.owner.perform(it.id, act, confirm))) return;
            // Глаз и поворот просмотр показывает сам (redraw, spin), пересобирать его — только после удаления.
            if (act !== 'delete') return;
            const next = this.items();
            const at = act === 'delete' ? Math.min(i, next.length - 1) : next.findIndex((n) => n.id === it.id);
            this.viewer.refresh(next, at);
        };
        const hidden = (i) => (this.viewer?.items ?? items)[i]?.el.closest('.photo-cell')?.dataset.hidden === '1';
        const lightbox = await openLightbox({
            items, index, download: true,
            actions: [
                // Глаз показывает состояние: открыт — фото видно, перечёркнут — скрыто.
                { name: 'eye', icon: 'eye', title: 'Скрыть', bottom: true, iconFor: (i) => (hidden(i) ? 'eye-off' : 'eye'), titleFor: (i) => (hidden(i) ? 'Показать' : 'Скрыть'), shown: (i) => can(i, 'hide'), run: act('hide') },
                { name: 'rotate', icon: 'rotate', title: 'Повернуть', bottom: true, shown: (i) => can(i, 'rotate'), run: act('rotate') },
                { name: 'trash', icon: 'trash', title: 'Удалить', shown: (i) => can(i, 'delete'), run: act('delete', 'Удалить фото?') },
                { name: 'mark', icon: 'mark', title: 'Водяной знак', shown: (i) => can(i, 'mark'), run: (i) => this.markSheet(this.viewer.items[i]) },
            ],
            onClose: () => { this.viewer = null; shown = null; },
        });
        if (lightbox) {
            this.viewer = lightbox;
            shown = { lb: lightbox, items: () => this.items() };
        }
    }

    // ---- шторка «Водяной знак» поверх просмотрщика (только фото предложений CRM, data-photos-mark-value): кадр
    // «Без знака / Со знаком», «Вернуть со знаком», «Заменить своим файлом». Разметку отдаёт сервер (mark-sheet).

    async markSheet(it) {
        const owner = it.owner;
        const r = await fetch(`${owner.urlValue}/${it.id}/mark`, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) { window.toast?.('Не получилось', 'danger'); return; }
        document.getElementById('mark-sheet')?.remove();
        const d = document.createElement('dialog');
        d.id = 'mark-sheet';
        d.className = 'sheet';
        d.dataset.turboTemporary = '';
        d.innerHTML = await r.text();
        document.body.append(d);
        d.addEventListener('click', (e) => { if (e.target === d) closeSheet(d); });
        d.addEventListener('close', () => d.remove());
        d.querySelector('[data-mark-close]')?.addEventListener('click', () => closeSheet(d));

        const img = d.querySelector('.mark-view img');
        d.querySelectorAll('[data-mark-show]').forEach((b) => b.addEventListener('click', () => {
            img.src = img.dataset[b.dataset.markShow];
            d.querySelectorAll('[data-mark-show]').forEach((x) => x.setAttribute('aria-current', String(x === b)));
        }));

        const post = (id, body) => fetch(`${owner.urlValue}/${id}/mark`, {
            method: 'POST',
            headers: { Accept: 'text/vnd.turbo-stream.html', 'X-CSRF-TOKEN': owner.token, 'X-Requested-With': 'XMLHttpRequest', ...(owner.rowId ? { 'X-Photos-Target': owner.rowId } : {}) },
            body,
        });
        const form = (fields) => { const f = new FormData(); Object.entries(fields).forEach(([k, v]) => f.append(k, v)); return f; };
        const finish = (ids) => {
            ids.forEach((id) => owner.bust(id));
            closeSheet(d);
            const next = this.items();
            this.viewer?.refresh(next, Math.max(0, next.findIndex((n) => n.id === it.id)));
        };
        const busy = async (job) => {
            if (d.getAttribute('aria-busy') === 'true') return;
            d.setAttribute('aria-busy', 'true');
            try { await job(); } finally { d.removeAttribute('aria-busy'); }
        };
        const send = (body) => busy(async () => {
            const res = await post(it.id, body);
            if (!res.ok) {
                const message = (await res.json().catch(() => null))?.message;
                window.toast?.(message || 'Не получилось', 'danger');
                return;
            }
            owner.apply(await res.text());
            finish([it.id]);
        });
        d.querySelectorAll('[data-mark-act="mark"]').forEach((b) => b.addEventListener('click', () => send(form({ act: 'mark', mark: b.dataset.mark }))));
        // «Со всех фото»: по кадру за запрос (ручной поиск знака — секунды на кадр), ход — в подписи строки.
        d.querySelectorAll('[data-mark-all]').forEach((b) => b.addEventListener('click', () => busy(async () => {
            const ids = b.dataset.markAll.split(',');
            const label = b.querySelector('[data-mark-label]');
            let done = 0, last = null;
            for (const [k, id] of ids.entries()) {
                label.textContent = `Снимаем ${k + 1} из ${ids.length}`;
                const res = await post(id, form({ act: 'mark', mark: b.dataset.mark }));
                if (res.ok) { done++; last = await res.text(); }
            }
            if (last) owner.apply(last);
            window.toast?.(done === ids.length ? `Снято со всех ${ids.length}` : `Снято ${done} из ${ids.length}`, done ? undefined : 'danger');
            finish(ids);
        })));
        d.querySelector('[data-mark-act="undo"]')?.addEventListener('click', async (e) => {
            if (await confirmSheet(e.currentTarget.dataset.confirm)) send(form({ act: 'undo' }));
        });
        d.querySelector('[data-mark-file]')?.addEventListener('change', (e) => {
            const file = e.target.files?.[0];
            if (file) send(form({ file }));
        });
        // «Новый знак»: рамка по кадру (доли кадра), название площадки — знак собирается по фото предложения.
        const frame = d.querySelector('[data-mark-frame]');
        const box = d.querySelector('[data-mark-box]');
        const learn = d.querySelector('[data-mark-learn]');
        const title = learn?.querySelector('input');
        const go = learn?.querySelector('[data-mark-learn-go]');
        let start = null, rect = null;
        const point = (e) => {
            const r = img.getBoundingClientRect();
            const clamp = (v) => Math.min(1, Math.max(0, v));
            return [clamp((e.clientX - r.left) / r.width), clamp((e.clientY - r.top) / r.height)];
        };
        const sync = () => {
            box.hidden = !rect;
            if (rect) Object.assign(box.style, { left: `${rect[0] * 100}%`, top: `${rect[1] * 100}%`, width: `${(rect[2] - rect[0]) * 100}%`, height: `${(rect[3] - rect[1]) * 100}%` });
            if (go) go.disabled = !(rect && rect[2] - rect[0] > 0.03 && rect[3] - rect[1] > 0.02 && title.value.trim());
        };
        d.querySelector('[data-mark-learn-open]')?.addEventListener('click', () => {
            d.querySelectorAll('[data-mark-main]').forEach((el) => { el.hidden = true; });
            learn.hidden = false;
            d.querySelector('[data-mark-head]').textContent = 'Обведите знак';
            frame.classList.add('is-drawing');
        });
        frame.addEventListener('pointerdown', (e) => {
            if (!frame.classList.contains('is-drawing')) return;
            e.preventDefault();
            frame.setPointerCapture(e.pointerId);
            start = point(e);
            rect = null;
            sync();
        });
        frame.addEventListener('pointermove', (e) => {
            if (!start) return;
            const p = point(e);
            rect = [Math.min(start[0], p[0]), Math.min(start[1], p[1]), Math.max(start[0], p[0]), Math.max(start[1], p[1])];
            sync();
        });
        frame.addEventListener('pointerup', () => { start = null; sync(); });
        // шторка тянется за палец с любого места: рамку рисуют, а не закрывают шторку
        ['touchstart', 'touchmove'].forEach((t) => frame.addEventListener(t, (e) => {
            if (frame.classList.contains('is-drawing')) e.stopPropagation();
        }, { passive: true }));
        title?.addEventListener('input', sync);
        go?.addEventListener('click', () => busy(async () => {
            const label = go.textContent;
            go.textContent = 'Собираем знак';
            try {
                const res = await fetch(`${owner.urlValue}/${it.id}/learn`, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': owner.token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: form({ title: title.value.trim(), x0: rect[0], y0: rect[1], x1: rect[2], y1: rect[3] }),
                });
                const answer = await res.json().catch(() => ({}));
                if (!res.ok) { window.toast?.(answer.message || 'Не получилось', 'danger'); return; }
                window.toast?.(answer.message);
                closeSheet(d);
                this.markSheet(it);
            } finally {
                go.textContent = label;
            }
        }));
        // Без своей записи в истории: «Назад» закрывает просмотрщик под шторкой, как у подтверждения.
        openSheet(d, { history: false });
    }

    showProgress(label, ratio) {
        if (!this.hasProgressTarget) return;
        this.progressTarget.hidden = false;
        this.progressTarget.querySelector('[data-label]').textContent = label;
        this.progressTarget.querySelector('[data-bar]').style.width = `${Math.round(ratio * 100)}%`;
    }

    hideProgress() { if (this.hasProgressTarget) this.progressTarget.hidden = true; }

    get token() { return document.querySelector('meta[name=csrf-token]').content; }
}

const hasFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');

// Кадр — картинка или HEIC (у HEIC на Windows и Android тип бывает пустым).
const isImage = (f) => f.type.startsWith('image/') || /\.(heic|heif)$/i.test(f.name);

// Кадр в кэше браузера: по load, decode — только ускорение (в фоновой вкладке он не завершается вовсе).
function preload(src) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => withTimeout(img.decode().catch(() => {}), 300).then(resolve);
        img.onerror = resolve;
        img.src = src;
    });
}

// Контроллеры узла и его потомков с их значениями — одной строкой для сравнения.
function controllers(root) {
    return [root, ...root.querySelectorAll('[data-controller]')].filter((el) => el.dataset.controller)
        .map((el) => [...el.attributes].filter((a) => a.name === 'data-controller' || a.name.endsWith('-value')).map((a) => `${a.name}=${a.value}`).sort().join(' '))
        .join('|');
}

function withTimeout(promise, ms) {
    return Promise.race([promise, new Promise((r) => setTimeout(r, ms))]);
}
