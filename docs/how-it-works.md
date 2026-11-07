# How it works

## The race

With rotating refresh tokens, every refresh returns a new refresh token and the provider retires the old one (some immediately, some after a grace period, see [providers.md](providers.md)). Two workers that notice the same expired access token both send the same refresh token. The first wins. The second sends a retired token and gets `invalid_grant`. If the app takes that as "disconnected", a user has to reconnect for no reason. With some providers the reuse itself revokes the connection.

## One refresh per expiry

```mermaid
sequenceDiagram
    participant A as Worker A
    participant B as Worker B
    participant L as Cache lock
    participant S as Token store
    participant P as Provider

    A->>S: get(key): expires soon
    B->>S: get(key): expires soon
    A->>L: lock(key)
    L-->>A: acquired
    B->>L: lock(key)
    Note over B,L: B waits (up to wait_seconds)
    A->>S: get(key) again: still expiring
    A->>P: refresh_token grant
    P-->>A: new access + refresh token
    A->>S: put(new, expectedVersion: v)
    S-->>A: ok (version v+1)
    A->>L: release
    L-->>B: acquired
    B->>S: get(key) again: fresh (v+1)
    B->>L: release
    Note over B: B uses A's token, no second refresh
```

1. **Fast path.** A token that does not expire within `skew_seconds` is returned without touching the lock.
2. **One lock per key.** Workers that need a refresh take `lock_prefix + key` from the configured cache store and wait up to `wait_seconds` for it.
3. **Read again inside the lock.** The worker that waited usually finds the token the previous holder just stored and returns it.
4. **Compare-and-swap write.** The refreshed token is stored only if the stored version is still the one that was read. If someone else wrote in the meantime, their write wins: after a reconnect the new token is returned, after a disconnect the caller gets `TokenNotFound`.

Writes from the app (connect, reconnect) are unconditional, and the store decides their version: an existing key moves to version + 1, a new key starts from a time-based value. So a worker holding an old read can never win a compare-and-swap against a newer connection, even one made after the key was forgotten.

## A refresh that outlives the lock

The lock is a lease of `lock_seconds`. If the provider call takes longer:

1. Worker A is still waiting for the provider; its lease has expired.
2. Worker B takes the lock, reads the token, sends the same refresh token. If A's refresh already went through, the provider answers `invalid_grant`, and B stores the token as revoked and throws `TokenRevoked` (and dispatches `TokenRevokedByProvider`).
3. A's call returns the new, valid tokens. A's compare-and-swap fails because B wrote. A sees a revocation of exactly the generation it rotated (`revokedFromVersion`), so the revocation was caused by its own rotation, and it stores its valid tokens (and dispatches `TokenRefreshed`).

This only helps if the provider still accepts A's new tokens. Providers with a grace period for the previous refresh token (Xero) or no revocation on reuse may; providers that punish reuse may revoke the whole connection, A's new tokens included (Salesforce with Refresh Token Rotation; Intuit may). Then the user has to connect again whatever the package stores. B's caller has seen `TokenRevoked` in any case.

Keep the refresh call's HTTP timeout well below `lock_seconds` so this does not happen, and check the stored token before acting on a revocation event.

## When the lock store fails

Laravel 10–12's database lock can hit a deadlock on MySQL when many workers compete for the lock row. A concurrency error while acquiring the lock means only that the worker lost the race, so it keeps waiting. Once the refresh has started, nothing is retried, so the provider is never called twice by one worker.

## Classifying errors

What the refresher throws decides what happens to the token. `DefaultErrorClassifier`, in order:

| Error | Class | Token |
|---|---|---|
| Network error (PSR-18, Guzzle connect error, Laravel `ConnectionException`), HTTP 408, 429, 5xx | Temporary → `TransientRefreshFailure` | Kept |
| OAuth error `temporarily_unavailable`, `server_error` | Temporary → `TransientRefreshFailure` | Kept |
| OAuth error `invalid_grant` | Revoked → `TokenRevoked` | Marked revoked |
| Anything else | Unexpected → the original exception | Kept |

The HTTP status wins over the response body, because gateways and struggling providers return odd bodies. Only `invalid_grant` revokes: wrongly revoking forces a user to reconnect, while a kept token costs one more failed attempt. The list of revoking and temporary codes can be changed through the classifier's constructor, or the classifier replaced.

The classifier reads errors from league/oauth2-client (`IdentityProviderException`), Guzzle, Laravel's HTTP client and PSR-18 clients. None of these packages is required.

## Events

Events are collected while the lock is held and dispatched after it is released, so slow listeners never extend the lock. A listener's exception reaches the caller, as with any synchronous Laravel listener; the token has already been stored by then.
