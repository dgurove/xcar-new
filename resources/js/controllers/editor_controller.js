import { Controller } from '@hotwired/stimulus';

const RU = {
    bold: 'Жирный', italic: 'Курсив', strike: 'Зачёркнутый', link: 'Ссылка', heading1: 'Заголовок', quote: 'Цитата',
    code: 'Код', bullets: 'Список', numbers: 'Нумерованный список', outdent: 'Левее', indent: 'Правее',
    attachFiles: 'Файлы', undo: 'Отменить', redo: 'Вернуть', unlink: 'Убрать', url: 'Адрес', urlPlaceholder: 'Адрес ссылки',
};

// Trix без вложений: файлы к письму идут отдельным списком. Сам Trix — половина
// бандла — грузится здесь, только на экране с редактором.
export default class extends Controller {
    static targets = ['editor'];
    static values = { focus: Boolean };

    async connect() {
        const { default: Trix } = await import('trix');
        // Подписи панели и диалога ссылки по-русски: Trix строит их из config.lang, а элементы объявляет следующим
        // тиком (setTimeout в trix.esm) — сюда мы успеваем раньше.
        Object.assign(Trix.config.lang, RU);
        // Кнопка диалога ссылки берёт то же слово, что подсказка панели, — а кнопка называет исход.
        const done = () => {
            const b = this.editorTarget.toolbarElement?.querySelector('[data-trix-method="setAttribute"]'); if (b) b.value = 'Готово';
            // Ответ на письмо: курсор сразу в начало, над подписью — пиши ответ, не выбираясь из цитаты.
            if (this.focusValue) { (window.focusField ?? ((el) => el.focus()))(this.editorTarget); this.editorTarget.editor?.setSelectedRange([0, 0]); }
        };
        if (this.editorTarget.editor) done(); else this.editorTarget.addEventListener('trix-initialize', done, { once: true });
        this.block = (e) => e.preventDefault();
        this.editorTarget.addEventListener('trix-file-accept', this.block);
    }

    disconnect() {
        this.editorTarget.removeEventListener('trix-file-accept', this.block);
    }
}
