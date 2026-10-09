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
| API route | `admin-api/online/` → `Api\Controller\Online` |
| API scope | `hampel_admin_api:error_detail`, not usable with OAuth clients |
| API scope | `hampel_admin_api:values`, not usable with OAuth clients |
| code event | `hampel_admin_api_redaction` — an add-on declares its secrets |
| code event listener | `hampel_admin_api_redaction` → `Listener::adminApiRedaction`, this add-on's own declaration |
| other add-ons' listeners | restored by `Setup` after an install or a rebuild, from each add-on's release data |
| admin permission | `hampelAdminApiOnline` |
| admin permission | `hampelAdminApiValues` — values on the options and config endpoints |
| admin permission | `hampelAdminApiErrorLog` |
| admin permission | `hampelAdminApiAddOns` |
| admin permission | `hampelAdminApiJobs` — both the cron and the jobs endpoint |
| admin permission | `hampelAdminApiOptions` |
| admin permission | `hampelAdminApiConfig` |
| admin permission | `hampelAdminApiErrorDetail` — with core's `viewLogs` |
| phrases | 13 — the three scope descriptions, the eight permission titles, two errors |

| CLI command | `admin-api:config`, `admin-api:validate` |
| CLI command | `admin-api:fault-arm`, `admin-api:fault-clean`, `admin-api:fault-list` |
| table | `xf_hampel_admin_api_fault` — what the fault commands planted |
| XenForo | 2.2.0 or later; five 2.3-only features are guarded where they are used |
| config key | `hampelAdminApi.faults`, `hampelAdminApi.redact` and `hampelAdminApi.show` in `config.php` |

No class extensions, code event listeners, template modifications, templates, options, entities,
cron entries, JS or LESS. `Setup.php` creates the one table on install; on uninstall it cleans
every fault batch and drops it. Its post-install, post-upgrade and post-rebuild hooks withhold the
scopes from OAuth clients.

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

**The name rule is two patterns because case means two things**, and the first version had one.
A single case-sensitive pattern matched `password` and missed `smtpLoginPassword`, `clientSecret`,
`accessToken`, `globalSalt` and `slackWebhookUrl` — every credential word that follows another
word in a camel-case name. Nothing leaked, because each of those was also on the declared list,
which is the layers doing what they are for; but the rule that is supposed to catch an
*undeclared* secret was not working, and only its own unit test showed it. Words are matched in
any case; `Key`, `Dsn` and `Login` keep their case, because there the capital is the word
boundary.

**The name rule has been too eager three times, and each is now a test**: `imageProxyBypass`
(ends in "pass"), `ban_user` (ends in "user"), and `hampelPrivateSiteHomeMessage` (contains
"private"). The fix each time was to narrow that pattern. A false positive only hides a value;
check the list of innocent names in `RedactorTest` before widening anything.

**An empty secret is withheld like a full one, and its redaction says `set: false`.** The first
version did the opposite, on the reasoning that reporting a blank password as redacted reads as a
password being set. A session using the API through a client then read a blank SMTP password
returned as `""` as a secret that had been *shown*. Both readings are wrong, and `set` on the
redaction is what removes them. An untrusted add-on's empty text is withheld the same way.

**No add-on is trusted by being listed, except XenForo's own.** The first version listed every
first-party add-on on the development forum with the secrets read off its option names. The list
came out the same day: it put add-on names in a public file, and each entry was one person's
reading of somebody else's options. A test fails if any id that is not an official add-on's is
added to `Registry::OPTIONS`.

**The values sweep is the test to trust.** `ValuesTest` asks for every real option on the forum in
values mode and fails if any declared secret came back, if anything returned is named or shaped
like a credential, or if an untrusted add-on's text was returned. It runs against whatever the
forum holds, so it is only as good as the forum: on one with few secrets configured it proves
little, and the masked review below is the complement.

**Who is online groups on the address and never returns it.** XenForo keeps one session per guest
address, which is why the size of the largest network is the figure that shows a swarm.

**What an option or a setting discloses in the shape tier is decided in one place, `ValueShape`,
by type — plus one lookup.** Loosening it — showing a string because its name looks harmless, raising the number
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

