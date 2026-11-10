<?php

namespace Omnical\Caldav;

use Omnical\CalendarEventsInterface;
use Omnical\Capabilities;
use Omnical\Exception\InvalidArgumentException;
use Omnical\Exception\NotSupportedException;
use Omnical\Exception\ProviderException;
use Omnical\FreeBusyInterface;
use Omnical\Model\Calendar;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusy;
use Omnical\Model\FreeBusyQuery;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\FreeBusyGenerator;
use Sabre\VObject\TimeZoneUtil;

/**
 * A CalDAV server (RFC 4791): iCloud, Fastmail, Nextcloud, any sabre/dav
 * server. Its calendars are found from the server's address - the user's
 * principal (RFC 5397 current-user-principal, /.well-known/caldav per RFC
 * 6764 when the address gives none), its calendar-home-set, the calendar
 * collections in it (PROPFIND Depth 1, VEVENT calendars only) - unless the
 * home is given.
 *
 * Identifiers are the server's hrefs: a calendar's is its collection's, an
 * event's its resource's; an occurrence of a series is the resource's href,
 * "#", its RECURRENCE-ID in UTC (read only: a series is written whole).
 *
 * Events are asked with a calendar-query REPORT on their span and read with
 * sabre/vobject; occurrences are expanded on this side (VCalendar::
 * expand()), the same for every server whatever it supports of <C:expand>.
 * Writes are PUTs with If-None-Match: * (create) and If-Match (update, the
 * event's etag or the one just read: a ConflictException when it changed),
 * DELETE with If-Match. Busy time is the free-busy-query REPORT (§7.10),
 * computed from the events (FreeBusyGenerator) where the server does not
 * answer it (Google, iCloud).
 */
final class CaldavProvider implements CalendarEventsInterface, FreeBusyInterface, DeclaresUsagePolicyInterface
{
    /** CalDAV's usage policy as a protocol: each server's terms apply (provenance.notes). */
    public const POLICY = __DIR__.'/policy.json';

    private const C = '{'.DavClient::CALDAV.'}';

    /** The statuses of a server that does not answer a free-busy-query REPORT. */
    private const NO_FREE_BUSY = [400, 403, 405, 415, 422, 501];

    private static ?UsagePolicy $policy = null;

    private ?string $home;

    public function __construct(private readonly DavClient $dav, private readonly string $baseUri, ?string $home = null)
    {
        $this->home = null === $home ? null : rtrim($home, '/').'/';
    }

    public function getName(): string
    {
        return 'caldav';
    }

    public function getTitle(): string
    {
        return 'CalDAV';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(calendarEvents: true, write: true, expand: true, attendees: true, freeBusy: true, policy: $this->usagePolicy());
    }

    /** The user's calendar home (absolute), found once. */
    public function home(): string
    {
        if (null !== $this->home) {
            return $this->home;
        }
        $origin = (string) preg_replace('~^(https?://[^/]+).*$~i', '$1', $this->baseUri);
        $principal = $this->principal($this->baseUri) ?? $this->principal($origin.'/.well-known/caldav')
            ?? throw new ProviderException(\sprintf('The CalDAV server at %s names no principal for these credentials (current-user-principal).', $origin), 0);
        $home = ($this->dav->propfind($principal, 0, [self::C.'calendar-home-set'])[0] ?? null)?->hrefs(self::C.'calendar-home-set')[0]
            ?? throw new ProviderException('The CalDAV principal names no calendar-home-set.', 0);

        return $this->home = rtrim(DavClient::resolve($principal, $home), '/').'/';
    }

    public function calendars(): array
    {
        $home = $this->home();
        $calendars = [];
        foreach ($this->dav->propfind($home, 1, [
            '{DAV:}resourcetype', '{DAV:}displayname', '{DAV:}current-user-privilege-set', '{DAV:}sync-token',
            self::C.'calendar-description', self::C.'supported-calendar-component-set', self::C.'calendar-timezone',
            '{'.DavClient::CALSERVER.'}getctag', '{'.DavClient::APPLE.'}calendar-color',
        ]) as $response) {
            $components = $response->attributes(self::C.'supported-calendar-component-set', self::C.'comp', 'name');
            if (!\in_array(self::C.'calendar', $response->names('{DAV:}resourcetype'), true) || ([] !== $components && !\in_array('VEVENT', array_map('strtoupper', $components), true))) {
                continue;
            }
            $privileges = $response->names('{DAV:}current-user-privilege-set');
            $calendars[] = new Calendar(
                $response->href,
                $response->text('{DAV:}displayname') ?? basename(rtrim($response->href, '/')),
                'caldav',
                self::zone($response->text(self::C.'calendar-timezone')),
                $response->text(self::C.'calendar-description'),
                $response->text('{'.DavClient::APPLE.'}calendar-color'),
                [] !== $privileges && [] === array_intersect(['{DAV:}write', '{DAV:}write-content', '{DAV:}bind', '{DAV:}all'], $privileges),
                version: $response->text('{'.DavClient::CALSERVER.'}getctag') ?? $response->text('{DAV:}sync-token'),
            );
        }

        return $calendars;
    }

