<?php

namespace Omnical\Caldav\Tests;

use Omnical\Auth\ArraySecrets;
use Omnical\Caldav\CaldavFactory;
use Omnical\Caldav\CaldavProvider;
use Omnical\Exception\OmnicalException;
use Omnical\Exception\UsageLimitReachedException;
use Omnical\Model\EventQuery;
use Omnical\Registry;
use Omnical\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\Permission;
use PHPUnit\Framework\TestCase;

/** CalDAV's usage policy as a protocol, and a site's own limits for its server counted on every call. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = self::registry(self::http())->policy('icloud');

        self::assertSame('caldav', $policy->provider);
        self::assertNull($policy->rateLimits, 'each server\'s own, none published by iCloud, Fastmail or Nextcloud');
        self::assertSame(Permission::Unknown, $policy->rights->commercial);
        self::assertFalse($policy->rights->attribution->required);
        self::assertTrue($policy->requirements->key);
        self::assertSame(['https://www.rfc-editor.org/rfc/rfc4791', '2026-11-10'], [$policy->provenance->terms, $policy->provenance->verifiedAt]);
        self::assertStringContainsString('https://support.apple.com/en-us/102654', (string) $policy->provenance->notes);
        self::assertSame($policy->toArray(), (new CaldavProvider(new \Omnical\Caldav\DavClient(new \Omnical\Http\RawClient(self::http(), 'CalDAV'), new ArraySecrets([])), 'https://x.example/'))->capabilities()->policy()->toArray());
    }

    public function testASitesLimitPerUserIsCountedAndTheCallOverItNeverMade(): void
    {
        $http = self::http();
        $registry = self::registry($http, ['rateLimits' => [['max' => 2, 'window' => 'minute', 'scope' => 'user', 'note' => 'Google CalDAV: 600 a minute per user, a site\'s share']]]);
        $query = new EventQuery('/123/calendars/home/', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-17'));

        $registry->calendarEvents('icloud', 'user-1')->events($query);
        $registry->calendarEvents('icloud', 'user-1')->events($query);
        $registry->calendarEvents('icloud', 'user-2')->events($query);
        try {
            $registry->calendarEvents('icloud', 'user-1')->events($query);
            self::fail('A third call of the minute went out.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnicalException::class, $e);
        }
        self::assertCount(3, $http->requests);
    }

    /** @param array<string, mixed> $policy */
    private static function registry(RecordedHttpClient $http, array $policy = []): Registry
    {
        return new Registry([new CaldavFactory($http, new ArraySecrets(['P' => 'x']))], ['icloud' => ['factory' => 'caldav', 'options' => ['base_uri' => 'https://caldav.icloud.com/', 'calendar_home' => 'https://p42-caldav.icloud.com/123/calendars/', 'username' => 'a', 'password' => 'P'], 'policy' => $policy]]);
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes(['~^REPORT ~' => [207, '<multistatus xmlns="DAV:"/>']]);
    }
}
