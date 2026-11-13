# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnical` `^1.0` with no stability flag, so `composer require omnical/caldav` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- First version: CalDAV (RFC 4791) as an omnical provider - discovery (RFC 5397, RFC 6764),
  calendars, events read (expanded with sabre/vobject, or series with their recurrence as data)
  and written (`If-None-Match: *`, `If-Match`), free/busy (`free-busy-query`, or computed from
  the events), Basic or bearer credentials by reference, its usage policy as data (`policy.json`).