**A helper on a command must not be called `run()`**, which is the command's own public method;
`Validate` renders through `rows()`. And the report trait writes grey through `muted()`, never a
grey tag, which an older XenForo console throws on.

**`validate`'s probe depends on XenForo logging an error raised from the command line**, which
2.3.0 does not do. There the probe is a warning that says so, not a failure: the add-on is not
what is broken.

**A job's class name has to be the right shape even when the class does not exist.** XenForo checks
for a `\Job\` segment before it looks for the class. The first missing-job fault was named
outside that pattern: instead of being skipped as a missing job, it threw out of the queue
runner, abandoned the rest of that run, and was re-queued to do it again every fifteen minutes.
Only a real queue run showed it — the suite plants the row and reads it back as `stalled`, which
worked. The name is now `…\Job\NoSuchJob`, and takes XenForo's own "could not get runner,
skipping" path.

**What a planted job leaves behind is found by a marker, `adminApiFault:<batch>:`**, which is in
the job's unique key and in the data the rejected job chokes on, and so in both error messages.
It ends in a colon so that batch `a` does not match batch `ab`.

**XenForo 2.3.0 drops a job that throws without recording it as failed.** It logs the error and
removes the job. Later versions record a failed job, in debug mode. So the planted jobs exercise
`failed.signatures` only on those versions, and that has not been run.

**A fault page that nobody can view raises nothing, and a new node cannot be viewed.** XenForo
queues the permission rebuild for an administrator to trigger. Running it inside `arm` was not
enough twice over: the node tree it reads is itself rebuilt at the end of the request, and a
permission builder created earlier in the process — saving a member makes one — keeps the tree
from before the node existed. So `arm` plants the page first, settles the tree, discards the
builder and runs the rebuild until it finishes. Each of those was found by the driver being
refused, on a real forum; nothing in the suite would have shown it.

**A template may only call a method whose name starts with an allowed prefix** — `get`, `is`,
`can` and the like. Anything else is refused when the template is saved, so the fault never
reaches a visitor. The fault template calls `getFault()` on a variable nothing sets.

**Saving a template commits the transaction a test is running in.** XenForo compiles it with a
statement that does. So `arm` cannot run inside the suite without leaving a page and members on
the forum the tests run against; the suite covers the guard and the clean-up with planted records,
and `arm` is proved on a disposable forum.

**XenForo prints after a command's own output** when the command left work queued, so the JSON
`fault-arm` prints is followed by lines that are not JSON. The driver reads the first object.

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
- `tests/Feature/FaultTest.php` covers the three commands loading, the config guard in each
  state, `arm` refusing and planting nothing, batch names, `clean` clearing records whose content
  has gone, one batch leaving another, cleaning with the guard off, and the error log clean-up
  taking its own page's rows and no neighbour's. The job, cron and add-on faults are rows and
  nothing else, so those are planted for real inside the test's transaction, read back through
  each endpoint, cleaned, and the tables compared with how they started. One test hands `clean`
  records naming real rows — this add-on, core, a real cron entry, a real job — and checks it
  deletes none of them.
- `tests/Feature/CommandsTest.php` covers `config` and `validate`: both load; a clean forum exits
  0; nobody able to read is a warning and still 0; `--strict` makes that 2 and changes nothing
  else; the API being off is 1 either way; a check that throws fails its own row and the rest
  still report; a key that can never work is named with its reason; `--unattended` writes nothing;
  the probe leaves nothing behind; `config` describes a key and never shows it, and changes
  nothing. One test scans the source for a logged error without the add-on's prefix.
- `tests/Feature/SetupTest.php` sets every scope's OAuth flag as a release import leaves it and
  checks that the post-install hook clears them.
- `tests/Feature/OptionShapeTest.php` adds options of each type under an add-on id nothing real
  uses, and checks what is returned, the filters and the counts — including a choice on and off
  its list, the same text in a free-text option, and an array with declared, undeclared and
  nested members.
- `tests/Feature/ConfigShapeTest.php` sets config values of each type and checks the same, plus
  the forum's real database credentials by key and by absence.
- `tests/Feature/CommandsTest.php` also loads every command class the add-on ships the way
  XenForo's own runner does, since one that cannot load stops `cmd.php` for the whole forum.
- `tests/Unit/SecretsDeclarationTest.php` covers what this add-on declares about itself, and fails
  when it gains an option or a new reader of its `config.php` key, so the declaration is revisited.
- `tests/Unit/RedactorTest.php` covers every redaction rule with made-up values: names that are
  credentials and names that only resemble them, values that are tokens and long values that are
  not, URLs, arrays, lists, the untrusted tier, and the forum's overrides in both directions.
- `tests/Feature/ValuesTest.php` covers the gate — the scope, core's `option` permission, the
  values scope alone — then options and `config.php` in values mode, the declaration event, a
  malformed declaration, and the sweep over every real option.
- `tests/Feature/OnlineTest.php` plants a swarm of guests in one network, robots and members, and
  checks the counts, the network figures, and that no address, member or request detail returns.
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

**At the time, the options endpoint reported options as stored**, and the sandbox showed why that
is wrong: it pins its mail transport to SMTP in config code, and the endpoint reported the stored
`sendmail`. The endpoint now reports the value in force and flags `overridden` when the two
differ.

### Values, the declaration hook and who is online, 2026-10-08

The same two floors — XenForo 2.3.0 Patch 2 on PHP 7.4.33, development off, the release zip
installed by path — plus two scratch add-ons made for the run: one with a text option, a secret
and an empty secret, which answers the redaction event; one with a text option and a switch, which
does not. Each also had a key in `config.php`.

Every check passed over HTTP with a non-super administrator's keys: values mode on options and
`config.php`; the declared add-on's text shown and its secret and its empty secret withheld; the
undeclared add-on's text withheld and its switch shown; the database host and name shown and the
login and salt withheld; a key with the read scope alone given shape and told why; who is online;
and the four older endpoints. `admin-api:config` and `admin-api:validate` ran from the zip,
`validate` exiting 0. Every PHP file in the zip lints under 7.4. The error log stayed empty.

**It found two defects, both fixed before this was written.**

- **XenForo 2.3.0 returns every option as the string it is stored as.** Later releases cast a
  boolean or a number when the option is read; 2.3.0 does not, so 54 of 55 boolean options arrived
  as `"1"` or `"0"`. Judged as text they were withheld for an undeclared add-on, and in the shape
  tier too. The endpoint now casts to the option's declared type itself. **The suite cannot show
  this**, because the forum it runs on already casts; only a 2.3.0 forum does.
- **Uninstalling this add-on deletes every other add-on's listener for its event.** XenForo's
  `CodeEvent` entity deletes all listeners for an event when the event goes. Installed again, the
  add-on found nobody declaring anything, and nothing said why. `Setup` now puts back, after an
  install or a rebuild, any listener for the event that an installed add-on's release data
  registers and that is missing. Seen in the sandbox: listeners gone after the uninstall, both
  back after the reinstall.

**The values permission was proved over HTTP on 2026-10-09**, on the same floors, from the zip
tagged `1.0.0a1`: an administrator holding it got values from both endpoints; one with the values
scope and without the permission got shape from both, and was told which permission was missing;
a super user key was refused with and without the bypass parameter. Every boolean option arrived
as a boolean. Install, uninstall and the error log were clean.

### XenForo 2.2, 2026-10-09

The floor was lowered to 2.2.0 after reading the add-on against 2.2.19's source, and then proved
by installing the release zip on XenForo 2.2.19 Patch 2 with PHP 7.4.33, development off. **The
suite cannot run there** — the test framework needs 2.3 — so this run is the whole of the proof
for 2.2, and a change to any guarded path needs it again.

What was seen, over HTTP with a non-super administrator's keys unless it says otherwise:

- the install imported 3 scopes, 8 permissions, 13 phrases, 8 routes, the event and the listener,
  the forum and its control panel rendered, and all five commands listed;
- every endpoint answered, with values mode on options and `config.php`, each boolean option a
  boolean, the mail login and the database login withheld;
- **every refusal held on the key alone**, which is all 2.2 offers: a super user key with and
  without the bypass parameter, a member's key allowing every scope, an administrator with no
  permissions, the read key on the entries endpoint, the detail key on the summary, the values
  scope without the values permission, and no key at all;
- the job queue reported `retrying: null` and `failed.recorded: false`;
- `admin-api:config` and `admin-api:validate` reported each scope as defined, and `validate` ran
  its probe: logged, read back, removed;
- the fault commands planted a page, three members, one job, a cron entry and an add-on record;
  the driver's eight views were served; the summary showed eight web errors across three members
  under the planted page's template, one job error and the overdue cron entry; `fault-clean`
  left no row behind;
- uninstall removed everything.

**Two things 2.2 does that 2.3.0 does not**, both in its favour: it logs an error raised from the
command line, and `xf:run-jobs` is not needed to see a job fail — requesting `job.php` ran it.

**An add-on that listens for the event installs cleanly where nothing defines it**, on 2.3.0 and
on 2.2.19 Patch 2: the listener row is written, the forum and its control panel render, the error
log stays empty, and it uninstalls. That is what lets an add-on declare without requiring this one.

To repeat it: build the release, and install the zip by path on a fresh instance with
`$config['development']['enabled'] = false`.

### The fault commands, the same day and the same way

On the same kind of forum — the release zip, XenForo 2.3.0 Patch 2, PHP 7.4.33 — with
`hampelAdminApi.faults` on:

| checked | result |
|---|---|
| `fault-arm`, then the driver straight away | every view served: a guest and each member, logged in through the login form |
| what the views logged | one error per view, each with its request and the member who made it |
| the summary over HTTP | all `web` origin, the members counted, `E_USER_WARNING`, the page's template named |
| the entries endpoint with `request=1` | the message, the member's id, the address and the page's URL |
| `fault-clean` | the page, its template, the members, their address rows and every error it raised: all gone, and nothing else |
| a run that stopped half-way | `fault-clean` removed the three members and the page it had recorded |
| uninstalling with a batch still planted | the batch was cleaned and the table dropped |
| the same batch name twice | refused |
| the `jobs` fault, before the queue ran | both jobs `stalled` |
| the `jobs` fault, after `job.php` ran the queue | an error each with `job` origin, both jobs gone, and the rest of the queue run as normal |
| the `cron` fault | `overdue`; no longer overdue once cron had run |
| the `add-on` fault | `pending_action: upgrade`, not processing, and errors still being logged |
| `fault-clean` after all four | every planted row and all eight errors gone; the forum's 19 cron entries and 2 add-ons untouched |

## Needs a human

- **A request over HTTP with a real key.** The suite sets the key and the visitor side by side; a
  real request has XenForo derive the visitor from the key. Create a *User* key for an
  administrator with only this add-on's scope and request each endpoint, then repeat with a
  super user key adding `api_bypass_permissions=1`, and with a key for an ordinary member that
  allows all scopes. The second and third must be refused.
- **An install or upgrade that has really stopped part-way.** `pending_action` is tested with
  planted records, not with an add-on whose setup step failed.
- **A stopped queue.** `stalled` and `overdue` are tested with planted rows. Whether they read
  correctly on a forum whose cron trigger has really stopped has not been seen.
- **A masked review of values mode on a forum with real secrets configured.** Ask for options and
  config in values mode and, for every string returned, look at its length and first few
  characters only. Done on a development forum on 2026-10-08, where it found three names the
  rules hid needlessly and nothing they let through; a production forum has more secrets set and
  is the review that matters.
- **Who is online, on a forum under real load.** The figures are tested with planted sessions.
- **`admin-api:validate` with its probe on a forum that logs it and has alerting attached.** The
  probe was run once on a development forum: logged, read back, removed. What an alert on the
  server error log makes of a row that exists for a moment has not been looked at.
- **The upgrade path.** `1.0.0a1` is the first version built; the first upgrade to test is from it.
  `Setup` has no upgrade steps, so there is nothing yet that could fail on an upgrade and pass on
  an install.
- **The admin control panel pages themselves.** The phrases are checked in the database after an
  install; nobody has looked at the administrator edit page, the API key page or the OAuth client
  page in a browser.
