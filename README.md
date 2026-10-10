# Admin API: read-only operational endpoints for XenForo

A XenForo add-on that adds read-only REST API endpoints for a forum's operational state — the
things the core API does not cover, because it is a content API. For an administrator who wants a
monitoring script, a dashboard or an agent to be able to ask "is anything in the error log?",
"what is installed, at which version?", "is cron running?" and "how is this forum configured?"
without being handed the admin control panel — or a single credential.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

## Requirements

- XenForo 2.2.0 or later
- PHP 7.4 or later

### XenForo 2.2 limitations

Everything works, and three things XenForo 2.2 does not have are reported as absent, never as
zero:

- **It has no table of failed jobs.** The job queue endpoint's `failed` is empty with
  `recorded: false`. A job that fails on 2.2 shows up in the error log summary, if it logged.
- **It keeps no count of attempts.** `retrying` and `max_attempts` are `null`.
- **It has no OAuth**, so there is no access token to refuse and no scope to withhold from OAuth
  clients. `admin-api:config` and `admin-api:validate` report each scope as defined.

The fault commands plant one job fault on 2.2, not two: the second uses a core job that arrived
with 2.3.

## Installation

Install the release zip through the admin control panel, or unpack it and run:

```bash
php cmd.php xf:addon-install Hampel/AdminApi
```

Nothing is reachable until an administrator has a permission and an API key — see *Setting up
access*. The add-on creates one small table, for the commands under
*Testing with faults*, and removes it when uninstalled.

## Setting up access

1. **Pick an administrator for the key to act as**, under *Groups & permissions >
   Administrators*. A super administrator already holds every permission below, so their key
   reaches every endpoint its scopes allow; for anyone else, the permissions decide.
2. **Give that administrator the permissions**, on the same page, for the endpoints you want.
   Skip this for a super administrator.

   | endpoint | admin permission |
   |---|---|
   | `error-log/` | Admin API: View error log summary |
   | `add-ons/` | Admin API: View add-ons |
   | `cron/`, `jobs/` | Admin API: View cron and jobs |
   | `online/` | Admin API: View who is online |
   | `options/` | Admin API: View options |
   | `config/` | Admin API: View config.php |

3. **Create the key.** Under *Setup > API keys*, add a key with:
   - *Key type*: **User key**, with that administrator's username
   - *Allowed scopes*: **Selected scopes only**, with `hampel_admin_api:read` ticked
4. **Test it.**

   ```bash
   curl -H "XF-Api-Key: <key>" https://forum.example.com/api/admin-api/add-ons/
   ```

   A 403 names what is missing; see *Errors*. `php cmd.php admin-api:validate` checks every key
   on the forum.

**Recommendation**: create a new user with no other permissions, make them an administrator and give them just the
"Admin API: ..." permissions you want them to have. This then restricts the api user to these specific roles and
they can't do anything else.

**To see what settings are set to**, not only whether they are set:

- give the administrator *Admin API: View setting values*, and XenForo's *Manage options*;
- add the `hampel_admin_api:values` scope to the key.

**To read individual error log entries**, messages included:

- give the administrator *Admin API: View error log entries*, and XenForo's *View logs*;
- create a second key with only the `hampel_admin_api:error_detail` scope.

## How access is decided

**A request has to pass three checks, and the third is the one that decides.**

1. **The key must belong to one user.** A super user key is refused outright, whatever it is
   acting as, and so are a guest key and an OAuth access token. A super user key can act as any
   user and can ask XenForo to skip its permission checks, so there would be no single
   administrator whose permissions the request could be held to.
2. **The key must hold the scope for that endpoint.** `hampel_admin_api:read` covers every
   endpoint that returns counts and shape, and `hampel_admin_api:error_detail` the one that
   returns error content. A third, `hampel_admin_api:values`, adds to the read scope: with it the
   options and configuration endpoints return what settings are set to, not only whether.
3. **The key's user must hold the admin permission for that endpoint.**

So a key can never do more than the administrator it belongs to. A key with *all scopes* allowed
passes the second check and still fails the third unless its user is an administrator with the
permission. The scopes exist to let an administrator issue a key that can do *less* than they
can — above all, a key for routine monitoring that cannot read a single error message.

A super administrator holds every admin permission, so the per-endpoint permissions constrain
ordinary administrators only.

**The options and configuration permissions cover which settings are set, and no more.** Seeing
what they are set to takes its own permission as well as its own scope, as above.

## Endpoints

Every endpoint is a `GET` under `/api/admin-api/`, and needs the `hampel_admin_api:read` scope
except *Error log entries*, which needs `hampel_admin_api:error_detail` instead. The JSON under
each shows the shape of a response; the figures in it are illustrative.

## Error log summary

`GET /api/admin-api/error-log/`

**Counts, never content.** The summary returns four columns of the server error log — the
exception type, the file, the line and the date — and never the message, the stack trace, the
request, or the user and IP address behind an error, because those are where member data ends up.

Four more things are *worked out* from the message, the trace and the request, and each can only
ever be a value from a fixed list, so nothing a member wrote or sent can come back through them:

