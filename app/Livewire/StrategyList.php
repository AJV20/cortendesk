<?php

namespace App\Livewire;

use App\Livewire\Concerns\AuthorizesConsole;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyRevision;
use App\Models\StrategyRollout;
use App\Models\User;
use App\Services\StrategyAssignmentImpact;
use App\Services\StrategyCompliance;
use App\Services\StrategyImpact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Strategies console screen (PLAN C4).
 *
 * The editor only ever offers keys from Strategy::OPTION_KEYS — the same
 * allowlist the delivery engine sanitizes against — so nothing this screen can
 * produce is capable of reaching a device with a key the protocol doc does not
 * document. The catalogue below adds labels and help text on top of that table;
 * it never adds keys.
 *
 * Form convention: an option whose form value is the empty string is NOT part
 * of the strategy. It is stored by omission, not as "". That matters, because ""
 * on the wire means "reset this key to the client's built-in default"
 * (docs/strategy-protocol.md §2.3) and the delivery engine already emits it by
 * itself for keys a device is holding that the strategy has dropped. Letting an
 * operator type an explicit "" as well would give two spellings of one idea.
 */
class StrategyList extends Component
{
    use AuthorizesConsole;

    private const MAX_EDITOR_ASSIGNMENTS = 5000;

    /** Editor state: null = closed, 0 = creating, >0 = editing that strategy. */
    public ?int $editingId = null;

    public string $formName = '';

    public string $formNote = '';

    public bool $formEnabled = true;

    public bool $formIsDefault = false;

    public bool $formEnforce = false;

    public int $formConfirmationTimeout = 15;

    /** @var array<string,string> option key => form value ('' = not managed) */
    public array $formOptions = [];

    public bool $previewing = false;

    /** @var array<string,mixed> */
    public array $impactPreview = [];

    public ?string $previewFingerprint = null;

    public string $revisionNote = '';

    public string $rolloutStartAt = '';

    public int $rolloutBatchSize = 25;

    public int $rolloutIntervalMinutes = 15;

    public ?int $historyStrategyId = null;

    public ?int $compareFromRevisionId = null;

    public ?int $compareToRevisionId = null;

    public ?int $complianceStrategyId = null;

    public string $complianceState = 'all';

    /** Assignment editor state: strategy id, or null when closed. */
    public ?int $assigningId = null;

    public string $assignTab = 'devices';

    public string $assignSearch = '';

    /** @var array<int,int|string> */
    public array $assignDeviceIds = [];

    /** @var array<int,int|string> */
    public array $assignUserIds = [];

    /** @var array<int,int|string> */
    public array $assignGroupIds = [];

    public bool $assignPreviewing = false;

    /** @var array<string,mixed> */
    public array $assignmentImpact = [];

    public ?string $assignmentFingerprint = null;

    /** Section titles + the apply-timing caveat that belongs to each group. */
    private const GROUP_META = [
        'permissions' => [
            'title' => 'Permissions',
            'icon' => 'ri-shield-keyhole-line',
            'help' => 'What an incoming session may do. Applied when the next session is authorised — sessions already running keep the values they started with.',
        ],
        'security' => [
            'title' => 'Security & password',
            'icon' => 'ri-lock-password-line',
            'help' => 'How the device authorises incoming connections. Most of these take effect within one heartbeat; the two password-shape options only apply the next time the one-time password is regenerated.',
        ],
        'display' => [
            'title' => 'Capture & display',
            'icon' => 'ri-macbook-line',
            'help' => 'Screen capture and desktop behaviour during a session.',
        ],
        'client' => [
            'title' => 'Client & updates',
            'icon' => 'ri-refresh-line',
            'help' => 'How the RustDesk client maintains itself. Unlike the groups above, these are not about an incoming session.',
        ],
    ];

