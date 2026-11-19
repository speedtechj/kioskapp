<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;

class ControlLogin extends Login
{
    protected string $view = 'filament.auth.control-login';

    public function getSubheading(): ?string
    {
        return 'Access the control panel.';
    }

    protected function getEmailFormComponent(): TextInput
    {
        return parent::getEmailFormComponent()->placeholder('you@example.com');
    }
}