| field | worked out from | can only be |
|---|---|---|
| `severity` | the first characters of the message | a PHP error level, `fatal`, `mixed` or null |
| origin | the first key of the recorded request, and whether the job runner is in the trace | `web`, `cli`, `job` or `unknown` |
| originating add-on | the file paths in the trace | the id of an installed add-on, or null |
| template | the path of a compiled template in the trace | the name of a template the forum has, or none |

The user behind an error is read only to be **counted** — how many different members, never
which. The IP address is not read at all.

| parameter | meaning |
|---|---|
| `since` | start of the window, as a Unix timestamp. Defaults to 24 hours before `until`; `0` is the whole log |
| `until` | end of the window. Defaults to now |
| `limit` | how many signatures to return, most frequent first. Default 20, maximum 100 |

```bash
curl -H "XF-Api-Key: $KEY" "https://forum.example.com/api/admin-api/error-log/?since=0&limit=1"
```

```json
{
    "window": {"since": 0, "until": 1791288535, "precedes_log": true},
    "total": 24,
    "oldest_date": 1790305832,
    "newest_date": 1790857181,
    "members": 3,
    "member_errors": 9,
    "by_day": [
        {"day": "2026-09-25", "count": 12},
        {"day": "2026-09-26", "count": 0}
    ],
    "by_type": [
        {"exception_type": "ErrorException", "count": 15, "newest_date": 1790857181}
    ],
    "by_severity": [
        {"severity": "E_USER_WARNING", "count": 5, "newest_date": 1790771401}
    ],
    "by_origin": {"web": 19, "cli": 4, "job": 1, "unknown": 0},
    "by_location": {
        "core": 12, "add_on": 2, "add_on_vendor": 5, "add_on_tests": 0,
        "vendor": 0, "other": 0, "outside": 5
    },
    "by_add_on": [
        {"add_on_id": "XF", "count": 12, "newest_date": 1790771401}
    ],
    "by_origin_add_on": [
        {"add_on_id": "Vendor/AddOn", "count": 6, "from_message": 0, "newest_date": 1790771401}
    ],
    "by_template": [
        {"template": "public:thread_view", "add_on_id": "XF", "count": 4, "newest_date": 1790771401}
    ],
    "examined": {"rows": 24, "complete": true},
    "signatures": [
        {
            "exception_type": "ErrorException",
            "filename": "src/XF/Template/Templater.php",
            "line": 1285,
            "in_install": true,
            "location": "core",
            "add_on_id": "XF",
            "count": 4,
            "first_date": 1790340412,
            "last_date": 1790771401,
            "last_error_id": 1001,
            "members": 2,
            "member_errors": 4,
            "severity": "E_USER_WARNING",
            "origins": {"web": 4, "cli": 0, "job": 0, "unknown": 0},
            "origin_add_ons": [
                {"add_on_id": "Vendor/AddOn", "count": 4, "from_message": 0, "newest_date": 1790771401}
            ],
            "templates": [
                {"template": "public:thread_view", "add_on_id": "XF", "count": 4, "newest_date": 1790771401}
            ]
        }
    ],
    "signature_count": 15,
    "log": {"total": 24, "oldest_date": 1790305832, "newest_date": 1790857181}
}
```

- `last_error_id` is the newest entry of a signature. It is what the error log entries endpoint
  takes, and it is the only way in to that endpoint.
- A **signature** is one exception type at one file and line. `signature_count` is how many there
  are in the window before `limit` is applied; the totals and the two `by_` groups always cover
  the whole window.
- `filename` is relative to the forum's root directory. For a file outside it only the file's own
  name is returned, with `in_install: false` — its directories are the server's layout and can
  hold an account name. `Command line code` is PHP's name for code run with `php -r`.
- **An `ErrorException` says little by itself.** XenForo uses it for a PHP warning, notice or
  deprecation, for a fatal error, and for a message an add-on chose to log — read `severity` to
  tell the first two apart. A logged message has no level and is recorded at the line of
  XenForo's own logging function, so every add-on's logged messages share one signature; the
  originating add-on is what separates them.
- `first_date` equal to `last_date` with a `count` above one is a burst within one second —
  usually one request failing several times, not several requests.
- `add_on_id` is the add-on whose directory the error was raised in, `XF` for the core, or `null`.
  **It says where an error surfaced, not what caused it** — a fault in an add-on's template is
  raised inside the core's templater.
- `location` says what kind of code the file is, from its path: `core`, an `add_on`'s own code,
  `add_on_vendor` or `add_on_tests` for a library bundled with an add-on or its test suite,
  XenForo's own `vendor` libraries, `other` for anything else under the forum's root, or
  `outside`. **An error in `add_on_vendor` or `add_on_tests` carries that add-on's id without
  being that add-on's code** — typically someone running a test suite — so check `location`
  before holding an add-on responsible for the errors counted against it.
- **The originating add-on is the better answer to "whose error is this".** It is the add-on the
  error was raised in or, for an error raised in the core, the first add-on in the calls that led
  there. `null` means no installed add-on's PHP file is in that path — which is not the same as no
  add-on being involved: an error raised while rendering an add-on's *template* has only core
  files in its path. It is the *nearest* add-on, which
  is usually the cause and occasionally one that only wraps a core class every request passes
  through — so read a surprising result as a lead. `by_origin_add_on` is the forum-wide count and
  `origin_add_ons` the same for one signature, since one line of core is reached from many places.
