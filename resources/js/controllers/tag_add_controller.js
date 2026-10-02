import { Controller } from '@hotwired/stimulus';

// Своя метка у предложения: «+» открывает поле и цвета, Enter или «Добавить» — отмеченный чип этого цвета перед «+».
// Такая метка уже есть — её просто отмечаем. Цвета своих меток — JSON в скрытом tag_colors; change с чипа и с
// tag_colors подхватывает автосохранение окошка, полный редактор шлёт их формой.
export default class extends Controller {
    static targets = ['button', 'draft', 'input', 'dot', 'colors'];

    connect() {
        this.color = 'grey';
    }

    open() {
        this.buttonTarget.hidden = true;
        this.draftTarget.hidden = false;
        this.inputTarget.focus();
    }

    pick({ currentTarget }) {
        this.color = currentTarget.dataset.color;
        this.dotTargets.forEach((d) => d.setAttribute('aria-pressed', String(d === currentTarget)));
        this.inputTarget.focus();
    }

    add() {
        const name = this.inputTarget.value.trim().replace(/\s+/g, ' ');
        const color = this.color;
        this.close();
        if (!name) return;
        const boxes = [...this.element.querySelectorAll('input[name="tags[]"]')];
        let box = boxes.find((b) => b.value.toLowerCase() === name.toLowerCase());
        if (!box) {
            const dot = this.dotTargets.find((d) => d.dataset.color === color);
            const label = document.createElement('label');
            label.className = 'choice choice-tag';
            label.setAttribute('style', dot?.getAttribute('style') ?? '');
            box = Object.assign(document.createElement('input'), { type: 'checkbox', name: 'tags[]', value: name });
            // Блок меток вне формы (редактор: «Деньги» справа) — новая галка идёт в ту же форму, что остальные.
            const form = this.colorsTarget.getAttribute('form');
            if (form) box.setAttribute('form', form);
            const text = document.createElement('span');
            text.textContent = name;
            label.append(box, text);
            this.buttonTarget.before(label);
            const colors = JSON.parse(this.colorsTarget.value || '{}');
            colors[name] = color;
            this.colorsTarget.value = JSON.stringify(colors);
            this.colorsTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (box.checked) return;
        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }

    close() {
        this.inputTarget.value = '';
        this.draftTarget.hidden = true;
        this.buttonTarget.hidden = false;
        this.color = 'grey';
        this.dotTargets.forEach((d) => d.setAttribute('aria-pressed', String(d.dataset.color === 'grey')));
    }
}
