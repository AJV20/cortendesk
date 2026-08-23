# Strategy delivery and Strategies V2

Strategies are server-side policy snapshots delivered through the existing RustDesk strategy heartbeat response. Strategies V2 adds operator safety and rollout state without changing the client wire format.

## Resolution precedence

An enabled strategy resolves in this order:

1. device assignment
2. device owner assignment
3. device-group assignment
4. default strategy

Disabled strategies are skipped. CortenDesk stores the winner on `devices.strategy_id_resolved` and recomputes it whenever a strategy or assignment changes.

## Client delivery contract

`Strategy::deliveryFor()` remains the compatibility boundary. It returns the existing payload fields:

- `modified_at`: monotonically increasing device-local strategy version
- `strategy.config_options`: normalized option map

`enforce` remains a server-side resend policy; it is not a new wire field.

No Strategies V2 revision or rollout identifiers are exposed to clients. A staged rollout only changes which immutable server-side snapshot supplies `config_options` for a released device.

When a released candidate is sent, its target stores the exact `modified_at` token. A later echo confirms that historical target even if a newer rollout has already changed the current desired snapshot. Devices carry a cheap pending-evidence flag so ordinary heartbeats do not pay for a rollout-history lookup.

## Impact preview

Every normal editor and assignment mutation is a two-step server-side action:

1. preview validates and normalizes the proposal, computes exact affected devices, records dangerous/reset warnings, and creates a fingerprint from current strategy, assignment, and resolution state;
2. confirmation recomputes the fingerprint and refuses stale previews before writing.

The legacy Livewire `save` and `saveAssign` action names now open their respective previews; they no longer bypass review. Fleet scans and rollout target materialization are chunked. The interactive assignment editor rejects sets above 5,000 direct targets and directs operators toward device groups instead of serializing an unbounded Livewire payload.

## Compliance states

Compliance is calculated against the desired snapshot for each device, including released rollout candidates:

- **Confirmed**: the acknowledged normalized options equal the desired options and the acknowledgement is not older than the most recent send.
- **Pending**: the device is online, has not yet acknowledged, and the strategy's configured confirmation timeout has not elapsed.
- **Stale**: the device is online and its unconfirmed send is older than the configured timeout.
- **Offline**: the device is not currently online; offline takes precedence over pending/stale.
- **Overridden**: the strategy is assigned to the device directly, through its owner or group, or as default, but another enabled strategy wins (or this strategy is disabled).

Fleet summaries use chunked set-based queries and classify devices without retaining the full fleet in Livewire. Counts are exact; a drill-down returns at most the first 200 matching devices and directs full-fleet work to the Devices page.

## Immutable revisions and rollback

Each confirmed save captures a `strategy_revisions` row containing:

- monotonically increasing per-strategy revision number;
- full normalized snapshot;
- author and timestamp;
- operator change note;
- exact affected-device count.

Upgrades create revision 1 for every existing strategy and point `strategies.active_revision_id` at it.

Revision rows reject application-level updates and direct deletes. Rollback applies the selected policy snapshot and captures the result as a new revision. Strategy names are durable identities and are not rolled back. When default status moves, the displaced strategy also gets a revision. Rollback is blocked while a rollout is scheduled, active, or paused. Strategy deletion is a soft delete that releases live assignments but retains revisions and rollout evidence; permanently deleted devices leave their RustDesk ID on rollout targets.

## Staged rollouts

A rollout points to an immutable candidate revision and a frozen device target set ordered by stable device ID. It supports:

- an optional future start time;
- configurable batch size and interval;
- pause and resume;
- cancellation before completion (an active rollout with released devices must be paused first);
- released and confirmed target timestamps.

Names, enabled state, default status, and confirmation timeout cannot be staged because they change resolution or compliance semantics before every device shares one active revision. Apply those changes immediately through the reviewed impact flow. Option values, enforcement, and notes may be staged.

For an active or paused rollout, released devices receive the candidate snapshot; unreleased devices continue receiving the active strategy snapshot. Cancellation causes released devices to return to the active snapshot on their next heartbeat. When the final batch is released, the candidate becomes the strategy's active revision and the rollout is marked completed.

Assignment mutations involving a strategy are blocked while its rollout is scheduled, active, or paused. Assignment writers lock every currently/newly involved strategy in stable order, so a concurrent schedule either sees the updated preview state or wins the lock and causes the assignment to fail safely.

The scheduler runs `cortendesk:advance-strategy-rollouts` every minute with overlap protection.

## Authorization and auditability

- `strategy:r` may view policies, compliance drill-downs, revision history, and comparisons.
- `strategy:rw` is required at every server-side create, edit, assignment, rollout, pause/resume/cancel, rollback, enable/disable, and delete boundary.
- Editor confirmations, assignment changes, rollout lifecycle actions, and rollback actions create console audit records.
- Rollout and revision tables retain author, timestamps, state, and per-device release/confirmation evidence independently of console audit retention.
