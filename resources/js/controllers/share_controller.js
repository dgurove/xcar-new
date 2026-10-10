import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet, backdrop } from '../sheet';
import { fileName } from '../docs/share';

// Поделиться оффером или машиной закупки. Текст и файл уходят порознь: вместе
// мессенджеры теряют файл. Текст кладётся в буфер до первого await — share()
// требует свежего жеста, а Safari без жеста в буфер не пишет.
//
// PDF собирается заранее (fetch при открытии шторки), «Отправить» до готовности
// выключена: иначе в лист уходил один текст. Файл уходит через
// navigator.share({files}) — и на компьютере тоже (10.10.2026, владелец: «пусть будет
// именно поделиться, открывать документ не надо»); где листа нет или он однажды упал —
// файл скачивается. Скачать и так — строка «PDF готов, …» со значком справа.
// Каждый сбой — тостом и на сервер (/share/error): иначе с чужого телефона не видно ничего.
//
// «Фото» — те же кадры JPEG-ами одним вызовом share: так их отдаёт Google Фото, и
// WhatsApp собирает альбом, а получатель качает картинки там, где документ не
// качается. Качаются следом за PDF, чтобы не отнимать у него связь. Больше 10
// файлов Chrome в лист не пускает.
const BROKEN = 'share:open';
const MAX_FILES = 10;
// Сколько ждём системный лист, прежде чем признать его зависшим: промис share
// тогда не выполняется и не падает, а флаг отправки режет повторные нажатия.
const SHARE_HANG_MS = 15000;
// iPad с iPadOS 13 представляется Mac — его выдают касания.
const IOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const PRICES = ['publish_price', 'price'];
const FROM = ['publish_price'];

export default class extends Controller {
    static targets = ['dialog', 'field', 'photo', 'watermark', 'preview', 'status', 'statusRow', 'statusIcon', 'send', 'label', 'all', 'photosSend', 'photosLabel'];
    static values = { url: String, photoUrl: String, vat: String, name: String, locked: String };

    connect() {
        this.compose();
        this.ready(!this.hasPhotoTarget);
        this.photosReady(false);
        this.countPhotos();
    }

    countPhotos() {
        const n = this.selectedPhotos().length;
        if (this.hasPhotosLabelTarget) this.photosLabelTarget.textContent = n ? `Поделиться ${n} фото` : 'Поделиться фото';
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
        backdrop(this.dialogTarget, event, () => this.close());
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
        this.countPhotos();
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

    ready(on, text = '') {
        if (this.hasSendTarget) this.sendTarget.disabled = !on;
        if (this.hasLabelTarget) this.labelTarget.textContent = on ? 'Поделиться PDF' : text || 'Поделиться PDF';
    }

    photosReady(on) {
        if (this.hasPhotosSendTarget) this.photosSendTarget.setAttribute('aria-disabled', on ? 'false' : 'true');
    }

    async prepare() {
        // Ответ на прежний набор фото, пришедший после нового, не должен стать «готовым».
        const seq = (this.seq = (this.seq || 0) + 1);
        this.pdf = null;
        this.jpegs = null;
        this.photosError = null;
        this.photosReady(false);
        const photos = this.selectedPhotos();
        if (!photos.length) { this.status(''); this.ready(true); return; }
        await this.preparePdf(seq, photos);
        if (seq === this.seq) await this.preparePhotos(seq, photos);
    }

    // Кадры по одному, по три за раз. Сбой не кричит тостом — его покажет нажатие «Фото».
    async preparePhotos(seq, photos) {
        if (!this.hasPhotosSendTarget || photos.length > MAX_FILES) return;
        const files = new Array(photos.length);
        let next = 0;
        const worker = async () => {
            while (next < photos.length && seq === this.seq) {
                const i = next++;
                const r = await fetch(`${this.photoUrlValue.replace('{id}', photos[i])}?watermark=${this.watermark()}`, { credentials: 'same-origin' });
                if (!r.ok) throw Object.assign(new Error(`http ${r.status}`), { name: `http ${r.status}` });
                const blob = await r.blob();
                files[i] = new File([blob], fileName(r, `${this.nameValue.replace(/\.pdf$/, '')} ${i + 1}`), { type: 'image/jpeg' });
            }
        };
        try {
            await Promise.all([worker(), worker(), worker()]);
            if (seq !== this.seq) return;
            this.jpegs = files;
            this.photosReady(true);
        } catch (e) {
            if (seq !== this.seq) return;
            this.photosError = e;
            this.photosReady(true);
        }
    }

    async preparePdf(seq, photos) {
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
            this.status(`PDF готов, ${photos.length} фото, ${Math.round(this.pdf.size / 1024)} КБ`, true);
            this.ready(true);
        } catch (e) {
            if (seq === this.seq) this.fail('prepare', e, 'Связь с сервером потерялась');
        }
    }

