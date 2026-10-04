import { Controller } from '@hotwired/stimulus';

// Документы формы с выбором (период акта сверки, части выгрузки закупки): адрес каждой ссылки `a[data-doc]` внутри —
// её путь и поля формы, шторка документов открывает уже готовый. Свои параметры ссылки — `data-doc-query` («format=pdf»).
// Ничего не отмечено среди галок — тост вместо пустого файла (ссылке с data-doc-query-any галки не нужны).
// Enter в поле открывает первый документ.
export default class extends Controller {
    connect() {
        this.sync();
    }

    links() {
        return [...this.element.querySelectorAll('a[data-doc]')];
    }

    sync() {
        const fields = [...new FormData(this.element)].filter(([, v]) => typeof v === 'string');
        for (const a of this.links()) {
            const url = new URL(a.href, location.href);
            url.search = new URLSearchParams([...fields, ...new URLSearchParams(a.dataset.docQuery || '')]).toString();
            a.href = url.pathname + url.search;
        }
    }

    // До перехвата шторкой: ссылка уже с текущими полями.
    check(event) {
        const a = event.currentTarget;
        const boxes = this.element.querySelectorAll('input[type=checkbox]');
        if (!a.hasAttribute('data-doc-query-any') && boxes.length && !this.element.querySelector('input[type=checkbox]:checked')) {
            event.preventDefault();
            window.toast?.('Выберите, что выгружать', 'danger');
            return;
        }
        this.sync();
    }

    submit(event) {
        event.preventDefault();
        this.links()[0]?.click();
    }
}