    /**
     * Labels, help text and enum wording. Keyed by the option key so a key that
     * is not in the allowlist can never gain a control by being described here.
     *
     * @var array<string,array{label:string,help?:string,choices?:array<string,string>}>
     */
    private const OPTION_META = [
        'access-mode' => [
            'label' => 'Access mode',
            'help' => 'Master switch. "Full" forces every permission below on and "View only" forces them all off, whatever the individual controls say. Desktop only — ignored by the Android and iOS clients.',
            'choices' => ['full' => 'Full access', 'view' => 'View only', 'custom' => 'Custom (use the controls below)'],
        ],
        'enable-keyboard' => ['label' => 'Keyboard & mouse'],
        'enable-clipboard' => ['label' => 'Clipboard'],
        'enable-file-transfer' => ['label' => 'File transfer'],
        'enable-audio' => ['label' => 'Audio'],
        'enable-camera' => ['label' => 'Camera'],
        'enable-terminal' => ['label' => 'Terminal'],
        'enable-tunnel' => ['label' => 'TCP tunnelling'],
        'enable-remote-restart' => ['label' => 'Remote restart'],
        'enable-record-session' => ['label' => 'Session recording', 'help' => 'Whether the connecting side is permitted to record.'],
        'enable-block-input' => ['label' => 'Block local input', 'help' => 'Windows only in the client UI.'],
        'enable-privacy-mode' => ['label' => 'Privacy mode'],
        'enable-remote-printer' => ['label' => 'Remote printing', 'help' => 'Windows only. Re-checked per print job, so it takes effect mid-session.'],

        'verification-method' => [
            'label' => 'Password type',
            'choices' => [
                'use-temporary-password' => 'One-time password only',
                'use-permanent-password' => 'Permanent password only',
                'use-both-passwords' => 'Either password',
            ],
        ],
        'approve-mode' => [
            'label' => 'How connections are approved',
            'choices' => [
                'password' => 'Password only',
                'click' => 'Accept on the device only',
                'password-click' => 'Password or accept on the device',
            ],
        ],
        'temporary-password-length' => [
            'label' => 'One-time password length',
            'help' => 'Applies the next time the password is regenerated, not immediately.',
            'choices' => ['6' => '6 characters', '8' => '8 characters', '10' => '10 characters'],
        ],
        'allow-numeric-one-time-password' => [
            'label' => 'Digits-only one-time password',
            'help' => 'Applies at the next regeneration.',
        ],
        'whitelist' => [
            'label' => 'IP whitelist',
            'help' => 'Comma-separated IPs or CIDRs that may connect; empty means allow all. Entries that do not parse simply never match, so a typo here can lock everyone out of the device.',
        ],
        'allow-only-conn-window-open' => ['label' => 'Only accept while the client window is open', 'help' => 'Desktop only.'],
        'enable-trusted-devices' => ['label' => 'Offer "trust this device" on 2FA'],
        // Client wording is "Enable remote configuration modification". Ours
        // said only what it does, which reads better but meant nobody looking
        // for the client's term could find it (#11). Lead with the client's
        // label, keep the plain-English explanation as help.
        'allow-remote-config-modification' => [
            'label' => 'Enable remote configuration modification',
            'help' => 'Lets the connecting side change this device\'s settings during a session.',
        ],
        'allow-auto-update' => [
            'label' => 'Auto update',
            'help' => 'Lets the client update itself. The client only exposes this on installed Windows, and on installed macOS that is not a custom build — elsewhere (Linux, portable installs, custom-branded builds) the toggle is hidden, so setting it here may have no visible effect.',
        ],
        'allow-auto-disconnect' => ['label' => 'Disconnect idle sessions'],
        'auto-disconnect-timeout' => [
            'label' => 'Idle timeout (minutes)',
            'help' => 'Only read when idle disconnect is on. 1–1440.',
        ],
        'allow-scope-violation-alarm' => ['label' => 'Raise an alarm on an out-of-scope message'],
        'allow-scope-violation-close' => ['label' => 'Close the session on an out-of-scope message'],

        'enable-abr' => ['label' => 'Adaptive bitrate'],
        'allow-remove-wallpaper' => ['label' => 'Remove the wallpaper during a session', 'help' => 'Windows and Linux.'],
        'allow-auto-record-incoming' => ['label' => 'Record incoming sessions automatically'],
        'keep-awake-during-incoming-sessions' => ['label' => 'Keep the device awake during a session'],
        'enable-lan-discovery' => ['label' => 'Answer LAN discovery'],
    ];

    /**
     * Every entry point needs at least "View" on strategies, including the
     * Livewire update endpoint. Mutators additionally require "Manage".
     */
    public function boot(): void
    {
        $this->authorizeConsole('strategy', 'r');
    }

    // ------------------------------------------------------------- editor ---

    public function create(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $this->resetForm();
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = Strategy::findOrFail($id);

        $this->resetForm();
        $this->editingId = $strategy->id;
        $this->formName = $strategy->name;
        $this->formNote = (string) $strategy->note;
        $this->formEnabled = (bool) $strategy->enabled;
        $this->formIsDefault = (bool) $strategy->is_default;
        $this->formEnforce = (bool) $strategy->enforce;
        $this->formConfirmationTimeout = (int) ($strategy->confirmation_timeout_minutes ?: 15);

        foreach ($strategy->optionMap() as $key => $value) {
            if (array_key_exists($key, $this->formOptions)) {
                $this->formOptions[$key] = $value;
            }
        }
    }

    public function save(): void
    {
        // Backward-compatible action name: old clients still receive the review
        // step, but no public Livewire action can persist without confirmation.
        $this->previewSave();
    }

    private function persist(): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('rollout');

        $snapshot = $this->validatedFormSnapshot();
        if ($snapshot === null) {
            return;
        }

        if ($this->editingId && StrategyRollout::query()
            ->where('strategy_id', $this->editingId)
            ->whereIn('status', [StrategyRollout::STATUS_SCHEDULED, StrategyRollout::STATUS_ACTIVE, StrategyRollout::STATUS_PAUSED])
            ->exists()) {
            $this->addError('rollout', 'Cancel the current rollout before applying another revision.');

            return;
        }

