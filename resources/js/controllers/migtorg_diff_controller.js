import { Controller } from '@hotwired/stimulus';

// Поле не совпадает с лотом Мигторга (Offers\MigtorgDiff): у подписи поля — «!», нажатие раскрывает под полем, как на
// Мигторге. Наше значение важнее — поле не трогается; правят его руками, если Мигторг прав.
export default class extends Controller {
    static values = { fields: Object };

    fieldsValueChanged(fields) {
        this.element.querySelectorAll('[data-migtorg-diff]').forEach((el) => el.remove());
        for (const [name, text] of Object.entries(fields ?? {})) {
            const input = document.getElementById(`f-${name}`) ?? this.element.querySelector(`[name="${name}"]`);
            const box = input?.closest('.field');
            const label = box?.querySelector('.field-label');
            if (!label) continue;
            const note = document.createElement('p');
            note.className = 'field-note';
            note.dataset.migtorgDiff = '';
            note.hidden = true;
            note.textContent = `На Мигторге: ${text}`;
            const mark = document.createElement('button');
            mark.type = 'button';
            mark.className = 'migtorg-diff';
            mark.dataset.migtorgDiff = '';
            mark.textContent = '!';
            mark.setAttribute('aria-label', 'На Мигторге иначе');
            mark.addEventListener('click', (e) => { e.preventDefault(); note.hidden = !note.hidden; });
            label.append(mark);
            box.append(note);
        }
    }
}
