@php
    use App\Livewire\AddressBookManager as ABM;
    // Every personal book is created with this default name — hide it and show the owner instead.
    $isDefaultBookName = fn ($b) => strcasecmp(trim($b->name), 'My address book') === 0;
@endphp
<div>
    <div class="row g-3">

        {{-- ============ LEFT: master list ============ --}}
        <div class="col-12 col-lg-4">

            {{-- Tabs: personal vs shared books --}}
            <ul class="nav nav-tabs nav-bordered mb-2">
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'personal' ? 'active' : '' }}"
                       wire:click="setTab('personal')">
                        <i class="ri-user-line me-1"></i>Personal
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $personalCount }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="javascript:void(0);" class="nav-link {{ $tab === 'shared' ? 'active' : '' }}"
                       wire:click="setTab('shared')">
                        <i class="ri-share-line me-1"></i>Shared
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $sharedCount }}</span>
                    </a>
                </li>
            </ul>

            {{-- Mobile: collapse master list to a select --}}
            <div class="d-lg-none mb-2">
                <div class="d-flex gap-2">
                    <select class="form-select" wire:model.live="selectedBookId" aria-label="Select address book">
                        @foreach ($books as $b)
                            <option value="{{ $b->id }}">
                                @if ($tab === 'personal')
                                    {{ $b->owner?->username ?? 'unknown' }}{{ $isDefaultBookName($b) ? '' : ' — '.$b->name }}
                                @else
                                    {{ $b->name }} ({{ $b->owner?->username ?? '?' }})
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <button type="button" class="btn btn-primary flex-shrink-0" wire:click="openNewBook" title="New shared address book">
                            <i class="ri-add-line"></i>
                        </button>
                    @endif
                </div>
            </div>

            {{-- Desktop: list group --}}
            <div class="card d-none d-lg-block">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Address Books</h5>
                    @if (auth()->user()?->consoleAllows('address_book', 'rw'))
                        <button type="button" class="btn btn-sm btn-primary" wire:click="openNewBook">
                            <i class="ri-add-line me-1"></i>New shared
                        </button>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($books as $b)
                        <a href="javascript:void(0);" wire:key="book{{ $b->id }}" wire:click="selectBook({{ $b->id }})"
                           class="list-group-item list-group-item-action {{ $b->id === $selectedBookId ? 'active' : '' }}">
                            <span class="fw-semibold text-truncate d-block">
                                @if ($tab === 'personal')
                                    <i class="ri-user-line me-1"></i>{{ $b->owner?->username ?? 'unknown' }}
                                @else
                                    <i class="ri-contacts-book-2-line me-1"></i>{{ $b->name }}
                                @endif
                            </span>
                            <small class="{{ $b->id === $selectedBookId ? 'text-white-50' : 'text-muted' }}">
                                @if ($tab === 'personal')
                                    @unless ($isDefaultBookName($b)) {{ $b->name }} · @endunless
                                @else
                                    {{ $b->owner?->username ?? 'unknown' }} ·
                                @endif
                                {{ $b->entries_count }} {{ Str::plural('entry', $b->entries_count) }} ·
                                {{ $b->tags_count }} {{ Str::plural('tag', $b->tags_count) }}
                            </small>
                        </a>
                    @empty
                        <div class="list-group-item text-muted text-center py-4">
                            No {{ $tab }} address books yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ============ RIGHT: detail ============ --}}
        <div class="col-12 col-lg-8">
            @if ($book)
                <div class="card">
                    <div class="card-body">

                        {{-- Header --}}
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                            <div>
                                <h4 class="mb-1 d-flex align-items-center gap-2 flex-wrap">
                                    {{ $book->name }}
                                    @if ($book->is_personal)
                                        <span class="badge bg-info-subtle text-info">Personal</span>
                                    @else
                                        <span class="badge bg-primary-subtle text-primary">Shared</span>
                                    @endif
                                </h4>
                                <p class="text-muted mb-0">
                                    <i class="ri-user-line me-1"></i>{{ $book->owner?->username ?? 'unknown' }}
                                    @if ($book->note)
                                        <span class="ms-2">{{ $book->note }}</span>
                                    @endif
                                </p>
                            </div>
                            @if (! $book->is_personal && $canManage)
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-light" wire:click="openRenameBook">
                                        <i class="ri-pencil-line me-1"></i>Rename
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="deleteBook"
                                            wire:confirm="Delete address book “{{ $book->name }}”? This also permanently deletes all of its entries, tags, and sharing rules.">
                                        <i class="ri-delete-bin-line me-1"></i>Delete
                                    </button>
                                </div>
                            @elseif (! $book->is_personal)
                                <span class="badge bg-secondary-subtle text-secondary align-self-start">
                                    {{ $permission >= 2 ? 'Read/Write' : 'Read only' }}
                                </span>
                            @endif
                        </div>

                        <hr class="my-3">

                        {{-- Tags row --}}
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                            <span class="text-muted fw-semibold me-1"><i class="ri-price-tag-3-line me-1"></i>Tags:</span>
                            @forelse ($tags as $tag)
                                @php $hex = ABM::colorToHex($tag->color); @endphp
                                <span class="badge d-inline-flex align-items-center gap-1 {{ ABM::chipTextClass($hex) }}"
                                      style="background-color: {{ $hex }};" wire:key="tag{{ $tag->id }}">
                                    {{ $tag->name }}
                                    @if ($canManage)
                                        <a href="javascript:void(0);" class="{{ ABM::chipTextClass($hex) }} text-decoration-none lh-1"
                                           wire:click="deleteTag({{ $tag->id }})"
                                           wire:confirm="Delete tag “{{ $tag->name }}”? It will be removed from all entries."
                                           title="Delete tag"><i class="ri-close-line align-middle"></i></a>
                                    @endif
                                </span>
                            @empty
                                <span class="text-muted fst-italic">none</span>
                            @endforelse
                            @if ($canManage)
                                <button type="button" class="btn btn-sm btn-light" wire:click="openAddTag">
                                    <i class="ri-add-line"></i> Add tag
                                </button>
                            @endif
                        </div>

                        {{-- Entries toolbar --}}
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="mb-0">Entries</h5>
                            @if ($canWriteEntries)
                                <button type="button" class="btn btn-sm btn-primary" wire:click="openAddEntry">
                                    <i class="ri-add-line me-1"></i>Add entry
                                </button>
                            @endif
                        </div>

                        {{-- Desktop entries table (md and up) --}}
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover table-centered mb-0">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Alias</th>
                                    <th>User</th>
                                    <th>Tags</th>
                                    <th>Created</th>
                                    <th class="text-end">Action</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($entries as $entry)
                                    <tr wire:key="e{{ $entry->id }}">
                                        <td>
                                            <x-platform-icon :platform="$entry->platform ?: 'unknown'" class="me-1"/>
                                            <a href="rustdesk://{{ $entry->rustdesk_id }}" class="fw-semibold"
                                               title="Connect with RustDesk">{{ $entry->rustdesk_id }}</a>
                                        </td>
                                        <td>{{ $entry->alias ?: '—' }}</td>
                                        <td>{{ $entry->username ?: '—' }}</td>
                                        <td>
                                            @forelse (collect($entry->tag_ids ?? [])->map(fn ($id) => $tagMap->get((int) $id))->filter() as $t)
                                                @php $hex = ABM::colorToHex($t->color); @endphp
                                                <span class="badge {{ ABM::chipTextClass($hex) }}" style="background-color: {{ $hex }};">{{ $t->name }}</span>
                                            @empty
                                                <span class="text-muted">—</span>
                                            @endforelse
                                        </td>
                                        <td><span title="{{ $entry->created_at }}">{{ $entry->created_at?->diffForHumans() ?? '—' }}</span></td>
                                        <td class="text-end">
                                            @if ($canWriteEntries)
                                                <a href="javascript:void(0);" class="text-primary me-2" wire:click="openEditEntry({{ $entry->id }})">Edit</a>
                                                <a href="javascript:void(0);" class="text-danger"
                                                   wire:click="deleteEntry({{ $entry->id }})"
                                                   wire:confirm="Remove {{ $entry->rustdesk_id }} from this address book?">Remove</a>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No entries in this address book.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile entries card list (below md) --}}
                        <div class="d-md-none">
                            @forelse ($entries as $entry)
                                <div class="card border mb-2" wire:key="me{{ $entry->id }}">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="d-flex align-items-center gap-2">
                                                <x-platform-icon :platform="$entry->platform ?: 'unknown'" size="fs-22"/>
                                                <div>
                                                    <a href="rustdesk://{{ $entry->rustdesk_id }}" class="fw-semibold d-block"
                                                       title="Connect with RustDesk">{{ $entry->rustdesk_id }}</a>
                                                    <small class="text-muted">{{ $entry->alias ?: ($entry->username ?: '—') }}</small>
                                                </div>
                                            </div>
                                            @if ($canWriteEntries)
                                                <div>
                                                    <a href="javascript:void(0);" class="btn btn-sm btn-light me-1" wire:click="openEditEntry({{ $entry->id }})"><i class="ri-pencil-line"></i></a>
                                                    <a href="javascript:void(0);" class="btn btn-sm btn-light text-danger"
                                                       wire:click="deleteEntry({{ $entry->id }})"
                                                       wire:confirm="Remove {{ $entry->rustdesk_id }} from this address book?"><i class="ri-delete-bin-line"></i></a>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="mt-2 d-flex flex-wrap gap-1">
                                            @foreach (collect($entry->tag_ids ?? [])->map(fn ($id) => $tagMap->get((int) $id))->filter() as $t)
                                                @php $hex = ABM::colorToHex($t->color); @endphp
                                                <span class="badge {{ ABM::chipTextClass($hex) }}" style="background-color: {{ $hex }};">{{ $t->name }}</span>
                                            @endforeach
                                            <small class="text-muted ms-auto">{{ $entry->created_at?->diffForHumans(short: true) ?? '' }}</small>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-center text-muted py-4 mb-0">No entries in this address book.</p>
                            @endforelse
                        </div>

                        @if ($entries && $entries->hasPages())
                            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                                <small class="text-muted">
                                    Showing {{ $entries->firstItem() ?? 0 }}–{{ $entries->lastItem() ?? 0 }} of {{ $entries->total() }}
                                </small>
                                {{ $entries->links() }}
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Sharing rules (shared books, FULL control only) --}}
                @if (! $book->is_personal && $canManage)
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="mb-0"><i class="ri-share-line me-1"></i>Sharing rules</h5>
                                <button type="button" class="btn btn-sm btn-primary" wire:click="openAddRule">
                                    <i class="ri-add-line me-1"></i>Add rule
                                </button>
                            </div>
                            @forelse ($bookRules as $rule)
                                <div class="d-flex align-items-center gap-2 border rounded p-2 mb-2 flex-wrap" wire:key="rule{{ $rule->id }}">
                                    <span class="flex-grow-1 text-truncate">
                                        @if ($rule->subject_type === 'everyone')
                                            <i class="ri-global-line me-1 text-muted"></i>Everyone
                                        @elseif ($rule->subject_type === 'user')
                                            <i class="ri-user-line me-1 text-muted"></i>{{ $users->firstWhere('id', $rule->subject_id)?->username ?? 'user #'.$rule->subject_id }}
                                        @else
                                            <i class="ri-team-line me-1 text-muted"></i>{{ $userGroups->firstWhere('id', $rule->subject_id)?->name ?? 'group #'.$rule->subject_id }}
                                        @endif
                                    </span>
                                    <select class="form-select form-select-sm w-auto"
                                            wire:change="updateRulePermission({{ $rule->id }}, parseInt($event.target.value))">
                                        @foreach ($permissionLabels as $value => $label)
                                            <option value="{{ $value }}" @selected($rule->permission === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-sm btn-light text-danger"
                                            wire:click="deleteRule({{ $rule->id }})"
                                            wire:confirm="Delete this sharing rule?">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            @empty
                                <p class="text-muted mb-0">Not shared with anyone yet. Add a rule to share this address book.</p>
                            @endforelse
                        </div>
                    </div>
                @endif
            @else
                <div class="card">
                    <div class="card-body text-center text-muted py-5">
                        <i class="ri-contacts-book-2-line fs-36 d-block mb-2"></i>
                        Select an address book to view its entries.
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ============ Modals (Livewire-controlled) ============ --}}

    @if ($modal === 'newBook' || $modal === 'renameBook')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="{{ $modal === 'newBook' ? 'createBook' : 'renameBook' }}">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $modal === 'newBook' ? 'New shared address book' : 'Rename address book' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-book-name">Name</label>
                                <input type="text" id="ab-book-name" class="form-control @error('bookName') is-invalid @enderror"
                                       wire:model="bookName" autofocus>
                                @error('bookName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="ab-book-note">Note <span class="text-muted">(optional)</span></label>
                                <textarea id="ab-book-note" class="form-control @error('bookNote') is-invalid @enderror" rows="2"
                                          wire:model="bookNote"></textarea>
                                @error('bookNote') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">{{ $modal === 'newBook' ? 'Create' : 'Save' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'addTag')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="addTag">
                        <div class="modal-header">
                            <h5 class="modal-title">Add tag</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-8">
                                    <label class="form-label" for="ab-tag-name">Name</label>
                                    <input type="text" id="ab-tag-name" class="form-control @error('tagName') is-invalid @enderror"
                                           wire:model="tagName" autofocus>
                                    @error('tagName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="ab-tag-color">Color</label>
                                    <input type="color" id="ab-tag-color" class="form-control form-control-color w-100 @error('tagColor') is-invalid @enderror"
                                           wire:model="tagColor">
                                    @error('tagColor') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'entry')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="saveEntry">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $entryId ? 'Edit entry' : 'Add entry' }}</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-id">RustDesk ID</label>
                                <input type="text" id="ab-entry-id" class="form-control @error('entryRustdeskId') is-invalid @enderror"
                                       wire:model="entryRustdeskId" @disabled($entryId !== null)>
                                @error('entryRustdeskId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ab-entry-alias">Alias <span class="text-muted">(optional)</span></label>
                                <input type="text" id="ab-entry-alias" class="form-control @error('entryAlias') is-invalid @enderror"
                                       wire:model="entryAlias">
                                @error('entryAlias') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-0">
                                <label class="form-label d-block">Tags</label>
                                @forelse ($tags as $tag)
                                    <div class="form-check form-check-inline" wire:key="etag{{ $tag->id }}">
                                        <input class="form-check-input" type="checkbox" id="ab-etag-{{ $tag->id }}"
                                               value="{{ $tag->id }}" wire:model="entryTagIds">
                                        <label class="form-check-label" for="ab-etag-{{ $tag->id }}">
                                            <span class="badge {{ ABM::chipTextClass(ABM::colorToHex($tag->color)) }}"
                                                  style="background-color: {{ ABM::colorToHex($tag->color) }};">{{ $tag->name }}</span>
                                        </label>
                                    </div>
                                @empty
                                    <span class="text-muted fst-italic">No tags in this address book yet.</span>
                                @endforelse
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">{{ $entryId ? 'Save' : 'Add' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal === 'addRule')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form wire:submit="addRule">
                        <div class="modal-header">
                            <h5 class="modal-title">Add sharing rule</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ab-rule-type">Share with</label>
                                <select id="ab-rule-type" class="form-select @error('ruleSubjectType') is-invalid @enderror"
                                        wire:model.live="ruleSubjectType">
                                    <option value="everyone">Everyone</option>
                                    <option value="user">A specific user</option>
                                    <option value="group">A user group</option>
                                </select>
                                @error('ruleSubjectType') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @if ($ruleSubjectType === 'user')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-user">User</label>
                                    <select id="ab-rule-user" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">Choose a user…</option>
                                        @foreach ($users as $u)
                                            <option value="{{ $u->id }}">{{ $u->username }}{{ $u->name ? ' — '.$u->name : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @elseif ($ruleSubjectType === 'group')
                                <div class="mb-3">
                                    <label class="form-label" for="ab-rule-group">Group</label>
                                    <select id="ab-rule-group" class="form-select @error('ruleSubjectId') is-invalid @enderror"
                                            wire:model="ruleSubjectId">
                                        <option value="">Choose a group…</option>
                                        @foreach ($userGroups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('ruleSubjectId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            @endif
                            <div class="mb-0">
                                <label class="form-label" for="ab-rule-perm">Permission</label>
                                <select id="ab-rule-perm" class="form-select @error('rulePermission') is-invalid @enderror"
                                        wire:model="rulePermission">
                                    @foreach ($permissionLabels as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('rulePermission') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add rule</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if ($modal)
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
