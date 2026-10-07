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
| API route | `admin-api/error-entries/` → `Api\Controller\ErrorEntries` |
| API scope | `hampel_admin_api:read`, not usable with OAuth clients |
| API scope | `hampel_admin_api:error_detail`, not usable with OAuth clients |
| admin permission | `hampelAdminApiErrorLog` |
| admin permission | `hampelAdminApiAddOns` |
| admin permission | `hampelAdminApiJobs` — both the cron and the jobs endpoint |
| admin permission | `hampelAdminApiOptions` |
| admin permission | `hampelAdminApiConfig` |
| admin permission | `hampelAdminApiErrorDetail` — with core's `viewLogs` |
| phrases | 10 — the two scope descriptions, the six permission titles, two errors |

No class extensions, code event listeners, template modifications, templates, options, entities,
cron entries, JS or LESS. The add-on creates no schema and reads only core tables; `Setup.php` has
no install, upgrade or uninstall steps, and its post-install, post-upgrade and post-rebuild hooks
withhold the scopes from OAuth clients.

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

**A failed job's exception is one string with the message in the middle**, between the class and
the file. So the file cannot be trusted by shape: a message can end in something that looks like
` in path:line`. A path under the install is believed only if that file exists; one outside it is
reduced to a name that must look like a PHP file's; and the *last* ` in ` on the line is taken,
because a message can contain one. The tests plant forged locations of each kind.

**What an option or a setting discloses is decided in one place, `ValueShape`, by type — plus one
lookup.** Loosening it — showing a string because its name looks harmless, raising the number
ceiling — is how a credential reaches a response. The lookup is `describeChoice()`: a string is
returned when it equals a choice the option's definition lists, compared exactly. The options
test walks every real option on the forum, recomputes each option's choices and declared members
from `xf_option` itself, and fails if a string came back that is not on its own list or a member
that is not declared. The config test fails on any string at all, and searches the response for
the forum's own database password.

**A select option's choices are written `value=label`, sometimes with spaces round the equals
sign.** XenForo's own parser handles both; a hand-written pattern that expects none will call a
correct result wrong, as the test's first version did.

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

**The detail endpoint is the only place content leaves the forum, and four things keep it narrow**:
its own scope, its own permission plus `viewLogs`, entry by `error_id` only, and a cap of 20. Each
has a test. The cap had a bug on the day it was written — a finder asked to fetch none returns
them all, so `limit=1` returned every entry at that place — and only the test for it showed that.

**A trace has to be reduced the same way a filename is.** Stripping the install root from a trace
is not enough: a frame in a file outside the install was returned in full while the summary
returned the same file by name only. Each frame's own path now goes through
`getRelativeFilename()`. Arguments are not touched, and PHP truncates them, so a fragment of a
path can still appear in one.

**A class constant in a test is evaluated while the suite is being built**, before XenForo and the
add-on's classes can load. Naming an add-on class in one stops the whole run.

**Four fields are worked out from columns that are never returned, and each is checked against a
fixed list before it is kept**: `ErrorLog::SEVERITIES`, `ErrorLog::ORIGINS`, the add-on ids in
`xf_addon`, and the template names in `xf_template`. `pending_action` in the add-on inventory is closed the same way, against
`AddOns::PENDING_ACTIONS`. The message, trace and request state are read only in part — 40 characters, 8000 and
7. Returning a matched group without the `in_array()` beside it would let whatever sits in
brackets at the start of a message through. A test walks the forum's real log and fails if any
derived value is outside its list.

**A distinct count cannot be added up.** A file stored relative by one request and in full by
another is two rows of the grouped query and one signature, and a member behind both must be
counted once. Those signatures are recounted with a query of their own. The test for it needs a
member unique to each stored form: with the same members behind both, the first row's count is
already the right answer and the test passes with the recount removed.

**The template is the innermost compiled-template frame, and the name is looked up, not trusted.**
A path ending `templates/l1/s0/public/anything.php` names a template only if the forum has
`public:anything`. The list of templates is loaded the first time such a path is seen, so a log
with no template errors costs no query. A template named only inside an argument — the page a
wrapper was wrapping — is not found.

**`location` only recognises `vendor/`, `tests/` and `Test/` at the top of an add-on.** A directory
of the same name further in is that add-on's own code, and is reported as such.

**The originating add-on is the nearest one, not necessarily the guilty one.** An add-on that
extends a core class every request passes through is on the way to errors it did not cause.

**Whether an error happened in a job is decided by the query, not from the trace as read.** The
job runner sits at the bottom of a trace, past where a truncated read stops.

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
  personal and request column, of the install's own path, and of any directory outside it. Its
  rows differ in level, origin and call path, so each derived field is asserted in both its
  per-forum and per-signature form. It also plants errors for a guest, the command line and
  several members, and checks that only members are counted and each only once.