- **`from_message` counts the errors attributed from the message, because the trace named
  nobody.** The two commonest ways an add-on takes a forum down fail inside the core before any
  of the add-on's code runs — a class extension whose class is missing, and a Composer autoload
  file that is not there — so their traces are all core, and only the message names the add-on.
  For an error raised in the core whose trace names no add-on, the start of the message is
  searched for a path under `src/addons/` or a class namespace, and the result is kept only if it
  is an installed add-on's id. No part of the message is returned. It is the weaker lead: a
  message can quote whatever was being handled. `location` still says where the error was
  raised, which for these is `core`.
- **`members` is how many different logged-in members an error happened to**, for the whole window
  and for each signature, and `member_errors` is how many of the errors those were. A guest, the
  command line and a job count as nobody, so `members: 0` beside a non-zero `web` origin means
  guests only. A member behind two signatures is counted in both and once in the window's total,
  so the signatures do not add up to it. This is the number that says whether the entries
  endpoint is worth opening with `request=1`: it tells you someone was affected, and only that
  endpoint tells you who.
- **`by_template` and each signature's `templates` name the template being rendered**, as
  `type:title`, with the add-on it belongs to — null for one made in a style. Every template
  error is raised at the same few lines of XenForo's templater, so this is what tells one from
  another. It is the *innermost* template: an error in a page wrapper is named for the wrapper,
  not for the page inside it. An error with no entry here was not raised while rendering one, or
  was about a template that does not exist.
- **XenForo 2.3.0 itself does not log errors raised from the command line**, so on that version
  `cli` is always zero. Later 2.3 releases log them.
- `by_origin` and each signature's `origins` say how the errors arose: a `web` request, the `cli`,
  or a `job` — which includes every cron entry, however the job was triggered. Errors that are all
  `cli` are someone at a terminal, not members.
- `severity` is the PHP error level of a warning, notice or deprecation, `fatal` for a fatal
  error, and `null` for anything else — an exception that was thrown, or a message that was
  logged — which has no level. `mixed` means one
  signature was seen at more than one.
- `by_day` has one entry per UTC day, quiet days included, from the start of the window or of the
  log, whichever is later. A day before the log begins is left out, not shown as zero. At most 366
  days are returned, the most recent.
- **`examined.complete` is false only in an error storm.** Severity, origin, originating add-on
  and template are worked out from the most recent 20000 rows of the window. Every other figure
  is exact
  whatever the size, so when `complete` is false those four describe the newest part only.
- `log` describes the whole log whatever the window, so an empty window can be told apart from an
  empty log. XenForo prunes the log itself, so `log.oldest_date` is as far back as any window can
  usefully reach.
- **`window.precedes_log` is true when `since` is earlier than the oldest entry the log still
  holds.** The part of the window before that entry counts as zero whether or not anything
  happened then, so a quiet day there is not evidence of a quiet day.

## Error log entries

`GET /api/admin-api/error-entries/`

**The one endpoint that returns content.** Everything else here says that something is wrong and
where; this says why, by returning an entry's message and stack trace as XenForo logged them. A
message can quote whatever was being handled when the error happened — a member's input, an email
address, a query — which is why it sits behind its own scope and its own permission, and why a key
used for monitoring should not hold either.

It takes three things to reach it:

- a key holding `hampel_admin_api:error_detail` — `hampel_admin_api:read` is not enough;
- the *Admin API: View error log entries* admin permission;
- XenForo's own *View logs* admin permission. Everything returned here is in the server error log
  in the admin control panel, and an administrator who cannot open it there cannot read it here.

| parameter | meaning |
|---|---|
| `error_id` | required. The entry to return — a signature's `last_error_id` from the summary |
| `limit` | how many entries to return, counting the one asked for. Default 5, maximum 20 |
| `request` | `1` to add the recorded request, the user id and the IP address to each entry |

```bash
curl -H "XF-Api-Key: $KEY" "https://forum.example.com/api/admin-api/error-entries/?error_id=1001&limit=1"
```

```json
{
    "signature_total": 4,
    "entries": [
        {
            "error_id": 1001,
            "exception_date": 1790771401,
            "exception_type": "ErrorException",
            "message": "Template error: [E_USER_WARNING] Cannot call method canView on a non-object (NULL)",
            "filename": "src/XF/Template/Templater.php",
            "line": 1285,
            "in_install": true,
            "location": "core",
            "severity": "E_USER_WARNING",
            "origin": "web",
            "origin_add_on_id": "Vendor/AddOn",
            "origin_basis": "trace",
            "template": {"template": "public:thread_view", "add_on_id": "XF"},
            "trace": "#0 [internal function]: XF\\Template\\Templater->handleTemplateError(512, ...)\n#1 ...",
            "scrubbed": 0
        }
    ]
}
```

- **There is no way to page through the log.** A request names one entry and gets it back with
  the most recent others raised at the same type, file and line. `signature_total` says how many
  the log holds for that place.
- **The request, the user and the IP address are left out unless `request=1`.** Why an error
  happened is nearly always in the message and the trace; who it happened to rarely matters and is
  the most sensitive part. With `request=1` each entry gains `user_id`, `ip_address` and
  `request` — the URL, referrer and submitted data of a web request, or the command line.
  XenForo removes passwords from the submitted data before logging it, and nothing else.
