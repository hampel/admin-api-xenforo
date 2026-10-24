# Working on the Admin API add-on

Standing guidance for a session working in this repository: what the add-on is for, the rules its
design rests on, and where the detail lives. `README.md` documents the endpoints and `TESTING.md`
the surfaces and fragile points; this file does not repeat them.

## The endpoints are a read-only window onto the forum, for the people who run it

They cover what the core API leaves out — the server error log, the add-on inventory, cron entries,
the job queue, counts from every other log, the shape of options and of `config.php`, user upgrades
and their payments, the members behind an IP address, and the moderators and administrators so
far. **No endpoint changes the forum,
and none may.** The add-on has no options.

**The one row an endpoint writes is a line in the admin log, for each member lookup** — decided on
2026-10-24. Who looked up whom is the record an administrator wants afterwards, and the control
panel keeps the same record of its own actions. It goes through core's `logAdminRequest()`, so it
is pruned with the rest of the log; the aggregate endpoints write nothing.

**The one thing that changes the forum is the fault commands, kept away from everything else**:
command line only, refused without a key in the forum's own `config.php`, and recording each thing
they plant in the add-on's one table so it can be removed. See *The fault commands* below.

**It does not speak any agent protocol and must not grow one.** A consumer — a monitoring script,
or a separate tool that presents these endpoints to an agent — sits outside the forum. If a
protocol endpoint inside XenForo is ever wanted, it is a second add-on that requires this one, so
an administrator opts into a new listener by name.

## A key's ceiling is its own user's admin permissions

`Api\Controller\AbstractController` runs three gates before any action, and every controller
extends it:

1. a user key only — super user keys, guest keys and OAuth tokens are refused;
2. the scope the controller names in `getScope()` — `hampel_admin_api:read` unless it returns
   content;
3. the admin permission the controller names in `getAdminPermissionId()`.

**The scope is not the boundary.** `allow_all_scopes` satisfies any scope check, and anyone can be
handed such a key. The admin permission is the boundary, and the first gate is what makes it
hold, because a super user key can turn core's permission checks off from the request.

**A new capability gets its own admin permission, and every endpoint its own controller.** Reading
one thing must not imply reading another. Cron and the job queue share `hampelAdminApiJobs`
because they are one capability — cron entries are run by a job.

## Monitoring returns aggregates; member data is a tier behind gates of its own

**Read-only protects the forum and does nothing for the people on it.** Whatever an endpoint
returns ends up in a log, a transcript or a third party's system, so the question for every field
is whether it can carry member data.

**Member data is answered, deliberately, and never by loosening the aggregate tier** — decided on
2026-10-24, against this file's earlier rule that no endpoint returns it. Troubleshooting needs it:
which other accounts used this IP address recently, a member's email to look them up in a helpdesk
or CRM, which upgrades a member holds and what they paid. A tool that cannot answer those sends
the administrator back to the control panel. So:

- **The aggregate tier stays as the rules below have it**, because a monitoring key is handed out
  widely and polled constantly, and nothing in it should need the trust a member lookup does.
- **Each member capability has a scope of its own, an admin permission of its own, and core's own
  permission for the same thing**, as the entries endpoint asks for `viewLogs`. The API must not
  be a way round the control panel. A scope per capability, not one for all member data, so that
  a key can be narrower than its user without a second administrator — decided on 2026-10-24.
- **A member lookup is written to the admin log** through `logMemberLookup()`, after every gate
  and before the answer, whether or not anything is found: the lookup is what is recorded.
- **What core's API already answers is not rebuilt here.** Core's `users/{id}` and
  `users/find-email` give an administrator holding the `user` permission a member's email under
  core's `user:read` scope. An endpoint here returns `user_id` and username, and the client asks
  core for the rest.
- **A payment is reported as that it was made, how much, when and through which provider.** Read
  it from the purchase and upgrade records. Never return `xf_payment_provider_log.log_details`:
  XenForo never holds card numbers, but that column is the provider's own callback and can carry
  the payer's name and address.

The rules that hold in every tier, and the aggregate tier's own:

- **Name the columns a query reads.** The error log summary returns four of ten, and the other six
  are the reason it exists.
- **A count of people is not a person, and an id is.** The summary counts distinct `user_id`s in
  the query and returns the number. No id is selected, grouped on in the output, or kept in PHP.
  A breakdown that would let a count be tied to an individual — per user, per IP address — is
  not a count any more.
- **A field worked out from a withheld column must be closed.** The summary reads the start of
  the message, the trace and the request, and returns only a value from a fixed list: a PHP error
  level, one of four origins, one of three command-line scripts, an installed add-on's id, the
  name of a template the forum has.
  The add-on's id may come from the message when the trace names none; it is still looked up in
  the installed list, and the answer says which it rests on.
  Check the value against the list in code
  — a pattern that merely looks strict is the heuristic this add-on does not have. Anything that
  cannot be closed that way, the message text above all, does not belong in this tier. A job's
  `execute_data` and `unique_key` are the same hazard.