- `tests/Feature/AddOnInventoryTest.php` checks the response against `addon.json`, and plants an
  add-on record in each unfinished state to check what is reported for it.
- `tests/Feature/CronStateTest.php` adds entries that are due, recently due, overdue, disabled and
  owned by an add-on that is not installed, and checks which are called overdue.
- `tests/Feature/JobStateTest.php` queues jobs under classes no real job uses and checks the
  per-class counts, the failed-job counts and the absence of job data, keys and exception text.
  For failed jobs it also checks the signature of a plain failure and of a chained one, and that
  a location written into a message is not taken for one.
- `tests/Feature/ErrorEntriesTest.php` covers the detail endpoint: what an entry returns, which
  others come with it, the cap, the request data being absent unless asked for, and each of the
  ways in being refused — the wrong scope in both directions, the summary's permission, a missing
  `viewLogs`, a super user key.
- `tests/Feature/SetupTest.php` sets every scope's OAuth flag as a release import leaves it and
  checks that the post-install hook clears them.
- `tests/Feature/OptionShapeTest.php` adds options of each type under an add-on id nothing real
  uses, and checks what is returned, the filters and the counts — including a choice on and off
  its list, the same text in a free-text option, and an array with declared, undeclared and
  nested members.
- `tests/Feature/ConfigShapeTest.php` sets config values of each type and checks the same, plus
  the forum's real database credentials by key and by absence.
- `tests/Unit/ValueShapeTest.php` covers the disclosure rule, flattening and comparison.
- `tests/Unit/ErrorLogRepositoryTest.php` covers the path handling and the four derivations,
  including what must not be taken for a level and which trace paths must not be followed.

To confirm the gate tests are real, remove one of the three calls in
`Api\Controller\AbstractController::preDispatchController()` and run `AuthorisationTest`: four
tests fail without the first, one without the second, four without the third.

## Proved on a clean forum

On 2026-10-07 the release zip was installed on a disposable forum that had never had the add-on,
at **XenForo 2.3.0 Patch 2 on PHP 7.4.33** — both declared floors at once — with development mode
off, and then uninstalled.

| checked | result |
|---|---|
| the install took the release's data, not development output | `Importing add-on data`, and no `_output/` appeared |
| every PHP file parses on 7.4 | `php -l` clean |
| what was installed | 2 scopes, 6 admin permissions, 7 routes, 10 phrases with their text |
| **both scopes are withheld from OAuth clients** | `usable_with_oauth_clients` 0 for each — the column default is 1, so `Setup` did this |
| every endpoint, with a user key for an administrator | 200, with real data |
| the read scope on the entries endpoint, and the detail scope on a summary | 403 `missing_scope`, both ways |
| a super user key, with and without `api_bypass_permissions=1` | 403 `hampel_adminapi_user_api_key_required`, on a summary and on the entries endpoint |
| uninstall | no scope, key grant, permission, phrase or route left; no error logged; the forum still renders |

**XenForo 2.3.0 does not log an error raised from the command line.** Its logger converts the
request's IP address unconditionally and that throws for the empty address a CLI run has; the
exception is swallowed and no row is written. Later 2.3 releases guard it. So on 2.3.0 the summary
will show no `cli` origin whatever happens there, and the error rows for this check had to be
planted. Nothing in this add-on can change that.

**The options endpoint reports options as stored.** A forum whose `config.php` overrides an option
in code is running with a value this endpoint does not show: the sandbox pins its mail transport
to SMTP that way, and the endpoint correctly reported the stored `sendmail`.

To repeat it: build the release, and install the zip by path on a fresh instance with
`$config['development']['enabled'] = false`.

## Needs a human

- **A request over HTTP with a real key.** The suite sets the key and the visitor side by side; a
  real request has XenForo derive the visitor from the key. Create a *User* key for an
  administrator with only this add-on's scope and request each endpoint, then repeat with a
  super user key adding `api_bypass_permissions=1`, and with a key for an ordinary member that
  allows all scopes. The second and third must be refused.
- **An error from a real web request.** Every row in a development log tends to come from the
  command line. `origin: web` is tested with planted rows only.
- **An install or upgrade that has really stopped part-way.** `pending_action` is tested with
  planted records, not with an add-on whose setup step failed.
- **A stopped queue.** `stalled` and `overdue` are tested with planted rows. Whether they read
  correctly on a forum whose cron trigger has really stopped has not been seen.
- **The upgrade path.** There is none to test until a version has been released.
- **The admin control panel pages themselves.** The phrases are checked in the database after an
  install; nobody has looked at the administrator edit page, the API key page or the OAuth client
  page in a browser.