- **Secrets quoted in an entry are taken out before it is returned**, from the message, the trace
  and the request alike, and replaced with `[withheld]`. An HTTP client's exception quotes the
  URL it called, and a webhook's URL is its credential. Three things are removed: any value this
  forum's own options or `config.php` hold as a secret, wherever it appears; a URL carrying a
  login, a webhook path or an opaque part, which keeps its scheme and host; and anything shaped
  like a token, or given as a parameter named like one. `scrubbed` counts what was removed from
  the entry, so a reader can tell that something was. It is a best effort on free text: a
  secret with no recognisable shape that the forum does not itself hold will get through, which
  is why this endpoint stays behind its own scope and permission.
- `origin_basis` says what `origin_add_on_id` rests on: `trace`, or `message` when the trace named
  no add-on and the message did. It is `null` when there is no originating add-on.
- Paths in `filename` and at the start of each trace line are relative to the forum's root, and a
  file outside it is given by name only, as in the summary. **The message, and the arguments
  inside a trace, are returned as logged** — a database error's message carries the SQL and the
  database name, which the configuration endpoint withholds.

## Add-on inventory

`GET /api/admin-api/add-ons/`

Every add-on the forum knows about — installed, or present on disk and not installed — with
XenForo's own version and the PHP version.

```json
{
    "xf": {"version_id": 2031270, "version_string": "2.3.12"},
    "php_version": "8.3.32",
    "add_ons": [
        {
            "addon_id": "Hampel/AdminApi",
            "title": "Admin API: read-only operational endpoints",
            "is_installed": true,
            "active": true,
            "is_legacy": false,
            "version_id": 1000011,
            "version_string": "1.0.0a1",
            "files_available": true,
            "files_version_id": 1000011,
            "files_version_string": "1.0.0a1",
            "can_upgrade": false,
            "has_pending_changes": false,
            "is_processing": false,
            "pending_action": null
        }
    ]
}
```

`version_*` is what is installed and `files_version_*` is what is on disk. They differ when new
files have been uploaded and the upgrade has not been run, which is what `can_upgrade` reports.

`has_pending_changes` is XenForo's own "this add-on's files have changed" flag, the one behind the
notice in the admin control panel: the add-on's `addon.json` on disk no longer matches what was
installed, by version or by content. With `can_upgrade` false it usually means the file was edited
without a version change, which is routine on a development forum and worth a look on a live one.

An add-on with `is_installed: false` is on disk only, and its `active` is always false.

**`is_processing` and `pending_action` are how a half-finished install or upgrade shows.**
`pending_action` is `install`, `upgrade` or `uninstall` while XenForo is part-way through one,
and null otherwise. With `is_processing` true it is under way now; with `is_processing` false it
started and never finished, which is the state to worry about. Matching versions do not rule
that out — `can_upgrade` only compares what is installed with what is on disk.

## Cron entries

`GET /api/admin-api/cron/`

**XenForo records when a cron entry is next due, and never when it last ran.** So this endpoint
cannot say "the daily clean-up ran at 03:00". What it can say is whether cron is running at all,
from two things: whether any entry is still due in the past, and when the job that drives every
entry last ran.

```json
{
    "now": 1791292600,
    "total": 47,
    "active": 47,
    "overdue": 0,
    "oldest_overdue_date": null,
    "runner": {"queued": true, "next_run": 1791292621, "last_run_date": 1791292562},
    "entries": [
        {
            "entry_id": "xfrmViews",
            "add_on_id": "XFRM",
            "cron_class": "XFRM\\Cron\\Views",
            "cron_method": "runViewUpdate",
            "run_rules": {"day_type": "dom", "dom": [-1], "hours": [-1], "minutes": [2, 17, 32, 47]},
            "active": true,
            "add_on_active": true,
            "next_run": 1791292621,
            "overdue": false
        }
    ]
}
```

- An entry is **overdue** when it is enabled, its add-on is active, and its `next_run` is more
  than an hour in the past. A disabled entry, or one whose add-on is disabled, is never overdue —
  it will not run, so a stale `next_run` is expected.
- `runner` is XenForo's own job that runs whichever entries are due. A `last_run_date` that is
  recent means cron is being triggered; `queued: false` means nothing will run any entry.
- `entries` is ordered by `next_run`, so anything overdue is first.
- **`run_rules` is in UTC**, like every timestamp here: `"hours": [3]` is 03:00 UTC, whatever the
  forum's own time zone. `-1` means every one. `day_type` says whether the day is chosen by `dom`
  (day of the month) or `dow` (day of the week, 0 for Sunday).

## Job queue

`GET /api/admin-api/jobs/`

**Counts by job class, never a job's contents.** A queued job carries whatever it was queued
with — user ids, email addresses, message text — and its unique key often embeds a user or
content id. Neither is read, and nor is a failed job's data. A failed job's exception is read
only for *where* it was raised; its message is never returned.