        [$strategy, $creating] = DB::transaction(function () use ($snapshot): array {
            // Impact fingerprints include precedence and assignments. Lock every
            // strategy in stable order so all fleet-policy writers share one
            // source boundary before we revalidate the reviewed plan.
            $lockedStrategies = Strategy::query()->orderBy('id')->lockForUpdate()->get();
            $strategy = $this->editingId ? Strategy::findOrFail($this->editingId) : new Strategy;
            if ($strategy->exists && $strategy->rollouts()->whereIn('status', [
                StrategyRollout::STATUS_SCHEDULED,
                StrategyRollout::STATUS_ACTIVE,
                StrategyRollout::STATUS_PAUSED,
            ])->lockForUpdate()->first() !== null) {
                $this->addError('rollout', 'Cancel the current rollout before applying another revision.');

                return [null, false];
            }

            $impact = StrategyImpact::preview($strategy->exists ? $strategy : null, $snapshot);
            if (! $this->previewing
                || $this->previewFingerprint === null
                || ! hash_equals($this->previewFingerprint, $impact['fingerprint'])) {
                $this->addError('preview', 'The strategy changed after the preview. Review the impact again.');

                return [null, false];
            }
            $affectedCount = (int) $impact['affected_count'];

            $displacedDefault = null;
            $displacedAffected = 0;
            if ($snapshot['is_default']) {
                $displacedDefault = $lockedStrategies->first(
                    fn (Strategy $candidate): bool => $candidate->id !== $strategy->id && $candidate->is_default,
                );

                if ($displacedDefault?->rollouts()->whereIn('status', [
                    StrategyRollout::STATUS_SCHEDULED,
                    StrategyRollout::STATUS_ACTIVE,
                    StrategyRollout::STATUS_PAUSED,
                ])->lockForUpdate()->first() !== null) {
                    $this->addError('rollout', 'Cancel the current rollout on the existing default strategy before replacing it.');

                    return [null, false];
                }

                if ($displacedDefault !== null) {
                    $displacedAffected = (int) StrategyImpact::preview($displacedDefault, [
                        ...$displacedDefault->snapshot(),
                        'is_default' => false,
                    ])['affected_count'];
                }
            }

            $strategy->fill([
                'name' => $snapshot['name'],
                'note' => $snapshot['note'],
                'enabled' => $snapshot['enabled'],
                'is_default' => $snapshot['is_default'],
                'enforce' => $snapshot['enforce'],
                'confirmation_timeout_minutes' => $snapshot['confirmation_timeout_minutes'],
            ]);
            $strategy->setOptions($snapshot['options']);
            $creating = ! $strategy->exists;
            $strategy->save();

            $revision = StrategyRevision::captureSnapshot(
                $strategy,
                $strategy->snapshot(),
                auth()->id(),
                $this->revisionNote !== ''
                    ? $this->revisionNote
                    : ($creating ? 'Initial revision' : 'Saved from the strategy editor'),
                $affectedCount,
            );
            $strategy->forceFill(['active_revision_id' => $revision->id])->saveQuietly();

            if ($displacedDefault !== null) {
                $displacedDefault->refresh();
                $displacedRevision = StrategyRevision::captureSnapshot(
                    $displacedDefault,
                    $displacedDefault->snapshot(),
                    auth()->id(),
                    'Default status moved to '.$strategy->name,
                    $displacedAffected,
                );
                $displacedDefault->forceFill(['active_revision_id' => $displacedRevision->id])->saveQuietly();
            }

            return [$strategy, $creating];
        });

        if ($strategy === null) {
            return;
        }

        ConsoleAudit::record(
            $creating ? 'strategy.create' : 'strategy.update',
            ($creating ? 'Created' : 'Updated').' strategy '.$strategy->name
                .' ('.count($strategy->optionMap()).' option(s))',
            'strategy',
            $strategy->name,
        );

