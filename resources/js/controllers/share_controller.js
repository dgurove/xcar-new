import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet } from '../sheet';

// Поделиться оффером или машиной закупки. Текст и файл уходят порознь: вместе
// мессенджеры теряют файл. Текст кладётся в буфер до первого await — share()
// требует свежего жеста, а Safari без жеста в буфер не пишет.
//
// PDF собирается заранее (fetch при открытии шторки), «Отправить» до готовности
// выключена: иначе в лист уходил один текст. Файл уходит через
// navigator.share({files}); где его нет или он однажды упал — тот же адрес
// в шторке документов поверх окна («Открыть PDF», ссылка `a[data-doc]`): у неё
// свои «Поделиться» и «Скачать». Каждый сбой — тостом и на сервер (/share/error):
// иначе с чужого телефона не видно ничего.
const BROKEN = 'share:open';

const PRICES = ['publish_price', 'price'];
const FROM = ['publish_price'];

export default class extends Controller {
    static targets = ['dialog', 'field', 'photo', 'watermark', 'preview', 'status', 'send', 'label', 'all', 'pdfLink'];
    static values = { url: String, vat: String, name: String, locked: String };

    connect() {
        this.compose();
        this.ready(!this.hasPhotoTarget);
    }

    // Шеринг запрещён в редакторе оффера: кнопка серая, нажатие объясняет почему.
    locked() {
        window.toast?.(this.lockedValue);
    }

    open() {
        openSheet(this.dialogTarget);
        this.compose();
        this.prepare();
    }

    close() {
        closeSheet(this.dialogTarget);
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    // Строки сверху вниз, цены — одной последней строкой: «от → до» и метка НДС.
    caption() {
        const lines = [];
        const price = [];
        let link = null;
        let group = null;
        for (const f of this.fieldTargets) {
            if (!f.checked || !f.dataset.value) continue;
            if (PRICES.includes(f.dataset.key)) price.push(f.dataset.value);
            else if (f.dataset.key === 'link') link = f.dataset.value;
            // Соседние строки одной группы (характеристики) — одной строкой через «; ».
            else if (f.dataset.group && f.dataset.group === group) lines[lines.length - 1] += '; ' + f.dataset.value;
            else lines.push(f.dataset.value);
            group = f.dataset.group || null;
        }
        if (price.length) lines.push(price.join(' → ') + this.vatValue);
        // Ссылка — самой последней, после цены.
        if (link) lines.push(link);
        return lines.join('\n');
    }

    // Закупочная и заявленная — обе «от»: отметили одну — вторая снимается.
    pick(event) {
        const key = event.target.dataset.key;
        if (event.target.checked && FROM.includes(key)) {
            this.fieldTargets.forEach((f) => { if (f !== event.target && FROM.includes(f.dataset.key)) f.checked = false; });
        }
        this.compose();
    }

    compose() {
        if (this.hasPreviewTarget) this.previewTarget.textContent = this.caption();
    }

    // «Выбрать все» / «Снять все»: выбраны все — снимает, иначе отмечает все.
    toggleAll() {
        const every = this.photoTargets.every((p) => p.checked);
        this.photoTargets.forEach((p) => { p.checked = !every; });
        this.photosChanged();
    }

    photosChanged() {
        if (this.hasAllTarget) this.allTarget.textContent = this.photoTargets.every((p) => p.checked) ? 'Снять все' : 'Выбрать все';
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.prepare(), 500);
    }

    selectedPhotos() {
        return this.photoTargets.filter((p) => p.checked).map((p) => p.value);
    }

    watermark() {
        return this.hasWatermarkTarget && !this.watermarkTarget.checked ? '0' : '1';
    }

    // Тот же адрес, что у fetch, но строкой запроса — для шторки документов.
    openUrl() {
        const params = new URLSearchParams();
        this.selectedPhotos().forEach((id) => params.append('photos[]', id));
        params.set('watermark', this.watermark());
        return `${this.urlValue}?${params}`;
    }

