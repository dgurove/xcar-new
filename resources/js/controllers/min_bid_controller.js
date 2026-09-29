import { Controller } from '@hotwired/stimulus';

// Серые подсказки цен, пока поле пустое: заявленная по умолчанию — закупочная вверх до тысячи (Offer::declaredFrom), минимальная — заявленная плюс
// доля (по умолчанию 0,6) от разницы до цены продажи, с округлением до тысячи (Offer::minBid). Пересчитываются
// на ходу при наборе любой из цен — и цены продажи в «Оценке» окошка, она вне формы полей.
const DEFAULT_SHARE = 0.6;
const num = (v) => { const d = String(v ?? '').replace(/[^\d]/g, ''); return d ? Number(d) : null; };
const money = (v) => new Intl.NumberFormat('ru-RU').format(v);

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

    value(name) {
        for (const el of this.root.querySelectorAll(`[name="${name}"]`)) {
            const v = num(el.value);
            if (v) return v;
        }
        return null;
    }

    update() {
        const raw = this.value('floor_price');
        const floor = raw ? Math.ceil(raw / 1000) * 1000 : null;
        const from = this.value('publish_price') ?? floor;
        const asking = this.value('asking_price');
        const shareRaw = this.root.querySelector('[name="min_bid_share"]')?.value.replace(',', '.').trim();
        const share = shareRaw !== '' && !Number.isNaN(Number(shareRaw)) ? Number(shareRaw) : DEFAULT_SHARE;
        const publish = this.root.querySelector('[name="publish_price"]');
        if (publish) publish.placeholder = floor ? money(floor) : '';
        const min = this.root.querySelector('[name="min_bid_price"]');
        if (!min) return;
        let value = null;
        if (asking && from && asking > from) value = Math.round((from + (asking - from) * share) / 1000) * 1000;
        else if (asking) value = asking;
        min.placeholder = value ? money(value) : '';
    }
}
