import { Controller } from '@hotwired/stimulus';
import { plural } from '../lib/plural.js';

// Форма отчёта о приёме (x-park.report-form): кнопка называет, что уйдёт («Отправить 24 фото и 3 документа»),
// у «Добавить» и слотов — сколько выбрано файлов; фраза письма сама меняется «фотоотчет» ↔ «фотоотчет и скан-копии»,
// пока её не правили руками.

export default class extends Controller {
    static targets = ['photo', 'newPhotos', 'newLabel', 'photoCount', 'doc', 'scan', 'file', 'slot', 'slotNote', 'text', 'submit'];
    static values = { docs: String, plain: String };

    connect() {
        this.touched = false;
        this.count();
    }

    edited() {
        this.touched = true;
    }

    count() {
        const added = this.hasNewPhotosTarget ? this.newPhotosTarget.files.length : 0;
        const photos = this.photoTargets.filter((i) => i.checked).length + added;
        if (this.hasNewLabelTarget) this.newLabelTarget.textContent = added ? `+${added}` : 'Добавить';
        if (this.hasPhotoCountTarget) this.photoCountTarget.textContent = photos ? `${photos}` : '';

        // Документ слота — отмеченные лежащие файлы и выбранные снимки/файлы (снимки одного выбора — один PDF).
        let docs = 0;
        this.slotTargets.forEach((slot, i) => {
            const kept = [...slot.querySelectorAll('[data-report-target="doc"]')].filter((d) => d.checked).length;
            // «Снять» — страницы одного документа (один PDF), «Файл» — каждый файл своим документом.
            const shots = [...slot.querySelectorAll('[data-report-target="scan"]')].reduce((sum, inp) => sum + inp.files.length, 0);
            const files = [...slot.querySelectorAll('[data-report-target="file"]')].reduce((sum, inp) => sum + inp.files.length, 0);
            const picked = shots + files;
            docs += kept + files + (shots ? 1 : 0);
            const note = this.slotNoteTargets[i];
            if (note) note.textContent = picked ? `выбрано ${picked} ${plural(picked, ['файл', 'файла', 'файлов'])}` : '';
        });

        if (!this.touched && this.hasTextTarget) this.textTarget.value = docs ? this.docsValue : this.plainValue;
        const parts = [photos ? `${photos} фото` : '', docs ? `${docs} ${plural(docs, ['документ', 'документа', 'документов'])}` : ''].filter(Boolean);
        this.submitTarget.textContent = parts.length ? `Отправить ${parts.join(' и ')}` : 'Отправить отчёт';
        this.submitTarget.disabled = !parts.length;
    }
}
