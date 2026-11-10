<?php

namespace Omnical\Caldav\Tests;

use Omnical\Auth\ArraySecrets;
use Omnical\Caldav\CaldavFactory;
use Omnical\Caldav\CaldavProvider;
use Omnical\Exception\AuthenticationException;
use Omnical\Exception\InvalidConfigException;
use Omnical\Model\Attendee;
use Omnical\Model\Calendar;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusyQuery;
use Omnical\Model\ParticipationStatus;
use Omnical\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Answers shaped as iCloud's and Nextcloud's are: written from RFC 4791 and
 * the shapes these servers are known to answer (iCloud's partition host,
 * its VTODO and shared calendars, a 403 to free-busy-query; an event
 * synchronised from Exchange with a Windows time zone name), not recorded
 * from an account - none was used. The sabre/dav server test is the
 * conformance check; these are the servers' quirks.
 */
final class RecordedTest extends TestCase
{
    public function testICloudsCalendarsAreFoundOnItsPartitionHost(): void
    {
        $http = self::icloud();
        $calendars = self::provider($http, 'https://caldav.icloud.com/')->calendars();

        self::assertSame(['https://caldav.icloud.com/', 'https://caldav.icloud.com/123456789/principal/', 'https://p42-caldav.icloud.com:443/123456789/calendars/'], array_map(static fn (array $r) => $r['url'], $http->requests));
        self::assertSame(['0', '0', '1'], array_map(static fn (array $r) => \is_array($r['options']['headers'] ?? null) ? ($r['options']['headers']['Depth'] ?? null) : null, $http->requests));
        self::assertSame('Basic '.base64_encode('anna@icloud.example:abcd-efgh-ijkl-mnop'), $http->header('Authorization'));
        self::assertStringContainsString('<d:current-user-principal/>', self::body($http->requests[0]));
        self::assertSame([
            ['/123456789/calendars/home/', 'Domicile', '#1BADF8', 'Europe/Paris', false, 'HwoQEgwAAEp0XQ4bAAEAARgAIhUI'],
            ['/123456789/calendars/0A1B2C3D-shared/', 'Famille', '#CC73E1', null, true, null],
        ], array_map(static fn (Calendar $c) => [$c->id, $c->name, $c->color, $c->timeZone, $c->readOnly, $c->version], $calendars), 'the reminders (VTODO) and the inbox are left out; the shared one is read only');
    }

    public function testWhereFreeBusyQueryIsRefusedBusyTimeIsComputedFromTheEvents(): void
    {
        // A route matches the method and address only: the REPORT's body tells free-busy-query from calendar-query.
        $http = new RecordedHttpClient(static fn (string $method, string $url, array $options): array => str_contains(\is_string($options['body'] ?? null) ? $options['body'] : '', 'free-busy-query')
            ? [403, '<error xmlns="DAV:"><supported-report/></error>']
            : [207, (string) file_get_contents(__DIR__.'/Fixtures/nextcloud-report.xml'), ['content-type' => ['application/xml']]]);
        $answers = self::provider($http, 'https://caldav.icloud.com/', 'https://p42-caldav.icloud.com:443/123456789/calendars/')->freeBusy(new FreeBusyQuery(['/123456789/calendars/home/'], new \DateTimeImmutable('2026-11-16T00:00Z'), new \DateTimeImmutable('2026-11-21T00:00Z')));

        self::assertSame(['free-busy-query', 'calendar-query'], array_map(static fn (array $r) => preg_match('~<c:([a-z-]+-query)~', self::body($r), $m) ? $m[1] : '?', $http->requests));
        self::assertSame([['2026-11-17T09:00:00+00:00', '2026-11-17T10:30:00+00:00']], array_map(static fn ($s) => [$s->start->format(\DATE_ATOM), $s->end->format(\DATE_ATOM)], $answers[0]->slots), 'the fortnightly committee in winter time; the transparent holidays take no time');
    }

