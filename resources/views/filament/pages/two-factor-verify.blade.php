<x-filament-panels::page>
    <div class="max-w-sm mx-auto">
        <x-filament::section>
            <x-slot name="heading">Twee-factor verificatie</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Voer de 6-cijferige code in uit je authenticator-app.
            </p>
            <div class="space-y-4">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        wire:model="code"
                        placeholder="123456"
                        maxlength="6"
                        inputmode="numeric"
                        autofocus
                    />
                </x-filament::input.wrapper>
                <x-filament::button wire:click="verify" class="w-full">
                    Verifiëren
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
