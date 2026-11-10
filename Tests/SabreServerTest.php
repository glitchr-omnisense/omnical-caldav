<?php

namespace Omnical\Caldav\Tests;

use Omnical\Auth\ArraySecrets;
use Omnical\Caldav\CaldavFactory;
use Omnical\Caldav\CaldavProvider;
use Omnical\Exception\AuthenticationException;
use Omnical\Exception\ConflictException;
use Omnical\Exception\NotSupportedException;
use Omnical\Model\Attendee;
use Omnical\Model\AttendeeRole;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusy;
use Omnical\Model\FreeBusyQuery;
use Omnical\Model\FreeBusyStatus;
use Omnical\Model\ParticipationStatus;
use Omnical\Model\Recurrence;
use Omnical\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The provider against a real CalDAV server: sabre/dav (the server of
 * Nextcloud, among others) in this process, over SQLite in memory. Every
 * request goes through sabre's own parsing, ACL checks, calendar-query
 * filtering, ETags and preconditions.
 */
final class SabreServerTest extends TestCase
{
    private const WORK = '/dav/calendars/anna/work/';

    private SabreHttpClient $http;

    private CaldavProvider $provider;

    protected function setUp(): void
    {
        $this->http = new SabreHttpClient();
        $provider = (new Registry([new CaldavFactory($this->http, new ArraySecrets(['CALDAV_PASSWORD' => 'app-password']))], ['dav' => ['factory' => 'caldav', 'options' => [
            'base_uri' => SabreHttpClient::BASE, 'username' => 'anna', 'password' => 'CALDAV_PASSWORD', 'user_agent' => 'omnical-tests/1.0 (tests@example.org)',
        ]]]))->get('dav');
        self::assertInstanceOf(CaldavProvider::class, $provider);
        $this->provider = $provider;
    }

    protected function tearDown(): void
    {
        self::assertSame([], array_values(array_filter(array_map(static fn (array $r) => $r['warning'], $this->http->requests))), 'sabre repaired nothing of what was written (X-Sabre-Ew-Gross)');
    }

    public function testTheCalendarsAreFoundFromTheServersAddress(): void
    {
        $calendars = $this->provider->calendars();

        self::assertSame('https://dav.test/dav/calendars/anna/', $this->provider->home());
        self::assertCount(1, $calendars, 'the inbox and outbox are no event calendars');
        self::assertSame([self::WORK, 'Cabinet', '#3A87AD', 'Rendez-vous du cabinet', false, 'caldav'], [$calendars[0]->id, $calendars[0]->name, $calendars[0]->color, $calendars[0]->description, $calendars[0]->readOnly, $calendars[0]->source]);
        self::assertNotNull($calendars[0]->version, 'the ctag');
        self::assertSame(['PROPFIND', 'PROPFIND', 'PROPFIND'], array_map(static fn (array $r) => $r['method'], $this->http->requests));
        self::assertSame(207, $this->http->requests[0]['status']);
    }

    public function testAnEventIsWrittenReadUpdatedAndDeletedWithItsEtag(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $created = $this->provider->create(new Event('Consultation', new \DateTimeImmutable('2026-11-16T09:00', $paris), new \DateTimeImmutable('2026-11-16T09:45', $paris), calendarId: self::WORK,
            description: 'Première consultation', location: 'Cabinet, 2 rue de la Paix',
            organizer: new Attendee('anna@example.org', 'Anna'), attendees: [new Attendee('paul@example.org', 'Paul Martin', role: AttendeeRole::Required), new Attendee('salle-1@example.org', resource: true)]));

        self::assertNotNull($created->uid);
        self::assertStringStartsWith(self::WORK, (string) $created->id);
        self::assertNotNull($created->etag);
        $put = $this->http->requests[\count($this->http->requests) - 1];
        self::assertSame(['PUT', 201], [$put['method'], $put['status']], 'sabre stored it as written: its ETag came with the 201, nothing was read again');
        self::assertSame('*', \is_array($put['options']['headers'] ?? null) ? ($put['options']['headers']['If-None-Match'] ?? null) : null);

        $read = $this->provider->event(self::WORK, (string) $created->id);
        self::assertNotNull($read);
        self::assertSame(['Consultation', 'Europe/Paris', '2026-11-16T09:00:00+01:00', '2026-11-16T09:45:00+01:00', 'Cabinet, 2 rue de la Paix', $created->etag], [$read->title, $read->timeZone, $read->start->format(\DATE_ATOM), $read->end->format(\DATE_ATOM), $read->location, $read->etag]);
        self::assertSame(['anna@example.org', 'Anna'], [$read->organizer?->email, $read->organizer?->name]);
        self::assertSame([['paul@example.org', 'Paul Martin', ParticipationStatus::NeedsAction, false], ['salle-1@example.org', null, ParticipationStatus::NeedsAction, true]], array_map(static fn (Attendee $a) => [$a->email, $a->name, $a->status, $a->resource], $read->attendees));

        $moved = $this->provider->update($read->moved(new \DateTimeImmutable('2026-11-16T10:00', $paris), new \DateTimeImmutable('2026-11-16T11:00', $paris)));
        self::assertNotSame($created->etag, $moved->etag);
        self::assertSame('2026-11-16T10:00:00+01:00', $this->provider->event(self::WORK, (string) $created->id)?->start->format(\DATE_ATOM));
        try {
            $this->provider->update($read);
            self::fail('A stale etag was written over.');
        } catch (ConflictException $e) {
            self::assertSame(412, $e->status());
        }
        try {
            $this->provider->delete(self::WORK, (string) $created->id, (string) $created->etag);
            self::fail('A stale etag deleted the event.');
        } catch (ConflictException) {
        }
        $this->provider->delete(self::WORK, (string) $created->id, $moved->etag);
        self::assertNull($this->provider->event(self::WORK, (string) $created->id));
        $this->provider->delete(self::WORK, (string) $created->id);
    }

