<div>
    {{-- ---------------------------------------------------------------- Profile --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="ri-user-line me-1"></i>Profile</h5>
        </div>
        <div class="card-body">
            @if ($profileSaved)
                <div class="alert alert-success py-2" wire:poll.4s="$set('profileSaved', false)">
                    <i class="ri-check-line me-1"></i>Profile saved.
                </div>
            @endif

            <form wire:submit="saveProfile">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="{{ $user->username }}" disabled>
                    <div class="form-text">
                        Your sign-in name. Only an administrator can change it.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="account-name" class="form-label">Display name</label>
                    <input type="text" id="account-name" class="form-control @error('name') is-invalid @enderror"
                           wire:model="name" placeholder="Your name">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label for="account-email" class="form-label">Email</label>
                    <input type="email" id="account-email" class="form-control @error('email') is-invalid @enderror"
                           wire:model="email" placeholder="you@example.com">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($user->isSsoLinked())
                        <div class="form-text">
                            <i class="ri-information-line me-1"></i>Your name and email come from your identity
                            provider and may be overwritten the next time you sign in with SSO.
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="ri-save-line me-1"></i>Save Changes
                </button>
            </form>
        </div>
    </div>

    {{-- --------------------------------------------------------------- Password --}}
    @if ($user->isSsoProvisioned())
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="ri-lock-line me-1"></i>Password</h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 text-muted mb-0">
                    <i class="ri-shield-user-line fs-20 text-primary"></i>
                    <div>
                        This account signs in through single sign-on, so it has no console password to change.
                        Manage your credentials with your identity provider.
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="ri-lock-line me-1"></i>Change Password</h5>
            </div>
            <div class="card-body">
                @if ($passwordSaved)
                    <div class="alert alert-success py-2" wire:poll.4s="$set('passwordSaved', false)">
                        <i class="ri-check-line me-1"></i>Password changed.
                    </div>
                @endif

                <form wire:submit="updatePassword">
                    <div class="mb-3">
                        <label for="current-password" class="form-label">Current password</label>
                        <input type="password" id="current-password" autocomplete="current-password"
                               class="form-control @error('currentPassword') is-invalid @enderror"
                               wire:model="currentPassword">
                        @error('currentPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label for="new-password" class="form-label">New password</label>
                        <input type="password" id="new-password" autocomplete="new-password"
                               class="form-control @error('password') is-invalid @enderror"
                               wire:model="password">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">At least 8 characters.</div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm-password" class="form-label">Confirm new password</label>
                        <input type="password" id="confirm-password" autocomplete="new-password"
                               class="form-control @error('passwordConfirmation') is-invalid @enderror"
                               wire:model="passwordConfirmation">
                        @error('passwordConfirmation') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="ri-key-2-line me-1"></i>Change Password
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
