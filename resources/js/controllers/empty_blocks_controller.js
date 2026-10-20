import { Controller } from '@hotwired/stimulus';

// Пустые свёрнутые блоки машины (`x-car.card` с `data-empty-block`: «ПТС и СТС», «Страхователь», «Хранение») — одной строкой
// чипами «+ ПТС и СТС», «+ Страхователь» на месте первого из них (20.10.2026, владелец: «компактнее»). Нажатие — блок
// появляется на своём месте сразу полями («Изменить» его карточки), кнопка из строки уходит.
export default class extends Controller {
    connect() {
        this.build();
    }

    disconnect() {
        this.strip?.remove();
        this.blocks().forEach((b) => { b.hidden = false; });
    }

    blocks() {
        return [...this.element.querySelectorAll('[data-empty-block]')];
    }

    build() {
        const blocks = this.blocks().filter((b) => !b.closest('[hidden]'));
        if (blocks.length < 2) return;
        const strip = document.createElement('div');
        strip.className = 'empty-blocks';
        for (const block of blocks) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chip empty-blocks-chip';
            button.textContent = block.dataset.emptyBlock;
            button.addEventListener('click', () => this.open(block, button));
            strip.append(button);
            block.hidden = true;
        }
        blocks[0].before(strip);
        this.strip = strip;
    }

    open(block, button) {
        block.hidden = false;
        block.querySelector('[data-edit-card-target="button"]')?.click();
        button.remove();
        if (!this.strip.querySelector('button')) this.strip.remove();
        block.querySelector('input:not([type=hidden]), select, textarea')?.focus({ preventScroll: true });
        block.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}
