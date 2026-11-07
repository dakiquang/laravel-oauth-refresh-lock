# Providers

How some providers handle refresh tokens, from their own documentation (checked 07/11/2026). Providers change these rules; follow the links before relying on them. Where the documentation does not say, this page says so instead of guessing.

## Summary

| Provider | New refresh token on refresh | Old refresh token afterwards | Risk of a duplicate refresh |
|---|---|---|---|
| Intuit QuickBooks Online | The value changes every 24 hours | Expires when a new one is issued | **High**: Intuit may revoke the refresh tokens |
| Salesforce, Refresh Token Rotation on | Every time | Invalid immediately | **High**: reuse revokes the current token too |
| Xero | Every time | Usable for 30 minutes | Low within 30 minutes |
| Microsoft identity platform | Every time | Not revoked when used | Low |
| Google | No (keep using the same one) | Stays valid | None from rotation |

The package also saves duplicate provider calls when the risk is low, but the reason it exists is the first two rows.

## Intuit QuickBooks Online

- The refresh token value changes every 24 hours; store the latest value from every response. When a new value is issued, the previous one expires.
- Refresh tokens have a rolling expiry of 100 days, with a hard limit of 5 years. Access tokens last 3,600 seconds.
- Using an old refresh token returns `invalid_grant`. If two refreshes use the same value, the first succeeds and the second gets `invalid_grant`, and Intuit may treat this as a security issue and revoke the refresh tokens, so the user has to authorise again. Intuit advises refreshing one at a time.
- Source: [OAuth 2.0 FAQ](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/faq), [Set up OAuth 2.0](https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0).
- Not confirmed by Intuit's documentation: a 24-hour grace period for the previous refresh token (mentioned in community threads; the FAQ says the previous value expires).

See [examples/quickbooks](../examples/quickbooks) for a working setup with league/oauth2-client.

## Salesforce (Refresh Token Rotation)

- With Refresh Token Rotation on, every refresh returns a new refresh token and the previous one is invalidated immediately. It is on by default, and cannot be turned off, for new External Client Apps since 22/10/2026.
- Reusing an invalidated refresh token revokes the current refresh token and its access tokens, so the client must authenticate again. The article's example error for an expired refresh token is `invalid_grant`.
- Source: [Refresh Token Rotation](https://help.salesforce.com/s/articleView?id=005316711&type=1), [External Client App defaults](https://help.salesforce.com/s/articleView?id=005390912&type=1).

## Xero

- Every refresh returns a new refresh token. The previous one can still be used for a grace period of 30 minutes; after that it no longer works.
- Refresh tokens are valid for up to 60 days. Access tokens last 30 minutes.
- Not stated by Xero: the error for a refresh token used after the grace period, and whether reuse is detected.
- Source: [Token types](https://developer.xero.com/documentation/guides/oauth2/token-types), [Auth flow](https://developer.xero.com/documentation/guides/oauth2/auth-flow).

## Microsoft identity platform

- Refresh tokens replace themselves with a new one on every use; the old one is not revoked, and the client should discard it.
- Refresh tokens last 90 days by default (24 hours for single-page apps). Access tokens last 60–90 minutes.
- An expired or revoked refresh token returns `invalid_grant` (for example AADSTS70008, 700082, 700084, 50173).
- Source: [Refresh tokens](https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens), [Error codes](https://learn.microsoft.com/en-us/entra/identity-platform/reference-error-codes).

## Google

- Refreshing does not return a new refresh token; keep using the one you have.
- A refresh token stops working after 6 months unused, when the user revokes access, or when the account has more than 100 refresh tokens for the same client (the oldest is invalidated). Apps in "Testing" with external users get refresh tokens that expire after 7 days.
- An expired or invalidated refresh token returns `invalid_grant`.
- Source: [OAuth 2.0 for web server apps](https://developers.google.com/identity/protocols/oauth2/web-server), [Using OAuth 2.0](https://developers.google.com/identity/protocols/oauth2).

## Notes on league/oauth2-client

- `GenericProvider` fills `IdentityProviderException`'s code from a field of the response body (the `responseCode` option, unset by default), not from the HTTP status. The default classifier therefore treats the code as an HTTP status only between 100 and 599. If you configure `responseCode` and the provider puts a value in that range there that is not the HTTP status, classification may be wrong; bind your own classifier in that case.
- Some provider packages attach the PSR-7 response to the exception; then the real HTTP status is used.
- When the token endpoint answers with something that is not JSON (a gateway's error page, or a wrong URL), league/oauth2-client throws an `UnexpectedValueException` without the HTTP status. There is no telling an outage from a misconfiguration, so the error reaches your code as is and the token is kept.
