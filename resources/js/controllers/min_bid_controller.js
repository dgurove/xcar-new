import { Controller } from '@hotwired/stimulus';
import { ceilThousand } from '../lib/floor';
import { nums, rubles } from '../lib/money';

// Серые подсказки цен, пока поле пустое: заявленная по умолчанию — закупочная вверх до тысячи (Offer::declaredFrom), минимальная — заявленная плюс
// доля (по умолчанию 0,6) от разницы до цены продажи, с округлением до тысячи (Offer::minBid). Пересчитываются
// на ходу при наборе любой из цен — и цены продажи в «Оценке» карточки, она вне формы полей.
const DEFAULT_SHARE = 0.6;

export default class extends Controller {
    connect() {
        this.root = this.element.closest('turbo-frame') ?? this.element.closest('main') ?? document;
        this.onInput = () => this.update();
        this.root.addEventListener('input', this.onInput);
        this.update();
    }

    disconnect() {
        this.root.removeEventListener('input', this.onInput);
    }

    // Сначала свой блок (шторки публикации и «Вернуть в продажу», 16.11.2026: в окне машины рядом — поля «Цен» того же
    // предложения), потом вся страница (цена продажи «Оценки» вне формы полей).
    value(name) {
        for (const el of [...this.element.querySelectorAll(`[name="${name}"]`), ...this.root.querySelectorAll(`[name="${name}"]`)]) {
            const v = rubles(el.value);
            if (v) return v;
        }
        return null;
    }

    update() {
        const raw = this.value('floor_price');
        const floor = raw ? ceilThousand(raw) : null;
        const from = this.value('publish_price') ?? floor;
        const asking = this.value('asking_price');
        const shareRaw = (this.element.querySelector('[name="min_bid_share"]') ?? this.root.querySelector('[name="min_bid_share"]'))?.value.replace(',', '.').trim();
        const share = shareRaw !== '' && !Number.isNaN(Number(shareRaw)) ? Number(shareRaw) : DEFAULT_SHARE;
        const publish = this.element.querySelector('[name="publish_price"]') ?? this.root.querySelector('[name="publish_price"]');
        if (publish) publish.placeholder = floor ? nums(floor) : '';
        // Минимальная шторки переоценки — своя подсказка от новой закупочной (`reprice_controller`).
        const min = this.element.querySelector('[name="min_bid_price"]:not([data-reprice-target])') ?? this.root.querySelector('[name="min_bid_price"]:not([data-reprice-target])');
        if (!min) return;
        let value = null;
        if (asking && from && asking > from) value = Math.round((from + (asking - from) * share) / 1000) * 1000;
        else if (asking) value = asking;
        min.placeholder = value ? nums(value) : '';
    }
}
