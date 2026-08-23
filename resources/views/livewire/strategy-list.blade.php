<div class="strategy-v2">
    @php($canWriteStrategies = auth()->user()->consoleAllows('strategy', 'rw'))
    <div class="card">

            {{-- Toolbar --}}
            <div class="rd-toolbar">
                <div>
                    <h4 class="header-title">Strategies</h4>
                    <p class="rd-card-sub mb-0">Client settings pushed to devices on their next heartbeat.</p>
                </div>
                @if ($canWriteStrategies)
                    <div class="rd-toolbar-actions">
                        <button type="button" class="btn btn-primary" wire:click="create">
                            <i class="ri-add-line"></i>Add Strategy
                        </button>
                    </div>
                @endif
            </div>

            @if ($strategies->isNotEmpty() && $strategies->firstWhere('is_default', true) === null)
                <div class="rd-toolbar">
                    <div class="alert alert-secondary py-2 mb-0 w-100">
                        <i class="ri-information-line me-1"></i>No default strategy. Devices with no assignment of their own keep whatever settings they already have.
                    </div>
                </div>
            @endif

            @error('rollout')
                <div class="px-3 pb-2"><div class="alert alert-danger mb-0" role="alert">{{ $message }}</div></div>
            @enderror
            @error('assignment')
                <div class="px-3 pb-2"><div class="alert alert-danger mb-0" role="alert">{{ $message }}</div></div>
            @enderror

            {{-- Desktop table (md+) --}}
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover table-centered mb-0">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Options</th>
                        <th>Assigned to</th>
                        <th>Confirmation</th>
                        <th>Enabled</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($strategies as $strategy)
                        @php($summary = $strategySummaries[$strategy->id]['counts'])
                        @php($rollout = $openRollouts->get($strategy->id))
                        <tr wire:key="s{{ $strategy->id }}">
                            <td>
                                <span class="rd-cell-title d-inline">{{ $strategy->name }}</span>
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary ms-1">Default</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning ms-1" title="Re-pushed on every heartbeat, overwriting local changes">Enforced</span>
                                @endif
                                @if ($strategy->note)
                                    <small class="text-muted d-block">{{ $strategy->note }}</small>
                                @endif
                                @if ($rollout)
                                    <small class="d-block mt-1">
                                        <span class="badge bg-primary-subtle text-primary text-capitalize">{{ $rollout->status }} rollout · r{{ $rollout->revision->revision }}</span>
                                        <span class="text-muted">{{ $rollout->released_targets_count }}/{{ $rollout->targets_count }} released · {{ $rollout->confirmed_targets_count }} confirmed</span>
                                        @if ($rollout->status === 'scheduled' && $rollout->starts_at)
                                            <span class="text-muted d-block">Starts {{ $rollout->starts_at->timezone(config('app.timezone'))->format('M j, g:i A T') }}</span>
                                        @elseif ($rollout->status === 'active' && $rollout->next_release_at)
                                            <span class="text-muted d-block">Next batch {{ $rollout->next_release_at->timezone(config('app.timezone'))->format('M j, g:i A T') }}</span>
                                        @endif
                                    </small>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">{{ count($strategy->optionMap()) }}</span>
                            </td>
                            <td>
                                <span class="text-nowrap" title="Devices">
                                    <i class="ri-computer-line me-1 text-muted"></i>{{ $strategy->devices_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="Users">
                                    <i class="ri-user-line me-1 text-muted"></i>{{ $strategy->users_count }}
                                </span>
                                <span class="text-nowrap ms-2" title="Device groups">
                                    <i class="ri-folder-line me-1 text-muted"></i>{{ $strategy->device_groups_count }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1" aria-label="Strategy confirmation status">
                                    <button type="button" class="btn btn-sm py-0 px-1 bg-success-subtle text-success" wire:click="showCompliance({{ $strategy->id }}, 'confirmed')" title="Confirmed" aria-label="Confirmed: {{ $summary['confirmed'] }} devices">✓ {{ $summary['confirmed'] }}</button>
                                    <button type="button" class="btn btn-sm py-0 px-1 bg-warning-subtle text-warning" wire:click="showCompliance({{ $strategy->id }}, 'pending')" title="Pending" aria-label="Pending: {{ $summary['pending'] }} devices">◷ {{ $summary['pending'] }}</button>
                                    <button type="button" class="btn btn-sm py-0 px-1 bg-danger-subtle text-danger" wire:click="showCompliance({{ $strategy->id }}, 'stale')" title="Stale" aria-label="Stale: {{ $summary['stale'] }} devices">! {{ $summary['stale'] }}</button>
                                    <button type="button" class="btn btn-sm py-0 px-1 bg-secondary-subtle text-secondary" wire:click="showCompliance({{ $strategy->id }}, 'offline')" title="Offline" aria-label="Offline: {{ $summary['offline'] }} devices">○ {{ $summary['offline'] }}</button>
                                    <button type="button" class="btn btn-sm py-0 px-1 bg-info-subtle text-info" wire:click="showCompliance({{ $strategy->id }}, 'overridden')" title="Overridden" aria-label="Overridden: {{ $summary['overridden'] }} devices">↳ {{ $summary['overridden'] }}</button>
                                </div>
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="strategy-enabled-{{ $strategy->id }}"
                                           @checked($strategy->enabled)
                                           @disabled(!$canWriteStrategies)
                                           @if($canWriteStrategies) wire:click="toggleEnabled({{ $strategy->id }})" @endif>
                                    <label class="form-check-label visually-hidden" for="strategy-enabled-{{ $strategy->id }}">Enabled for {{ $strategy->name }}</label>
                                </div>
                            </td>
                            <td class="text-end rd-rowact">
                                @if ($rollout && $canWriteStrategies)
                                    @if ($rollout->status === 'paused')
                                        <button type="button" class="btn btn-link btn-sm p-0 me-2" aria-label="Resume rollout for {{ $strategy->name }}" wire:click="resumeRollout({{ $rollout->id }})">Resume</button>
                                    @elseif ($rollout->status === 'active')
                                        <button type="button" class="btn btn-link btn-sm p-0 me-2" aria-label="Pause rollout for {{ $strategy->name }}" wire:click="pauseRollout({{ $rollout->id }})">Pause</button>
                                    @endif
                                    @if ($rollout->status !== 'active' || $rollout->released_targets_count === 0)
                                        <button type="button" class="btn btn-link btn-sm p-0 me-2 text-danger" aria-label="Cancel rollout for {{ $strategy->name }}" wire:click="cancelRollout({{ $rollout->id }})" wire:confirm="Cancel this rollout? {{ $rollout->released_targets_count }} released device(s) return to the current revision on their next heartbeat.">Cancel rollout</button>
                                    @endif
                                @endif
                                <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" aria-label="Revision history for {{ $strategy->name }}" wire:click="showHistory({{ $strategy->id }})">History</button>
                                @if ($canWriteStrategies)
                                    <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" aria-label="Assign {{ $strategy->name }}" wire:click="openAssign({{ $strategy->id }})">Assign</button>
                                    <button type="button" class="btn btn-link btn-sm p-0 me-2 rd-act" aria-label="Edit {{ $strategy->name }}" wire:click="edit({{ $strategy->id }})">Edit</button>
                                    <a href="javascript:void(0);" class="text-danger" aria-label="Delete {{ $strategy->name }}"
                                       wire:click="deleteStrategy({{ $strategy->id }})"
                                       wire:confirm="Delete strategy {{ $strategy->name }}? Devices assigned to it fall back to the default strategy, and the options it pushed are reset to the client defaults on the next heartbeat.">Delete</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="rd-empty-cell">
                                <div class="rd-empty">
                                    <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                                    <p class="rd-empty-title">No strategies yet. Click "Add Strategy" to create one.</p>
                                    <p class="rd-empty-text">A strategy is a set of client options the server pushes out — permissions, defaults, whatever you want held steady across a fleet.</p>
                                    @if ($canWriteStrategies)<button type="button" class="btn btn-sm btn-outline-light" wire:click="create">Add Strategy</button>@endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile card list (below md) --}}
            <div class="d-md-none rd-cardlist">
                @forelse ($strategies as $strategy)
                    @php($summary = $strategySummaries[$strategy->id]['counts'])
                    @php($rollout = $openRollouts->get($strategy->id))
                    <div class="rd-mini" wire:key="ms{{ $strategy->id }}">
                            <div class="rd-mini-head">
                                <div class="min-width-0">
                                    <span class="rd-mini-title text-truncate">{{ $strategy->name }}</span>
                                    <span class="rd-mini-sub text-truncate">{{ $strategy->note ?: count($strategy->optionMap()).' option(s)' }}</span>
                                </div>
                                <div class="form-check form-switch mb-0 flex-shrink-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="m-strategy-enabled-{{ $strategy->id }}"
                                           @checked($strategy->enabled)
                                           @disabled(!$canWriteStrategies)
                                           @if($canWriteStrategies) wire:click="toggleEnabled({{ $strategy->id }})" @endif>
                                    <label class="form-check-label visually-hidden" for="m-strategy-enabled-{{ $strategy->id }}">Enabled for {{ $strategy->name }}</label>
                                </div>
                            </div>
                            <div class="mt-2">
                                @if ($strategy->is_default)
                                    <span class="badge bg-primary-subtle text-primary">Default</span>
                                @endif
                                @if ($strategy->enforce)
                                    <span class="badge bg-warning-subtle text-warning">Enforced</span>
                                @endif
                                @if ($rollout)
                                    <span class="badge bg-primary-subtle text-primary text-capitalize">{{ $rollout->status }} · {{ $rollout->released_targets_count }}/{{ $rollout->targets_count }} released · {{ $rollout->confirmed_targets_count }} confirmed</span>
                                    @if ($canWriteStrategies && $rollout->status === 'active')
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-1" aria-label="Pause rollout for {{ $strategy->name }}" wire:click="pauseRollout({{ $rollout->id }})">Pause</button>
                                    @elseif ($canWriteStrategies && $rollout->status === 'paused')
                                        <button type="button" class="btn btn-sm btn-link p-0 ms-1" aria-label="Resume rollout for {{ $strategy->name }}" wire:click="resumeRollout({{ $rollout->id }})">Resume</button>
                                    @endif
                                    @if ($canWriteStrategies && ($rollout->status !== 'active' || $rollout->released_targets_count === 0))<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" aria-label="Cancel rollout for {{ $strategy->name }}" wire:click="cancelRollout({{ $rollout->id }})" wire:confirm="Cancel this rollout? {{ $rollout->released_targets_count }} released device(s) return to the current revision.">Cancel</button>@endif
                                    @if ($rollout->status === 'scheduled' && $rollout->starts_at)
                                        <small class="w-100 text-muted">Starts {{ $rollout->starts_at->timezone(config('app.timezone'))->format('M j, g:i A T') }}</small>
                                    @elseif ($rollout->status === 'active' && $rollout->next_release_at)
                                        <small class="w-100 text-muted">Next {{ $rollout->next_release_at->timezone(config('app.timezone'))->format('M j, g:i A T') }}</small>
                                    @endif
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <button type="button" class="btn btn-sm py-0 px-1 bg-success-subtle text-success" aria-label="Confirmed: {{ $summary['confirmed'] }} devices" wire:click="showCompliance({{ $strategy->id }}, 'confirmed')">✓ {{ $summary['confirmed'] }}</button>
                                <button type="button" class="btn btn-sm py-0 px-1 bg-warning-subtle text-warning" aria-label="Pending: {{ $summary['pending'] }} devices" wire:click="showCompliance({{ $strategy->id }}, 'pending')">◷ {{ $summary['pending'] }}</button>
                                <button type="button" class="btn btn-sm py-0 px-1 bg-danger-subtle text-danger" aria-label="Stale: {{ $summary['stale'] }} devices" wire:click="showCompliance({{ $strategy->id }}, 'stale')">! {{ $summary['stale'] }}</button>
                                <button type="button" class="btn btn-sm py-0 px-1 bg-secondary-subtle text-secondary" aria-label="Offline: {{ $summary['offline'] }} devices" wire:click="showCompliance({{ $strategy->id }}, 'offline')">○ {{ $summary['offline'] }}</button>
                                <button type="button" class="btn btn-sm py-0 px-1 bg-info-subtle text-info" aria-label="Overridden: {{ $summary['overridden'] }} devices" wire:click="showCompliance({{ $strategy->id }}, 'overridden')">↳ {{ $summary['overridden'] }}</button>
                            </div>
                            <div class="rd-mini-foot">
                                <span class="rd-mini-sub text-nowrap">
                                    <i class="ri-computer-line me-1"></i>{{ $strategy->devices_count }}
                                    <i class="ri-user-line ms-2 me-1"></i>{{ $strategy->users_count }}
                                    <i class="ri-folder-line ms-2 me-1"></i>{{ $strategy->device_groups_count }}
                                </span>
                                <div class="rd-mini-acts">
                                    <button type="button" class="rd-iconbtn border-0" title="Revision history" aria-label="Revision history for {{ $strategy->name }}"
                                            wire:click="showHistory({{ $strategy->id }})"><i class="ri-history-line"></i></button>
                                    @if ($canWriteStrategies)
                                        <a href="javascript:void(0);" class="rd-iconbtn" title="Assign" aria-label="Assign {{ $strategy->name }}"
                                           wire:click="openAssign({{ $strategy->id }})"><i class="ri-links-line"></i></a>
                                        <a href="javascript:void(0);" class="rd-iconbtn" title="Edit" aria-label="Edit {{ $strategy->name }}"
                                           wire:click="edit({{ $strategy->id }})"><i class="ri-pencil-line"></i></a>
                                        <a href="javascript:void(0);" class="rd-iconbtn text-danger" title="Delete" aria-label="Delete {{ $strategy->name }}"
                                           wire:click="deleteStrategy({{ $strategy->id }})"
                                           wire:confirm="Delete strategy {{ $strategy->name }}? Devices assigned to it fall back to the default strategy."><i class="ri-delete-bin-line"></i></a>
                                    @endif
                                </div>
                            </div>
                    </div>
                @empty
                    <div class="rd-empty">
                        <div class="rd-empty-icon"><i class="ri-settings-3-line"></i></div>
                        <p class="rd-empty-title">No strategies yet. Tap "Add Strategy" to create one.</p>
                        @if ($canWriteStrategies)<button type="button" class="btn btn-sm btn-outline-light" wire:click="create">Add Strategy</button>@endif
                    </div>
                @endforelse
            </div>
    </div>

    {{-- Create / edit modal --}}
    @if ($editingId !== null)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="strategy-editor-title"
             wire:keydown.escape.window="{{ $previewing ? 'closePreview' : 'closeModal' }}"
             style="background: rgba(0,0,0,.6);" wire:key="strategy-editor">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="previewSave">
                        <div class="modal-header">
                            <h5 class="modal-title" id="strategy-editor-title">{{ $editingId === 0 ? 'Add Strategy' : 'Edit Strategy' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-name">Name <span class="text-danger">*</span></label>
                                    <input type="text" id="sl-name" class="form-control @error('formName') is-invalid @enderror"
                                           wire:model="formName" autocomplete="off">
                                    @error('formName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-12 col-md-6 mb-3">
                                    <label class="form-label" for="sl-note">Note</label>
                                    <input type="text" id="sl-note" class="form-control @error('formNote') is-invalid @enderror"
                                           wire:model="formNote" maxlength="500">
                                    @error('formNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enabled" wire:model="formEnabled">
                                        <label class="form-check-label" for="sl-enabled">Enabled</label>
                                    </div>
                                    <small class="text-muted">A disabled strategy is skipped as if it were not assigned.</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-default" wire:model="formIsDefault">
                                        <label class="form-check-label" for="sl-default">Default strategy</label>
                                    </div>
                                    <small class="text-muted">Applied to every device with no assignment of its own. Only one strategy can hold this.</small>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="sl-enforce" wire:model="formEnforce">
                                        <label class="form-check-label" for="sl-enforce">Enforce</label>
                                    </div>
                                    <small class="text-muted">Re-push on every heartbeat, so a change made on the device is undone within a minute. Off = push once, then leave the device alone.</small>
                                </div>
                            </div>

                            <div class="alert alert-secondary py-2 fs-13 mb-3">
                                <i class="ri-information-line me-1"></i>Controls left on <strong>Not managed</strong> are not part of this strategy: the device keeps whatever it has. Changing a managed option back to Not managed resets that option to the client's built-in default on the next heartbeat.
                            </div>

                            <div class="row mb-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="sl-confirmation-timeout">Confirmation becomes stale after</label>
                                    <div class="input-group">
                                        <input type="number" id="sl-confirmation-timeout" min="1" max="10080"
                                               class="form-control @error('formConfirmationTimeout') is-invalid @enderror"
                                               wire:model="formConfirmationTimeout">
                                        <span class="input-group-text">minutes</span>
                                    </div>
                                    @error('formConfirmationTimeout') <div class="text-danger fs-13">{{ $message }}</div> @enderror
                                    <small class="text-muted">Pending devices move to stale when they have not acknowledged within this window.</small>
                                </div>
                            </div>

                            @foreach ($catalog as $groupKey => $group)
                                <h5 class="mt-3 mb-1 fs-15">
                                    <i class="{{ $group['icon'] }} me-1 text-muted"></i>{{ $group['title'] }}
                                </h5>
                                <p class="text-muted fs-13">{{ $group['help'] }}</p>

                                <div class="row">
                                    @foreach ($group['options'] as $key => $opt)
                                        <div class="col-12 col-md-6 mb-3" wire:key="opt-{{ $key }}">
                                            <label class="form-label mb-1" for="sl-opt-{{ $key }}">{{ $opt['label'] }}</label>
                                            @if ($opt['choices'] !== null)
                                                <select id="sl-opt-{{ $key }}" class="form-select"
                                                        wire:model="formOptions.{{ $key }}">
                                                    <option value="">Not managed</option>
                                                    @foreach ($opt['choices'] as $value => $choiceLabel)
                                                        <option value="{{ $value }}">{{ $choiceLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" id="sl-opt-{{ $key }}"
                                                       class="form-control @error('formOptions.'.$key) is-invalid @enderror"
                                                       placeholder="Not managed"
                                                       wire:model="formOptions.{{ $key }}">
                                                @error('formOptions.'.$key) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                            @endif
                                            <small class="text-muted d-block">
                                                <code class="fs-12">{{ $opt['key'] }}</code>
                                                @if ($opt['help']) — {{ $opt['help'] }} @endif
                                            </small>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="previewSave">Review Impact</span>
                                <span wire:loading wire:target="previewSave">Calculating…</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Impact preview and rollout choice --}}
    @if ($previewing && $editingId !== null)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="strategy-impact-title" style="background: rgba(0,0,0,.72); z-index: 1080;" wire:key="strategy-impact-preview">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-impact-title">Review strategy impact</h5>
                            <small class="text-muted">Nothing has been changed yet.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closePreview" aria-label="Back to editor"></button>
                    </div>
                    <div class="modal-body">
                        @error('preview') <div class="alert alert-danger">{{ $message }}</div> @enderror
                        @error('rollout') <div class="alert alert-danger">{{ $message }}</div> @enderror

                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <i class="ri-computer-line fs-4"></i>
                            <div><strong>{{ $impactPreview['affected_count'] ?? 0 }} device(s)</strong> will receive a different effective strategy or policy.</div>
                        </div>

                        @if (!empty($impactPreview['dangerous']))
                            <div class="alert alert-warning">
                                <strong><i class="ri-alert-line me-1"></i>High-impact controls</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach ($impactPreview['dangerous'] as $warning)
                                        <li><code>{{ $warning['key'] }}</code> changes to <strong>{{ $warning['after'] }}</strong>.</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (!empty($impactPreview['resets']))
                            <div class="alert alert-warning">
                                <strong>{{ count($impactPreview['resets']) }} managed option(s) will reset to the client default:</strong>
                                {{ implode(', ', $impactPreview['resets']) }}
                            </div>
                        @endif

                        <h6>Policy diff</h6>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Setting</th><th>Before</th><th>After</th></tr></thead>
                                <tbody>
                                @forelse (($impactPreview['option_changes'] ?? []) as $key => $change)
                                    <tr>
                                        <td><code>{{ $key }}</code></td>
                                        <td>{{ $change['before'] ?? 'Not managed' }}</td>
                                        <td>{{ $change['after'] ?? 'Not managed' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">No option changes.</td></tr>
                                @endforelse
                                @foreach (($impactPreview['metadata_changes'] ?? []) as $key => $change)
                                    <tr>
                                        <td>{{ str_replace('_', ' ', ucfirst($key)) }}</td>
                                        <td>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] ?? '—') }}</td>
                                        <td>{{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] ?? '—') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if (!empty($impactPreview['affected_devices']))
                            <details class="mb-3">
                                <summary class="fw-semibold">Affected-device sample (up to 50)</summary>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @foreach ($impactPreview['affected_devices'] as $device)
                                        <span class="badge bg-secondary-subtle text-secondary">
                                            {{ $device['rustdesk_id'] }} · {{ $device['label'] }}
                                            @if ($device['winning_level']) · {{ str_replace('_', ' ', $device['winning_level']) }} @endif
                                        </span>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="strategy-revision-note">Revision note</label>
                            <input id="strategy-revision-note" type="text" class="form-control @error('revisionNote') is-invalid @enderror"
                                   wire:model="revisionNote" maxlength="500" placeholder="Why is this changing?">
                            @error('revisionNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        @if ($editingId !== 0)
                            <div class="card border mb-0">
                                <div class="card-body">
                                    <h6>Staged rollout</h6>
                                    <p class="text-muted fs-13">Release a fixed device batch now or at a scheduled time, in stable device-ID order. Pausing stops future batches; already released devices keep the candidate revision.</p>
                                    <div class="row g-2">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="rollout-start">Start (optional)</label>
                                            <input id="rollout-start" type="datetime-local" class="form-control" wire:model="rolloutStartAt">
                                            <small class="form-text text-muted">{{ config('app.timezone') }} time</small>
                                        </div>
                                        <div class="col-6 col-md-4">
                                            <label class="form-label" for="rollout-batch">Devices per batch</label>
                                            <input id="rollout-batch" type="number" min="1" max="1000" class="form-control" wire:model="rolloutBatchSize">
                                        </div>
                                        <div class="col-6 col-md-4">
                                            <label class="form-label" for="rollout-interval">Minutes between</label>
                                            <input id="rollout-interval" type="number" min="1" max="10080" class="form-control" wire:model="rolloutIntervalMinutes">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer flex-wrap">
                        <button type="button" class="btn btn-light me-auto" wire:click="closePreview">Back</button>
                        @if ($editingId !== 0)
                            <button type="button" class="btn btn-outline-primary" wire:click="scheduleRollout">
                                <span wire:loading.remove wire:target="scheduleRollout">Schedule staged rollout</span>
                                <span wire:loading wire:target="scheduleRollout">Scheduling…</span>
                            </button>
                        @endif
                        <button type="button" class="btn btn-primary" wire:click="confirmSave">
                            <span wire:loading.remove wire:target="confirmSave">{{ $editingId === 0 ? 'Create now' : 'Apply to all now' }}</span>
                            <span wire:loading wire:target="confirmSave">Applying…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Assignment modal --}}
    @if ($assigning)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true"
             aria-labelledby="strategy-assignment-title" wire:keydown.escape.window="{{ $assignPreviewing ? 'closeAssignPreview' : 'closeAssign' }}" style="background: rgba(0,0,0,.6);" wire:key="strategy-assign">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="strategy-assignment-title">Assign "{{ $assigning->name }}"</h5>
                        <button type="button" class="btn-close" wire:click="closeAssign" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted fs-13">
                            A device gets one strategy: its own assignment wins, then its owner's, then its device group's, then the default.
                            Checking a target that already belongs to another strategy moves it here.
                        </p>

                        <ul class="nav nav-tabs nav-bordered mb-3">
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'devices' ? 'active' : '' }}"
                                   wire:click="setAssignTab('devices')">
                                    <i class="ri-computer-line me-1"></i>Devices
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignDeviceIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'users' ? 'active' : '' }}"
                                   wire:click="setAssignTab('users')">
                                    <i class="ri-user-line me-1"></i>Users
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignUserIds) }}</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="javascript:void(0);" class="nav-link {{ $assignTab === 'groups' ? 'active' : '' }}"
                                   wire:click="setAssignTab('groups')">
                                    <i class="ri-folder-line me-1"></i>Device groups
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($assignGroupIds) }}</span>
                                </a>
                            </li>
                        </ul>

                        @if ($assignTab === 'devices')
                            <input type="search" class="form-control mb-2" placeholder="Search ID, alias, hostname…"
                                   wire:model.live.debounce.300ms="assignSearch">
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignDevices as $d)
                                        <tr wire:key="ad{{ $d->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-dev-{{ $d->id }}"
                                                       value="{{ $d->id }}" wire:model="assignDeviceIds"
                                                       aria-label="Assign device {{ $d->rustdesk_id }}">
                                            </td>
                                            <td>
                                                {{-- The label is the tap target: a 14px checkbox is not one, and
                                                     `for` makes the whole cell toggle it without a second binding. --}}
                                                <label class="rd-picklabel" for="sa-dev-{{ $d->id }}">
                                                    <span class="fw-semibold">{{ $d->rustdesk_id }}</span>
                                                    @if ($d->alias || $d->hostname)
                                                        <small class="text-muted d-block">{{ $d->alias ?: $d->hostname }}</small>
                                                    @endif
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['devices'][$d->id] ?? null) && $assignTaken['devices'][$d->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['devices'][$d->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">No devices match.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">
                                {{ count($assignDeviceIds) }} selected
                                @if ($assignDevices->count() >= 200) · showing first 200, refine with search @endif
                            </small>
                        @elseif ($assignTab === 'users')
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignUsers as $u)
                                        <tr wire:key="au{{ $u->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-usr-{{ $u->id }}"
                                                       value="{{ $u->id }}" wire:model="assignUserIds"
                                                       aria-label="Assign user {{ $u->username }}">
                                            </td>
                                            <td>
                                                <label class="rd-picklabel" for="sa-usr-{{ $u->id }}">
                                                    <span class="fw-semibold">{{ $u->username }}</span>
                                                    @if ($u->name)
                                                        <small class="text-muted d-block">{{ $u->name }}</small>
                                                    @endif
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['users'][$u->id] ?? null) && $assignTaken['users'][$u->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['users'][$u->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">No users yet.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">Applies to every device owned by the checked users.</small>
                        @else
                            <div class="rd-scrollbox" style="max-height: 340px; overflow-y: auto;">
                                <table class="table table-sm table-hover mb-0">
                                    <tbody>
                                    @forelse ($assignGroups as $g)
                                        <tr wire:key="ag{{ $g->id }}">
                                            <td style="width:38px;">
                                                <input class="form-check-input" type="checkbox" id="sa-grp-{{ $g->id }}"
                                                       value="{{ $g->id }}" wire:model="assignGroupIds"
                                                       aria-label="Assign device group {{ $g->name }}">
                                            </td>
                                            <td>
                                                <label class="rd-picklabel" for="sa-grp-{{ $g->id }}">
                                                    <span class="fw-semibold">{{ $g->name }}</span>
                                                </label>
                                            </td>
                                            <td class="text-end">
                                                @if (($assignTaken['groups'][$g->id] ?? null) && $assignTaken['groups'][$g->id] !== $assigning->name)
                                                    <span class="badge bg-secondary-subtle text-secondary">{{ $assignTaken['groups'][$g->id] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-center text-muted py-3">No device groups yet.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <small class="text-muted">Applies to every device in the checked groups that has no closer assignment.</small>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="closeAssign">Cancel</button>
                        <button type="button" class="btn btn-primary" wire:click="previewAssign">
                            <span wire:loading.remove wire:target="previewAssign">Review Assignment Impact</span>
                            <span wire:loading wire:target="previewAssign">Calculating…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Assignment impact preview --}}
    @if ($assignPreviewing && $assigning)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="assignment-impact-title"
             style="background: rgba(0,0,0,.72); z-index: 1080;" wire:key="assignment-impact-preview">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="assignment-impact-title">Review assignment impact</h5>
                            <small class="text-muted">Nothing has been reassigned yet.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeAssignPreview" aria-label="Back to assignments"></button>
                    </div>
                    <div class="modal-body">
                        @error('assignment') <div class="alert alert-danger">{{ $message }}</div> @enderror
                        <div class="alert alert-info"><strong>{{ $assignmentImpact['affected_count'] ?? 0 }} device(s)</strong> will resolve to a different strategy.</div>
                        <div class="row g-2 mb-3">
                            @foreach (['device' => 'Direct devices', 'user' => 'Users', 'device_group' => 'Device groups'] as $level => $label)
                                @php($change = $assignmentImpact['assignment_changes'][$level] ?? ['added_count' => 0, 'removed_count' => 0])
                                <div class="col-12 col-md-4">
                                    <div class="card border h-100"><div class="card-body py-2">
                                        <strong>{{ $label }}</strong>
                                        <div class="text-success">+ {{ $change['added_count'] }}</div>
                                        <div class="text-danger">− {{ $change['removed_count'] }}</div>
                                    </div></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Device</th><th>Before</th><th>After</th><th>Winning level</th></tr></thead>
                                <tbody>
                                @forelse (($assignmentImpact['affected_devices'] ?? []) as $device)
                                    <tr>
                                        <td><strong>{{ $device['rustdesk_id'] }}</strong><small class="text-muted d-block">{{ $device['label'] }}</small></td>
                                        <td>{{ $device['before_strategy'] }}</td>
                                        <td>{{ $device['after_strategy'] }}</td>
                                        <td class="text-capitalize">{{ str_replace('_', ' ', $device['winning_level'] ?? 'none') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-3">No device changes. Only assignment metadata will change.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light me-auto" wire:click="closeAssignPreview">Back</button>
                        <button type="button" class="btn btn-primary" wire:click="confirmAssign">
                            <span wire:loading.remove wire:target="confirmAssign">Apply assignments</span>
                            <span wire:loading wire:target="confirmAssign">Applying…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Immutable revision history and comparison --}}
    @if ($historyStrategy)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="strategy-history-title" wire:keydown.escape.window="closeHistory"
             style="background: rgba(0,0,0,.65);" wire:key="strategy-history">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-history-title">{{ $historyStrategy->name }} revision history</h5>
                            <small class="text-muted">Restoring creates a new revision; existing history is never rewritten.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeHistory" aria-label="Close history"></button>
                    </div>
                    <div class="modal-body">
                        @error('history') <div class="alert alert-danger">{{ $message }}</div> @enderror
                        @if ($revisionHistory->isNotEmpty())
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-from">Compare from</label>
                                    <select id="compare-from" class="form-select" wire:model.live="compareFromRevisionId">
                                        <option value="">Choose revision</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">Revision {{ $revision->revision }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label class="form-label" for="compare-to">Compare to</label>
                                    <select id="compare-to" class="form-select" wire:model.live="compareToRevisionId">
                                        <option value="">Choose revision</option>
                                        @foreach ($revisionHistory->sortBy('revision') as $revision)
                                            <option value="{{ $revision->id }}">Revision {{ $revision->revision }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            @if ($compareFromRevisionId && $compareToRevisionId)
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm">
                                        <thead><tr><th>Field</th><th>From</th><th>To</th></tr></thead>
                                        <tbody>
                                        @forelse ($revisionComparison as $change)
                                            <tr>
                                                <td><code>{{ $change['key'] }}</code></td>
                                                <td>{{ is_bool($change['before']) ? ($change['before'] ? 'Yes' : 'No') : ($change['before'] ?? 'Not managed') }}</td>
                                                <td>{{ is_bool($change['after']) ? ($change['after'] ? 'Yes' : 'No') : ($change['after'] ?? 'Not managed') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="text-muted">These revisions are identical.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            <div class="list-group">
                                @foreach ($revisionHistory as $revision)
                                    <div class="list-group-item d-flex flex-column flex-md-row justify-content-between gap-2" wire:key="revision-{{ $revision->id }}">
                                        <div>
                                            <strong>Revision {{ $revision->revision }}</strong>
                                            @if ($historyStrategy->active_revision_id === $revision->id)
                                                <span class="badge bg-success-subtle text-success ms-1">Active</span>
                                            @endif
                                            <div class="text-muted fs-13">
                                                {{ $revision->created_by_name ?? $revision->creator?->username ?? 'System' }} ·
                                                {{ $revision->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }} ·
                                                {{ $revision->affected_devices }} affected device(s)
                                            </div>
                                            @if ($revision->change_note)<div class="mt-1">{{ $revision->change_note }}</div>@endif
                                            @foreach ($revision->rollouts as $revisionRollout)
                                                <div class="mt-1 fs-13">
                                                    <span class="badge bg-primary-subtle text-primary text-capitalize">{{ $revisionRollout->status }} rollout</span>
                                                    <span class="text-muted">{{ $revisionRollout->batch_size }}/batch · {{ $revisionRollout->interval_minutes }} min</span>
                                                </div>
                                            @endforeach
                                        </div>
                                        @if ($canWriteStrategies && ! $openRollouts->has($historyStrategy->id) && $historyStrategy->active_revision_id !== $revision->id)
                                            <button type="button" class="btn btn-sm btn-outline-warning align-self-md-center"
                                                    wire:click="restoreRevision({{ $revision->id }})"
                                                    wire:confirm="Restore revision {{ $revision->revision }} as a new revision? This applies it to all affected devices immediately.">Restore as new revision</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-muted">No revisions have been captured yet.</div>
                        @endif
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeHistory">Close</button></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif

    {{-- Confirmation/compliance drill-down --}}
    @if ($complianceStrategyId && $complianceSummary)
        @php($complianceStrategy = $strategies->firstWhere('id', $complianceStrategyId))
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="strategy-compliance-title" wire:keydown.escape.window="closeCompliance"
             style="background: rgba(0,0,0,.65);" wire:key="strategy-compliance">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="strategy-compliance-title">{{ $complianceStrategy?->name }} confirmation</h5>
                            <small class="text-muted">Live acknowledgement state for the currently desired policy.</small>
                        </div>
                        <button type="button" class="btn-close" wire:click="closeCompliance" aria-label="Close confirmation details"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filter confirmation state">
                            @foreach (['all' => 'All', 'confirmed' => 'Confirmed', 'pending' => 'Pending', 'stale' => 'Stale', 'offline' => 'Offline', 'overridden' => 'Overridden'] as $state => $label)
                                <button type="button" class="btn btn-sm {{ $complianceState === $state ? 'btn-primary' : 'btn-outline-secondary' }}"
                                        wire:click="setComplianceState('{{ $state }}')">
                                    {{ $label }}@if ($state !== 'all') ({{ $complianceSummary['counts'][$state] }})@endif
                                </button>
                            @endforeach
                        </div>
                        @php($complianceTotal = $complianceState === 'all' ? array_sum($complianceSummary['counts']) : ($complianceSummary['counts'][$complianceState] ?? 0))
                        @if ($complianceTotal > count($complianceDevices))
                            <div class="alert alert-info py-2" role="status">Showing the first {{ count($complianceDevices) }} of {{ $complianceTotal }} matching devices. Use the Devices page for full-fleet operations.</div>
                        @endif
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead><tr><th>Device</th><th>State</th><th>Last online</th><th>Sent</th><th>Confirmed</th></tr></thead>
                                <tbody>
                                @forelse ($complianceDevices as $device)
                                    <tr wire:key="compliance-device-{{ $device['id'] }}-{{ $device['state'] }}">
                                        <td><strong>{{ $device['rustdesk_id'] }}</strong><small class="d-block text-muted">{{ $device['label'] }}</small></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary text-capitalize">{{ $device['state'] }}</span></td>
                                        <td>{{ $device['last_online_at'] ? \Illuminate\Support\Carbon::parse($device['last_online_at'])->timezone(config('app.timezone'))->format('M j, g:i A') : 'Never' }}</td>
                                        <td>{{ $device['sent_at'] ? \Illuminate\Support\Carbon::parse($device['sent_at'])->timezone(config('app.timezone'))->format('M j, g:i A') : '—' }}</td>
                                        <td>{{ $device['acked_at'] ? \Illuminate\Support\Carbon::parse($device['acked_at'])->timezone(config('app.timezone'))->format('M j, g:i A') : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">No devices in this state.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" wire:click="closeCompliance">Close</button></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