    public function testAWeeklySeriesIsExpandedWithoutItsExceptionOrKeptAsData(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $series = $this->provider->create(new Event('Point hebdomadaire', new \DateTimeImmutable('2026-10-19T14:00', $paris), new \DateTimeImmutable('2026-10-19T14:30', $paris), calendarId: self::WORK,
            recurrence: Recurrence::rule('FREQ=WEEKLY;BYDAY=MO;COUNT=10', [new \DateTimeImmutable('2026-11-02T14:00', $paris)])));
        $this->provider->create(new Event('Fermeture', new \DateTimeImmutable('2026-11-11'), new \DateTimeImmutable('2026-11-12'), allDay: true, calendarId: self::WORK));

        $span = new EventQuery(self::WORK, new \DateTimeImmutable('2026-10-26T00:00', $paris), new \DateTimeImmutable('2026-11-17T00:00', $paris));
        $events = $this->provider->events($span);
        self::assertSame([
            ['Point hebdomadaire', '2026-10-26 14:00', true],
            ['Point hebdomadaire', '2026-11-09 14:00', true],
            ['Fermeture', '2026-11-11 00:00', false],
            ['Point hebdomadaire', '2026-11-16 14:00', true],
        ], array_map(static fn (Event $e) => [$e->title, $e->start->format('Y-m-d H:i'), $e->isOccurrence()], $events), 'the 2 November occurrence is taken out (EXDATE), across the change from summer time');
        self::assertSame('2026-10-26T14:00:00+01:00', $events[0]->start->format(\DATE_ATOM), 'summer time ended on 25 October: still 14:00 in Paris');
        self::assertSame($series->id, $events[0]->recurringEventId);
        self::assertSame($series->id.'#20261026T130000Z', $events[0]->id);
        self::assertSame('2026-10-26T13:00:00+00:00', $events[0]->originalStart?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertTrue($events[2]->allDay);
        self::assertSame('Point hebdomadaire', $this->provider->event(self::WORK, (string) $events[1]->id)?->title, 'an occurrence read by its id');

        $kept = $this->provider->events(new EventQuery(self::WORK, $span->from, $span->to, expand: false));
        self::assertCount(2, $kept);
        $recurrence = $kept[0]->recurrence;
        self::assertNotNull($recurrence);
        self::assertSame(['FREQ=WEEKLY;BYDAY=MO;COUNT=10'], $recurrence->rules);
        self::assertSame(['2026-11-02T14:00:00+01:00'], array_map(static fn (\DateTimeImmutable $d) => $d->setTimezone($paris)->format(\DATE_ATOM), $recurrence->exdates));

        $this->expectException(NotSupportedException::class);
        $this->provider->update($events[0]);
    }

    public function testBusyTimeIsTheServersFreeBusyQuery(): void
    {
        $utc = new \DateTimeZone('UTC');
        $this->provider->create(new Event('A', new \DateTimeImmutable('2026-11-16T09:00Z'), new \DateTimeImmutable('2026-11-16T10:00Z'), calendarId: self::WORK));
        $this->provider->create(new Event('B', new \DateTimeImmutable('2026-11-16T13:00Z'), new \DateTimeImmutable('2026-11-16T14:00Z'), calendarId: self::WORK, status: \Omnical\Model\EventStatus::Tentative));
        $this->provider->create(new Event('C', new \DateTimeImmutable('2026-11-16T15:00Z'), new \DateTimeImmutable('2026-11-16T16:00Z'), calendarId: self::WORK, transparency: \Omnical\Model\Transparency::Transparent));

        $answers = $this->provider->freeBusy(new FreeBusyQuery([self::WORK, '/dav/calendars/anna/missing/'], new \DateTimeImmutable('2026-11-16T08:00Z'), new \DateTimeImmutable('2026-11-16T18:00Z')));
        self::assertSame('REPORT', $this->http->requests[\count($this->http->requests) - 1]['method']);
        self::assertSame([['09:00', '10:00', FreeBusyStatus::Busy], ['13:00', '14:00', FreeBusyStatus::Tentative]], array_map(static fn ($s) => [$s->start->setTimezone($utc)->format('H:i'), $s->end->setTimezone($utc)->format('H:i'), $s->status], $answers[0]->slots));
        self::assertNull($answers[0]->error);
        self::assertSame('notFound', $answers[1]->error);
        self::assertSame(['08:00-09:00', '10:00-13:00', '14:00-18:00'], array_map(static fn ($s) => $s->start->setTimezone($utc)->format('H:i').'-'.$s->end->setTimezone($utc)->format('H:i'), FreeBusy::common([$answers[0]], new \DateTimeImmutable('2026-11-16T08:00Z'), new \DateTimeImmutable('2026-11-16T18:00Z'), 3600)), 'tentative counts as taken; the transparent event does not');
    }

    public function testWrongCredentialsAreRefused(): void
    {
        $provider = (new CaldavFactory($this->http, new ArraySecrets(['CALDAV_PASSWORD' => 'wrong'])))->create(['base_uri' => SabreHttpClient::BASE, 'username' => 'anna', 'password' => 'CALDAV_PASSWORD']);
        self::assertInstanceOf(CaldavProvider::class, $provider);

        try {
            $provider->calendars();
            self::fail('Wrong credentials listed calendars.');
        } catch (AuthenticationException $e) {
            self::assertStringNotContainsString('wrong', $e->getMessage());
            self::assertStringNotContainsString(base64_encode('anna:wrong'), $e->getMessage());
        }
    }
}
