import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import { confirmSheet } from '../confirm';
import { loadSortable } from '../lib/sortable';

// Фотографии: загрузка по одному файлу с прогрессом (выбор или drop на карточку), перестановка перетаскиванием,
// действия глаз · поворот · корзина на плитке и в просмотрщике.
// Сервер на каждое действие возвращает turbo-stream с новой полосой; полоса
// подменяется сразу (не через Turbo), чтобы просмотрщик и Sortable пересобрались
// на новом узле тут же. readonly — только смотреть (кадры из письма рядом с приёмом): без
// перестановки, без действий на плитке и в просмотрщике.
export default class extends Controller {
    static targets = ['input', 'progress', 'grid'];
    static values = { url: String, collection: { type: String, default: 'photos' }, readonly: Boolean, reload: Boolean };

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
            filter: '.photo-actions',
            preventOnFilter: false,
            onEnd: () => this.reorder(),
        });
    }

    disconnect() {
        this.element.removeEventListener('dragover', this.over);
        this.element.removeEventListener('dragleave', this.leave);
        this.element.removeEventListener('drop', this.drop);
        this.sortable?.destroy();
        this.viewer?.destroy();
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
    async uploadFiles(files) {
        if (this.uploading || !files.length) return;
        if (!this.hasGridTarget || this.collectionValue !== 'photos') return this.uploadWithBar(files);
        this.uploading = true;
        this.pendingCells ??= new Map();
        const cells = files.map((file) => this.pendingCell(file));
        const wake = await navigator.wakeLock?.request?.('screen').catch(() => null);
        let next = this.prepare(files[0]);
        for (let i = 0; i < files.length; i++) {
            const cell = cells[i];
            const prepared = await next;
            next = files[i + 1] ? this.prepare(files[i + 1]) : null;
            try {
                const html = await this.send(prepared, (p) => cell.style.setProperty('--p', p));
                this.pendingCells.delete(cell);
                cell.remove();
                this.apply(html);
                this.restorePending();
            } catch (e) {
                cell.classList.add('is-failed');
                cell.title = e.message || 'Не загрузилось';
            }
        }
        wake?.release?.().catch(() => {});
        this.uploading = false;
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
            for (const [k, v] of Object.entries(this.extra || {})) if (v) form.append(k, v);
            form.append('_token', this.token);
            xhr.open('POST', this.urlValue);
            xhr.setRequestHeader('Accept', 'text/vnd.turbo-stream.html');
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
            headers: { 'Content-Type': type, Accept: 'text/vnd.turbo-stream.html', 'X-CSRF-TOKEN': this.token, 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
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
        const img = this.cell(id)?.querySelector('img');
        if (!img) return;
        const stamp = (u) => u + (u.includes('?') ? '&' : '?') + 't=' + Date.now();
        img.src = stamp(img.src);
        img.dataset.full = stamp(img.dataset.full);
    }

    cells() { return [...this.gridTarget.querySelectorAll('.photo-cell')]; }

    cell(id) { return this.gridTarget.querySelector(`.photo-cell[data-id="${id}"]`); }

    // ---- просмотрщик

    async open(event) {
        const index = this.cells().indexOf(event.currentTarget.closest('.photo-cell'));
        if (!this.Viewer) {
            const [{ default: Viewer }] = await Promise.all([import('viewerjs'), import('viewerjs/dist/viewer.css')]);
            this.Viewer = Viewer;
        }
        this.show(index);
    }

    show(index) {
        this.viewer?.destroy();
        const canHide = !!this.gridTarget.querySelector('[data-act="hide"]');
        const toolbar = { prev: 1, zoomOut: 1, zoomIn: 1, next: 1 };
        if (canHide) toolbar.eye = { show: 1, size: 'large', click: () => this.fromViewer('hide') };
        if (!this.readonlyValue) {
            toolbar.rotate = { show: 1, size: 'large', click: () => this.fromViewer('rotate') };
            toolbar.trash = { show: 1, size: 'large', click: () => this.fromViewer('udalit', 'Удалить фото?') };
        }
        this.viewer = new this.Viewer(this.gridTarget, {
            url: (img) => img.dataset.full,
            filter: (img) => !!img.dataset.full,
            navbar: true, title: false, transition: false, toolbar,
            initialViewIndex: Math.max(0, index),
            viewed: () => this.markEye(),
            hidden: () => { this.viewer?.destroy(); this.viewer = null; },
        });
        this.viewer.show();
    }

    current() { return this.cells()[this.viewer?.index ?? 0]; }

    async fromViewer(act, confirm) {
        const cellEl = this.current();
        if (!cellEl) return;
        const index = this.viewer.index;
        if (!(await this.perform(cellEl.dataset.id, act, confirm))) return;
        const n = this.cells().length;
        if (n === 0) { this.viewer?.destroy(); this.viewer = null; return; }
        // Полоса заменена целиком — пересобрать просмотрщик на новом узле, на том же кадре.
        this.show(Math.min(index, n - 1));
    }

    markEye() {
        const eye = this.viewer?.viewer?.querySelector('.viewer-eye');
        if (eye) eye.classList.toggle('is-off', this.current()?.dataset.hidden === '1');
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
