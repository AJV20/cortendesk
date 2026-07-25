<?php

namespace App\Http\Controllers\AdminApi;

use App\Models\AddressBook;
use App\Models\AddressBookEntry;
use App\Models\AddressBookRule;
use App\Models\ConsoleAudit;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AddressBooksController extends AdminApiController
{
    // ---- Address books -----------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $books = AddressBook::query()
            ->with('owner')
            ->withCount(['entries', 'tags', 'rules'])
            ->when($request->filled('name'), fn ($q) => $q
                ->where('name', 'like', '%'.$request->query('name').'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($books, fn (AddressBook $b) => $this->serializeBook($b));
    }

    public function show(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($this->serializeBook(
            $addressBook->load('owner')->loadCount(['entries', 'tags', 'rules'])
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_personal' => ['boolean'],
            'note' => ['nullable', 'string'],
        ]);

        // Books need an owner; default to the token's creator (a shared book the
        // operator owns) when the caller does not name one.
        $data['owner_user_id'] ??= $request->user()->id;

        $book = AddressBook::create($data);

        ConsoleAudit::record('address-book.create', 'Created address book '.$book->name.' (API)', 'address_book', $book->guid);

        return $this->created($this->serializeBook($book), 'Address book created.');
    }

    public function update(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'note' => ['nullable', 'string'],
        ]);

        $addressBook->update($data);

        ConsoleAudit::record('address-book.update', 'Updated address book '.$addressBook->name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializeBook($addressBook), 'Address book updated.');
    }

    public function destroy(AddressBook $addressBook): JsonResponse
    {
        $guid = $addressBook->guid;
        $addressBook->entries()->delete();
        $addressBook->tags()->delete();
        $addressBook->rules()->delete();
        $addressBook->delete();

        ConsoleAudit::record('address-book.delete', 'Deleted address book '.$addressBook->name.' (API)', 'address_book', $guid);

        return $this->ok(null, 'Address book deleted.');
    }

    // ---- Peers (entries) ---------------------------------------------------

    public function peers(AddressBook $addressBook): JsonResponse
    {
        $peers = $addressBook->entries()->orderBy('rustdesk_id')->get();

        return $this->ok($peers->map(fn (AddressBookEntry $e) => $this->serializePeer($e))->all());
    }

    public function storePeer(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $this->validatePeer($request);
        $data['address_book_id'] = $addressBook->id;

        $peer = AddressBookEntry::create($data);

        ConsoleAudit::record('address-book.peer-add', 'Added peer '.$peer->rustdesk_id.' to '.$addressBook->name.' (API)', 'address_book', $addressBook->guid);

        return $this->created($this->serializePeer($peer), 'Peer added.');
    }

    public function updatePeer(Request $request, AddressBook $addressBook, AddressBookEntry $peer): JsonResponse
    {
        abort_unless($peer->address_book_id === $addressBook->id, 404);

        $peer->update($this->validatePeer($request, true));

        ConsoleAudit::record('address-book.peer-update', 'Updated peer '.$peer->rustdesk_id.' (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializePeer($peer), 'Peer updated.');
    }

    public function destroyPeer(AddressBook $addressBook, AddressBookEntry $peer): JsonResponse
    {
        abort_unless($peer->address_book_id === $addressBook->id, 404);
        $rid = $peer->rustdesk_id;
        $peer->delete();

        ConsoleAudit::record('address-book.peer-delete', 'Deleted peer '.$rid.' (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Peer deleted.');
    }

    // ---- Tags --------------------------------------------------------------

    public function tags(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($addressBook->tags()->orderBy('name')->get()
            ->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all());
    }

    public function storeTag(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);
        $data['address_book_id'] = $addressBook->id;

        $tag = Tag::create($data);

        ConsoleAudit::record('address-book.tag-add', 'Added tag '.$tag->name.' (API)', 'address_book', $addressBook->guid);

        return $this->created(['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color], 'Tag added.');
    }

    public function updateTag(Request $request, AddressBook $addressBook, Tag $tag): JsonResponse
    {
        abort_unless($tag->address_book_id === $addressBook->id, 404);

        $tag->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]));

        ConsoleAudit::record('address-book.tag-update', 'Updated tag '.$tag->name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok(['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color], 'Tag updated.');
    }

    public function destroyTag(AddressBook $addressBook, Tag $tag): JsonResponse
    {
        abort_unless($tag->address_book_id === $addressBook->id, 404);
        $name = $tag->name;
        $tag->delete();

        ConsoleAudit::record('address-book.tag-delete', 'Deleted tag '.$name.' (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Tag deleted.');
    }

    // ---- Rules -------------------------------------------------------------

    public function rules(AddressBook $addressBook): JsonResponse
    {
        return $this->ok($addressBook->rules()->get()
            ->map(fn (AddressBookRule $r) => $this->serializeRule($r))->all());
    }

    public function storeRule(Request $request, AddressBook $addressBook): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['everyone', 'user', 'group'])],
            'subject_id' => ['nullable', 'integer'],
            'permission' => ['required', 'integer', Rule::in([
                AddressBookRule::PERM_READ,
                AddressBookRule::PERM_READ_WRITE,
                AddressBookRule::PERM_FULL,
            ])],
        ]);
        $data['address_book_id'] = $addressBook->id;
        if ($data['subject_type'] === 'everyone') {
            $data['subject_id'] = null;
        }

        $rule = AddressBookRule::create($data);

        ConsoleAudit::record('address-book.rule-add', 'Added AB rule (API)', 'address_book', $addressBook->guid);

        return $this->created($this->serializeRule($rule), 'Rule added.');
    }

    public function updateRule(Request $request, AddressBook $addressBook, AddressBookRule $rule): JsonResponse
    {
        abort_unless($rule->address_book_id === $addressBook->id, 404);

        $rule->update($request->validate([
            'subject_type' => ['sometimes', 'required', Rule::in(['everyone', 'user', 'group'])],
            'subject_id' => ['nullable', 'integer'],
            'permission' => ['sometimes', 'required', 'integer', Rule::in([
                AddressBookRule::PERM_READ,
                AddressBookRule::PERM_READ_WRITE,
                AddressBookRule::PERM_FULL,
            ])],
        ]));

        ConsoleAudit::record('address-book.rule-update', 'Updated AB rule (API)', 'address_book', $addressBook->guid);

        return $this->ok($this->serializeRule($rule), 'Rule updated.');
    }

    public function destroyRule(AddressBook $addressBook, AddressBookRule $rule): JsonResponse
    {
        abort_unless($rule->address_book_id === $addressBook->id, 404);
        $rule->delete();

        ConsoleAudit::record('address-book.rule-delete', 'Deleted AB rule (API)', 'address_book', $addressBook->guid);

        return $this->ok(null, 'Rule deleted.');
    }

    // ---- helpers -----------------------------------------------------------

    private function validatePeer(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'rustdesk_id' => [$req, 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:64'],
            'username' => ['nullable', 'string', 'max:255'],
            'login_name' => ['nullable', 'string', 'max:255'],
            'force_always_relay' => ['boolean'],
            'rdp_port' => ['nullable', 'integer'],
            'rdp_username' => ['nullable', 'string', 'max:255'],
            'tag_ids' => ['nullable', 'array'],
        ]);
    }

    private function serializeBook(AddressBook $book): array
    {
        return [
            'id' => $book->id,
            'guid' => $book->guid,
            'name' => $book->name,
            'is_personal' => (bool) $book->is_personal,
            'owner' => $book->owner ? ['id' => $book->owner->id, 'username' => $book->owner->username] : null,
            'note' => $book->note,
            'peers_count' => $book->entries_count ?? $book->entries()->count(),
            'tags_count' => $book->tags_count ?? $book->tags()->count(),
            'rules_count' => $book->rules_count ?? $book->rules()->count(),
        ];
    }

    private function serializePeer(AddressBookEntry $e): array
    {
        return [
            'id' => $e->id,
            'rustdesk_id' => $e->rustdesk_id,
            'alias' => $e->alias,
            'hostname' => $e->hostname,
            'platform' => $e->platform,
            'username' => $e->username,
            'login_name' => $e->login_name,
            'force_always_relay' => (bool) $e->force_always_relay,
            'rdp_port' => $e->rdp_port,
            'rdp_username' => $e->rdp_username,
            'tags' => $e->tag_ids ?? [],
        ];
    }

    private function serializeRule(AddressBookRule $r): array
    {
        return [
            'id' => $r->id,
            'subject_type' => $r->subject_type,
            'subject_id' => $r->subject_id,
            'permission' => (int) $r->permission,
        ];
    }
}
