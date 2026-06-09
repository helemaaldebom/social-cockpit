<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TwoFactorSetup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = '2FA Instellen';
    protected static ?string $title = 'Twee-factor authenticatie';
    protected static string $view = 'filament.pages.two-factor-setup';
    protected static bool $shouldRegisterNavigation = true;

    public string $secret = '';
    public string $qrCode = '';
    public string $code = '';

    public function mount(): void
    {
        $user = Auth::user();

        if ($user->two_factor_secret) {
            $this->secret = decrypt($user->two_factor_secret);
        } else {
            $g2fa = new Google2FA();
            $this->secret = $g2fa->generateSecretKey();
        }

        $g2fa = new Google2FA();
        $otpUrl = $g2fa->getQRCodeUrl(
            config('app.name'),
            Auth::user()->email,
            $this->secret
        );

        $this->qrCode = base64_encode(QrCode::format('png')->size(200)->generate($otpUrl));
    }

    public function setup(): void
    {
        $user = Auth::user();
        $g2fa = new Google2FA();

        if (! $g2fa->verifyKey($this->secret, $this->code)) {
            Notification::make()->title('Ongeldige code. Probeer opnieuw.')->danger()->send();
            return;
        }

        $user->two_factor_secret = encrypt($this->secret);
        $user->two_factor_confirmed_at = now();
        $user->save();

        Notification::make()->title('2FA succesvol ingesteld!')->success()->send();
    }

    public function disable(): void
    {
        $user = Auth::user();
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        session()->forget('two_factor_verified');
        Notification::make()->title('2FA uitgeschakeld.')->warning()->send();
    }
}