    public function testAnEventFromExchangeKeepsItsZoneByItsIanaName(): void
    {
        $http = RecordedHttpClient::routes(['~^REPORT ~' => [207, '@'.__DIR__.'/Fixtures/nextcloud-report.xml']]);
        $provider = self::provider($http, 'https://cloud.example.org/remote.php/dav', 'https://cloud.example.org/remote.php/dav/calendars/anna/');
        $query = new EventQuery('/remote.php/dav/calendars/anna/personal/', new \DateTimeImmutable('2026-11-01T00:00Z'), new \DateTimeImmutable('2026-12-01T00:00Z'), expand: false);
        $events = $provider->events($query);

        self::assertSame('https://cloud.example.org/remote.php/dav/calendars/anna/personal/', $http->requests[0]['url'], 'no discovery: the home was given');
        $committee = $events[0];
        self::assertSame(['Comité de direction', 'Europe/Berlin', '2026-11-03T10:00:00+01:00', '"6c1a0d2b5f0e4b8f"', 'Salle Kléber'], [$committee->title, $committee->timeZone, $committee->start->format(\DATE_ATOM), $committee->etag, $committee->location]);
        self::assertSame(['FREQ=WEEKLY;INTERVAL=2;BYDAY=TU'], $committee->recurrence?->rules);
        self::assertSame(['claire@example.org', 'Claire Durand', true], [$committee->organizer?->email, $committee->organizer?->name, $committee->organizer?->organizer]);
        self::assertSame([['anna@example.org', ParticipationStatus::Accepted, false], ['kleber@example.org', ParticipationStatus::Accepted, true]], array_map(static fn (Attendee $a) => [$a->email, $a->status, $a->resource], $committee->attendees));
        self::assertSame('2026-10-20T08:00:00+00:00', $committee->updated?->format(\DATE_ATOM));
        $holidays = $events[1];
        self::assertSame([true, '2026-11-16', '2026-11-21', false], [$holidays->allDay, $holidays->start->format('Y-m-d'), $holidays->end->format('Y-m-d'), $holidays->busy()]);

        $occurrences = $provider->events(new EventQuery($query->calendarId, $query->from, $query->to, search: 'comité'));
        self::assertSame(['2026-11-03T10:00:00+01:00', '2026-11-17T10:00:00+01:00'], array_map(static fn (Event $e) => $e->start->format(\DATE_ATOM), $occurrences), 'every other Tuesday, found by a word of its title');
    }

    public function testABearerTokenRefusedCannotBeRefreshedFromAReference(): void
    {
        $http = RecordedHttpClient::routes(['~~' => [401, 'Unauthorized']]);
        $provider = (new CaldavFactory($http, new ArraySecrets(['GOOGLE_TOKEN' => 'ya29.not-a-real-token'])))->create(['base_uri' => 'https://apidata.googleusercontent.com/caldav/v2/', 'token' => 'GOOGLE_TOKEN', 'calendar_home' => 'https://apidata.googleusercontent.com/caldav/v2/anna@example.org/']);
        self::assertInstanceOf(CaldavProvider::class, $provider);

        try {
            $provider->events(new EventQuery('events/', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-17')));
            self::fail('A refused token read events.');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('cannot be refreshed', $e->getMessage());
        }
        self::assertSame('Bearer ya29.not-a-real-token', $http->header('Authorization'));
    }

    public function testOptionsAreCheckedBeforeAnyCall(): void
    {
        try {
            (new CaldavFactory(RecordedHttpClient::routes([])))->create(['base_uri' => 'caldav.icloud.com']);
            self::fail('A base_uri without scheme was taken.');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('needs a base_uri', $e->getMessage());
        }
        $this->expectExceptionMessage('password goes with its username');
        (new CaldavFactory(RecordedHttpClient::routes([])))->create(['base_uri' => 'https://caldav.fastmail.com/', 'password' => 'FASTMAIL_APP_PASSWORD']);
    }

    /** @param array{options: array<string, mixed>} $request */
    private static function body(array $request): string
    {
        return \is_string($request['options']['body'] ?? null) ? $request['options']['body'] : '';
    }

    private static function icloud(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            '~^PROPFIND https://caldav\.icloud\.com/$~' => [207, '@'.__DIR__.'/Fixtures/icloud-principal.xml'],
            '~^PROPFIND https://caldav\.icloud\.com/123456789/principal/~' => [207, '@'.__DIR__.'/Fixtures/icloud-home-set.xml'],
            '~^PROPFIND https://p42-caldav\.icloud\.com:443/123456789/calendars/$~' => [207, '@'.__DIR__.'/Fixtures/icloud-calendars.xml'],
        ]);
    }

    private static function provider(RecordedHttpClient $http, string $base, ?string $home = null): CaldavProvider
    {
        $provider = (new CaldavFactory($http, new ArraySecrets(['ICLOUD_APP_PASSWORD' => 'abcd-efgh-ijkl-mnop'])))->create(array_filter(['base_uri' => $base, 'username' => 'anna@icloud.example', 'password' => 'ICLOUD_APP_PASSWORD', 'calendar_home' => $home]));
        self::assertInstanceOf(CaldavProvider::class, $provider);

        return $provider;
    }
}
