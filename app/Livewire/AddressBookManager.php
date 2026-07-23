<?php

namespace App\Livewire;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AddressBookManager extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Url(except: 0)]
    public int $selectedBookId = 0;

    /** Which books-list tab is active: personal | shared */
    public string $tab = 'shared';

    /** Which modal is open: newBook | renameBook | addTag | entry | addRule */
    public ?string $modal = null;

    // Book form (create / rename)
    public string $bookName = '';

    public string $bookNote = '';

    // Tag form
    public string $tagName = '';

    public string $tagColor = '#2563EB';

    // Entry form (create when $entryId is null, edit otherwise)
    public ?int $entryId = null;

    public string $entryRustdeskId = '';

    public string $entryAlias = '';

    /** @var array<int, int|string> tag ids checked in the entry modal */
    public array $entryTagIds = [];

    // Sharing rule form
    public string $ruleSubjectType = 'everyone';

    public ?int $ruleSubjectId = null;

    public int $rulePermission = AddressBookRule::PERM_READ;

    public function mount(): void
    {
        if ($user = auth()->user()) {
            AddressBook::personalFor($user);
            // Admins live in the shared list; regular users mostly care about their own book.
            $this->tab = $user->is_admin ? 'shared' : 'personal';
        }

        // A deep-linked ?selectedBookId wins and pulls the tab along with it.
        if ($this->selectedBookId !== 0) {
            if ($book = $this->book()) {
                $this->tab = $book->is_personal ? 'personal' : 'shared';

                return;
            }
            $this->selectedBookId = 0;
        }

        $this->selectedBookId = $this->defaultBookId();
        if ($this->selectedBookId === 0) {
            // Active tab is empty (e.g. no shared books yet) — fall back to the other one.
            $this->tab = $this->tab === 'shared' ? 'personal' : 'shared';
            $this->selectedBookId = $this->defaultBookId();
        }
    }

    /** First visible book on the active tab, or 0 when the tab is empty. */
    protected function defaultBookId(): int
    {
        return (int) (self::orderedBooks()
            ->where('is_personal', $this->tab === 'personal')
            ->value('id') ?? 0);
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['personal', 'shared'], true) || $tab === $this->tab) {
            return;
        }

        $this->tab = $tab;
        $this->selectedBookId = $this->defaultBookId();
        $this->resetPage();
    }

    /* ---------------------------------------------------------------------
     | Color helpers — RustDesk stores tag colors as u32 ARGB (0xAARRGGBB).
     * ------------------------------------------------------------------- */

    public static function colorToHex(int $color): string
    {
        if ($color === 0) {
            return '#6C757D'; // default gray when the client never set a color
        }

        return sprintf('#%06X', $color & 0xFFFFFF);
    }

    public static function hexToColor(string $hex): int
    {
        $rgb = hexdec(ltrim($hex, '#')) & 0xFFFFFF;

        return 0xFF000000 | $rgb; // opaque alpha, as the RustDesk client does
    }

    /** Pick a readable text color class for a chip with the given background. */
    public static function chipTextClass(string $hex): string
    {
        $rgb = hexdec(ltrim($hex, '#'));
        $luma = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);

        return $luma > 160 ? 'text-dark' : 'text-white';
    }

    /* ---------------------------------------------------------------------
     | Selection & modals
     * ------------------------------------------------------------------- */

    public function selectBook(int $id): void
    {
        $this->selectedBookId = $id;
        if ($book = $this->book()) {
            $this->tab = $book->is_personal ? 'personal' : 'shared';
        }
        $this->resetPage();
    }

    public function updatedSelectedBookId(): void
    {
        $this->resetPage();
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->resetValidation();
    }

    public function openNewBook(): void
    {
        $this->reset('bookName', 'bookNote');
        $this->resetValidation();
        $this->modal = 'newBook';
    }

    public function openRenameBook(): void
    {
        $book = $this->book();
        if (! $book || $book->is_personal) {
            return;
        }
        $this->bookName = $book->name;
        $this->bookNote = (string) $book->note;
        $this->resetValidation();
        $this->modal = 'renameBook';
    }

    public function openAddTag(): void
    {
        $this->reset('tagName');
        $this->tagColor = '#2563EB';
        $this->resetValidation();
        $this->modal = 'addTag';
    }

    public function openAddEntry(): void
    {
        $this->reset('entryId', 'entryRustdeskId', 'entryAlias', 'entryTagIds');
        $this->resetValidation();
        $this->modal = 'entry';
    }

    public function openEditEntry(int $id): void
    {
        $entry = AddressBookEntry::where('address_book_id', $this->selectedBookId)->findOrFail($id);
        $this->entryId = $entry->id;
        $this->entryRustdeskId = $entry->rustdesk_id;
        $this->entryAlias = (string) $entry->alias;
        $this->entryTagIds = array_map('strval', $entry->tag_ids ?? []);
        $this->resetValidation();
        $this->modal = 'entry';
    }

    public function openAddRule(): void
    {
        $this->ruleSubjectType = 'everyone';
        $this->ruleSubjectId = null;
        $this->rulePermission = AddressBookRule::PERM_READ;
        $this->resetValidation();
        $this->modal = 'addRule';
    }

    public function updatedRuleSubjectType(): void
    {
        $this->ruleSubjectId = null;
    }

    /* ---------------------------------------------------------------------
     | Address books
     * ------------------------------------------------------------------- */

    public function createBook(): void
    {
        $this->validate([
            'bookName' => 'required|string|max:255',
            'bookNote' => 'nullable|string|max:500',
        ], [], ['bookName' => 'name', 'bookNote' => 'note']);

        $book = AddressBook::create([
            'name' => trim($this->bookName),
            'note' => $this->bookNote !== '' ? $this->bookNote : null,
            'owner_user_id' => auth()->id() ?? 0,
            'is_personal' => false,
        ]);

        $this->closeModal();
        $this->selectBook($book->id);
    }

    public function renameBook(): void
    {
        $book = $this->book();
        if (! $book || $book->is_personal) {
            return;
        }

        $this->validate([
            'bookName' => 'required|string|max:255',
            'bookNote' => 'nullable|string|max:500',
        ], [], ['bookName' => 'name', 'bookNote' => 'note']);

        $book->update([
            'name' => trim($this->bookName),
            'note' => $this->bookNote !== '' ? $this->bookNote : null,
        ]);

        $this->closeModal();
    }

    public function deleteBook(): void
    {
        $book = $this->book();
        if (! $book || $book->is_personal) {
            return; // personal address books can never be deleted
        }

        $book->entries()->delete();
        $book->tags()->delete();
        $book->rules()->delete();
        $book->delete();

        $this->selectedBookId = $this->defaultBookId();
        $this->resetPage();
    }

    /* ---------------------------------------------------------------------
     | Tags
     * ------------------------------------------------------------------- */

    public function addTag(): void
    {
        $book = $this->book();
        if (! $book) {
            return;
        }

        $this->validate([
            'tagName' => [
                'required', 'string', 'max:64',
                Rule::unique('tags', 'name')->where('address_book_id', $book->id),
            ],
            'tagColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [], ['tagName' => 'tag name', 'tagColor' => 'color']);

        Tag::create([
            'address_book_id' => $book->id,
            'name' => trim($this->tagName),
            'color' => self::hexToColor($this->tagColor),
        ]);

        $this->closeModal();
    }

    public function deleteTag(int $id): void
    {
        $tag = Tag::where('address_book_id', $this->selectedBookId)->findOrFail($id);

        // Strip the tag id from every entry that references it.
        $tag->addressBook->entries()->get()->each(function (AddressBookEntry $entry) use ($tag) {
            $ids = array_map('intval', $entry->tag_ids ?? []);
            if (in_array($tag->id, $ids, true)) {
                $entry->update(['tag_ids' => array_values(array_diff($ids, [$tag->id]))]);
            }
        });

        $tag->delete();
    }

    /* ---------------------------------------------------------------------
     | Entries
     * ------------------------------------------------------------------- */

    public function saveEntry(): void
    {
        $book = $this->book();
        if (! $book) {
            return;
        }

        $validTagIds = $book->tags()->pluck('id')->all();
        $tagIds = array_values(array_intersect(array_map('intval', $this->entryTagIds), $validTagIds));

        if ($this->entryId !== null) {
            $entry = $book->entries()->findOrFail($this->entryId);
            $this->validate(['entryAlias' => 'nullable|string|max:255'], [], ['entryAlias' => 'alias']);
            $entry->update([
                'alias' => $this->entryAlias !== '' ? trim($this->entryAlias) : null,
                'tag_ids' => $tagIds,
            ]);
        } else {
            $this->validate([
                'entryRustdeskId' => [
                    'required', 'string', 'max:255',
                    Rule::unique('address_book_entries', 'rustdesk_id')->where('address_book_id', $book->id),
                ],
                'entryAlias' => 'nullable|string|max:255',
            ], [], ['entryRustdeskId' => 'RustDesk ID', 'entryAlias' => 'alias']);

            $book->entries()->create([
                'rustdesk_id' => trim($this->entryRustdeskId),
                'alias' => $this->entryAlias !== '' ? trim($this->entryAlias) : null,
                'tag_ids' => $tagIds,
            ]);
        }

        $this->closeModal();
    }

    public function deleteEntry(int $id): void
    {
        AddressBookEntry::where('address_book_id', $this->selectedBookId)->findOrFail($id)->delete();
    }

    /* ---------------------------------------------------------------------
     | Sharing rules
     * ------------------------------------------------------------------- */

    public function addRule(): void
    {
        $book = $this->book();
        if (! $book || $book->is_personal) {
            return;
        }

        $rules = [
            'ruleSubjectType' => 'required|in:everyone,user,group',
            'rulePermission' => 'required|integer|in:1,2,3',
        ];
        if ($this->ruleSubjectType === 'user') {
            $rules['ruleSubjectId'] = 'required|integer|exists:users,id';
        } elseif ($this->ruleSubjectType === 'group') {
            $rules['ruleSubjectId'] = 'required|integer|exists:user_groups,id';
        }

        $this->validate($rules, [], [
            'ruleSubjectType' => 'subject',
            'ruleSubjectId' => 'subject',
            'rulePermission' => 'permission',
        ]);

        AddressBookRule::create([
            'address_book_id' => $book->id,
            'subject_type' => $this->ruleSubjectType,
            'subject_id' => $this->ruleSubjectType === 'everyone' ? null : $this->ruleSubjectId,
            'permission' => $this->rulePermission,
        ]);

        $this->closeModal();
    }

    public function updateRulePermission(int $id, int $permission): void
    {
        if (! in_array($permission, [AddressBookRule::PERM_READ, AddressBookRule::PERM_READ_WRITE, AddressBookRule::PERM_FULL], true)) {
            return;
        }

        AddressBookRule::where('address_book_id', $this->selectedBookId)
            ->findOrFail($id)
            ->update(['permission' => $permission]);
    }

    public function deleteRule(int $id): void
    {
        AddressBookRule::where('address_book_id', $this->selectedBookId)->findOrFail($id)->delete();
    }

    /* ---------------------------------------------------------------------
     | Render
     * ------------------------------------------------------------------- */

    protected static function orderedBooks()
    {
        return AddressBook::query()
            ->visibleTo(auth()->user())
            ->orderByDesc('is_personal')
            ->orderBy('name');
    }

    protected function book(): ?AddressBook
    {
        // Only resolve a book the current user is allowed to see.
        return $this->selectedBookId > 0
            ? AddressBook::query()->visibleTo(auth()->user())->find($this->selectedBookId)
            : null;
    }

    public function render()
    {
        $allBooks = self::orderedBooks()
            ->with('owner')
            ->withCount(['entries', 'tags'])
            ->get();

        // Personal books all share the same default name, so sort them by owner instead.
        $personalBooks = $allBooks->filter->is_personal
            ->sortBy(fn (AddressBook $b) => mb_strtolower($b->owner?->username ?? ''), SORT_STRING)
            ->values();
        $sharedBooks = $allBooks->reject->is_personal->values();

        $books = $this->tab === 'personal' ? $personalBooks : $sharedBooks;

        $book = $this->book()?->load('owner');
        $tags = $book ? $book->tags()->orderBy('name')->get() : collect();
        $entries = $book ? $book->entries()->orderBy('rustdesk_id')->paginate(15) : null;
        $rules = ($book && ! $book->is_personal) ? $book->rules()->orderBy('id')->get() : collect();

        return view('livewire.address-book-manager', [
            'books' => $books,
            'personalCount' => $personalBooks->count(),
            'sharedCount' => $sharedBooks->count(),
            'book' => $book,
            'tags' => $tags,
            'tagMap' => $tags->keyBy('id'),
            'entries' => $entries,
            'bookRules' => $rules,
            'users' => User::orderBy('username')->get(['id', 'username', 'name']),
            'userGroups' => UserGroup::orderBy('name')->get(['id', 'name']),
            'permissionLabels' => [
                AddressBookRule::PERM_READ => 'Read',
                AddressBookRule::PERM_READ_WRITE => 'Read/Write',
                AddressBookRule::PERM_FULL => 'Full Control',
            ],
        ]);
    }
}
