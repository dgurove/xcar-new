<?php

namespace App\Users;

use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Events\CredentialAsserted;
use Laragear\WebAuthn\Events\CredentialCloned;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/** Ключи доступа: след последнего входа и название хранилища по aaguid. */
class Passkeys
{
    /** Известные хранилища ключей — чтобы в профиле было понятно, где ключ живёт. */
    private const VAULTS = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
        '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
        '50726f74-6f6e-5061-7373-50726f746f6e' => 'Proton Pass',
        'b5397666-4885-aa6b-cebf-e52262a439a2' => 'Chromium',
    ];

    public static function vault(WebAuthnCredential $credential): ?string
    {
        return self::VAULTS[strtolower((string) $credential->aaguid)] ?? null;
    }

    public function asserted(CredentialAsserted $event): void
    {
        $event->credential->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function cloned(CredentialCloned $event): void
    {
        Log::warning('passkey: ключ отключён — счётчик ниже сохранённого', [
            'credential' => substr($event->credential->getKey(), 0, 8),
            'user' => $event->credential->authenticatable_id,
            'reported' => $event->reportedCount,
        ]);
    }

    public function subscribe(): array
    {
        return [
            CredentialAsserted::class => 'asserted',
            CredentialCloned::class => 'cloned',
        ];
    }
}
