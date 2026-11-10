# omnical/caldav

CalDAV (RFC 4791) as an omnical provider: `calendar-events` (read and write) and `free-busy`.

## Installation

```
composer require omnical/caldav
```

It requires omnisense/omnical, sabre/vobject (^4.5), sabre/xml (^2.2 or ^4.0) and
symfony/http-client. Give the factory the application's
`Symfony\Contracts\HttpClient\HttpClientInterface`, or let it make one.

## In plain PHP

```php
use Omnical\Auth\EnvSecrets;
use Omnical\Caldav\CaldavFactory;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusy;
use Omnical\Model\FreeBusyQuery;
use Omnical\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new CaldavFactory(HttpClient::create(), new EnvSecrets())], [
    'cabinet' => ['factory' => 'caldav', 'options' => [
        'base_uri' => 'https://cloud.example.org/remote.php/dav',
        'username' => 'anna',
        'password' => 'NEXTCLOUD_APP_PASSWORD',
    ]],
]);
$calendar = $registry->calendarEvents('cabinet');
$work = $calendar->calendars()[0];

// The week's appointments, each occurrence of a series on its own.
$week = $calendar->events(new EventQuery($work->id, new DateTimeImmutable('monday this week'), new DateTimeImmutable('monday next week')));

// One written, then moved: its etag guards against writing over a change made elsewhere.
$paris = new DateTimeZone('Europe/Paris');
$rdv = $calendar->create(new Event('Consultation', new DateTimeImmutable('2026-11-16 09:00', $paris), new DateTimeImmutable('2026-11-16 09:45', $paris), calendarId: $work->id));
$calendar->update($rdv->moved(new DateTimeImmutable('2026-11-16 10:00', $paris), new DateTimeImmutable('2026-11-16 10:45', $paris)));

// The hours free on a day, an hour long at least.
$busy = $registry->freeBusy('cabinet')->freeBusy(new FreeBusyQuery([$work->id], new DateTimeImmutable('2026-11-17 08:00', $paris), new DateTimeImmutable('2026-11-17 18:00', $paris)));
$free = FreeBusy::common($busy, new DateTimeImmutable('2026-11-17 08:00', $paris), new DateTimeImmutable('2026-11-17 18:00', $paris), 3600);
```

## Servers

| Server | `base_uri` | Credentials |
|---|---|---|
| iCloud | `https://caldav.icloud.com/` (the home is on a `pNN-caldav.icloud.com` host, found by discovery) | Apple Account address + an app-specific password (two-factor authentication needed; 25 at most; all revoked when the account's password changes) |
| Fastmail | `https://caldav.fastmail.com/` | address + an app password (no CalDAV on the Basic plan) |
| Nextcloud | `https://<host>/remote.php/dav` | user + an app password |
| any sabre/dav, Radicale, Baïkal... | the server's DAV root | its own |
| Google | `https://apidata.googleusercontent.com/caldav/v2/` with `calendar_home` `https://apidata.googleusercontent.com/caldav/v2/<calendar id>/` | OAuth 2.0 only: a `RefreshingAccessToken` (omnical's core) given to the factory; omnical/google is the better way to Google |

Google and iCloud do not answer `free-busy-query`: busy time is then computed from the span's
events with sabre's FreeBusyGenerator, which reads TRANSP and STATUS as RFC 4791 §7.10 says.

## Testing an application

`Tests/SabreHttpClient.php` shows how: an `HttpClientInterface` whose requests a sabre/dav server
in the same process answers, over SQLite in memory (`Tests/Fixtures/schema.sqlite.sql`). It is a
test of this package, not part of it (sabre/dav is a development requirement only).
