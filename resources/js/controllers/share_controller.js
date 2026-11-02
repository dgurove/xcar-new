import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet, backdrop } from '../sheet';
import { fileName } from '../docs/share';
import { copyText } from '../lib/clipboard';
import { debounce } from '../lib/debounce';
import { csrf, request } from '../lib/http';

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
// файлов Chrome в лист не пускает — тогда вторая кнопка «Поделиться ZIP» (02.11.2026, владелец: «выбирать все, хоть
// сотни»): архив с папками групп собирает сервер. Кадров больше 10 — сервер сначала готовит их пачками (`warm`, ход
// строкой «Готовим фото 30 из 240»), PDF и ZIP потом лишь складывают готовые.
const WARM_BATCH = 10;
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
    static targets = ['dialog', 'field', 'photo', 'watermark', 'preview', 'status', 'statusRow', 'statusIcon', 'send', 'label', 'all', 'photosSend', 'photosLabel', 'group', 'tabCount', 'groupCount'];
    static values = { url: String, photoUrl: String, vat: String, name: String, locked: String, warmUrl: String, zipUrl: String, zipName: String };

    connect() {
        this.later = debounce(() => this.prepare(), 500);
        this.compose();
        this.ready(!this.hasPhotoTarget);
        this.photosReady(false);
        this.countPhotos();
        this.labelGroups();
    }

    disconnect() {
        this.later.cancel();
    }

    countPhotos() {
        const n = this.selectedPhotos().length;
        if (this.hasPhotosLabelTarget) this.photosLabelTarget.textContent = n > MAX_FILES ? 'Поделиться ZIP' : n ? `Поделиться ${n} фото` : 'Поделиться фото';
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

    // «Выбрать все» / «Снять все» своей группы («от страховой», «при приёме»): выбраны все — снимает, иначе отмечает все.
    toggleAll(event) {
        const photos = this.groupPhotos(event.currentTarget.closest('[data-share-target="group"]'));
        const every = photos.every((p) => p.checked);
        photos.forEach((p) => { p.checked = !every; });
        this.photosChanged();
    }

    groupPhotos(group) {
        return group ? this.photoTargets.filter((p) => group.contains(p)) : this.photoTargets;
    }

    // «Выбрать все» / «Снять все» своей группы и «N из M» — у вкладки и над кадрами.
    labelGroups() {
        this.groupTargets.forEach((group, i) => {
            const photos = this.groupPhotos(group);
            const on = photos.filter((p) => p.checked).length;
            const all = this.allTargets.find((a) => group.contains(a));
            if (all) all.textContent = on === photos.length ? 'Снять все' : 'Выбрать все';
            const text = `${on} из ${photos.length}`;
            if (this.tabCountTargets[i]) this.tabCountTargets[i].textContent = text;
            const count = this.groupCountTargets.find((c) => group.contains(c));
            if (count) count.textContent = text;
        });
    }

    // Вкладка группы кадров: видна одна группа.
    tab(event) {
        const i = Number(event.target.value);
        this.groupTargets.forEach((g, n) => { g.hidden = n !== i; });
    }

    photosChanged() {
        this.countPhotos();
        this.labelGroups();
        this.later();
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
        this.zip = null;
        this.photosError = null;
        this.photosReady(false);
        const photos = this.selectedPhotos();
        if (!photos.length) { this.status(''); this.ready(true); return; }
        if (photos.length > MAX_FILES && !(await this.warm(seq, photos))) return;
        if (seq !== this.seq) return;
        await this.preparePdf(seq, photos);
        if (seq !== this.seq) return;
        if (photos.length > MAX_FILES) await this.prepareZip(seq, photos);
        else await this.preparePhotos(seq, photos);
    }

    // Кадры заранее, пачками по 10 в два потока (сотни фото: PDF за один запрос не собрать), ход — строкой.
    async warm(seq, photos) {
        this.ready(false, 'Готовим фото…');
        const batches = [];
        for (let i = 0; i < photos.length; i += WARM_BATCH) batches.push(photos.slice(i, i + WARM_BATCH));
        let done = 0;
        let next = 0;
        const worker = async () => {
            while (next < batches.length && seq === this.seq) {
                const batch = batches[next++];
                const form = new FormData();
                batch.forEach((id) => form.append('photos[]', id));
                form.append('watermark', this.watermark());
                const r = await request(this.warmUrlValue, { method: 'POST', body: form, quiet: true });
                if (!r?.ok) throw Object.assign(new Error('warm failed'), { name: 'warm' });
                done += batch.length;
                if (seq === this.seq) this.status(`Готовим фото ${done} из ${photos.length}`);
            }
        };
        try {
            await Promise.all([worker(), worker()]);
            return seq === this.seq;
        } catch (e) {
            if (seq === this.seq) this.fail('warm', e, 'Фото не подготовились');
            return false;
        }
    }

    // Архив тех же кадров с папками групп — для «Поделиться ZIP», когда фото больше 10.
    async prepareZip(seq, photos) {
        const form = new FormData();
        photos.forEach((id) => form.append('photos[]', id));
        form.append('watermark', this.watermark());
        try {
            const r = await request(this.zipUrlValue, { method: 'POST', body: form, accept: 'application/zip', statuses: [422], quiet: true });
            if (seq !== this.seq) return;
            if (!r?.ok) throw Object.assign(new Error('zip failed'), { name: `zip ${r?.status ?? 'request'}` });
            const blob = await r.blob();
            if (seq !== this.seq) return;
            this.zip = new File([blob], this.zipNameValue, { type: 'application/zip' });
            this.photosReady(true);
        } catch (e) {
            if (seq !== this.seq) return;
            this.photosError = e;
            this.photosReady(true);
        }
    }

    // Кадры по одному, по три за раз. Сбой не кричит тостом — его покажет нажатие «Фото».
    async preparePhotos(seq, photos) {
        if (!this.hasPhotosSendTarget || photos.length > MAX_FILES) return;
        const files = new Array(photos.length);
        let next = 0;
        const worker = async () => {
            while (next < photos.length && seq === this.seq) {
                const i = next++;
                const r = await request(`${this.photoUrlValue.replace('{id}', photos[i])}?watermark=${this.watermark()}`, { accept: 'image/*', quiet: true });
                if (!r) throw Object.assign(new Error('photo not loaded'), { name: 'photo' });
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

    // retry — второй заход: сервер не ответил (19.10.2026: запрос попал в перезапуск выкладки, «PDF не собрался» у
    // менеджера), через 3 с спрашиваем ещё раз, и только потом строка об ошибке.
    async preparePdf(seq, photos, retry = false) {
        this.ready(false, 'Собираем PDF…');
        this.status('');
        const form = new FormData();
        photos.forEach((id) => form.append('photos[]', id));
        form.append('watermark', this.watermark());
        try {
            // 422 — отказ словами сервера; обрыв и прочие сбои request называет тостом сам (на первом заходе молча — будет повтор),
            // здесь — строка и маяк.
            const r = await request(this.urlValue, { method: 'POST', body: form, accept: 'application/pdf', statuses: [422], quiet: !retry });
            if (seq !== this.seq) return;
            if (!r) {
                if (!retry) {
                    await new Promise((resolve) => setTimeout(resolve, 3000));
                    if (seq === this.seq) this.preparePdf(seq, photos, true);
                    return;
                }
                this.status('PDF не собрался');
                this.beacon('prepare', { name: 'request', message: '' });
                return;
            }
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

    writeCaption(done = null) {
        // Переносы на iPhone — U+2028: подпись к файлу в WhatsApp там режет \n, а этот разделитель пропускает. На Android
        // у U+2028 нет начертания (квадратик), а подпись многострочная — там и на компьютере обычный \n.
        const text = IOS ? this.caption().replace(/\n/g, '\u2028') : this.caption();
        // Запись в буфер начинается сразу, в том же нажатии, что и лист «Поделиться» после неё (iOS).
        return copyText(text, done);
    }

    copy() {
        this.writeCaption('Текст в буфере');
    }

    copyLink(event) {
        copyText(event.currentTarget.dataset.link, 'Ссылка в буфере');
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
        const copied = this.writeCaption();
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
                    this.status('Лист открывается долго, нажмите ещё раз');
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
        if (await copied) window.toast?.('Текст в буфере');
    }

    async sendPhotos() {
        if (this.sending) return;
        this.writeCaption();
        const count = this.selectedPhotos().length;
        if (!count) { window.toast?.('Отметьте фото'); return; }
        if (count > MAX_FILES) { await this.sendZip(); return; }
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

    // Больше 10 фото — одним архивом: лист, где он умеет ZIP (iPhone), иначе скачать (Chrome архив в лист не пускает).
    async sendZip() {
        if (this.photosError) {
            this.fail('zip-prepare', this.photosError, 'Архив не собрался');
            this.prepare();
            return;
        }
        if (!this.zip) { window.toast?.('Архив ещё собирается'); return; }
        const files = [this.zip];
        const facts = { files: 1, bytes: this.zip.size, canshare: !!navigator.canShare?.({ files }) };
        if (!navigator.share || !facts.canshare || this.broken()) { this.download(files); return; }
        this.sending = true;
        try {
            await navigator.share({ files });
            this.close();
        } catch (e) {
            if (e.name === 'AbortError') return;
            this.beacon('zip', e, facts);
            this.download(files);
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
        const type = files[0]?.type;
        window.toast?.(type === 'application/pdf' ? 'Скачиваем PDF' : type === 'application/zip' ? 'Скачиваем архив' : files.length > 1 ? `Скачиваем ${files.length} фото` : 'Скачиваем фото');
    }

    fail(stage, e, text, facts = {}) {
        this.status(text);
        window.toast?.(text, 'danger');
        this.beacon(stage, e, facts);
    }

    beacon(stage, e, facts = {}) {
        // _token в теле: заголовков у sendBeacon нет, без него 419.
        navigator.sendBeacon?.('/share/error', new Blob([JSON.stringify({
            _token: csrf(), stage, name: e.name || '?', message: String(e.message ?? '').slice(0, 300),
            standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true, ...facts,
        })], { type: 'application/json' }));
    }
}
