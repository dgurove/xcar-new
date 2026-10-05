{{-- Спор по сумме в карточке «Оценки из текста»: оставить вписанное (по умолчанию) или перезаписать текстом. --}}
<span class="segment valuation-choice">
    <label><input type="radio" name="keep[{{ $offer->id }}]" value="keep" checked data-action="valuation#count">Оставить</label>
    <label><input type="radio" name="keep[{{ $offer->id }}]" value="overwrite" data-action="valuation#count">Перезаписать</label>
</span>
