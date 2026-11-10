-- The tables sabre/dav's PDO backends read and write (Sabre\CalDAV\Backend\PDO,
-- Sabre\DAVACL\PrincipalBackend\PDO), for SQLite: sabre's own examples/sql/sqlite.*.sql
-- are not in its dist archive, so this is written from those backends' queries
-- (sabre/dav 4.7, BSD-3-Clause), the columns they name and nothing else.
CREATE TABLE principals (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    uri TEXT NOT NULL,
    email TEXT,
    displayname TEXT,
    UNIQUE (uri)
);
CREATE TABLE groupmembers (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    principal_id INTEGER NOT NULL,
    member_id INTEGER NOT NULL,
    UNIQUE (principal_id, member_id)
);
CREATE TABLE calendars (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    synctoken INTEGER DEFAULT 1 NOT NULL,
    components TEXT NOT NULL
);
CREATE TABLE calendarinstances (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    calendarid INTEGER,
    principaluri TEXT,
    access INTEGER,
    displayname TEXT,
    uri TEXT NOT NULL,
    description TEXT,
    calendarorder INTEGER,
    calendarcolor TEXT,
    timezone TEXT,
    transparent BOOL,
    share_href TEXT,
    share_displayname TEXT,
    share_invitestatus INTEGER DEFAULT '2',
    UNIQUE (principaluri, uri),
    UNIQUE (calendarid, principaluri),
    UNIQUE (calendarid, share_href)
);
CREATE TABLE calendarobjects (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    calendardata BLOB NOT NULL,
    uri TEXT NOT NULL,
    calendarid INTEGER NOT NULL,
    lastmodified INTEGER NOT NULL,
    etag TEXT NOT NULL,
    size INTEGER NOT NULL,
    componenttype TEXT,
    firstoccurence INTEGER,
    lastoccurence INTEGER,
    uid TEXT
);
CREATE TABLE calendarchanges (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    uri TEXT,
    synctoken INTEGER NOT NULL,
    calendarid INTEGER NOT NULL,
    operation INTEGER NOT NULL
);
CREATE TABLE calendarsubscriptions (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    uri TEXT NOT NULL,
    principaluri TEXT NOT NULL,
    source TEXT,
    displayname TEXT,
    refreshrate TEXT,
    calendarorder INTEGER,
    calendarcolor TEXT,
    striptodos BOOL,
    stripalarms BOOL,
    stripattachments BOOL,
    lastmodified INTEGER
);
CREATE TABLE schedulingobjects (
    id INTEGER PRIMARY KEY ASC NOT NULL,
    principaluri TEXT,
    calendardata BLOB,
    uri TEXT,
    lastmodified INTEGER,
    etag TEXT,
    size INTEGER
);
