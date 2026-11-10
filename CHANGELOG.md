# Changelog

## 1.x (unreleased)

- First version: CalDAV (RFC 4791) as an omnical provider - discovery (RFC 5397, RFC 6764),
  calendars, events read (expanded with sabre/vobject, or series with their recurrence as data)
  and written (`If-None-Match: *`, `If-Match`), free/busy (`free-busy-query`, or computed from
  the events), Basic or bearer credentials by reference, its usage policy as data (`policy.json`).
