# Testing

What this add-on touches, where it can go wrong without saying so, and how each of those is
checked. For whoever changes it next.

## Surfaces

| type | name |
|---|---|
| API route | `admin-api/error-log/` → `Api\Controller\ErrorLog` |
| API route | `admin-api/add-ons/` → `Api\Controller\AddOns` |
| API scope | `hampel_admin_api:read`, not usable with OAuth clients |
| admin permission | `hampelAdminApiErrorLog` |
| admin permission | `hampelAdminApiAddOns` |
| phrases | 4 — the scope description, the two permission titles, one error |

No class extensions, code event listeners, template modifications, templates, options, entities,
cron entries, JS or LESS. The add-on creates no schema and reads only core tables; `Setup.php` has
no install, upgrade or uninstall steps, and its post-install, post-upgrade and post-rebuild hooks
withhold the scope from OAuth clients.

## Fragile points

**Every refusal is a 403, so a test asserting only the status proves nothing.** Three gates and
core's own checks all deny with the same code. Each authorisation test asserts on the message, and
`tests/TestCase.php` grants the acting user permission to view the board — without that grant
core refuses first, with the same message the third gate uses, and the gate's tests pass with the
gate deleted.

**`\XF::apiKey()` is never null.** With no key it is a fallback key of type `guest`; with an OAuth
token it is that same fallback while the visitor is the token's user. The first gate therefore
tests for an access token as well as the key type. Testing `is_super_user` alone lets an
administrator's OAuth token through.

**Core's `assertAdminPermission()` returns early when the request bypasses permissions.** The
third gate does not call it. If it is ever "simplified" to do so, it becomes dependent on the
first gate having run.

**Core lowercases an API scope id.** The scope is spelled in lower case everywhere for that
reason; a mixed-case id imports as a different string from the one the code asks for.

**The summary query names its columns.** `SELECT *`, or one more column added for convenience, is
how a message or a request reaches the response. One test encodes the whole response and searches
it for values planted in the columns that must not appear.

**`add_on_id` is derived from the path.** It is matched against installed add-on ids, two path
segments first and then one, so an add-on without a vendor is still found. A file under
`src/addons/` belonging to no installed add-on gives `null`, not a guess.

**A release does not carry the scope's `usable_with_oauth_clients` flag.** XenForo exports the
scope id alone, so a forum installing from a zip gets the column default, which is usable. The
gates refuse an access token regardless; `Setup.php` puts the flag back so the scope is not
offered to an OAuth client at all. Only an install from a zip can show whether that worked.

## Automated

```bash
composer install
vendor/bin/phpunit
```

- `tests/Feature/AuthorisationTest.php` dispatches the routes, so `preDispatch()` runs. It covers
  each gate in both directions, including a super user key with the permission bypass on.
- `tests/Feature/ErrorLogSummaryTest.php` writes rows inside a transaction, dated in a window no
  real entry occupies, and asserts the counts, the grouping, the limit and the absence of every
  personal and request column.
- `tests/Feature/AddOnInventoryTest.php` checks the response against `addon.json`.
- `tests/Feature/SetupTest.php` sets the scope's OAuth flag as a release import leaves it and
  checks that the post-install hook clears it.
- `tests/Unit/ErrorLogRepositoryTest.php` covers the path handling.

To confirm the gate tests are real, remove one of the three calls in
`Api\Controller\AbstractController::preDispatchController()` and run `AuthorisationTest`: four
tests fail without the first, one without the second, four without the third.

## Needs a human

- **A request over HTTP with a real key.** The suite sets the key and the visitor side by side; a
  real request has XenForo derive the visitor from the key. Create a *User* key for an
  administrator with only this add-on's scope and request both endpoints, then repeat with a
  super user key adding `api_bypass_permissions=1`, and with a key for an ordinary member that
  allows all scopes. The second and third must be refused.
- **The admin control panel.** The two permissions appear on the administrator edit page and the
  scope appears on the API key page, each with its description rather than a raw phrase key.
- **Install on a forum that has never had it**, from a release zip, and uninstall again. After the
  install, the scope must not be offered when editing an OAuth client. There is no upgrade path
  to test until a version has been released.
- **The declared floors.** The suite runs on one XenForo and one PHP version. XenForo 2.3.0 and
  PHP 7.4 are what the code is written to, not what it has been run on.