```json
{
    "now": 1791292600,
    "total": 2,
    "manual": 0,
    "due": 0,
    "stalled": 0,
    "retrying": 0,
    "oldest_due_date": null,
    "by_class": [
        {
            "execute_class": "XF\\Job\\Cron",
            "total": 1,
            "manual": 0,
            "due": 0,
            "stalled": 0,
            "retrying": 0,
            "max_attempts": 0,
            "oldest_trigger_date": 1791292621
        }
    ],
    "failed": {
        "recorded": true,
        "total": 1,
        "oldest_date": 1790151723,
        "newest_date": 1790151723,
        "by_class": [
            {"execute_class": "XF\\Job\\IconUsage", "count": 1, "oldest_date": 1790151723, "newest_date": 1790151723}
        ],
        "signatures": [
            {
                "execute_class": "XF\\Job\\IconUsage",
                "exception_type": "InvalidArgumentException",
                "filename": "src/XF/Service/Icon/UsageAnalyzerService.php",
                "line": 98,
                "in_install": true,
                "location": "core",
                "add_on_id": "XF",
                "origin_add_on_id": null,
                "count": 1,
                "oldest_date": 1790151723,
                "newest_date": 1790151723
            }
        ],
        "examined": {"rows": 1, "complete": true}
    }
}
```

| field | meaning |
|---|---|
| `manual` | jobs that run only when triggered from the admin control panel |
| `due` | automatic jobs whose trigger date has passed. A few is normal; they run on the next request |
| `stalled` | automatic jobs more than an hour past their trigger date — the queue is not being run |
| `retrying` | jobs that have been attempted at least once; `null` on XenForo 2.2 |

**`failed.signatures` says where each failed job failed** — the exception's class, file and line,
with the same `location`, `add_on_id` and `origin_add_on_id` as an error log signature — which
is usually enough to say why without reading a message. Where one exception caused another, the
cause is the one reported.

XenForo stores a failed job's exception as text with its message in the middle, so each part is
checked before it is returned: the class has to be shaped like a class name, a file under the
forum's root has to exist there, and a file outside it is given by name only. A part that does
not pass is `null` — so a `null` filename means "could not be established", which includes a file
that has since been removed by an upgrade. The signatures rest on the most recent 5000 failed
jobs; `examined.complete` says when that is not all of them.

**`failed.recorded` says whether an empty `failed` means anything.** XenForo writes a failed job
to its table only when debug mode is on, so on a production forum `total: 0` with
`recorded: false` means nothing was recorded, not that nothing failed. The server error log is
where a production job failure shows up. `recorded` is the setting as it is now: it does not say
whether failures were being recorded when the ones listed happened, or throughout since.

## Who is online

`GET /api/admin-api/online/`

**Counts of who the forum considers online, split the way that matters when something is
hammering it**: members, guests, and robots the forum recognised — with guests being where robots
that do not identify themselves end up. No member is named and no address is returned.

```json
{
    "now": 1791453581,
    "window_minutes": 15,
    "total": 1840,
    "members": 37,
    "guests": 1512,
    "robots": 291,
    "errors": 12,
    "newest_date": 1791453579,
    "guest_networks": {
        "sessions": 1512, "ipv4": 1490,
        "largest_24": 640, "distinct_24": 212,
        "largest_16": 911, "distinct_16": 87
    },
    "robot_networks": {
        "sessions": 291, "ipv4": 291,
        "largest_24": 44, "distinct_24": 61,
        "largest_16": 120, "distinct_16": 19
    },
    "by_robot": [
        {"robot_key": "googlebot", "count": 120}
    ],
    "by_action": [
        {"controller": "XF\\Pub\\Controller\\ThreadController", "action": "Index", "count": 902, "members": 11, "guests": 804, "robots": 87}
    ]
}
```

- `window_minutes` is the forum's own "online status timeout" option, so these are the figures the
  forum's own online list is built from.
- **`guest_networks` is what tells a crowd from a swarm.** XenForo keeps one session per guest
  address, so a thousand guests is a thousand addresses. `largest_24` is how many of them sit in
  the single busiest /24 network and `distinct_24` how many /24s there are in all; the same for
  /16. Forty guests spread over forty networks are visitors. Six hundred in one /24 is one
  operator. The networks themselves are not returned.
- Network figures are for IPv4. `sessions` less `ipv4` is how many were IPv6, which are counted
  and not grouped.
- `by_robot` names the robots, most numerous first, up to fifty; any beyond that, or with a key
  that is not shaped like one, are counted together under a `null` key.
- `by_action` is what sessions were last doing — the controller and action, never the thread or
  page — for the 25 busiest; the rest are counted together in a last entry whose `controller`
  and `action` are `null`.
- `errors` is how many sessions' last request ended in an error.

For alerting, the numbers to watch are `guests`, `robots` and `guest_networks.largest_24`. This
endpoint reports them; deciding what is too many is the caller's.

## Values and secrets

**By default the next two endpoints say whether a setting is set, and not what to.** That is safe
and, for working out why a forum is misbehaving, close to useless. A key that also holds
`hampel_admin_api:values` is given the values, with the secrets removed.

**What it takes to see values:**

- the key holds `hampel_admin_api:values` as well as `hampel_admin_api:read`;
- the key's user holds this add-on's values permission, `hampelAdminApiValues`, as well as the
  permission for the endpoint. The endpoint's permission never includes values, so granting
  someone the options or configuration endpoint does not show them a setting;
