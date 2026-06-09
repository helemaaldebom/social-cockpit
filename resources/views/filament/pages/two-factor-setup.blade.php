<x-filament-panels::page>
    <div class="max-w-md space-y-6">
        @if(Auth::user()->two_factor_confirmed_at)
            <x-filament::section>
                <x-slot name="heading">2FA is ingeschakeld</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-400">Twee-factor authenticatie is actief op je account.</p>
                <div class="mt-4">
                    <x-filament::button wire:click="disable" color="danger">
                        2FA uitschakelen
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">2FA instellen</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Scan de QR-code met Microsoft Authenticator of een andere TOTP-app.
                </p>
                <div class="flex justify-center mb-4">
                    <img src="data:image/png;base64,{{ $qrCode }}" alt="QR Code" class="rounded border p-2 bg-white">
                </div>
                <p class="text-xs text-gray-500 text-center mb-4">
                    Of gebruik deze sleutel handmatig: <code class="font-mono bg-gray-100 px-1 rounded">{{ $secret }}</code>
                </p>
                <div class="space-y-4">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model="code"
                            placeholder="6-cijferige code"
                            maxlength="6"
                            inputmode="numeric"
                        />
                    </x-filament::input.wrapper>
                    <x-filament::button wire:click="setup">
                        Bevestigen en 2FA activeren
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