        $this->closeModal();
    }

    public function previewSave(): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $snapshot = $this->validatedFormSnapshot();
        if ($snapshot === null) {
            return;
        }

        $strategy = $this->editingId ? Strategy::findOrFail($this->editingId) : null;
        $this->impactPreview = StrategyImpact::preview($strategy, $snapshot);
        $this->previewFingerprint = $this->impactPreview['fingerprint'];
        $this->previewing = true;
    }

    public function confirmSave(): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->validate(['revisionNote' => ['nullable', 'string', 'max:500']]);

        $snapshot = $this->validatedFormSnapshot();
        if ($snapshot === null) {
            return;
        }

        if (! $this->previewing || $this->previewFingerprint === null) {
            $this->addError('preview', 'The strategy changed after the preview. Review the impact again.');

            return;
        }

        // persist() revalidates the reviewed impact while holding the shared
        // strategy/assignment source locks; this outer check is UX only.
        $this->persist();
    }

    public function closePreview(): void
    {
        $this->reset('previewing', 'impactPreview', 'previewFingerprint');
        $this->resetValidation(['preview', 'rollout', 'revisionNote']);
    }

    public function scheduleRollout(): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation(['preview', 'rollout']);

        if (! $this->editingId) {
            $this->addError('rollout', 'Create the strategy before scheduling a staged rollout.');

            return;
        }

        $this->validate([
            'revisionNote' => ['nullable', 'string', 'max:500'],
            'rolloutStartAt' => ['nullable', 'date'],
            'rolloutBatchSize' => ['required', 'integer', 'min:1', 'max:1000'],
            'rolloutIntervalMinutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $snapshot = $this->validatedFormSnapshot();
        if ($snapshot === null) {
            return;
        }

        $startsAt = $this->rolloutStartAt !== '' ? Carbon::parse($this->rolloutStartAt) : now();
        $scheduled = DB::transaction(function () use ($snapshot, $startsAt): ?array {
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $strategy = Strategy::findOrFail($this->editingId);
            if (! $this->previewing) {
                $this->addError('preview', 'The strategy changed after the preview. Review the impact again.');

                return null;
            }

            if ($snapshot['name'] !== $strategy->name
                || $snapshot['enabled'] !== (bool) $strategy->enabled
                || $snapshot['is_default'] !== (bool) $strategy->is_default
                || $snapshot['confirmation_timeout_minutes'] !== (int) $strategy->confirmation_timeout_minutes) {
                $this->addError(
                    'rollout',
                    'Names, enabled state, default status, and confirmation timeout cannot be staged. Apply those changes to all devices now.',
                );

                return null;
            }

            $impact = StrategyImpact::preview($strategy, $snapshot);
            if (! hash_equals((string) $this->previewFingerprint, $impact['fingerprint'])) {
                $this->addError('preview', 'The strategy changed after the preview. Review the impact again.');

                return null;
            }
            $affectedCount = (int) $impact['affected_count'];
            if ($affectedCount === 0) {
                $this->addError('rollout', 'There are no device policy changes to stage. Apply metadata-only changes now.');

                return null;
            }

            $revision = StrategyRevision::captureSnapshot(
                $strategy,
                $snapshot,
                auth()->id(),
                $this->revisionNote !== '' ? $this->revisionNote : 'Staged rollout',
                $affectedCount,
            );
            $rollout = StrategyRollout::schedule(
                $strategy,
                $revision,
                StrategyImpact::affectedDeviceIds($strategy, $snapshot),
                $startsAt,
                $this->rolloutBatchSize,
                $this->rolloutIntervalMinutes,
                auth()->id(),
            );

            return [$rollout, $strategy, $affectedCount];
        });

        if ($scheduled === null) {
            return;
        }
        [$rollout, $strategy, $affectedCount] = $scheduled;

        ConsoleAudit::record(
            'strategy.rollout.schedule',
            "Scheduled revision {$rollout->revision->revision} of {$strategy->name} for {$affectedCount} device(s)",
            'strategy',
            $strategy->name,
        );

        $this->closeModal();
    }

    public function pauseRollout(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('rollout');
        $rollout = StrategyRollout::findOrFail($id);
        if (! $rollout->pause(auth()->id())) {
            $this->addError('rollout', 'This rollout is no longer active.');

            return;
        }
        ConsoleAudit::record('strategy.rollout.pause', 'Paused rollout '.$rollout->id, 'strategy', $rollout->strategy->name);
    }

    public function resumeRollout(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('rollout');
        $rollout = StrategyRollout::findOrFail($id);
        if (! $rollout->resume()) {
            $this->addError('rollout', 'This rollout is no longer paused.');

            return;
        }
        ConsoleAudit::record('strategy.rollout.resume', 'Resumed rollout '.$rollout->id, 'strategy', $rollout->strategy->name);
    }

    public function cancelRollout(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('rollout');
        $rollout = StrategyRollout::findOrFail($id);
        if (! $rollout->cancel()) {
            $this->addError('rollout', 'This rollout is already closed.');

            return;
        }
        ConsoleAudit::record('strategy.rollout.cancel', 'Cancelled rollout '.$rollout->id, 'strategy', $rollout->strategy->name);
    }

    public function closeModal(): void
    {
        $this->editingId = null;
        $this->resetForm();
        $this->dispatch('strategy-dialog-closed');
    }

    public function toggleEnabled(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $strategy = Strategy::findOrFail($id);
        $this->edit($strategy->id);
        $this->formEnabled = ! $strategy->enabled;
        $this->previewSave();
    }

    public function deleteStrategy(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('rollout');

        $name = DB::transaction(function () use ($id): ?string {
            $strategy = Strategy::query()->lockForUpdate()->findOrFail($id);
            if ($strategy->rollouts()->whereIn('status', [
                StrategyRollout::STATUS_SCHEDULED,
                StrategyRollout::STATUS_ACTIVE,
                StrategyRollout::STATUS_PAUSED,
            ])->exists()) {
                $this->addError('rollout', 'Cancel the current rollout before deleting this strategy.');

                return null;
            }

            $name = $strategy->name;
            $strategy->delete(); // pivots cascade; the model hook re-resolves the fleet

            return $name;
        });

        if ($name === null) {
            return;
        }

        ConsoleAudit::record('strategy.delete', 'Deleted strategy '.$name, 'strategy', $name);
    }

    public function restoreRevision(int $revisionId): void
    {
        $this->authorizeConsole('strategy', 'rw');

        $restored = DB::transaction(function () use ($revisionId): ?Strategy {
            // Revisions are immutable, so discover the owner without locking,
            // then acquire the shared strategy-source lock before any row lock.
            // This keeps rollback ordered with save, rollout, and assignment paths.
            $revisionStrategyId = StrategyRevision::query()->findOrFail($revisionId)->strategy_id;
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $revision = StrategyRevision::query()->lockForUpdate()->findOrFail($revisionId);
            abort_unless((int) $revision->strategy_id === (int) $revisionStrategyId, 409);
            $strategy = Strategy::query()->findOrFail($revision->strategy_id);

            $hasRollout = DB::table('strategy_rollouts')
                ->where('strategy_id', $strategy->id)
                ->whereIn('status', ['scheduled', 'active', 'paused'])
                ->exists();

            if ($hasRollout) {
                $this->addError('history', 'Cancel the current rollout before restoring a revision.');

                return null;
            }

            $snapshot = is_array($revision->snapshot) ? $revision->snapshot : [];
            $affectedCount = (int) StrategyImpact::preview($strategy, [
                ...$snapshot,
                'name' => $strategy->name,
            ])['affected_count'];
            $displacedDefault = null;
            $displacedAffected = 0;
            if ((bool) ($snapshot['is_default'] ?? false)) {
                $displacedDefault = Strategy::query()
                    ->whereKeyNot($strategy->id)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->first();
                if ($displacedDefault?->rollouts()->whereIn('status', [
                    StrategyRollout::STATUS_SCHEDULED,
                    StrategyRollout::STATUS_ACTIVE,
                    StrategyRollout::STATUS_PAUSED,
                ])->exists()) {
                    $this->addError('history', 'Cancel the rollout on the current default strategy before restoring this revision.');

                    return null;
                }
                if ($displacedDefault !== null) {
                    $displacedAffected = (int) StrategyImpact::preview($displacedDefault, [
                        ...$displacedDefault->snapshot(),
                        'is_default' => false,
                    ])['affected_count'];
                }
            }
            $strategy->fill([
                // Strategy names are durable identities. A rollback restores the
                // policy, not a historical name that may now belong to another row.
                'note' => $snapshot['note'] ?? null,
                'enabled' => (bool) ($snapshot['enabled'] ?? true),
                'is_default' => (bool) ($snapshot['is_default'] ?? false),
                'enforce' => (bool) ($snapshot['enforce'] ?? false),
                'confirmation_timeout_minutes' => (int) ($snapshot['confirmation_timeout_minutes'] ?? 15),
            ]);
            $strategy->setOptions(is_array($snapshot['options'] ?? null) ? $snapshot['options'] : []);
            $strategy->save();

            $newRevision = StrategyRevision::captureSnapshot(
                $strategy,
                $strategy->snapshot(),
                auth()->id(),
                'Restored revision '.$revision->revision,
                $affectedCount,
            );
            $strategy->forceFill(['active_revision_id' => $newRevision->id])->saveQuietly();

            if ($displacedDefault !== null) {
                $displacedDefault->refresh();
                $displacedRevision = StrategyRevision::captureSnapshot(
                    $displacedDefault,
                    $displacedDefault->snapshot(),
                    auth()->id(),
                    'Default status moved to '.$strategy->name.' by rollback',
                    $displacedAffected,
                );
                $displacedDefault->forceFill(['active_revision_id' => $displacedRevision->id])->saveQuietly();
            }

            return $strategy;
        });

        if ($restored === null) {
            return;
        }

        ConsoleAudit::record(
            'strategy.rollback',
            'Restored an earlier revision of strategy '.$restored->name,
            'strategy',
            $restored->name,
        );
    }

    public function showHistory(int $strategyId): void
    {
        $this->authorizeConsole('strategy', 'r');
        $strategy = Strategy::findOrFail($strategyId);
        $ids = $strategy->revisions()->orderBy('revision')->pluck('id');
        $this->historyStrategyId = $strategy->id;
        $this->compareFromRevisionId = $ids->count() > 1 ? (int) $ids->first() : null;
        $this->compareToRevisionId = $ids->isNotEmpty() ? (int) $ids->last() : null;
    }

    public function closeHistory(): void
    {
        $this->reset('historyStrategyId', 'compareFromRevisionId', 'compareToRevisionId');
        $this->resetValidation('history');
        $this->dispatch('strategy-dialog-closed');
    }

    public function showCompliance(int $strategyId, string $state = 'all'): void
    {
        $this->authorizeConsole('strategy', 'r');
        Strategy::findOrFail($strategyId);
        $this->complianceStrategyId = $strategyId;
        $this->setComplianceState($state);
    }

    public function setComplianceState(string $state): void
    {
        if (in_array($state, ['all', 'confirmed', 'pending', 'stale', 'offline', 'overridden'], true)) {
            $this->complianceState = $state;
        }
    }

    public function closeCompliance(): void
    {
        $this->reset('complianceStrategyId', 'complianceState');
        $this->dispatch('strategy-dialog-closed');
    }

    public function getHistoryStrategyProperty(): ?Strategy
    {
        return $this->historyStrategyId ? Strategy::find($this->historyStrategyId) : null;
    }

    public function getRevisionHistoryProperty()
    {
        return $this->historyStrategyId
            ? StrategyRevision::query()->where('strategy_id', $this->historyStrategyId)->with(['creator', 'rollouts'])->orderByDesc('revision')->get()
            : collect();
    }

    /** @return array<int,array{key:string,before:mixed,after:mixed}> */
    public function getRevisionComparisonProperty(): array
    {
        if (! $this->historyStrategyId || ! $this->compareFromRevisionId || ! $this->compareToRevisionId) {
            return [];
        }

        $revisions = StrategyRevision::query()
            ->where('strategy_id', $this->historyStrategyId)
            ->whereIn('id', [$this->compareFromRevisionId, $this->compareToRevisionId])
            ->get()->keyBy('id');
        $from = $revisions->get($this->compareFromRevisionId);
        $to = $revisions->get($this->compareToRevisionId);

        return $from && $to ? StrategyRevision::diffSnapshots($from->snapshot, $to->snapshot) : [];
    }

    // --------------------------------------------------------- assignments ---

    public function openAssign(int $id): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $this->resetValidation('assignment');

        $strategy = Strategy::findOrFail($id);
        if ($strategy->rollouts()->whereIn('status', [
            StrategyRollout::STATUS_SCHEDULED,
            StrategyRollout::STATUS_ACTIVE,
            StrategyRollout::STATUS_PAUSED,
        ])->exists()) {
            $this->addError('assignment', 'Cancel the open rollout before editing this strategy’s assignments.');

            return;
        }
        $assignmentTotal = array_sum($strategy->assignmentCounts());
        if ($assignmentTotal > self::MAX_EDITOR_ASSIGNMENTS) {
            $this->addError('assignment', 'This strategy has too many direct assignments for the interactive editor. Consolidate devices into groups before editing assignments.');

            return;
        }

        $this->assigningId = $strategy->id;
        $this->reset('assignPreviewing', 'assignmentImpact', 'assignmentFingerprint');
        $this->assignTab = 'devices';
        $this->assignSearch = '';
        $this->assignDeviceIds = $strategy->devices()->pluck('devices.id')->all();
        $this->assignUserIds = $strategy->users()->pluck('users.id')->all();
        $this->assignGroupIds = $strategy->deviceGroups()->pluck('device_groups.id')->all();
    }

    public function setAssignTab(string $tab): void
    {
        if (in_array($tab, ['devices', 'users', 'groups'], true)) {
            $this->assignTab = $tab;
            $this->assignSearch = '';
        }
    }

    public function saveAssign(): void
    {
        // Backward-compatible action name: assignment changes also require review.
        $this->previewAssign();
    }

    public function previewAssign(): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $strategy = Strategy::findOrFail($this->assigningId);
        $this->validateAssignmentIds();

        $this->assignmentImpact = StrategyAssignmentImpact::preview(
            $strategy,
            $this->assignDeviceIds,
            $this->assignUserIds,
            $this->assignGroupIds,
        );
        $this->assignmentFingerprint = $this->assignmentImpact['fingerprint'];
        $this->assignPreviewing = true;
    }

    public function confirmAssign(): void
    {
        $this->authorizeConsole('strategy', 'rw');
        $strategy = Strategy::findOrFail($this->assigningId);
        $this->validateAssignmentIds();

        if (! $this->assignPreviewing || $this->assignmentFingerprint === null) {
            $this->addError('assignment', 'Assignments changed after the preview. Review the impact again.');

            return;
        }

        $this->persistAssign($strategy);
    }

    public function closeAssignPreview(): void
    {
        $this->reset('assignPreviewing', 'assignmentImpact', 'assignmentFingerprint');
        $this->resetValidation('assignment');
    }

    private function persistAssign(Strategy $strategy): void
    {
        $changed = DB::transaction(function () use ($strategy): ?int {
            // Assignment writers lock the strategy set first, then target rows,
            // so two strategies cannot concurrently claim the same target.
            Strategy::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $currentAssignments = [
                Strategy::LEVEL_DEVICE => $strategy->devices()->pluck('devices.id')->map(fn ($id) => (int) $id)->all(),
                Strategy::LEVEL_USER => $strategy->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
                Strategy::LEVEL_DEVICE_GROUP => $strategy->deviceGroups()->pluck('device_groups.id')->map(fn ($id) => (int) $id)->all(),
            ];
            $desiredAssignments = [
                Strategy::LEVEL_DEVICE => array_map('intval', $this->assignDeviceIds),
                Strategy::LEVEL_USER => array_map('intval', $this->assignUserIds),
                Strategy::LEVEL_DEVICE_GROUP => array_map('intval', $this->assignGroupIds),
            ];
            Device::query()->whereIn('id', array_values(array_unique([
                ...$currentAssignments[Strategy::LEVEL_DEVICE],
                ...$desiredAssignments[Strategy::LEVEL_DEVICE],
            ])))->orderBy('id')->lockForUpdate()->get(['id']);
            User::query()->whereIn('id', array_values(array_unique([
                ...$currentAssignments[Strategy::LEVEL_USER],
                ...$desiredAssignments[Strategy::LEVEL_USER],
            ])))->orderBy('id')->lockForUpdate()->get(['id']);
            DeviceGroup::query()->whereIn('id', array_values(array_unique([
                ...$currentAssignments[Strategy::LEVEL_DEVICE_GROUP],
                ...$desiredAssignments[Strategy::LEVEL_DEVICE_GROUP],
            ])))->orderBy('id')->lockForUpdate()->get(['id']);
            $pivots = [
                Strategy::LEVEL_DEVICE => ['device_strategy', 'device_id'],
                Strategy::LEVEL_USER => ['strategy_user', 'user_id'],
                Strategy::LEVEL_DEVICE_GROUP => ['device_group_strategy', 'device_group_id'],
            ];
            $involvedStrategyIds = [$strategy->id];
            foreach ($pivots as $level => [$table, $column]) {
                $targetIds = array_values(array_unique([...$currentAssignments[$level], ...$desiredAssignments[$level]]));
                if ($targetIds !== []) {
                    $involvedStrategyIds = [
                        ...$involvedStrategyIds,
                        ...DB::table($table)->whereIn($column, $targetIds)->pluck('strategy_id')->all(),
                    ];
                }
            }
            Strategy::guardAssignmentChanges($involvedStrategyIds);
            $strategy = Strategy::findOrFail($strategy->id);
            $current = StrategyAssignmentImpact::preview(
                $strategy,
                $this->assignDeviceIds,
                $this->assignUserIds,
                $this->assignGroupIds,
            );
            if (! hash_equals((string) $this->assignmentFingerprint, $current['fingerprint'])) {
                $this->addError('assignment', 'Assignments changed after the preview. Review the impact again.');

                return null;
            }

            $changed = 0;
            $changed += $this->syncLevel($strategy, Strategy::LEVEL_DEVICE,
                $currentAssignments[Strategy::LEVEL_DEVICE], $this->assignDeviceIds);
            $changed += $this->syncLevel($strategy, Strategy::LEVEL_USER,
                $currentAssignments[Strategy::LEVEL_USER], $this->assignUserIds);
            $changed += $this->syncLevel($strategy, Strategy::LEVEL_DEVICE_GROUP,
                $currentAssignments[Strategy::LEVEL_DEVICE_GROUP], $this->assignGroupIds);

            return $changed;
        });

        if ($changed === null) {
            return;
        }

        ConsoleAudit::record(
            'strategy.assign',
            'Assignments for strategy '.$strategy->name.': '
                .count($this->assignDeviceIds).' device(s), '
                .count($this->assignUserIds).' user(s), '
                .count($this->assignGroupIds).' device group(s)'
                .' ('.$changed.' change(s))',
            'strategy',
            $strategy->name,
        );

        $this->closeAssign();
    }

    private function validateAssignmentIds(): void
    {
        $this->validate([
            'assignDeviceIds' => ['array', 'max:'.self::MAX_EDITOR_ASSIGNMENTS],
            'assignDeviceIds.*' => [Rule::exists('devices', 'id')],
            'assignUserIds' => ['array', 'max:'.self::MAX_EDITOR_ASSIGNMENTS],
            'assignUserIds.*' => [Rule::exists('users', 'id')],
            'assignGroupIds' => ['array', 'max:'.self::MAX_EDITOR_ASSIGNMENTS],
            'assignGroupIds.*' => [Rule::exists('device_groups', 'id')],
        ]);

        if (count($this->assignDeviceIds) + count($this->assignUserIds) + count($this->assignGroupIds) > self::MAX_EDITOR_ASSIGNMENTS) {
            throw ValidationException::withMessages(['assignment' => 'Keep the interactive assignment set at 5,000 targets or fewer.']);
        }
    }

    /**
     * Attach the newly checked targets and release the ones this strategy used
     * to hold. Targets assigned to a DIFFERENT strategy are left alone unless
     * they were checked here, in which case assignTo() moves them — one strategy
     * per target is a schema guarantee, so there is no "both" state to reach.
     *
     * @param  array<int,int>  $current
     * @param  array<int,int|string>  $desired
     */
    private function syncLevel(Strategy $strategy, string $level, array $current, array $desired): int
    {
        $current = array_map('intval', $current);
        $desired = array_values(array_unique(array_map('intval', $desired)));

        $changed = 0;

        foreach (array_diff($desired, $current) as $id) {
            Strategy::assignTo($level, $id, $strategy->id);
            $changed++;
        }

        foreach (array_diff($current, $desired) as $id) {
            Strategy::assignTo($level, $id, null);
            $changed++;
        }

        return $changed;
    }

    public function closeAssign(): void
    {
        $this->assigningId = null;
        $this->reset(
            'assignTab', 'assignSearch', 'assignDeviceIds', 'assignUserIds', 'assignGroupIds',
            'assignPreviewing', 'assignmentImpact', 'assignmentFingerprint',
        );
        $this->resetValidation(['assignDeviceIds', 'assignUserIds', 'assignGroupIds', 'assignment']);
        $this->dispatch('strategy-dialog-closed');
    }

    // -------------------------------------------------------------- render ---

    private function resetForm(): void
    {
        $this->reset(
            'formName', 'formNote', 'formEnabled', 'formIsDefault', 'formEnforce', 'formConfirmationTimeout',
            'previewing', 'impactPreview', 'previewFingerprint',
            'revisionNote', 'rolloutStartAt', 'rolloutBatchSize', 'rolloutIntervalMinutes',
        );
        $this->resetValidation();
        $this->formOptions = array_fill_keys(array_keys(Strategy::OPTION_KEYS), '');
    }

    /** @return array{name:string,note:?string,enabled:bool,is_default:bool,enforce:bool,options:array<string,string>}|null */
    private function validatedFormSnapshot(): ?array
    {
        $this->validate([
            'formName' => [
                'required', 'string', 'max:255',
                Rule::unique('strategies', 'name')->ignore($this->editingId ?: 0),
            ],
            'formNote' => ['nullable', 'string', 'max:500'],
            'formConfirmationTimeout' => ['required', 'integer', 'min:1', 'max:10080'],
        ], [], ['formName' => 'name', 'formNote' => 'note']);

        // Range-check numeric options instead of silently dropping them.
        $options = [];
        foreach ($this->formOptions as $key => $value) {
            $spec = Strategy::OPTION_KEYS[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($spec === null || $value === '') {
                continue;
            }

            if ($spec['type'] === 'bool' && ! in_array($value, ['Y', 'N'], true)) {
                $this->addError('formOptions.'.$key, 'Choose Enabled, Disabled, or Not managed.');

                continue;
            }

            if ($spec['type'] === 'enum' && ! in_array($value, $spec['values'] ?? [], true)) {
                $this->addError('formOptions.'.$key, 'Choose one of the supported values.');

                continue;
            }

            if ($spec['type'] === 'string' && mb_strlen($value) > 4096) {
                $this->addError('formOptions.'.$key, 'Keep this setting under 4,096 characters.');

                continue;
            }

            if ($spec['type'] === 'int' && ! (ctype_digit($value)
                && (int) $value >= ($spec['min'] ?? 0)
                && (int) $value <= ($spec['max'] ?? PHP_INT_MAX))) {
                $this->addError('formOptions.'.$key, 'Enter a whole number between '
                    .($spec['min'] ?? 0).' and '.($spec['max'] ?? PHP_INT_MAX).'.');

                continue;
            }

            $options[$key] = $value;
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        return [
            'name' => $this->formName,
            'note' => $this->formNote !== '' ? $this->formNote : null,
            'enabled' => $this->formEnabled,
            'is_default' => $this->formIsDefault,
            'enforce' => $this->formEnforce,
            'confirmation_timeout_minutes' => $this->formConfirmationTimeout,
            'options' => $options,
        ];
    }

    /**
     * Option keys, grouped and decorated for the editor. Built from
     * Strategy::OPTION_KEYS, so the editor cannot drift from the allowlist.
     *
     * @return array<string,array{title:string,icon:string,help:string,options:array<string,array<string,mixed>>}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (Strategy::optionGroups() as $group => $keys) {
            $options = [];

            foreach ($keys as $key => $spec) {
                $meta = self::OPTION_META[$key] ?? [];
                // `allow-*` keys read as a permission, the rest as a switch.
                $on = str_starts_with($key, 'allow-') ? 'Allowed' : 'Enabled';
                $off = str_starts_with($key, 'allow-') ? 'Not allowed' : 'Disabled';

                $choices = match ($spec['type']) {
                    'bool' => ['Y' => $on, 'N' => $off],
                    'enum' => $meta['choices'] ?? [],
                    default => null,
                };

                $options[$key] = [
                    'key' => $key,
                    'type' => $spec['type'],
                    'label' => $meta['label'] ?? $key,
                    'help' => $meta['help'] ?? null,
                    'choices' => $choices,
                ];
            }

            $catalog[$group] = self::GROUP_META[$group] + ['options' => $options];
        }

        return $catalog;
    }

    public function render()
    {
        $strategies = Strategy::query()
            ->withCount(['devices', 'users', 'deviceGroups', 'resolvedDevices'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $complianceState = in_array($this->complianceState, ['all', 'confirmed', 'pending', 'stale', 'offline', 'overridden'], true)
            ? $this->complianceState
            : 'all';
        $strategySummaries = app(StrategyCompliance::class)->summaries(
            $strategies,
            $this->complianceStrategyId,
            $complianceState,
        );
        $emptyStates = array_fill_keys(['confirmed', 'pending', 'stale', 'offline', 'overridden'], []);
        $complianceSummary = $this->complianceStrategyId
            ? ($strategySummaries[$this->complianceStrategyId] ?? [
                'counts' => array_map('count', $emptyStates),
                'devices' => $emptyStates,
            ])
            : null;
        $complianceDevices = $complianceSummary === null
            ? []
            : ($complianceState === 'all'
                ? collect($complianceSummary['devices'])->flatten(1)->sortBy('rustdesk_id')->values()->all()
                : $complianceSummary['devices'][$complianceState]);
        $openRollouts = StrategyRollout::query()
            ->with('revision')
            ->withCount([
                'targets',
                'targets as released_targets_count' => fn ($query) => $query->whereNotNull('released_at'),
                'targets as confirmed_targets_count' => fn ($query) => $query->whereNotNull('confirmed_at'),
            ])
            ->whereIn('status', [StrategyRollout::STATUS_SCHEDULED, StrategyRollout::STATUS_ACTIVE, StrategyRollout::STATUS_PAUSED])
            ->get()
            ->keyBy('strategy_id');

        return view('livewire.strategy-list', [
            'strategies' => $strategies,
            'catalog' => self::catalog(),
            'strategySummaries' => $strategySummaries,
            'openRollouts' => $openRollouts,
            'historyStrategy' => $this->historyStrategy,
            'revisionHistory' => $this->revisionHistory,
            'revisionComparison' => $this->revisionComparison,
            'complianceSummary' => $complianceSummary,
            'complianceDevices' => $complianceDevices,
            'assigning' => $this->assigningId && auth()->user()->consoleAllows('strategy', 'rw')
                ? $strategies->firstWhere('id', $this->assigningId)
                : null,
        ] + $this->assignCandidates());

        // (assignCandidates() is only non-empty while the assignment modal is open)
    }

    /**
     * Candidates for the assignment modal, plus "already assigned to <other>"
     * hints for the rows on show. Device lists can be large, so that tab is
     * search-driven and capped — the checked set still carries every device the
     * strategy holds, including ones outside the current search.
     *
     * @return array<string,mixed>
     */
    private function assignCandidates(): array
    {
        $empty = ['assignDevices' => collect(), 'assignUsers' => collect(), 'assignGroups' => collect(), 'assignTaken' => []];

        if ($this->assigningId === null || ! auth()->user()->consoleAllows('strategy', 'rw')) {
            return $empty;
        }

        $devices = Device::query()
            ->when($this->assignSearch !== '' && $this->assignTab === 'devices', function ($q) {
                $s = '%'.$this->assignSearch.'%';
                $q->where(fn ($q) => $q->where('rustdesk_id', 'like', $s)
                    ->orWhere('alias', 'like', $s)
                    ->orWhere('hostname', 'like', $s));
            })
            ->orderBy('rustdesk_id')
            ->limit(200)
            ->get(['id', 'rustdesk_id', 'alias', 'hostname']);

        $users = User::orderBy('username')->get(['id', 'username', 'name']);
        $groups = DeviceGroup::orderBy('name')->get(['id', 'name']);

        return [
            'assignDevices' => $devices,
            'assignUsers' => $users,
            'assignGroups' => $groups,
            'assignTaken' => [
                'devices' => $this->takenBy('device_strategy', 'device_id', $devices->pluck('id')->all()),
                'users' => $this->takenBy('strategy_user', 'user_id', $users->pluck('id')->all()),
                'groups' => $this->takenBy('device_group_strategy', 'device_group_id', $groups->pluck('id')->all()),
            ],
        ];
    }

    /**
     * target id => name of the strategy currently holding it (this one included).
     *
     * @param  array<int,int>  $ids
     * @return array<int,string>
     */
    private function takenBy(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)
            ->join('strategies', 'strategies.id', '=', $table.'.strategy_id')
            ->whereIn($table.'.'.$column, $ids)
            ->pluck('strategies.name', $table.'.'.$column)
            ->all();
    }
}
