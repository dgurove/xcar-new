import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';
import Sortable from 'sortablejs';

// Фотографии: загрузка по одному файлу с прогрессом, перестановка перетаскиванием.
// Сервер на каждое действие возвращает turbo-stream с новой галереей.
export default class extends Controller {
    static targets = ['input', 'progress', 'grid'];
    static values = { url: String, collection: { type: String, default: 'photos' } };

    connect() {
        if (this.hasGridTarget) {
            this.sortable = Sortable.create(this.gridTarget, {
                animation: 150,
                delay: 150,
                delayOnTouchOnly: true,
                onEnd: () => this.reorder(),
            });
        }
    }

    disconnect() {
        this.sortable?.destroy();
    }

    pick() {
        this.inputTarget.click();
    }

    async upload() {
        const files = [...this.inputTarget.files];
        this.inputTarget.value = '';
        let n = 0;
        for (const file of files) {
            n++;
            this.showProgress(`${n} из ${files.length}`, 0);
            try {
                const html = await this.send(file, (p) => this.showProgress(`${n} из ${files.length}`, p));
                Turbo.renderStreamMessage(html);
            } catch (e) {
                window.toast?.(e.message || 'Файл не загрузился', 'danger');
            }
        }
        this.hideProgress();
    }

    send(file, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const form = new FormData();
            form.append('file', file);
            form.append('collection', this.collectionValue);
            form.append('_token', document.querySelector('meta[name=csrf-token]').content);
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
        const order = [...this.gridTarget.children].map((el) => el.dataset.id);
        const r = await fetch(this.urlValue + '/poryadok', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/vnd.turbo-stream.html', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ order }),
        });
        if (r.ok) Turbo.renderStreamMessage(await r.text());
    }

    showProgress(label, ratio) {
        this.progressTarget.hidden = false;
        this.progressTarget.querySelector('[data-label]').textContent = label;
        this.progressTarget.querySelector('[data-bar]').style.width = `${Math.round(ratio * 100)}%`;
    }

    hideProgress() {
        this.progressTarget.hidden = true;
    }
}