- for options, the key's user also holds XenForo's own *option* admin permission — the options
  pages in the control panel are where these values are otherwise seen, and the API asks for what
  they ask for.

Every response says which it gave: `mode` is `values` or `shape`, and `mode_reason` says why when
it is `shape`.

**A value is shown unless one of these says it is a secret.** They are independent, so a secret
has to get past all of them:

| `reason` | withheld because |
|---|---|
| `override` | this forum's `config.php` says to |
| `known_secret` | it is on the list of secrets for the add-on that owns it |
| `name` | its name, or the name of something it sits inside, reads like a credential: password, secret, token, salt, webhook, an API or private key, a username |
| `untrusted_add_on` | it is text belonging to an add-on nobody has declared the secrets of |
| `value` | it looks like a credential whatever it is called: a known token format, or a long random string |
| `url` | it is a URL carrying a login or a long opaque part, as a webhook does. The scheme and host are kept, since where it points is not the secret |

A withheld part comes back as `null`, and `redactions` lists each one, so nothing is mistaken for
empty. Booleans and numbers are shown.

```json
{"path": "smtp.example.com.password", "segments": ["smtp.example.com", "password"], "reason": "name", "set": true}
```

- **A secret is withheld whether or not it holds anything, and `set` says which.** A blank
  password comes back as `null` with a redaction whose `set` is `false`, never as `""` — an
  empty string would be indistinguishable from a value that had been shown. An empty value that
  is not a secret is returned as it is.
- **`segments` is how to find the part; `path` is for reading.** A key can contain a dot and a
  list has numeric keys, so the dotted `path` cannot always be split back into keys. `segments`
  is one entry per key, each a string. Both are empty when the whole value was withheld.
- **On the configuration endpoint each setting is already a single dotted key**, so a redaction
  there has a path only for one member of a list the setting holds. It also carries
  `inherited_from`: the key above the setting whose redaction took this one with it, or `null`
  when the setting was withheld in its own right.

### Trusted add-ons, and declaring yours

**An add-on's text settings are only shown once somebody has said which of them are secrets.**
XenForo and its official add-ons are listed in this add-on, from their source, because they will
never answer an event of ours. **Every other add-on is untrusted until it declares for itself,
whoever wrote it** — this add-on declares through the same event. Until then its text settings
are reported as set or not; its switches and numbers are still shown.

**To declare, listen for the `hampel_admin_api_redaction` code event** and add an entry for your
own add-on:

```php
public static function adminApiRedaction(array &$declarations)
{
    $declarations['Vendor/AddOn'] = [
        'options' => ['vendorApiKey', 'vendorService.password'],
        'config' => ['vendorAddOn' => ['vendorAddOn.secret']],
    ];
}
```

- `options` lists the options that are secrets: an option id, or `optionId.member` for one member
  of an array option.
- `config` lists the top-level `config.php` keys your add-on reads, each with the dotted keys
  under it that are secrets.
- **An add-on with no secrets declares an empty list.** That is still a declaration, and it is
  what moves the add-on's settings from "set" to shown.
- The event only fires when the Admin API is installed, so listening costs nothing where it is
  not, and your add-on does not need to require this one. The name and value rules above apply
  whatever you declare.
- **Uninstalling the Admin API removes every listener for its event**, yours included: XenForo
  deletes an event's listeners with the event. Installing it again, or rebuilding it, puts back
  each one that an installed add-on's release registers.

### Overriding it on one forum

For a secret the rules miss, or a value they hide needlessly, in `src/config.php`:

```php
$config['hampelAdminApi']['redact']['options'] = ['someOption', 'otherOption.member'];
$config['hampelAdminApi']['redact']['config'] = ['some.config.key'];
$config['hampelAdminApi']['show']['options'] = ['publicSiteKey'];
```

`redact` wins over every rule and `show` wins over `redact`. `show` is also how to see the text
settings of an add-on that has not declared, one setting at a time.

## Options

`GET /api/admin-api/options/`

With the values scope, each option carries its `value` and `redactions` as described above; the
rest of this section is what a key without it is given.

**The value is the one in force.** A `config.php` that replaces an option in code changes what the
forum runs with and not what is stored; `overridden` is true when the two differ.

**Which options are set and which have been changed — not what they hold.** An option can be an
API key, an SMTP password or a member's email address, and nothing about its name reliably says
so. So the rule involves no judgement about what a value looks like:

| the value is | returned |
|---|---|
| a boolean, or null | as it is |
| a number no larger than 999999 | as it is |
| a larger number | withheld — it is as likely an account number as a limit |
| a string that is one of the choices the option's own definition lists | as it is |
| any other string, an array, or anything else | withheld, always |

**A choice is returned because it is on a published list, not because it looks harmless.** A
select or radio option's definition names every value it can take, and that definition ships
with XenForo or the add-on. If the stored value is exactly one of those, it is returned; a value
that is not on the list is withheld like any other string, and so is every free-text option.

A withheld value still says its `type`, whether it is `set` (not null, not an empty string, not an
empty array), whether it `is_default`, and for an array how many members it has.

| parameter | meaning |
|---|---|
| `add_on_id` | only options belonging to this add-on |
| `changed` | `1` to list only options that differ from their default |
| `option_id` | one option id, several separated by commas, or an array |

