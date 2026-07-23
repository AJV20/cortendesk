<?php

namespace App\Livewire;

use App\Models\Setting;
use Livewire\Component;

class SettingsPage extends Component
{
    public string $idServer = '';

    public string $relayServer = '';

    public string $publicKey = '';

    public int $onlineWindow = 60;

    public string $rdgenUrl = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->idServer = Setting::get('id_server', config('cortendesk.id_server')) ?? '';
        $this->relayServer = Setting::get('relay_server', config('cortendesk.relay_server')) ?? '';
        $this->publicKey = Setting::get('public_key', config('cortendesk.public_key')) ?? '';
        $this->onlineWindow = (int) (Setting::get('online_window', (string) config('cortendesk.online_window')) ?: 60);
        $this->rdgenUrl = Setting::get('rdgen_url', config('cortendesk.rdgen_url')) ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'idServer' => 'nullable|string|max:255',
            'relayServer' => 'nullable|string|max:255',
            'publicKey' => 'nullable|string|max:255',
            'onlineWindow' => 'required|integer|min:20|max:600',
            'rdgenUrl' => 'nullable|url|max:255',
        ]);

        Setting::put('id_server', $this->idServer);
        Setting::put('relay_server', $this->relayServer);
        Setting::put('public_key', $this->publicKey);
        Setting::put('online_window', (string) $this->onlineWindow);
        Setting::put('rdgen_url', rtrim($this->rdgenUrl, '/'));

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.settings-page', [
            'apiUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
