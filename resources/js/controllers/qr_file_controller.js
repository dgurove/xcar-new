import { Controller } from '@hotwired/stimulus';

// QR картинкой (x-billing.pay-qr): «Скачать» — файлом, «Отправить» — системный лист с одним файлом. Файл собирается
// из data-URI картинки синхронно: до share() нет ни одного await, иначе Safari теряет жест. В share только files —
// с текстом мессенджеры теряют файл. Листа нет (ПК) — «Отправить» прячется.
export default class extends Controller {
    static targets = ['image', 'share', 'download'];
    static values = { name: String };

    connect() {
        // Листа нет — «Скачать» остаётся одна и становится главной.
        if (this.hasShareTarget && !navigator.share) {
            this.shareTarget.hidden = true;
            this.downloadTarget.classList.replace('btn-quiet', 'btn-accent');
        }
    }

    file() {
        const [, data] = this.imageTarget.src.split(',');
        const bytes = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
        return new File([bytes], this.nameValue, { type: 'image/png' });
    }

    async share() {
        const files = [this.file()];
        if (!navigator.canShare?.({ files })) { this.download(); return; }
        try {
            await navigator.share({ files });
        } catch (e) {
            if (e.name !== 'AbortError') window.toast?.('Лист не открылся, скачайте QR', 'danger');
        }
    }

    download() {
        const file = this.file();
        // data-doc="off": иначе ссылку перехватит шторка документов.
        const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(file), download: file.name });
        a.dataset.doc = 'off';
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 10000);
    }
}