**To ask what one option is set to, ask for it by id.** `option_id` returns exactly the options
named, whether they were changed or not, and overrides the other two filters — so an option still
at its default is read, not inferred from its absence. `not_found` lists any id this forum has no
option by, which is not the same as one at its default: the set of options differs between
XenForo versions and between forums. The same permissions and redaction apply as to the list.

```bash
curl -H "XF-Api-Key: $KEY" "https://forum.example.com/api/admin-api/options/?option_id=useFriendlyUrls"
```

```json
{
    "total": 384,
    "changed": 45,
    "by_add_on": [
        {"add_on_id": "XF", "total": 301, "changed": 31}
    ],
    "options": [
        {
            "option_id": "boardTitle",
            "add_on_id": "XF",
            "data_type": "string",
            "is_default": false,
            "type": "string",
            "set": true,
            "disclosed": false,
            "value": null,
            "count": null,
            "members": null
        },
        {
            "option_id": "boardActive",
            "add_on_id": "XF",
            "data_type": "boolean",
            "is_default": true,
            "type": "boolean",
            "set": true,
            "disclosed": true,
            "value": true,
            "count": null,
            "members": null
        },
        {
            "option_id": "emailTransport",
            "add_on_id": "XF",
            "data_type": "array",
            "is_default": false,
            "type": "array",
            "set": true,
            "disclosed": false,
            "value": null,
            "count": 7,
            "members": {
                "emailTransport": {"type": "string", "set": true, "disclosed": true, "value": "smtp", "count": null}
            }
        }
    ]
}
```

- **`members` opens an array option one level**, for the members its definition declares by
  name — whether registration is `enabled`, whether it needs `moderation` — each under the same
  rule. It is `null` for anything that is not an array or declares none. A definition can also
  allow any key at all; a member present only on that allowance is not listed, because such a
  key is data, so `count` can be larger than the number of members shown.
- **The mail transport is `emailTransport.members.emailTransport`.** `sendmail` and `smtp`, the
  two XenForo supplies, are returned. A transport an add-on supplies is withheld, which with
  `set: true` reads as "something other than those two". The host, the login and the password
  are not declared members and are not listed at all.
- **`value: null` with `disclosed: false` means withheld**, not empty. Read `set` for that.
- **`set` is not "on".** It means the option holds something: a boolean that is `false` is still
  `set: true`. Read `value` for whether a flag is on.
- **`changed` counts more than an administrator's decisions.** XenForo keeps a few of its own
  values as options — `currentVersionId`, `jsLastUpdate` — and they differ from their defaults on
  every forum.
- Numbers are decimal. A file mode shows as `436`, which is octal `0664`.
- `total`, `changed` and `by_add_on` count every option matching `add_on_id`; `changed=1` shortens
  the list, not the counts.
- An option left at its default holds whatever the add-on shipped, which is public. `is_default`
  therefore tells you the value of an unchanged option without this endpoint returning it.

## Configuration

`GET /api/admin-api/config/`

With the values scope each setting carries its `value` and `redactions`, as under *Values and
secrets*. The database password and username and the global salt are always withheld; the
database host and name are shown. A top-level key XenForo does not define is untrusted until an
add-on claims it in its declaration. What follows is what a key without the scope is given.

The settings in force from `src/config.php` merged over XenForo's defaults, one entry per dotted
key. A boolean, a null and a small number are returned, and a string never is — configuration has
no definitions listing choices, so the exception options have does not apply here. `db.password`
is reported as a string that is set, and that is all.

| parameter | meaning |
|---|---|
| `changed` | `1` to list only settings that differ from XenForo's default, or have none |
| `key` | one dotted key, several separated by commas, or an array |

**`key` asks for particular settings, changed or not.** A key matches itself and everything
beneath it, so `key=db` is every database setting and `key=debug` is one. `not_found` lists any
key that matched nothing, and `total` counts what matched.

```json
{
    "total": 87,
    "changed": 3,
    "settings": [
        {
            "key": "db.password",
            "has_default": false,
            "is_default": false,
            "type": "string",
            "set": true,
            "disclosed": false,
            "value": null,
            "count": null
        },
        {
            "key": "db.port",
            "has_default": false,
            "is_default": false,
            "type": "integer",
            "set": true,
            "disclosed": true,
            "value": 3306,
            "count": null
        },
        {
            "key": "debug",
            "has_default": true,
            "is_default": false,
            "type": "boolean",
            "set": true,
            "disclosed": true,
            "value": true,
            "count": null
        }
    ]
}
```

- A nested setting is followed while its keys are names, to five levels. A list is reported as one
  array with a `count`, because its members are values.
- `has_default: false` marks a key XenForo does not define — one your `config.php` or an add-on
  introduced. Every such key counts as changed, and that includes the `db.*` settings every forum
  has, so `changed` is never zero.
- **Key names are returned.** If your `config.php` uses a secret as an array *key*, this endpoint
  will show it; a value never is.

## Checking a forum is set up

Two commands answer "is it set up" and "does it work" from the command line, on any forum,
including one you cannot log in to.

```bash
php cmd.php admin-api:config
php cmd.php admin-api:validate
```

