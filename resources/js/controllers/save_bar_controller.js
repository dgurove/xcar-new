import { Controller } from '@hotwired/stimulus';

// Форма карточки строки с кнопкой «Сохранить изменения» внизу: тронул поле — кнопка выезжает, нажал — уходят только
// тронутые поля (их имена в `_fields[]`, OfferRequest::payload), чужие правки остальных полей не перетираются.
// Отправка — обычная форма Turbo во фрейм карточки: DetailBack возвращает свежую карточку с тостом и строкой.
// Не прошло проверку — сервер отдаёт карточку с ошибками и прежним `_fields[]` (data-save-bar-sent-value), кнопка
// остаётся на месте.
export default class extends Controller {
    static targets = ['bar', 'button'];
    static values = { sent: Array };

    connect() {
        this.dirty = new Set(this.sentValue);
        this.onEdit = (e) => this.touch(e.target);
        this.element.addEventListener('input', this.onEdit);
        this.element.addEventListener('change', this.onEdit);
        this.show();
    }

    disconnect() {
        this.element.removeEventListener('input', this.onEdit);
        this.element.removeEventListener('change', this.onEdit);
    }

    touch(el) {
        if (el.closest('[data-controller~="audience"]')) {
            // В шторке «Кому» свои списки без имён — сохраняется только итог в скрытом поле.
            if (el.name !== 'audience_rules') return;
            this.dirty.add('audience_rules');
        } else if (el.name && !el.name.startsWith('_')) {
            this.dirty.add(el.name.replace(/\[\]$/, ''));
        } else return;
        this.show();
    }

    show() {
        this.barTarget.hidden = this.dirty.size === 0;
    }

    // Перед отправкой — имена тронутых полей; ничего не тронуто (Enter в пустой форме) — не отправляем.
    submit(event) {
        if (!this.dirty.size) { event.preventDefault(); return; }
        this.element.querySelectorAll('input[name="_fields[]"]').forEach((i) => i.remove());
        for (const name of this.dirty) {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = '_fields[]';
            i.value = name;
            this.element.append(i);
        }
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Сохраняем…';
    }
}
