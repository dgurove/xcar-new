import { Controller } from '@hotwired/stimulus';

// Своя метка у предложения: «+» открывает поле и цвета, Enter или «Добавить» — отмеченный чип этого цвета перед «+».
// Такая метка уже есть — её просто отмечаем. Цвета своих меток — JSON в скрытом tag_colors; change с чипа и с
// tag_colors подхватывает автосохранение карточки, полный редактор шлёт их формой. «Цвет» — режим перекраски: нажатие
// по метке открывает палитру; метка справочника перекрашивается сразу и везде (POST /settings/tags/{id}/color), разовая —
// в tag_colors этого предложения.
export default class extends Controller {
    static targets = ['button', 'draft', 'input', 'dot', 'colors', 'chip', 'recolor', 'palette'];

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

    recolor() {
        const on = !this.element.classList.contains('is-recolor');
        this.element.classList.toggle('is-recolor', on);
        this.recolorTarget.setAttribute('aria-pressed', String(on));
        if (!on) this.stopPainting();
    }

    chip(event) {
        if (!this.element.classList.contains('is-recolor')) return;
        event.preventDefault();
        this.stopPainting();
        this.painting = event.currentTarget;
        this.painting.classList.add('is-painting');
        this.paletteTarget.hidden = false;
        this.paletteTarget.querySelectorAll('[data-color]').forEach((d) => d.setAttribute('aria-pressed', String(d.dataset.color === this.painting.dataset.color)));
    }

    async paint({ currentTarget }) {
        const label = this.painting;
        if (!label) return;
        const color = currentTarget.dataset.color;
        const style = currentTarget.getAttribute('style') ?? '';
        const name = label.querySelector('input')?.value;
        if (label.dataset.tagId) {
            const res = await fetch(`/settings/tags/${label.dataset.tagId}/color`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ color }),
            });
            if (!res.ok) { window.toast?.('Не получилось перекрасить'); return; }
            window.toast?.(`«${name}» теперь ${currentTarget.getAttribute('aria-label').toLowerCase()}`);
        } else {
            const colors = JSON.parse(this.colorsTarget.value || '{}');
            colors[name] = color;
            this.colorsTarget.value = JSON.stringify(colors);
            this.colorsTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        // Та же метка на странице (шапка редактора, другие блоки) — тем же цветом; формы этим не трогаем: метки
        // справочника уже сохранены, а разовые уходят своим tag_colors.
        document.querySelectorAll('.choice-tag').forEach((l) => {
            if (l.querySelector('input')?.value === name) { l.setAttribute('style', style); l.dataset.color = color; }
        });
        document.querySelectorAll('.pill-tag').forEach((p) => { if (p.textContent.trim() === name) p.setAttribute('style', style); });
        this.paletteTarget.querySelectorAll('[data-color]').forEach((d) => d.setAttribute('aria-pressed', String(d === currentTarget)));
    }

    stopPainting() {
        this.painting?.classList.remove('is-painting');
        this.painting = null;
        this.paletteTarget.hidden = true;
    }
}
