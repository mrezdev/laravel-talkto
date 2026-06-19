# Transactional Outgoing

Transactional outgoing scaffolding helps a host app create or update local source-side business state and create the Talkto outbox message in the same local database transaction.

It is for local consistency. It is not a distributed transaction.

## What Transactional Outgoing Means

Use transactional outgoing when the host app needs one local transaction to cover:

- a local business record or source-side state change
- the Talkto outgoing message record that represents the command to send

Example:

```bash
php artisan talkto:make-outgoing inventory verify-invoice --transactional
```

The generated client exposes a method like:

```php
app(\App\Talkto\Outgoing\Inventory\InventoryTalktoClient::class)
    ->verifyInvoiceTransactionally($data);
```

## What It Does Not Mean

Transactional outgoing does not make the remote service part of the local database transaction.

It does not mean:

- the destination service has already processed the command
- the source database transaction waits for remote success
- remote HTTP is called while source rows are locked
- retries are avoided
- callbacks are unnecessary

## Why Not To Call Remote HTTP Inside A DB Transaction

Remote calls can be slow, fail intermittently, time out, or succeed while the local transaction later rolls back. Holding a database transaction open during remote I/O increases lock time and makes recovery harder.

Laravel Talkto keeps the local transaction short. The source app persists local state and a durable outgoing message. Delivery happens after commit through the package flow and queue behavior.

## Local Transaction Flow

The generated transactional send action follows this shape:

1. Begin a local database transaction.
2. Run `Prepare{Command}SourceAction` to create or update the local source record.
3. Build the payload from the resulting source object.
4. Create the Talkto outgoing message.
5. Commit the local transaction.
6. Let the existing Talkto sending behavior deliver the message after commit.

If step 2 or message creation fails, the local transaction rolls back.

## Retry Flow After Commit

After commit, delivery is an ordinary reliable Talkto message flow. If the destination is temporarily unavailable, retry and recovery behavior can handle the outgoing message.

Destination success should usually be confirmed by a callback or result flow. The source app can then update local state from a pending or syncing state to a confirmed state.

## Example Generated Method

Generated client methods are intentionally small:

```php
app(\App\Talkto\Outgoing\Inventory\InventoryTalktoClient::class)
    ->verifyInvoiceTransactionally($data);
```

The `$data` array is host-owned input. The generated source action decides how to validate it and how to create or update local state.

## Example Prepare Source Action Responsibility

`Prepare{Command}SourceAction` should focus on local work only:

- validate source-side input needed for the local write
- create or update a local source record
- set an initial local status
- return the source object used by the payload builder

It should not call the destination service, send mail, perform long blocking work, or assume the remote command has succeeded.

## Recommended Source Record Statuses

Keep status names generic and meaningful for operators. Common examples include:

- `pending`: local record was created and is waiting for delivery
- `syncing`: delivery is in progress or awaiting destination confirmation
- `confirmed`: destination success was confirmed by a callback or result flow
- `failed`: the source app decided the command cannot complete without review or retry

Exact names belong to the host app.

## Common Mistakes

- Calling remote HTTP inside `Prepare{Command}SourceAction`.
- Treating local commit as destination success.
- Updating the source record to a final success state before a callback confirms destination work.
- Forgetting idempotency keys for retryable state-changing commands.
- Leaving generated payload builder or validator logic as placeholder business behavior.
- Copying incoming config snippets without reviewing service names, secrets, handlers, and idempotency.