    // download — строка готового PDF нажимается и скачивает его (значок справа).
    status(text, download = false) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
        if (this.hasStatusRowTarget) this.statusRowTarget.disabled = !download;
        if (this.hasStatusIconTarget) this.statusIconTarget.classList.toggle('hidden', !download);
    }

    downloadPdf() {
        if (this.pdf) this.download([this.pdf]);
    }

    writeCaption() {
        // Переносы на iPhone — U+2028: подпись к файлу в WhatsApp там режет \n, а этот разделитель пропускает. На Android
        // у U+2028 нет начертания (квадратик), а подпись многострочная — там и на компьютере обычный \n.
        const text = IOS ? this.caption().replace(/\n/g, '\u2028') : this.caption();
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

    async send() {
        if (this.sending) return;
        this.sending = true;
        // Тап виден сразу: иначе пока система думает над листом, кнопка молчит
        // и человек жмёт снова в пустоту.
        this.ready(false, 'Открываем…');
        try { await this.share(); } catch (e) { this.fail('share', e, 'Не получилось поделиться'); }
        finally { this.sending = false; this.ready(true); }
    }

    // sessionStorage бросает, когда печеньки запрещены: тогда любая отправка
    // умирала молча, без тоста и без маяка.
    broken() { try { return sessionStorage.getItem(BROKEN); } catch { return null; } }
    markBroken() { try { sessionStorage.setItem(BROKEN, '1'); } catch {} }

    async share() {
        this.writeCaption();
        if (this.pdf) {
            const files = [this.pdf];
            const facts = { files: files.length, bytes: files.reduce((s, f) => s + f.size, 0), canshare: !!navigator.canShare?.({ files }), hasshare: typeof navigator.share };
            // Листа с файлом нет (Firefox, старый Chrome на Windows) или он уже падал — файл скачивается.
            if (!facts.canshare || this.broken()) { this.download([this.pdf]); return; }
            let hang;
            try {
                await Promise.race([
                    navigator.share({ files }),
                    new Promise((_, reject) => { hang = setTimeout(() => reject(Object.assign(new Error('timeout'), { name: 'ShareTimeout' })), SHARE_HANG_MS); }),
                ]);
                this.close();
            } catch (e) {
                if (e.name === 'AbortError') return;
                if (e.name === 'ShareTimeout') {
                    this.beacon('share-hang', e, facts);
                    this.status('Лист открывается долго — нажмите ещё раз');
                    return;
                }
                this.markBroken();
                this.fail('share', e, 'Лист не открылся, скачиваем PDF', facts);
                this.download([this.pdf]);
            } finally {
                clearTimeout(hang);
            }
            return;
        }
        if (navigator.share) {
            try { await navigator.share({ text: this.caption() }); this.close(); } catch {}
            return;
        }
        window.toast?.('Текст в буфере');
    }

    async sendPhotos() {
        if (this.sending) return;
        this.writeCaption();
        const count = this.selectedPhotos().length;
        if (!count) { window.toast?.('Отметьте фото'); return; }
        if (count > MAX_FILES) { window.toast?.(`Не больше ${MAX_FILES} фото`); return; }
        if (this.photosError) {
            this.fail('photos-prepare', this.photosError, 'Фото не скачались');
            this.prepare();
            return;
        }
        if (!this.jpegs) { window.toast?.('Фото ещё качаются'); return; }
        const files = this.jpegs;
        // Листа нет вовсе (компьютер) — фото скачиваются.
        if (!navigator.share) { this.download(files); return; }
        const facts = { files: files.length, bytes: files.reduce((s, f) => s + f.size, 0), canshare: !!navigator.canShare?.({ files }) };
        // canShare не верим: Яндекс Браузер и Samsung Internet на Android отвечают «нет» про несколько картинок, хотя
        // лист их может взять (Chrome файлы в canShare вовсе не проверяет) — пробуем, отказ пишем в share.log.
        if (!facts.canshare) this.beacon('photos-canshare', { name: 'canShare false' }, facts);
        this.sending = true;
        try {
            await navigator.share({ files });
            this.close();
        } catch (e) {
            if (e.name === 'AbortError') return;
            // Первый share() израсходовал жест: PDF — новым нажатием, кнопкой в тосте.
            this.beacon('photos', e, facts);
            this.status('Браузер не отдаёт фото пачкой');
            if (this.pdf) window.toast?.('Браузер не отдаёт фото пачкой', { action: { label: 'Отправить PDF', run: () => this.send() } });
            else window.toast?.('Браузер не отдаёт фото пачкой', 'danger');
        } finally {
            this.sending = false;
        }
    }

    download(files) {
        files.forEach((file, i) => setTimeout(() => {
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(file), download: file.name });
            a.dataset.doc = 'off';
            document.body.append(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 10000);
        }, i * 250));
        const pdf = files[0]?.type === 'application/pdf';
        window.toast?.(pdf ? 'Скачиваем PDF' : files.length > 1 ? `Скачиваем ${files.length} фото` : 'Скачиваем фото');
    }

    fail(stage, e, text, facts = {}) {
        this.status(text);
        window.toast?.(text, 'danger');
        this.beacon(stage, e, facts);
    }

    beacon(stage, e, facts = {}) {
        // _token в теле: заголовков у sendBeacon нет, без него 419.
        navigator.sendBeacon?.('/share/error', new Blob([JSON.stringify({
            _token: document.querySelector('meta[name=csrf-token]')?.content, stage, name: e.name || '?', message: String(e.message ?? '').slice(0, 300),
            standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true, ...facts,
        })], { type: 'application/json' }));
    }
}
