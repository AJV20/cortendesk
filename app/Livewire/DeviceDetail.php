<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\AddressBook;
use App\Models\AlarmLog;
use App\Models\AuditConnection;
use App\Models\AuditFileTransfer;
use App\Models\ConsoleAudit;
use App\Models\Device;
use Livewire\Component;

/**
 * One device, everything the console knows about it (issue #35): identity and
 * hardware, an editable note, which address books carry it, and its recent
 * activity. The activity sections reuse the audit tables and are gated by the
 * audit permission, same as the log screens they excerpt.
 */
class DeviceDetail extends Component
{
    use AuthorizesConsole;

    public int $deviceId;

    public string $note = '';

    public bool $editingNote = false;

    public function mount(int $deviceId): void
    {
        $this->authorizeConsole('device', 'r');
        $this->deviceId = $deviceId;
        $this->note = (string) $this->device()->note;
    }

    /**
     * Re-resolved on every request, never cached on the component: visibility
     * can change between polls, and a device deleted meanwhile must 404 the
     * next interaction rather than serve stale facts.
     */
    private function device(): Device
    {
        return Device::visibleTo(auth()->user())
            ->withTrashed()
            ->with(['group', 'user', 'resolvedStrategy'])
            ->findOrFail($this->deviceId);
    }

    public function editNote(): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->editingNote = true;
    }

    public function saveNote(): void
    {
        $this->authorizeConsole('device', 'rw');
        $this->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $device = $this->device();
        $device->update(['note' => trim($this->note)]);
        ConsoleAudit::record('device.update', 'Updated note on device '.$device->rustdesk_id, 'device', $device->rustdesk_id);

        $this->editingNote = false;
    }

    public function cancelNote(): void
    {
        $this->note = (string) $this->device()->note;
        $this->editingNote = false;
    }

    public function render()
    {
        $user = auth()->user();
        $device = $this->device();
        $canAudit = $user->consoleAllows('audit');

        // Only books this user could open anyway — membership alone must not
        // reveal a shared book they have no rule for.
        $books = AddressBook::query()
            ->whereHas('entries', fn ($q) => $q->where('rustdesk_id', $device->rustdesk_id))
            ->get()
            ->filter(fn (AddressBook $b) => $b->permissionFor($user) > 0)
            ->values();

        return view('livewire.device-detail', [
            'device' => $device,
            'books' => $books,
            'canAudit' => $canAudit,
            'canEdit' => $user->consoleAllows('device', 'rw'),
            'connections' => $canAudit
                ? AuditConnection::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
            'transfers' => $canAudit
                ? AuditFileTransfer::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
            'alarms' => $canAudit
                ? AlarmLog::where('rustdesk_id', $device->rustdesk_id)->latest()->limit(10)->get()
                : collect(),
        ]);
    }
}
