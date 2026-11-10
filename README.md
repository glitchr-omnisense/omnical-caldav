# omnical/caldav

Omnical's CalDAV provider (RFC 4791), under any PHP host: the calendars of iCloud, Fastmail,
Nextcloud or any sabre/dav server, their events read and written, and busy time. Built on
[sabre/xml](https://sabre.io/xml/) (every request and Multi-Status answer) and
[sabre/vobject](https://sabre.io/vobject/) (iCalendar both ways, recurrences, free/busy), over the
application's HTTP client; credentials by reference.

```
composer require omnical/caldav
```

```php
use Omnical\Caldav\CaldavFactory;
use Omnical\Model\EventQuery;
use Omnical\Registry;

$registry = new Registry([new CaldavFactory($http)], [
    'icloud' => ['factory' => 'caldav', 'options' => [
        'base_uri' => 'https://caldav.icloud.com/',
        'username' => 'anna@icloud.com',
        'password' => 'ICLOUD_APP_PASSWORD',          // the NAME of the secret: an environment variable by default
    ]],
]);

$calendars = $registry->calendarEvents('icloud')->calendars();
$events = $registry->calendarEvents('icloud')->events(new EventQuery($calendars[0]->id, $from, $to));
```

## Options

| Option | |
|---|---|
| `base_uri` | required: the server's address - `https://caldav.icloud.com/`, `https://caldav.fastmail.com/`, `https://cloud.example.org/remote.php/dav` |
| `username`, `password` | Basic authentication; `password` is a secret's reference (`CALDAV_PASSWORD`, `env:CALDAV_PASSWORD`), read through the factory's `SecretsInterface` (`EnvSecrets` by default) at each call: an app-specific password for iCloud and Fastmail, an app password for Nextcloud |
| `token` | a bearer token's reference (Google's CalDAV takes OAuth only); an `AccessTokenInterface` given to the factory (`RefreshingAccessToken`) refreshes itself on a 401 instead |
| `calendar_home` | the calendar home's address: no discovery |
| `user_agent`, `timeout` | sent on every request; seconds |
| `policy`, `usage` | a site's tightening of the policy (its server's limits), what the site is |

`new CaldavFactory(?HttpClientInterface $http, ?SecretsInterface $secrets, ?AccessTokenInterface $token)`.

## What it does

| Call | Request |
|---|---|
| discovery | `PROPFIND` Depth 0 `current-user-principal` on `base_uri` (then `/.well-known/caldav`, RFC 6764), `PROPFIND` Depth 0 `calendar-home-set` on the principal; hrefs resolved against the address that answered (iCloud's home is on a `pNN-caldav.icloud.com` host) |
| `calendars()` | `PROPFIND` Depth 1 on the home: collections whose `resourcetype` holds `C:calendar` and whose `supported-calendar-component-set` holds `VEVENT`; name, description, colour (Apple's `calendar-color`), zone (`calendar-timezone`'s TZID), `getctag` or `sync-token` as `Calendar::$version`, read only when `current-user-privilege-set` holds none of `write`, `write-content`, `bind`, `all` |
| `events()` | `REPORT calendar-query` (VCALENDAR / VEVENT `time-range` in UTC) for `getetag` and `calendar-data`; occurrences expanded here (`VCalendar::expand()`) when `EventQuery::$expand`, the same on every server; series with their `Recurrence` (RRULE, EXRULE, RDATE, EXDATE kept as data) otherwise; `search` filtered here |
| `event()` | `GET` the resource (404: null); an occurrence by its id |
| `create()` | `PUT {calendar}{uid}.ics` with `If-None-Match: *`; the `ETag` answered, or a `PROPFIND getetag` when the server sends none (RFC 4791 §5.3.4: it changed what it stored) |
| `update()` | `GET` the stored object, the Event's properties written over its own (alarms, `X-` properties and overridden occurrences kept, `SEQUENCE` one more), `PUT` with `If-Match` (the Event's etag, or the one just read): `ConflictException` on 412 |
| `delete()` | `DELETE` with `If-Match` when an etag is given; 404 is nothing |
| `freeBusy()` | `REPORT free-busy-query` (RFC 4791 §7.10) on each calendar; where the server refuses it (400, 403, 405, 415, 422, 501: Google, iCloud), a `calendar-query` of the span and sabre's `FreeBusyGenerator` here; `FBTYPE` read into `FreeBusyStatus`; a calendar not found answers `error: notFound` |

Identifiers are the server's hrefs: a calendar's its collection's, an event's its resource's. An
occurrence of a series is the resource's href, `#`, its RECURRENCE-ID in UTC
(`/dav/calendars/anna/work/x.ics#20261026T130000Z`): it is read, not written apart (`update()` and
`delete()` throw `NotSupportedException`: write the series, add an EXDATE).

Time zones: an event's zone is its DTSTART's TZID as PHP knows it - Windows names, as Exchange
writes them, mapped by sabre (`W. Europe Standard Time` is `Europe/Berlin`). Written events carry
their TZID and a VTIMEZONE built from PHP's transitions around them (RFC 5545 §3.2.19). An all-day
event is written with `VALUE=DATE`.

## Usage policy

`policy.json` (omnisense/contracts' schema): CalDAV is a protocol, so its rate limits are unknown
(`null`) and its rights are each server's - iCloud publishes no CalDAV terms nor limits, Fastmail
and Nextcloud document none. A site tightens the policy with its server's own limits (Google's
CalDAV shares the Calendar API's quotas: 600 a minute per user, counted per user through
`Registry::calendarEvents($name, $user)`). The notes say what was read on 2026-11-10: iCloud's
app-specific passwords (two-factor authentication, 25 at most, revoked with the account's
password), Fastmail's app passwords (no CalDAV on its Basic plan), Google's OAuth-only CalDAV.

## Verified, and not

- Checked against **sabre/dav 4.7.1 in the same process** (the server Nextcloud is built on): its
  CalDAV, ACL and Basic authentication plugins over its PDO backends on SQLite - discovery, a
  calendar's properties, create (201 with its ETag), read, update, a stale etag refused (412) on
  update and delete, delete, a weekly series with an EXDATE expanded across the end of summer
  time and kept as data, an all-day event, free-busy-query (busy, tentative, a transparent event
  left out, a missing calendar). Every write was stored as sent: sabre repaired nothing
  (`X-Sabre-Ew-Gross`, asserted after each test).
- iCloud and Nextcloud answers in `Tests/Fixtures` are written from RFC 4791 and the shapes these
  servers are known to answer (iCloud's partition host, its VTODO and shared calendars, its refusal
  of free-busy-query; an Exchange event with a Windows zone name), **not recorded from an account**:
  none was used, and no request reached iCloud, Fastmail, Nextcloud or Google.
- Not done: scheduling (RFC 6638: invitations, others' free/busy through the outbox), sync-collection
  (RFC 6578), MKCALENDAR, VTODO, writing one occurrence of a series apart, `/.well-known/caldav`
  redirects beyond what the HTTP client follows.

Checked: 14 tests, PHPStan at level max with an empty baseline, BareTest (nothing loads but
omnisense/omnical, omnisense/contracts, sabre's libraries and Symfony's HTTP client contracts).

## Licence

MIT
