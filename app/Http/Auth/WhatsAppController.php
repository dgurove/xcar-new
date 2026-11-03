<?php

namespace App\Http\Auth;

use App\WhatsApp\Actions\UnlinkWhatsApp;
use Illuminate\Http\Request;

/** WhatsApp у аккаунта (`WhatsApp\LinkCode`): шторка вернулась из WhatsApp — привязан ли; «Отключить» в профиле. */
final class WhatsAppController
{
    public function state(Request $request)
    {
        return response()->json(['linked' => $request->user()->whatsapp_jid !== null]);
    }

    public function destroy(Request $request, UnlinkWhatsApp $unlink)
    {
        $unlink($request->user());

        return back()->with('toast', 'WhatsApp отключён');
    }
}