**`admin-api:config` reads and prints, and changes nothing.** It shows whether the REST API is on,
each scope, how many administrators hold each admin permission, and every API key that holds one
of the scopes — its id, its title, whose it is, what it holds and when it was last used. A key's
value is never shown.

**`admin-api:validate` proves it.** It checks that the API is enabled, that the scopes, admin
permissions and routes are in place, that at least one key can actually read, and that each
endpoint's reader runs. It names any key that holds a scope and can never use it, with the reason:
a super user key, an inactive key, a user who is not an administrator or lacks the permission.

| | all fine | warnings only | any failure |
|---|---|---|---|
| `admin-api:validate` | 0 | 0 | 1 |
| `admin-api:validate --strict` | 0 | 2 | 1 |

- **A warning exits 0**, so `admin-api:validate && next-step` can gate a deploy. A forum on which
  no key can read yet is a warning: nothing is broken, and the add-on does nothing.
- **`--strict` makes a warning exit 2**, for a monitor that wants to hear of one, and changes
  nothing else. **This is not the monitoring-plugin numbering**, where 1 is a warning: here 1
  always means failed.
- **By default it logs one error to the server error log, reads it back through the summary and
  removes it.** That is the only proof that what the forum logs is what the endpoints read, and
  anything watching the error log will see it for a moment. `--unattended` skips it, for a gate
  nobody is watching. The entry begins `AdminApi: ` like every row this add-on logs.
- It warns when the fault commands below are enabled, or have left anything planted — which on a
  live forum is the thing you would want to hear.

## Testing with faults

**On a healthy forum these endpoints have nothing to report**, which makes them hard to try out
and harder to build anything on. Three commands plant faults on purpose, so that a test forum has
something real for every endpoint to describe: errors raised by real requests with members behind
them, jobs left waiting, an overdue cron entry, and an add-on whose upgrade never finished.

**They are for a test forum, and they refuse to plant anything unless that forum says so.** Add
this to `src/config.php`, and only where errors appearing in the log is what you want:

```php
$config['hampelAdminApi']['faults'] = true;
```

The switch is in the forum's own configuration and not an option to the command, so the command
cannot be aimed at the wrong forum. Nothing here is reachable over the web or through the API.
Anything else that watches the server error log — an alert, a digest — will see these errors too,
which is the reason for the switch.

```bash
php cmd.php admin-api:fault-arm --batch=demo --members=3 > armed.json
php cmd.php admin-api:fault-list
php cmd.php admin-api:fault-clean demo
```

- **`fault-arm`** plants one fault of each kind below, or only those named in `--faults`. It
  prints what it made as JSON — the page's route, and each member with a password, which is the
  only time those can be read, so keep the output. XenForo may print lines of its own after the
  JSON.
- **`fault-list`** shows what is planted and whether planting is enabled.
- **`fault-clean`** removes a batch, or every batch when none is named: everything planted, and
  the error log entries it raised. **It is never refused**, so a forum
  that has had the switch taken away can still get rid of what it holds.

| `--faults=` | what is planted | what the endpoints then show |
|---|---|---|
| `page` | a page whose template calls a method on nothing, and members to view it as | nothing until it is viewed; then a template error per view, `web` origin, the members counted, the template named |
| `jobs` | two overdue jobs: one whose class does not exist, one whose data its class rejects | both `stalled`; once the queue runs, an error each with `job` origin, and the jobs gone |
| `cron` | a cron entry two hours past its next run | `overdue`, until cron next runs and moves it on |
| `add-on` | a record of an inactive add-on with an upgrade left part-way | `pending_action: upgrade` with `is_processing` false |

**None of these is code that fails.** Each is a row that XenForo itself trips over, the way it
would on a forum where something had gone wrong: a job left behind by an add-on that was removed,
a template with a mistake in it. The add-on contains nothing that throws.

**The jobs and the cron entry are faults only until XenForo deals with them**, which it does the
next time the queue runs — on a forum people are browsing, within a minute or two. Read the
`stalled` and `overdue` state before that, and the errors after. A sandbox that nobody is browsing
runs its queue only when asked: request `job.php`, or run `xf:run-jobs`. On XenForo 2.3.0 use
`job.php`, because that version logs nothing from the command line.

Everything is recorded as it is created, so a run that stops half-way can still be cleaned, and
`fault-clean` touches nothing it has no record of — and for each record it checks that the row
is still the fault it planted before deleting it. Uninstalling the add-on cleans every batch
first.

The page raises nothing until somebody views it. The add-on's repository has a script,
`_driver/drive-faults.php`, that views it as a guest and as each member over HTTP; it is not in
the release, and any HTTP client that can log in will do instead. Afterwards the error log
summary shows the errors with a `web` origin, the members counted, and the page's template named.

## Errors

Every refusal of access is a `403`, so read the error `code` rather than the status.

| code | meaning |
|---|---|
| `hampel_adminapi_user_api_key_required` | the key is a super user key, a guest key, or an OAuth token was used |
| `missing_scope` | the key does not hold the scope for this endpoint; the message names it |
| `do_not_have_permission` | the key's user does not hold the admin permission for this endpoint |

The error log entries endpoint also answers `400` with `hampel_adminapi_error_id_required` when no
`error_id` is given, and `404` when there is no such entry.

## Licence

MIT — see `LICENSE.md`.
