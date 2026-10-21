<?php

namespace App\Http\Site;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Media\Actions\WarmPhotos;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\ReadOffer;
use App\Support\ListContext;
use App\Users\User;
use Illuminate\Http\Request;

class OfferController
{
    public function show(Request $request, Offer $offer)
    {
        $user = $request->user();
        // Оценка больше не нужна (цену поставили, просьбу сняли), а черновик ему не виден — в «Предложения» словом, не 404:
        // сюда ведут уведомления «Оцените».
        if (! $offer->isVisibleTo($user) && $user && $offer->estimates()->where('user_id', $user->id)->exists()) {
            return redirect('/offers')->with('toast', 'Оценка уже не нужна');
        }
        abort_unless($offer->isVisibleTo($user), 404);
        // Откуда пришли: стрелки листают ровно тот список, что человек видел. Заход в каталог — до отметки «Просмотрено»:
        // в «Не просмотренных» открытое сейчас остаётся на своём месте.
        $context = ListContext::fromRequest($request);
        $since = $user && $context && ! $context->isGallery() ? CatalogQuery::since($request) : null;
        ReadOffer::mark($user, $offer);

        $offer->load(['settlement', 'media', 'favorites' => fn ($f) => $f->where('user_id', $user?->id ?? 0)]);
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer); // холодный слой: конверсии досчитаются в очереди
        }

        $position = $context
            ? CatalogQuery::position($user, $context->filters, $context->isGallery(), $offer, $since)
            : ['prev' => null, 'next' => null, 'index' => null, 'total' => 0];

        // Открыли шторку — непрочитанное прочитано, бейдж гаснет сразу.
        $canChat = $offer->chatOpenFor($user);
        $chat = $canChat ? Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first() : null;
        if ($request->boolean('chat') && $chat) {
            app(MarkChatRead::class)($chat, $user);
        }

        return view('site.offers.show', [
            'offer' => $offer,
            'photos' => $offer->visiblePhotos(),
            'context' => $context,
            'position' => $position,
            'myBid' => $user ? $offer->bids()->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])->latest('id')->first() : null,
            'myInterest' => $user ? $offer->interests()->where('user_id', $user->id)->first() : null,
            'chat' => $chat,
            // Шторка чата есть всегда; сам чат заведётся первым сообщением: менеджеру — с площадкой, покупателю — со своим менеджером.
            'canChat' => $canChat,
            'manager' => $user?->isBuyer() ? $user->manager : null,
            'showings' => $user?->isManager() ? $offer->showings()->where('manager_id', $user->id)->with(['user', ...User::withAvatar('user.media'), 'group'])->get() : collect(),
            'buyerInterests' => $user?->isManager() ? $offer->interests()->whereHas('user', fn ($u) => $u->where('manager_id', $user->id))->with(['user', ...User::withAvatar('user.media')])->get() : collect(),
        ]);
    }

    /**
     * Открыли с карточки или строки списка — маячок из браузера (`app.js`, `offerRead`): страницу и окно строки Turbo
     * часто берёт из предзагрузки, и до `show`/`detail` настоящий запрос не доходит.
     */
    public function read(Request $request, Offer $offer)
    {
        if ($offer->isVisibleTo($request->user())) {
            ReadOffer::mark($request->user(), $offer);
        }

        return response()->noContent();
    }

    /** Карточка строки таблицы: фото, метки, факты, цена как видит человек; подтвердить ценой или проявить интерес прямо тут. */
    public function detail(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($offer->isVisibleTo($user), 404);
        ReadOffer::mark($user, $offer);
        $offer->load(['settlement', 'media', 'favorites' => fn ($f) => $f->where('user_id', $user?->id ?? 0)]);
        CatalogQuery::withOpenedAt($offer, $user);
        $canChat = $offer->chatOpenFor($user);

        return view('site.offers.detail', [
            'offer' => $offer,
            'context' => ListContext::fromRequest($request),
            'myBid' => $user ? $offer->bids()->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])->latest('id')->first() : null,
            'myInterest' => $user ? $offer->interests()->where('user_id', $user->id)->first() : null,
            'chat' => $canChat ? Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first() : null,
            'canChat' => $canChat,
        ]);
    }

    /** Лента чата для шторки — отдельным фреймом, по открытию. */
    public function chat(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($user, 404);
        $chat = Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first();
        // Свой чат открыт и тогда, когда предложение ушло от человека; новый — только видящему (`chatOpenFor`).
        abort_unless($user->canChat() && (($chat && ($offer->chat_enabled || $offer->worksWith($user))) || $offer->chatOpenFor($user)), 404);

        // Последняя страница ленты, как в кабинете: старое подгружается прокруткой вверх, а не всей историей сразу.
        $messages = $chat?->messages()->with(['author', 'files'])->reorder('seq', 'desc')->limit(ChatController::PAGE)->get()->reverse()->values() ?? collect();

        return view('site.offers.chat', [
            'offer' => $offer,
            'chat' => $chat,
            'messages' => $messages,
            'more' => $messages->isNotEmpty() && $messages->first()->seq > 1,
            'user' => $user,
        ]);
    }
}
