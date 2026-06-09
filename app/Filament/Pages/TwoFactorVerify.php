<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorVerify extends Page
{
    protected static ?string $navigationIcon = null;
    protected static string $view = 'filament.pages.two-factor-verify';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'two-factor-verify';

    public string $code = '';

    public function verify(): void
    {
        $user = Auth::user();

        if (! $user->two_factor_secret) {
            session()->put('two_factor_verified', true);
            $this->redirectToIntended();
            return;
        }

        $g2fa = new Google2FA();
        $secret = decrypt($user->two_factor_secret);

        if (! $g2fa->verifyKey($secret, $this->code)) {
            Notification::make()->title('Ongeldige code. Probeer opnieuw.')->danger()->send();
            return;
        }

        session()->put('two_factor_verified', true);
        $this->redirectToIntended();
    }

    private function redirectToIntended(): void
    {
        $redirect = session()->pull('two_factor_redirect', route('filament.admin.pages.dashboard'));
        $this->redirect($redirect);
    }
}