    public function events(EventQuery $query): array
    {
        $events = [];
        foreach ($this->objects($query->calendarId, $query->from, $query->to) as [$href, $etag, $calendar]) {
            array_push($events, ...ICalendar::events($calendar, $href, $query->calendarId, $etag, 'caldav', $query->expand ? $query->from : null, $query->expand ? $query->to : null));
        }
        if (null !== $query->search) {
            $words = mb_strtolower($query->search);
            $events = array_values(array_filter($events, static fn (Event $e) => str_contains(mb_strtolower(implode(' ', [$e->title, $e->description, $e->location, ...array_map(static fn ($a) => $a->email.' '.$a->name, $e->attendees)])), $words)));
        }
        usort($events, static fn (Event $a, Event $b) => [$a->start, $a->id] <=> [$b->start, $b->id]);

        return \array_slice($events, 0, $query->limit);
    }

    public function event(string $calendarId, string $eventId): ?Event
    {
        $cut = strpos($eventId, '#');
        $href = false === $cut ? $eventId : substr($eventId, 0, $cut);
        $occurrence = false === $cut ? null : substr($eventId, $cut + 1);
        $answer = $this->dav->send('GET', $this->url($href), null, ['Accept' => 'text/calendar']);
        if (404 === $answer->status) {
            return null;
        }
        $calendar = ICalendar::parse($answer->body);
        $etag = $answer->header('etag');
        if (null === $occurrence) {
            return ICalendar::events($calendar, $href, $calendarId, $etag, 'caldav')[0] ?? null;
        }
        $at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $occurrence, new \DateTimeZone('UTC')) ?: throw new InvalidArgumentException(\sprintf('"%s" names no occurrence.', $eventId));
        foreach (ICalendar::events($calendar, $href, $calendarId, $etag, 'caldav', $at->modify('-1 day'), $at->modify('+2 days')) as $event) {
            if ($event->id === $eventId) {
                return $event;
            }
        }

