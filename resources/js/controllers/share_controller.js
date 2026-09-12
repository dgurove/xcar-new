import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet } from '../sheet';

// Поделиться оффером. Текст и файл уходят порознь: вместе мессенджеры теряют
// файл. Текст кладётся в буфер до первого await — share() требует свежего
// жеста, а Safari без жеста в буфер не пишет. Переносы — U+2028: в
// однострочной подписи WhatsApp \n режется, а разделитель строки проходит.
export default class extends Controller {
    static targets = ['dialog', 'field', 'photo', 'watermark', 'preview', 'status', 'send', 'download'];
    static values = { url: String, vat: String, name: String };

    connect() {
        this.compose();
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

    caption() {
        const lines = [];
        const price = [];
        for (const f of this.fieldTargets) {
            if (!f.checked || !f.dataset.value) continue;
            if (f.dataset.key === 'floor_price' || f.dataset.key === 'price') { price.push(f.dataset.value); continue; }
            if (price.length && f.dataset.key !== 'number') { lines.push(price.join(' → ') + this.vatValue); price.length = 0; }
            lines.push(f.dataset.value);
            if (f.dataset.key === 'number' && price.length) { lines.push(price.join(' → ') + this.vatValue); price.length = 0; }
        }
        if (price.length) lines.push(price.join(' → ') + this.vatValue);
        return lines.join('\n');
    }

    compose() {
        if (this.hasPreviewTarget) this.previewTarget.textContent = this.caption();
    }

    photosChanged() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.prepare(), 500);
    }

    selectedPhotos() {
        return this.photoTargets.filter((p) => p.checked).map((p) => p.value);
    }

    async prepare() {
        this.pdf = null;
        const photos = this.selectedPhotos();
        if (!photos.length) { this.status(''); return; }
        this.status('Собираем PDF…');
        const form = new FormData();
        photos.forEach((id) => form.append('photos[]', id));
        if (this.hasWatermarkTarget && !this.watermarkTarget.checked) form.append('watermark', '0'); else form.append('watermark', '1');
        try {
            const r = await fetch(this.urlValue, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/pdf' } });
            if (!r.ok) { const d = await r.json().catch(() => ({})); this.status(d.message || 'PDF не собрался'); return; }
            this.pdf = new File([await r.blob()], this.nameValue, { type: 'application/pdf' });
            this.status(`PDF готов, ${photos.length} фото, ${Math.round(this.pdf.size / 1024)} КБ`);
        } catch {
            this.status('Связь с сервером потерялась');
        }
    }

    status(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    writeCaption() {
        const text = this.caption().replace(/\n/g, ' ');
        if (!text) return false;
        try { navigator.clipboard.writeText(text); return true; } catch { return false; }
    }

    copy() {
        if (this.writeCaption()) window.toast?.('Текст в буфере');
    }

    download() {
        if (!this.pdf) return;
        const a = document.createElement('a');
        a.href = URL.createObjectURL(this.pdf);
        a.download = this.nameValue;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 10000);
    }

    async send() {
        if (this.sending) return;
        this.sending = true;
        try { await this.share(); } finally { this.sending = false; }
    }

    async share() {
        this.writeCaption();
        if (this.pdf && navigator.canShare?.({ files: [this.pdf] })) {
            try { await navigator.share({ files: [this.pdf] }); this.close(); } catch {}
            return;
        }
        if (navigator.share) {
            try { await navigator.share({ text: this.caption() }); this.close(); } catch {}
            return;
        }
        window.toast?.('Текст в буфере, PDF — кнопкой «Скачать»');
    }
}
