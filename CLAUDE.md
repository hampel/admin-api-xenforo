# Working on the Admin API add-on

Standing guidance for a session working in this repository: what the add-on is for, the rules its
design rests on, and where the detail lives. `README.md` documents the endpoints and `TESTING.md`
the surfaces and fragile points; this file does not repeat them.

## The add-on is a read-only window onto operational state, and nothing else

It adds REST endpoints for what the core API leaves out — the server error log and the add-on
inventory so far. It has no writes, no options, no schema and no `Setup.php`.

**It does not speak any agent protocol and must not grow one.** A consumer — a monitoring script,
or a separate tool that presents these endpoints to an agent — sits outside the forum. If a
protocol endpoint inside XenForo is ever wanted, it is a second add-on that requires this one, so
an administrator opts into a new listener by name.

## A key's ceiling is its own user's admin permissions

`Api\Controller\AbstractController` runs three gates before any action, and every controller
extends it:

1. a user key only — super user keys, guest keys and OAuth tokens are refused;
2. the `hampel_admin_api:read` scope;
3. the admin permission the controller names in `getAdminPermissionId()`.

**The scope is not the boundary.** `allow_all_scopes` satisfies any scope check, and anyone can be
handed such a key. The admin permission is the boundary, and the first gate is what makes it
hold, because a super user key can turn core's permission checks off from the request.

**A new endpoint gets its own controller and its own admin permission.** Reading one thing must
not imply reading another.

## An endpoint returns aggregates, and personal data never

**Read-only protects the forum and does nothing for the people on it.** Whatever an endpoint
returns ends up in a log, a transcript or a third party's system, so the question for every field
is whether it can carry member data.

- **Name the columns a query reads.** The error log summary reads four of ten, and the other six
  are the reason it exists.
- **A field that can carry personal or request data does not join an existing endpoint.** If it
  is needed, it goes behind a separate endpoint with its own admin permission, used deliberately.
- **Configuration is reported as shape, never as values.** `src/config.php` holds the database
  password; an endpoint returning configuration is a credential leak.

## Mechanics

- **No `xf-make:*` command exists for an API scope or an admin permission.** Create the entity so
  that XenForo writes the `_output/` file and its `_metadata.json` entry itself, or write the file
  and export the entity with `DevelopmentOutput::export()`. `xf-dev:import` reads metadata and
  never creates it.
- **Scope ids are lower case.** Core lowercases them on save.
- **`preDispatchController($action, ParameterBag $params)` is untyped**, as it is in core. A
  `string` on `$action` is a fatal at class load.
- **The PHP floor is 7.4 and the dev dependencies need far newer**, so the suite cannot tell you
  that a newer syntax has crept in. Keep to 7.4 by hand.
- **`composer.json` holds dev dependencies only.** `addon.json` has no `composer_autoload`, and
  `build.json` strips `vendor/` from a release.

## Local detail

Anything specific to one machine — paths, hostnames, the running record of work — is in
`CLAUDE.local.md`, which is not committed.
