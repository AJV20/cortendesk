<?php

namespace App\Livewire;

use App\Models\ConsoleAudit;
use App\Models\Setting;
use Livewire\Component;

class SettingsPage extends Component
{
    public string $idServer = '';

    public string $relayServer = '';

    public string $publicKey = '';

    public int $onlineWindow = 60;

    public string $rdgenUrl = '';

    public int $logRetentionDays = 365;

    public bool $saved = false;

    public string $pruneResult = '';

    public function mount(): void
    {
        $this->idServer = Setting::get('id_server', config('cortendesk.id_server')) ?? '';
        $this->relayServer = Setting::get('relay_server', config('cortendesk.relay_server')) ?? '';
        $this->publicKey = Setting::get('public_key', config('cortendesk.public_key')) ?? '';
        $this->onlineWindow = (int) (Setting::get('online_window', (string) config('cortendesk.online_window')) ?: 60);
        $this->rdgenUrl = Setting::get('rdgen_url', config('cortendesk.rdgen_url')) ?? '';
        $this->logRetentionDays = (int) (Setting::get('log_retention_days', (string) config('cortendesk.log_retention_days')) ?: 0);
    }

    public function save(): void
    {
        $this->validate([
            'idServer' => 'nullable|string|max:255',
            'relayServer' => 'nullable|string|max:255',
            'publicKey' => 'nullable|string|max:255',
            'onlineWindow' => 'required|integer|min:20|max:600',
            'rdgenUrl' => 'nullable|url|max:255',
            'logRetentionDays' => 'required|integer|min:0|max:3650',
        ]);

        Setting::put('id_server', $this->idServer);
        Setting::put('relay_server', $this->relayServer);
        Setting::put('public_key', $this->publicKey);
        Setting::put('online_window', (string) $this->onlineWindow);
        Setting::put('rdgen_url', rtrim($this->rdgenUrl, '/'));
        Setting::put('log_retention_days', (string) $this->logRetentionDays);

        ConsoleAudit::record('settings.update', 'Updated server settings', 'settings', null);

        $this->saved = true;
    }

    /** "Prune now": run retention immediately and surface the summary line. */
    public function pruneNow(): void
    {
        Setting::put('log_retention_days', (string) $this->logRetentionDays);
        \Illuminate\Support\Facades\Artisan::call('cortendesk:prune-logs');
        $this->pruneResult = trim(\Illuminate\Support\Facades\Artisan::output());

        ConsoleAudit::record('logs.prune', 'Pruned logs older than '.$this->logRetentionDays.' days', 'logs', null);
    }

    public function render()
    {
        return view('livewire.settings-page', [
            'apiUrl' => rtrim(config('app.url'), '/'),
        ]);
    }
}