- **A field that can carry personal or request data does not join an existing endpoint.** If it
  is needed, it goes behind a separate endpoint with its own admin permission, used deliberately.
- **`Api\Controller\ErrorEntries` was the first such endpoint, and is the pattern.** It has its own
  scope, so a monitoring key cannot reach it; its own permission; and it asks for core's `viewLogs`
  as well, because the API must not be a way round the control panel. It can only be entered through
  one named entry, it caps what comes back, and it leaves out the request, user and IP address
  unless asked. **Every gate runs before `error_id` is read**, so a request with no `error_id` is
  refused exactly as a real one would be, and answers 400 only when it would have been allowed. That
  is how a client finds out whether a key can read entries without reading one. It is pinned by a
  test; keep the order. **Do not add a list, a search or a date range to it** — the summary is how
  an entry is found, and that is what keeps this endpoint a deliberate act.
- **Free text that leaves the forum goes through `Redaction\TextScrubber`.** The entries endpoint
  returns a message, a trace and a request, and each can quote a credential the options endpoint
  would withhold. Permission to read an error is not permission to read a secret inside it. Any
  new field that returns text from the log - or from anywhere else a third party wrote it - is
  scrubbed the same way and counted in `scrubbed`.
- **Settings are reported as shape unless the key holds the values scope.** `src/config.php` holds
  the database password, and an option can hold an API key. `ValueShape` decides what the shape
  tier shows, by type: a boolean, a null, a number up to 999999.
- **The values tier shows a setting unless it is a secret, and is kept apart the same way.** It
  exists because shape alone could not answer "how is this forum configured" for someone
  troubleshooting it — decided on 2026-10-08, against this file's earlier rule. It is
  kept apart by its own scope, its own admin permission (`hampelAdminApiValues`), core's `option`
  permission for options, and trust tiers. **An endpoint's own permission never grants values**:
  its title tells an administrator what they are granting, and for `config.php` there is no core
  permission to lean on.
- **`Redaction\Redactor` holds every rule, and they are independent on purpose**: the forum's
  override, the declared list, the name, the untrusted tier, the value's shape, a URL's opaque
  part. A change that makes one of them looser has to assume the others have already failed.
  When a rule turns out too eager, narrow that rule and add the name to its test; do not add an
  exception higher up.
- **An add-on is trusted only by declaring for itself**, by answering the
  `hampel_admin_api_redaction` event. `Redaction\Registry` lists XenForo and its official add-ons
  and nothing else, because those are the only ones that cannot answer. **Do not add any other
  add-on to that list, whoever wrote it** — decided on 2026-10-08. A list here would have to
  know which add-ons may be named in a public file, and would go stale without their authors
  knowing. This add-on declares in `Listener`, which is also the reference for anyone else's.
- **`Setup` restores other add-ons' listeners for the event**, because uninstalling this add-on
  makes XenForo delete them. It is the one place this add-on writes a row another add-on owns;
  it writes only what that add-on's own release data registers, and never to its `_output/`.
- **Cast an option's value to its declared type before judging it.** XenForo 2.3.0 hands back
  flags and numbers as strings, and the development forum does not, so the suite cannot catch a
  regression here.
- **A secret is redacted whether or not it is set.** An empty one returned as `""` reads as
  shown; the redaction carries `set` instead.
- **A string is returned in exactly one case: it is on a list the option's definition publishes.**
  A radio or select option's choices come from its `edit_format_params`; the value must equal
  one of them. That is a lookup, not a judgement, and it is what `describeChoice()` is. Where core
  keeps a choice in an option template instead — the mail transport — the list is a constant in
  `Api\Controller\Options`, holding only the values core itself switches on. Add to that table
  only from core's source, never from what a value turned out to be on some forum.
- **An array option is opened one level, and only for members its definition declares.** Keys
  allowed by `*` are data.

## The fault commands plant real faults, and prove themselves by cleaning up

`Fault\Manager` plants things for the endpoints to report; `Cli\Command\Fault*` are thin.

- **A fault is data, never code that errors.** A page node whose template calls a method on
  nothing; a queued job whose class does not exist, and one whose data a core job rejects; a cron
  entry dated in the past; an add-on record with an upgrade left part-way. XenForo trips over
  each by itself. Nothing in the add-on's PHP
  exists to throw, and nothing is reachable from the web. Keep it that way: a route or a
  controller whose purpose is to fail must not be added, however it is guarded.
- **Record first, then build on it.** Each thing is recorded the moment it is saved, before
  anything that depends on it. `clean()` works from the records alone and removes each record only
  after its content has gone. No naming convention identifies a fault.
- **`clean` checks a row is still its fault before deleting it** — a job by its marker as well as
  its id, a cron entry by its class, an add-on record by its title and by being inactive. A record
  is a claim, and ids are reused.
- **Rows for jobs and cron entries are written straight to their tables.** Going through the job
  manager brings the forum's next queue run forward, and the cron entity refuses a callback that
  does not exist.
