# Testing

What this add-on touches, where it can go wrong without saying so, and how each of those is
checked. For whoever changes it next.

## Surfaces

| type | name |
|---|---|
| API route | `admin-api/error-log/` → `Api\Controller\ErrorLog` |
| API route | `admin-api/add-ons/` → `Api\Controller\AddOns` |
| API route | `admin-api/cron/` → `Api\Controller\Cron` |
| API route | `admin-api/jobs/` → `Api\Controller\Jobs` |
| API route | `admin-api/options/` → `Api\Controller\Options` |
| API route | `admin-api/config/` → `Api\Controller\Config` |
| API scope | `hampel_admin_api:read`, not usable with OAuth clients |
| admin permission | `hampelAdminApiErrorLog` |
| admin permission | `hampelAdminApiAddOns` |
| admin permission | `hampelAdminApiJobs` — both the cron and the jobs endpoint |
| admin permission | `hampelAdminApiOptions` |
| admin permission | `hampelAdminApiConfig` |
| phrases | 7 — the scope description, the five permission titles, one error |

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

**A test must only ever add rows, and never empty a table to get a known state.** The suite runs
against the forum's real tables inside a transaction. `emptyTable()` is a `TRUNCATE`, which is DDL:
it commits the transaction on the spot, so the table stays empty and everything the test wrote
before it becomes permanent. On 2026-10-07 a job queue test did exactly that and removed a
development forum's two queued jobs, cron's own among them, which then had to be put back by
hand. Queue rows under a class or id no real row uses and assert on those; where a value has to
beat every real row, date it at the start of 1970.

**XenForo does not record when a cron entry last ran.** `xf_cron_entry` holds `next_run` only. The
cron endpoint therefore reports overdue entries and the last run of the `cron` job, and cannot
answer "did this entry run last night" directly.

**`xf_failed_job` is written only in debug mode.** An empty table on a production forum is not
evidence that no job failed, which is why the response carries `failed.recorded`.

**The job queries name their columns too.** `execute_data`, `unique_key` and a failed job's
`exception` are where member data sits.

**What an option or a setting discloses is decided in one place, `ValueShape::isDisclosable()`,
by type alone.** Loosening it — showing a string because its name looks harmless, raising the
number ceiling — is how a credential reaches a response. Each endpoint has a test that walks every
real option or setting on the forum and fails if any returned value is not a boolean, a null or a
small number, and the config test searches the response for the forum's own database password.

**`value: null` is ambiguous without `disclosed`.** A withheld string and a real null both carry
it. A consumer that reads `value` alone will report every string option as empty.

**Config key names are returned.** A map is followed and its keys become part of the dotted key.
A list is not followed, and that distinction is what stops a list of hostnames becoming keys.

**The summary query names its columns.** `SELECT *`, or one more column added for convenience, is
how a message or a request reaches the response. One test encodes the whole response and searches
it for values planted in the columns that must not appear.

**XenForo stores most error-log filenames already relative to the install**, and a few in full.
A relative path is therefore inside the install, and only an absolute one is tested against the
root. Treating "does not start with the root" as "outside" reports nearly every core error as
outside and loses its add-on — the suite did not catch that until its rows were stored both ways,
because a test that writes only full paths never meets the common case.

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
  personal and request column, of the install's own path, and of any directory outside it.
- `tests/Feature/AddOnInventoryTest.php` checks the response against `addon.json`.
- `tests/Feature/CronStateTest.php` adds entries that are due, recently due, overdue, disabled and
  owned by an add-on that is not installed, and checks which are called overdue.
- `tests/Feature/JobStateTest.php` queues jobs under classes no real job uses and checks the
  per-class counts, the failed-job counts and the absence of job data, keys and exception text.
- `tests/Feature/SetupTest.php` sets the scope's OAuth flag as a release import leaves it and
  checks that the post-install hook clears it.
- `tests/Feature/OptionShapeTest.php` adds options of each type under an add-on id nothing real
  uses, and checks what is returned, the filters and the counts.
- `tests/Feature/ConfigShapeTest.php` sets config values of each type and checks the same, plus
  the forum's real database credentials by key and by absence.
- `tests/Unit/ValueShapeTest.php` covers the disclosure rule, flattening and comparison.
- `tests/Unit/ErrorLogRepositoryTest.php` covers the path handling.

To confirm the gate tests are real, remove one of the three calls in
`Api\Controller\AbstractController::preDispatchController()` and run `AuthorisationTest`: four
tests fail without the first, one without the second, four without the third.

## Needs a human

- **A request over HTTP with a real key.** The suite sets the key and the visitor side by side; a
  real request has XenForo derive the visitor from the key. Create a *User* key for an
  administrator with only this add-on's scope and request each endpoint, then repeat with a
  super user key adding `api_bypass_permissions=1`, and with a key for an ordinary member that
  allows all scopes. The second and third must be refused.
- **A stopped queue.** `stalled` and `overdue` are tested with planted rows. Whether they read
  correctly on a forum whose cron trigger has really stopped has not been seen.
- **The admin control panel.** The five permissions appear on the administrator edit page and the
  scope appears on the API key page, each with its description rather than a raw phrase key.
- **Install on a forum that has never had it**, from a release zip, and uninstall again. After the
  install, the scope must not be offered when editing an OAuth client. There is no upgrade path
  to test until a version has been released.
- **The declared floors.** The suite runs on one XenForo and one PHP version. XenForo 2.3.0 and
  PHP 7.4 are what the code is written to, not what it has been run on.