    ready(on, text = '') {
        if (this.hasSendTarget) this.sendTarget.disabled = !on;
        if (this.hasLabelTarget) this.labelTarget.textContent = on ? 'Отправить' : text || 'Отправить';
    }

    async prepare() {
        // Ответ на прежний набор фото, пришедший после нового, не должен стать «готовым».
        const seq = (this.seq = (this.seq || 0) + 1);
        this.pdf = null;
        const photos = this.selectedPhotos();
        if (!photos.length) { this.status(''); this.ready(true); return; }
        this.ready(false, 'Собираем PDF…');
        this.status('');
        const form = new FormData();
        photos.forEach((id) => form.append('photos[]', id));
        form.append('watermark', this.watermark());
        try {
            const r = await fetch(this.urlValue, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/pdf' } });
            if (seq !== this.seq) return;
            if (!r.ok) {
                const d = await r.json().catch(() => ({}));
                this.fail('prepare', { name: `http ${r.status}`, message: d.message || '' }, d.message || 'PDF не собрался');
                return;
            }
            const blob = await r.blob();
            if (seq !== this.seq) return;
            this.pdf = new File([blob], this.nameValue, { type: 'application/pdf' });
            this.status(`PDF готов, ${photos.length} фото, ${Math.round(this.pdf.size / 1024)} КБ`);
            this.ready(true);
        } catch (e) {
            if (seq === this.seq) this.fail('prepare', e, 'Связь с сервером потерялась');
        }
    }

    status(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    writeCaption() {
        // Переносы — U+2028: подпись к файлу в WhatsApp режет \n, а этот разделитель пропускает (проверено на iPhone).
        const text = this.caption().replace(/\n/g, '\u2028');
        if (!text) return false;
        try { navigator.clipboard.writeText(text); return true; } catch { return false; }
    }

    copy() {
        if (this.writeCaption()) window.toast?.('Текст в буфере');
    }

    copyLink(event) {
        try {
            navigator.clipboard.writeText(event.currentTarget.dataset.link);
            window.toast?.('Ссылка в буфере');
        } catch { window.toast?.('Не получилось', 'danger'); }
    }

    // Нажатие «Открыть PDF» — до перехвата шторкой: ссылка уже с отмеченными фото. Без нажатия (лист не открылся) —
    // нажимаем её сами.
    openPdf(event) {
        if (!this.selectedPhotos().length) { event?.preventDefault(); window.toast?.('Отметьте фото'); return; }
        if (!this.hasPdfLinkTarget) return;
        this.pdfLinkTarget.href = this.openUrl();
        if (!event) this.pdfLinkTarget.click();
    }

    async send() {
        if (this.sending) return;
        this.sending = true;
        try { await this.share(); } finally { this.sending = false; }
    }

    async share() {
        this.writeCaption();
        if (this.pdf) {
            const files = [this.pdf];
            if (!navigator.canShare?.({ files }) || sessionStorage.getItem(BROKEN)) { this.openPdf(); return; }
            try {
                await navigator.share({ files });
                this.close();
            } catch (e) {
                if (e.name === 'AbortError') return;
                sessionStorage.setItem(BROKEN, '1');
                this.fail('share', e, 'Лист не открылся, нажмите «Открыть PDF»');
            }
            return;
        }
        if (navigator.share) {
            try { await navigator.share({ text: this.caption() }); this.close(); } catch {}
            return;
        }
        window.toast?.('Текст в буфере');
    }

    fail(stage, e, text) {
        this.status(text);
        window.toast?.(text, 'danger');
        // _token в теле: заголовков у sendBeacon нет, без него 419.
        navigator.sendBeacon?.('/share/error', new Blob([JSON.stringify({
            _token: document.querySelector('meta[name=csrf-token]')?.content, stage, name: e.name || '?', message: String(e.message ?? '').slice(0, 300),
            standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        })], { type: 'application/json' }));
    }
}
