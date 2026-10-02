import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { confirmSheet } from '../confirm';
import { loadSortable } from '../lib/sortable';
import { openLightbox } from '../lightbox';

// Фотографии: загрузка по одному файлу с прогрессом (выбор или drop на карточку), перестановка перетаскиванием,
// действия глаз, поворот, корзина на плитке и в просмотрщике.
// Сервер на каждое действие возвращает turbo-stream с новой полосой; полоса
// подменяется сразу (не через Turbo), чтобы просмотрщик и Sortable пересобрались
// на новом узле тут же. readonly — только смотреть (кадры из письма рядом с приёмом): без
// перестановки, без действий на плитке и в просмотрщике. any — зона принимает любой файл (предложение CRM): документ
// и архив сервер разложит сам, картинку из буфера кладёт ⌘V в любом месте страницы.
export default class extends Controller {
    static targets = ['input', 'progress', 'grid'];
    static values = { url: String, collection: { type: String, default: 'photos' }, stage: String, readonly: Boolean, reload: Boolean, group: String, any: Boolean };

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
        // вставка своя: кусок страницы портала несёт и текст, и картинку — человеку нужен текст. Скрытое окошко
        // (закрытый peek) кадры не ловит.
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
                    const html = await this.send(await this.prepare(job.file), (p) => job.cell.style.setProperty('--p', p));
                    this.pendingCells.delete(job.cell);
                    job.cell.remove();
                    if (this.fresher(html)) {
                        this.apply(html);
                        this.restorePending();
                    }
                } catch (e) {
                    job.cell.classList.add('is-failed');
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

    send({ file, sha }, onProgress) {
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

    async perform(id, act, confirm) {
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
        if (act === 'rotate') this.bust(id);
        return true;
    }

    post(url, body, type) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': type, Accept: 'text/vnd.turbo-stream.html', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest', ...(this.rowId ? { 'X-Photos-Target': this.rowId } : {}) },
            body,
        });
    }

    // Какой ряд кадров перерисовать ответом: у редактора и у окошка они разные (id у ряда свой).
    get rowId() {
        return this.element.querySelector('.photo-row[id], .photo-grid[id]')?.id ?? '';
    }

    // Применить turbo-stream прямо сейчас (replace / append / prepend / update).
    apply(html) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('turbo-stream').forEach((stream) => {
            const target = document.getElementById(stream.getAttribute('target'));
            const content = stream.querySelector('template')?.content.cloneNode(true);
            if (!target || !content) return;
            switch (stream.getAttribute('action')) {
                case 'append': target.append(content); break;
                case 'prepend': target.prepend(content); break;
                case 'update': target.replaceChildren(content); break;
                default: target.replaceWith(content);
            }
        });
    }

    // После поворота у файла тот же адрес: добавить метку времени, чтобы браузер не показал старый кадр.
    bust(id) {
        const img = this.hasGridTarget ? this.cell(id)?.querySelector('img') : null;
        if (!img) return;
        const stamp = (u) => u + (u.includes('?') ? '&' : '?') + 't=' + Date.now();
        img.src = stamp(img.src);
        img.dataset.full = stamp(img.dataset.full);
    }

    cells() { return [...this.gridTarget.querySelectorAll('.photo-cell')]; }

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
            if (!it?.id || it.owner.readonlyValue) return false;
            return act !== 'hide' || !!it.owner.element.querySelector(`.photo-cell[data-id="${it.id}"] [data-act="hide"]`);
        };
        const act = (act, confirm) => async (i) => {
            const it = this.viewer.items[i];
            if (!can(i, act) || !(await it.owner.perform(it.id, act, confirm))) return;
            const next = this.items();
            const at = act === 'delete' ? Math.min(i, next.length - 1) : next.findIndex((n) => n.id === it.id);
            this.viewer.refresh(next, at);
        };
        const hidden = (i) => (this.viewer?.items ?? items)[i]?.el.closest('.photo-cell')?.dataset.hidden === '1';
        const lightbox = await openLightbox({
            items, index, download: true,
            actions: [
                { name: 'eye', icon: 'eye-off', title: 'Скрыть', iconFor: (i) => (hidden(i) ? 'eye' : 'eye-off'), titleFor: (i) => (hidden(i) ? 'Показать' : 'Скрыть'), shown: (i) => can(i, 'hide'), run: act('hide') },
                { name: 'rotate', icon: 'rotate', title: 'Повернуть', shown: (i) => can(i, 'rotate'), run: act('rotate') },
                { name: 'trash', icon: 'trash', title: 'Удалить', shown: (i) => can(i, 'delete'), run: act('delete', 'Удалить фото?') },
            ],
            onClose: () => { this.viewer = null; },
        });
        if (lightbox) this.viewer = lightbox;
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
