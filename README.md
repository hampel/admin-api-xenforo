# Admin API: read-only operational endpoints for XenForo

A XenForo 2.3 add-on that adds read-only REST API endpoints for a forum's operational state — the
things the core API does not cover, because it is a content API. For an administrator who wants a
monitoring script, a dashboard or an agent to be able to ask "is anything in the error log?",
"what is installed, at which version?" and "is cron running?" without being handed the admin
control panel.

By [Simon Hampel](https://xenforo.com/community/members/sim.4264/).

## Requirements

- XenForo 2.3.0 or later
- PHP 7.4 or later

## Installation

Install the release zip through the admin control panel, or unpack it and run:

```bash
php cmd.php xf:addon-install Hampel/AdminApi
```

Nothing is reachable until an administrator has been given a permission and a key has been
created — see *Access*.

## Access

**A request has to pass three checks, and the third is the one that decides.**

1. **The key must belong to one user.** A super user key is refused outright, whatever it is
   acting as, and so are a guest key and an OAuth access token. A super user key can act as any
   user and can ask XenForo to skip its permission checks, so there would be no single
   administrator whose permissions the request could be held to.
2. **The key must hold the `hampel_admin_api:read` scope.**
3. **The key's user must hold the admin permission for that endpoint.**

So a key can never do more than the administrator it belongs to. A key with *all scopes* allowed
passes the second check and still fails the third unless its user is an administrator with the
permission. The scope exists to let an administrator issue a key that can do *less* than they can.

A super administrator holds every admin permission, so the per-endpoint permissions constrain
ordinary administrators only.

To set it up:

1. Under *Groups & permissions > Administrators*, give the administrator the permission for each
   endpoint they should reach.
2. Under *Setup > API keys*, create a key of type *User* for that administrator, with only the
   `hampel_admin_api:read` scope.

| endpoint | admin permission |
|---|---|
| `GET /api/admin-api/error-log/` | Read the server error log summary through the API |
| `GET /api/admin-api/add-ons/` | Read the add-on inventory through the API |
| `GET /api/admin-api/cron/` | Read cron and job queue state through the API |
| `GET /api/admin-api/jobs/` | Read cron and job queue state through the API |

## Error log summary

`GET /api/admin-api/error-log/`

**Counts, never content.** The summary is built from four columns of the server error log — the
exception type, the file, the line and the date. It never reads the message, the stack trace, the
request, or the user and IP address behind an error, because those are where member data ends up.

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
    "window": {"since": 0, "until": 1791288535},
    "total": 24,
    "oldest_date": 1790305832,
    "newest_date": 1790857181,
    "by_type": [
        {"exception_type": "ErrorException", "count": 15, "newest_date": 1790857181}
    ],
    "by_add_on": [
        {"add_on_id": "XF", "count": 12, "newest_date": 1790771401}
    ],
    "signatures": [
        {
            "exception_type": "ErrorException",
            "filename": "src/XF/Template/Templater.php",
            "line": 1285,
            "add_on_id": "XF",
            "count": 4,
            "first_date": 1790340412,
            "last_date": 1790771401
        }
    ],
    "signature_count": 15,
    "log": {"total": 24, "oldest_date": 1790305832, "newest_date": 1790857181}
}
```

- A **signature** is one exception type at one file and line. `signature_count` is how many there
  are in the window before `limit` is applied; the totals and the two `by_` groups always cover
  the whole window.
- `filename` is relative to the forum's root directory. A file outside it is returned as logged.
- `add_on_id` is the add-on whose directory the error was raised in, `XF` for the core, or `null`.
  **It says where an error surfaced, not what caused it** — a fault in an add-on's template is
  raised inside the core's templater.
- `log` describes the whole log whatever the window, so an empty window can be told apart from an
  empty log. XenForo prunes the log itself, so `log.oldest_date` is as far back as any window can
  usefully reach.

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
            "has_pending_changes": false
        }
    ]
}
```

`version_*` is what is installed and `files_version_*` is what is on disk. They differ when new
files have been uploaded and the upgrade has not been run, which is what `can_upgrade` reports.

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

## Job queue

`GET /api/admin-api/jobs/`

**Counts by job class, never a job's contents.** A queued job carries whatever it was queued
with — user ids, email addresses, message text — and its unique key often embeds a user or
content id. Neither is read, and nor is the data or exception text of a failed job.

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
        "newest_date": 1790151723,
        "by_class": [
            {"execute_class": "XF\\Job\\IconUsage", "count": 1, "newest_date": 1790151723}
        ]
    }
}
```

| field | meaning |
|---|---|
| `manual` | jobs that run only when triggered from the admin control panel |
| `due` | automatic jobs whose trigger date has passed. A few is normal; they run on the next request |
| `stalled` | automatic jobs more than an hour past their trigger date — the queue is not being run |
| `retrying` | jobs that have been attempted at least once |

**`failed.recorded` says whether an empty `failed` means anything.** XenForo writes a failed job
to its table only when debug mode is on, so on a production forum `total: 0` with
`recorded: false` means nothing was recorded, not that nothing failed. The server error log is
where a production job failure shows up.

## Errors

Every refusal is a `403`, so read the error `code` rather than the status.

| code | meaning |
|---|---|
| `hampel_adminapi_user_api_key_required` | the key is a super user key, a guest key, or an OAuth token was used |
| `missing_scope` | the key does not hold `hampel_admin_api:read` |
| `do_not_have_permission` | the key's user does not hold the admin permission for this endpoint |

## Licence

MIT — see `LICENSE.md`.