        return null;
    }

    public function create(Event $event): Event
    {
        $calendarId = $event->calendarId ?? throw new InvalidArgumentException('An event is created in a calendar: its calendarId.');
        $uid = $event->uid ?? bin2hex(random_bytes(16)).'@omnical';
        $href = rtrim($calendarId, '/').'/'.((string) preg_replace('~[^A-Za-z0-9._-]+~', '-', $uid)).'.ics';
        $answer = $this->dav->send('PUT', $this->url($href), ICalendar::write($event, $uid)->serialize(), ['Content-Type' => 'text/calendar; charset=utf-8', 'If-None-Match' => '*'], []);

        return $event->stored($href, $calendarId, $answer->header('etag') ?? $this->etag($href), $uid, source: 'caldav');
    }

    public function update(Event $event): Event
    {
        $href = $event->id ?? throw new InvalidArgumentException('An event is updated by its id.');
        $calendarId = $event->calendarId ?? throw new InvalidArgumentException('An event is updated in its calendar: its calendarId.');
        if (str_contains($href, '#')) {
            throw NotSupportedException::call('CalDAV', 'write one occurrence of a series apart (write the series, its id without "#")');
        }
        $stored = $this->dav->send('GET', $this->url($href), null, ['Accept' => 'text/calendar'], []);
        $calendar = ICalendar::parse($stored->body);
        $master = ICalendar::master($calendar);
        $uid = null === $master ? null : ICalendar::text($master, 'UID');
        $condition = $event->etag ?? $stored->header('etag');
        $answer = $this->dav->send('PUT', $this->url($href), ICalendar::write($event, $uid ?? $event->uid ?? bin2hex(random_bytes(16)).'@omnical', $calendar)->serialize(), array_filter(['Content-Type' => 'text/calendar; charset=utf-8', 'If-Match' => $condition]), []);

        return $event->stored($href, $calendarId, $answer->header('etag') ?? $this->etag($href), $uid, source: 'caldav');
    }

    public function delete(string $calendarId, string $eventId, ?string $etag = null): void
    {
        if (str_contains($eventId, '#')) {
            throw NotSupportedException::call('CalDAV', 'delete one occurrence of a series apart (add it to the series\' EXDATE)');
        }
        $this->dav->send('DELETE', $this->url($eventId), null, array_filter(['If-Match' => $etag]));
    }

    public function freeBusy(FreeBusyQuery $query): array
    {
        $answers = [];
        foreach ($query->calendars as $calendarId) {
            $range = self::range($query->from, $query->to);
            $answer = $this->dav->send('REPORT', $this->url($calendarId), DavClient::service()->write(self::C.'free-busy-query', [['name' => self::C.'time-range', 'attributes' => $range]]), ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], [404, ...self::NO_FREE_BUSY]);
            if (404 === $answer->status) {
                $answers[] = new FreeBusy($calendarId, [], 'notFound', 'caldav');
            } elseif (200 === $answer->status) {
                $answers[] = new FreeBusy($calendarId, ICalendar::busy(ICalendar::parse($answer->body)), null, 'caldav');
            } else {
                $objects = array_map(static fn (array $object) => $object[2], $this->objects($calendarId, $query->from, $query->to));
                $result = (new FreeBusyGenerator(\DateTime::createFromInterface($query->from), \DateTime::createFromInterface($query->to), $objects, new \DateTimeZone('UTC')))->getResult();
                $answers[] = new FreeBusy($calendarId, $result instanceof VCalendar ? ICalendar::busy($result) : [], null, 'caldav');
            }
        }

        return $answers;
    }

    /**
     * The calendar objects that take some of a span: [href, etag, VCALENDAR].
     *
     * @return list<array{0: string, 1: ?string, 2: VCalendar}>
     */
    private function objects(string $calendarId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $objects = [];
        foreach ($this->dav->report($this->url($calendarId), self::C.'calendar-query', [
            '{DAV:}prop' => ['{DAV:}getetag' => null, self::C.'calendar-data' => null],
            self::C.'filter' => [['name' => self::C.'comp-filter', 'attributes' => ['name' => 'VCALENDAR'], 'value' => [
                ['name' => self::C.'comp-filter', 'attributes' => ['name' => 'VEVENT'], 'value' => [['name' => self::C.'time-range', 'attributes' => self::range($from, $to)]]],
            ]]],
        ]) as $response) {
            $data = $response->text(self::C.'calendar-data');
            if (null !== $data) {
                $objects[] = [$response->href, $response->text('{DAV:}getetag'), ICalendar::parse($data)];
            }
        }

        return $objects;
    }

    private function principal(string $url): ?string
    {
        try {
            $href = ($this->dav->propfind($url, 0, ['{DAV:}current-user-principal'])[0] ?? null)?->hrefs('{DAV:}current-user-principal')[0] ?? null;
        } catch (ProviderException $e) {
            if (\in_array($e->status(), [0, 404, 405, 501], true)) {
                return null;
            }
            throw $e;
        }

        return null === $href ? null : DavClient::resolve($url, $href);
    }

    /** An etag a server did not send with its answer to a PUT (it changed what it stored): asked. */
    private function etag(string $href): ?string
    {
        return ($this->dav->propfind($this->url($href), 0, ['{DAV:}getetag'])[0] ?? null)?->text('{DAV:}getetag');
    }

    private function url(string $href): string
    {
        return DavClient::resolve($this->home(), $href);
    }

    /** @return array{start: string, end: string} a CalDAV time-range: dates with UTC times (RFC 4791 §9.9) */
    private static function range(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $utc = new \DateTimeZone('UTC');

        return ['start' => \DateTimeImmutable::createFromInterface($from)->setTimezone($utc)->format('Ymd\THis\Z'), 'end' => \DateTimeImmutable::createFromInterface($to)->setTimezone($utc)->format('Ymd\THis\Z')];
    }

    /** A calendar-timezone's VTIMEZONE as its IANA name, when PHP knows it. */
    private static function zone(?string $vtimezone): ?string
    {
        if (null === $vtimezone) {
            return null;
        }
        try {
            $calendar = ICalendar::parse($vtimezone);
        } catch (ProviderException) {
            return null;
        }
        $tzid = ($c = ICalendar::components($calendar, 'VTIMEZONE')[0] ?? null) ? ICalendar::text($c, 'TZID') : null;
        $name = null === $tzid ? null : TimeZoneUtil::getTimeZone($tzid, $calendar)->getName();

        return null !== $name && (str_contains($name, '/') || 'UTC' === $name) ? $name : null;
    }
}