- **Never plant an add-on record with `is_processing` set.** XenForo stops writing its error log
  while any add-on is processing.
- **`clean` is never gated; `arm` always is.** A forum must be able to remove faults after the
  key has been taken out of its config.
- **A new kind of fault needs its clean-up written and tested before its planting.**
- **Uninstall cleans every batch** before it drops the table.

## The config and validate commands

`admin-api:config` and `admin-api:validate` follow the house pattern for every add-on; the
`xenforo-addon-validate` skill, where available, is the authority on it.

- **The checks live in `Check\HealthCheck`, and the command renders them.** A check returns rows of
  outcome, label and detail. It is about whether this add-on can do its job on this forum, never
  about the forum's health — that is what the endpoints report.
- **`Cli\RendersReport` is a copy of another add-on's trait, namespace changed and nothing else.**
  Do not improve it here: fix the original and take it again.
- **Every `\XF::logError()` and `logException()` in the add-on begins with `AdminApi: `.** A test
  scans the source for one that does not.
- **Extend the checks whenever the add-on comes to depend on something new** — a table, an option,
  another add-on.

## Mechanics

- **No `xf-make:*` command exists for an API scope or an admin permission.** Create the entity so
  that XenForo writes the `_output/` file and its `_metadata.json` entry itself, or write the file
  and export the entity with `DevelopmentOutput::export()`. `xf-dev:import` reads metadata and
  never creates it.
- **Scope ids are lower case.** Core lowercases them on save.
- **A new scope goes in `AbstractController::SCOPES`**, which is what `Setup.php` walks.
- **A release carries a scope's id and nothing else.** `usable_with_oauth_clients` is not exported,
  so an installed scope takes the column default of usable. `Setup.php` sets it back after an
  install, an upgrade and a rebuild. The development install never shows this, because there the
  value comes from `_output/`.
- **`preDispatchController($action, ParameterBag $params)` is untyped**, as it is in core. A
  `string` on `$action` is a fatal at class load.
- **The XenForo floor is 2.2.0, so core is named the way 2.2 names it.** Short names (`XF:ApiKey`)
  and the pre-2.3 class names work on both lines; a 2.3 class name (`...Repository`, `...Finder`,
  `...Service`) is a fatal on 2.2. Six things here exist only from 2.3 and each is guarded where it
  is used: `\XF::accessToken()`, a scope's `usable_with_oauth_clients`, the `xf_failed_job` table,
  `attempts` on `xf_job`, `XF\Job\IconUsage`, and `is_cancelled` on an active user upgrade. **A new
  use of anything from core gets checked against 2.2's source before it is written**, and what 2.2
  lacks is reported as absent, not as zero.
- **The suite runs on 2.3 only** — the test framework needs it — so 2.2 is proved by installing
  the release in a sandbox and asking over HTTP. A change that touches a guarded path needs that
  run again.
- **The PHP floor is 7.4 because that is the oldest PHP the add-on has been run on**, not because
  the code needs it: XenForo 2.3 itself asks for 7.2, and nothing here uses syntax newer than
  that. Lowering the floor means running it there first; do not lower it on a reading of the
  code. Decided 2026-10-09.
- **The dev dependencies need far newer PHP**, so the suite cannot tell you that a newer syntax
  has crept in. `vendor/bin/phpstan analyse` reads the add-on as 7.4 and reports newer syntax,
  but not a function newer than 7.4 (`str_contains()` passes). Keep to 7.4 by hand, and lint the
  release under 7.4 in a sandbox.
- **PHPStan runs at level 8 and must stay clean**; `phpstan.neon` gives the reason for each
  ignored error, and a count that stops matching fails the run. It is analysed against the
  development forum's XenForo 2.3, so a guard for 2.2 reads as always true: ignore it there with
  the reason, as the `accessToken()` guard is, rather than removing the guard. Types are
  declared in docblocks, not in signatures: a native type on a method XenForo or Symfony
  declares untyped is a fatal on load, and 7.4 has no `mixed` or union types.
- **The suite is analysed apart, at level 5**: `vendor/bin/phpstan analyse -c phpstan-tests.neon`.
  Read as 7.4, PHPUnit 12 cannot be parsed, so `tests/` has a config without the floor, and with
  the test framework's fragment, which types the entities a test builds.
- **`Seed/` holds seeders for `Hampel/Seeder`, which is not a dependency.** They are analysed with
  the add-on, kept to 7.4 like it, and removed from a release by `build.json`. They seed the
  server error log, so they run in a sandbox: `TESTING.md` has how.
- **A test only adds rows.** The suite runs on the forum's real tables inside a transaction, and
  `emptyTable()` is a `TRUNCATE` that commits it. `TESTING.md` has the incident.
- **`composer.json` holds dev dependencies only.** `addon.json` has no `composer_autoload`, and
  `build.json` strips `vendor/` from a release.

## Local detail

Anything specific to one machine — paths, hostnames, the running record of work — is in
`CLAUDE.local.md`, which is not committed.
