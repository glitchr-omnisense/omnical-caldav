<?php

namespace Omnical\Caldav;

use Omnical\Exception\ProviderException;
use Omnical\Model\Attendee;
use Omnical\Model\AttendeeRole;
use Omnical\Model\BusySlot;
use Omnical\Model\Event;
use Omnical\Model\EventStatus;
use Omnical\Model\FreeBusyStatus;
use Omnical\Model\ParticipationStatus;
use Omnical\Model\Recurrence;
use Omnical\Model\Transparency;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Parameter;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\TimeZoneUtil;

/**
 * iCalendar (RFC 5545) both ways, through sabre/vobject: a calendar
 * object's VEVENTs read into Events (a series with its Recurrence as data,
 * or each occurrence in a span, expanded by VCalendar::expand()), an Event
 * written into a calendar object (a stored one's other properties, alarms
 * and overridden occurrences kept; a VTIMEZONE for its zone, as RFC 5545
 * §3.2.19 asks), a VFREEBUSY read into busy spans.
 */
final class ICalendar
{
    /** The properties an Event says: removed from a stored VEVENT before it is written again, the others kept. */
    private const WRITTEN = ['DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'DESCRIPTION', 'LOCATION', 'STATUS', 'TRANSP', 'URL', 'ORGANIZER', 'ATTENDEE', 'RRULE', 'EXRULE', 'EXDATE', 'RDATE', 'DTSTAMP', 'LAST-MODIFIED', 'SEQUENCE'];

    public static function parse(string $data, string $provider = 'CalDAV'): VCalendar
    {
        try {
            $calendar = Reader::read($data, Reader::OPTION_FORGIVING);
        } catch (ParseException $e) {
            throw new ProviderException(\sprintf('%s answered a calendar object that is not iCalendar: %s', $provider, $e->getMessage()), 0);
        }

        return $calendar instanceof VCalendar ? $calendar : throw new ProviderException(\sprintf('%s answered a %s where a VCALENDAR was expected.', $provider, $calendar->name), 0);
    }

    /**
     * The events of one calendar object: its series and single events, or -
     * $from and $to given - each occurrence in that span, its id the
     * resource's href and "#" its RECURRENCE-ID in UTC.
     *
     * @return list<Event>
     */
    public static function events(VCalendar $calendar, string $href, string $calendarId, ?string $etag, string $source, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $masters = [];
        foreach (self::components($calendar, 'VEVENT') as $vevent) {
            if (null === self::prop($vevent, 'RECURRENCE-ID')) {
                $masters[self::text($vevent, 'UID') ?? ''] = $vevent;
            }
        }
        if (null === $from || null === $to) {
            return array_values(array_filter(array_map(static fn (Component $v) => self::event($v, $calendar, $href, $calendarId, $etag, $source), array_values($masters))));
        }
        $events = [];
        $expanded = $calendar->expand(\DateTime::createFromInterface($from), \DateTime::createFromInterface($to));
        foreach (self::components($expanded, 'VEVENT') as $vevent) {
            $master = $masters[self::text($vevent, 'UID') ?? ''] ?? null;
            // Expanded, every occurrence of a series carries its RECURRENCE-ID; a single event does not.
            $event = self::event($vevent, $calendar, $href, $calendarId, $etag, $source, null !== self::prop($vevent, 'RECURRENCE-ID'), $master);
            if (null !== $event && $event->overlaps($from, $to)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** The VEVENT that is the series (no RECURRENCE-ID), or the first one. */
    public static function master(VCalendar $calendar): ?Component
    {
        $first = null;
        foreach (self::components($calendar, 'VEVENT') as $vevent) {
            if (null === self::prop($vevent, 'RECURRENCE-ID')) {
                return $vevent;
            }
            $first ??= $vevent;
        }

        return $first;
    }

    /**
     * An Event as a calendar object: written over a stored one (its other
     * properties, alarms and overridden occurrences kept, its SEQUENCE one
     * more), or a new one.
     */
    public static function write(Event $event, string $uid, ?VCalendar $stored = null): VCalendar
    {
        $calendar = $stored ?? new VCalendar(['PRODID' => '-//omnisense//omnical caldav//EN']);
        $vevent = null === $stored ? null : self::master($stored);
        $sequence = 0;
        if (null === $vevent) {
            $vevent = self::child($calendar, 'VEVENT', ['UID' => $uid]);
        } else {
            $sequence = (int) (self::text($vevent, 'SEQUENCE') ?? -1) + 1;
        }
        // A new VEVENT comes with sabre's defaults (a DTSTAMP): every property the Event says is written once.
        foreach (self::WRITTEN as $name) {
            $vevent->remove($name);
        }
        $utc = new \DateTimeZone('UTC');
        $zone = null !== $event->timeZone && 'UTC' !== $event->timeZone ? new \DateTimeZone($event->timeZone) : null;
        $vevent->add('DTSTAMP', new \DateTimeImmutable('now', $utc));
        $vevent->add('SEQUENCE', (string) $sequence);
        if ($event->allDay) {
            $vevent->add('DTSTART', $event->start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->end->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', null === $zone ? $event->start->setTimezone($utc) : $event->start->setTimezone($zone));
            $vevent->add('DTEND', null === $zone ? $event->end->setTimezone($utc) : $event->end->setTimezone($zone));
        }
        $vevent->add('SUMMARY', $event->title);
        foreach (['DESCRIPTION' => $event->description, 'LOCATION' => $event->location, 'URL' => $event->url] as $name => $value) {
            if (null !== $value && '' !== $value) {
                $vevent->add($name, $value);
            }
        }
        $vevent->add('STATUS', strtoupper($event->status->value));
        $vevent->add('TRANSP', strtoupper($event->transparency->value));
        if (null !== $event->organizer) {
            $vevent->add('ORGANIZER', 'mailto:'.$event->organizer->email, array_filter(['CN' => $event->organizer->name]));
        }
        foreach ($event->attendees as $attendee) {
            $vevent->add('ATTENDEE', 'mailto:'.$attendee->email, array_filter([
                'CN' => $attendee->name,
                'PARTSTAT' => $attendee->status->ical(),
                'ROLE' => $attendee->role->ical(),
                'CUTYPE' => $attendee->resource ? 'RESOURCE' : null,
            ]));
        }
        $recurrence = $event->recurrence;
        if (null !== $recurrence) {
            foreach ($recurrence->rules as $rule) {
                $vevent->add('RRULE', $rule);
            }
            foreach ($recurrence->exrules as $rule) {
                $vevent->add('EXRULE', $rule);
            }
            foreach (['EXDATE' => $recurrence->exdates, 'RDATE' => $recurrence->rdates] as $name => $dates) {
                if ([] === $dates) {
                    continue;
                }
                if ($event->allDay) {
                    $vevent->add($name, implode(',', array_map(static fn (\DateTimeImmutable $d) => $d->format('Ymd'), $dates)), ['VALUE' => 'DATE']);
                } else {
                    $vevent->add($name, array_map(static fn (\DateTimeImmutable $d) => $d->setTimezone($zone ?? $utc), $dates));
                }
            }
        }
        if (null !== $zone && !$event->allDay) {
            $until = $recurrence?->until() ?? ($recurrence?->ends() === false ? $event->start->modify('+10 years') : null);
            self::timezone($calendar, $zone, $event->start, $until ?? $event->end);
        }

        return $calendar;
    }

    /**
     * The busy spans of a VFREEBUSY (RFC 5545 §3.6.4): each FREEBUSY period
     * ("start/end" or "start/duration") with its FBTYPE.
     *
     * @return list<BusySlot>
     */
    public static function busy(VCalendar $calendar): array
    {
        $slots = [];
        foreach (self::components($calendar, 'VFREEBUSY') as $freebusy) {
            foreach ($freebusy->select('FREEBUSY') as $property) {
                if (!$property instanceof Property) {
                    continue;
                }
                $status = FreeBusyStatus::read(self::param($property, 'FBTYPE') ?? 'BUSY');
                foreach ($property->getParts() as $period) {
                    $slot = \is_string($period) ? self::period($period, $status) : null;
                    if (null !== $slot) {
                        $slots[] = $slot;
                    }
                }
            }
        }

        return $slots;
    }

    /**
     * A sub-component added: a VEVENT, a VTIMEZONE.
     *
     * @param array<string, mixed> $children
     */
    private static function child(Component $parent, string $name, array $children): Component
    {
        $child = $parent->add($name, $children);

        return $child instanceof Component ? $child : throw new \LogicException(\sprintf('sabre/vobject made no %s component.', $name));
    }

    /** @return list<Component> */
    public static function components(Component $parent, string $name): array
    {
        return array_values(array_filter($parent->select($name), static fn ($c) => $c instanceof Component));
    }

    public static function prop(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $property) {
            if ($property instanceof Property) {
                return $property;
            }
        }

        return null;
    }

    public static function text(Component $component, string $name): ?string
    {
        $value = self::prop($component, $name)?->getValue();

        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function param(Property $property, string $name): ?string
    {
        $parameter = $property->parameters()[$name] ?? null;
        $value = $parameter instanceof Parameter ? $parameter->getValue() : null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function event(Component $vevent, VCalendar $calendar, string $href, string $calendarId, ?string $etag, string $source, bool $occurrence = false, ?Component $master = null): ?Event
    {
        $start = self::prop($vevent, 'DTSTART');
        if (!$start instanceof DateTimeProperty) {
            return null;
        }
        $zone = self::zone($master ?? $vevent, $calendar);
        $allDay = !$start->hasTime();
        $begin = \DateTimeImmutable::createFromInterface($start->getDateTime($zone));
        $end = self::prop($vevent, 'DTEND');
        $duration = self::prop($vevent, 'DURATION');
        $finish = match (true) {
            $end instanceof DateTimeProperty => \DateTimeImmutable::createFromInterface($end->getDateTime($zone)),
            null !== $duration => $begin->add(self::interval((string) $duration->getValue())),
            $allDay => $begin->modify('+1 day'),
            default => $begin,
        };
        if (null !== $zone) {
            $begin = $begin->setTimezone($zone);
            $finish = $finish->setTimezone($zone);
        }
        $recurrenceId = self::prop($vevent, 'RECURRENCE-ID');
        $original = $recurrenceId instanceof DateTimeProperty ? \DateTimeImmutable::createFromInterface($recurrenceId->getDateTime($zone)) : null;
        $organizer = self::prop($vevent, 'ORGANIZER');
        $attendees = [];
        foreach ($vevent->select('ATTENDEE') as $attendee) {
            if ($attendee instanceof Property && '' !== trim((string) $attendee->getValue())) {
                $attendees[] = new Attendee(
                    (string) $attendee->getValue(),
                    self::param($attendee, 'CN'),
                    ParticipationStatus::read(self::param($attendee, 'PARTSTAT')),
                    AttendeeRole::read(self::param($attendee, 'ROLE')),
                    resource: \in_array(strtoupper((string) self::param($attendee, 'CUTYPE')), ['RESOURCE', 'ROOM'], true),
                );
            }
        }
        $modified = self::prop($vevent, 'LAST-MODIFIED');
        $id = $occurrence && null !== $original ? $href.'#'.$original->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z') : $href;

        return new Event(
            self::text($vevent, 'SUMMARY') ?? '',
            $begin,
            $finish < $begin ? $begin : $finish,
            $allDay,
            $id,
            $calendarId,
            null === $zone ? null : $zone->getName(),
            self::text($vevent, 'DESCRIPTION'),
            self::text($vevent, 'LOCATION'),
            EventStatus::read(self::text($vevent, 'STATUS')),
            'TRANSPARENT' === strtoupper(self::text($vevent, 'TRANSP') ?? '') ? Transparency::Transparent : Transparency::Opaque,
            null === $organizer || '' === trim((string) $organizer->getValue()) ? null : new Attendee((string) $organizer->getValue(), self::param($organizer, 'CN'), ParticipationStatus::Accepted, AttendeeRole::Chair, organizer: true),
            $attendees,
            $occurrence ? null : self::recurrence($vevent, $zone),
            $occurrence ? $href : null,
            $occurrence ? $original : null,
            self::text($vevent, 'UID'),
            self::text($vevent, 'URL'),
            $etag,
            $modified instanceof DateTimeProperty ? \DateTimeImmutable::createFromInterface($modified->getDateTime()) : null,
            $source,
        );
    }

    /** The event's own zone: its DTSTART's TZID as PHP knows it (Windows names mapped by sabre), UTC for a UTC time; null when floating or a date. */
    private static function zone(Component $vevent, VCalendar $calendar): ?\DateTimeZone
    {
        $start = self::prop($vevent, 'DTSTART');
        if (!$start instanceof DateTimeProperty || !$start->hasTime()) {
            return null;
        }
        $tzid = self::param($start, 'TZID');
        if (null === $tzid) {
            return str_ends_with(strtoupper((string) $start->getValue()), 'Z') ? new \DateTimeZone('UTC') : null;
        }
        $zone = TimeZoneUtil::getTimeZone($tzid, $calendar);
        $name = $zone->getName();

        return str_contains($name, '/') || 'UTC' === $name ? $zone : null;
    }

    private static function recurrence(Component $vevent, ?\DateTimeZone $zone): ?Recurrence
    {
        $lines = [];
        foreach (['RRULE', 'EXRULE', 'RDATE', 'EXDATE'] as $name) {
            foreach ($vevent->select($name) as $property) {
                if ($property instanceof Property) {
                    $lines[] = rtrim((string) preg_replace("~\r?\n[ \t]~", '', $property->serialize()));
                }
            }
        }

        return [] === $lines ? null : Recurrence::fromLines($lines, $zone);
    }

    private static function interval(string $duration): \DateInterval
    {
        $interval = DateTimeParser::parseDuration($duration);

        return $interval instanceof \DateInterval ? $interval : new \DateInterval('PT0S');
    }

    private static function period(string $period, FreeBusyStatus $status): ?BusySlot
    {
        [$start, $end] = explode('/', trim($period), 2) + [1 => ''];
        try {
            $from = DateTimeParser::parseDateTime($start, new \DateTimeZone('UTC'));
            $to = preg_match('~^[+-]?P~', $end) ? $from->add(self::interval($end)) : DateTimeParser::parseDateTime($end, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $to >= $from ? new BusySlot($from, $to, $status) : null;
    }

    /** A VTIMEZONE for a zone, its transitions around the event, unless the calendar holds one already. */
    private static function timezone(VCalendar $calendar, \DateTimeZone $zone, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        foreach (self::components($calendar, 'VTIMEZONE') as $existing) {
            if (self::text($existing, 'TZID') === $zone->getName()) {
                return;
            }
        }
        $begin = $from->getTimestamp() - 366 * 86400;
        $finish = min(max($to->getTimestamp(), $from->getTimestamp()) + 366 * 86400, $from->getTimestamp() + 20 * 366 * 86400);
        $transitions = $zone->getTransitions($begin, $finish) ?: [];
        $vtimezone = self::child($calendar, 'VTIMEZONE', ['TZID' => $zone->getName()]);
        $offset = static fn (int $seconds): string => ($seconds < 0 ? '-' : '+').\sprintf('%02d%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
        if (\count($transitions) < 2) {
            $now = $zone->getOffset(new \DateTimeImmutable('@'.$from->getTimestamp()));
            $vtimezone->add($calendar->createComponent('STANDARD', ['DTSTART' => '19700101T000000', 'TZOFFSETFROM' => $offset($now), 'TZOFFSETTO' => $offset($now), 'TZNAME' => (new \DateTimeImmutable('@'.$from->getTimestamp()))->setTimezone($zone)->format('T')], false));

            return;
        }
        for ($i = 1, $n = \count($transitions); $i < $n; ++$i) {
            [$previous, $transition] = [$transitions[$i - 1], $transitions[$i]];
            $local = (new \DateTimeImmutable('@'.($transition['ts'] + $previous['offset'])))->format('Ymd\THis');
            $vtimezone->add($calendar->createComponent($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD', ['DTSTART' => $local, 'TZOFFSETFROM' => $offset($previous['offset']), 'TZOFFSETTO' => $offset($transition['offset']), 'TZNAME' => $transition['abbr']], false));
        }
    }
}
