{{-- Пришли контакты владельца, а менеджер ещё не взял сделку в работу (10.10.2026, владелец: «красиво заблюренный контакт
     и паспорт, чтобы был соблазн подтвердить»). Текст замаскирован на сервере (`InsurerReplies::masked`), картинки —
     пикселизированной копией (`DealController::teaser`): настоящее в страницу не попадает, размытие — только вид. --}}
@php
    $files = collect($replies)->flatMap(fn ($r) => ($replyFiles ?? collect())->get($r->id, []));
@endphp
<section class="teaser mt-5">
    <div class="teaser-body" aria-hidden="true">
        @foreach ($replies as $reply)
            <p class="teaser-text">{{ \App\Offers\InsurerReplies::masked($reply) }}</p>
        @endforeach
        @if ($files->isNotEmpty())
            <div class="teaser-files">
                @foreach ($files->take(4) as $media)
                    @if (\App\Offers\InsurerReplies::teasable($media))
                        <img src="/deals/{{ $deal->id }}/teaser/{{ $media->id }}" alt="" class="teaser-image" loading="lazy">
                    @else
                        <span class="teaser-image teaser-doc"><x-ui.icon name="file" class="size-6"/></span>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
    <p class="teaser-lock"><x-ui.icon name="lock" class="size-4"/>Откроется после «Беру в работу»</p>
</section>
