<?php

namespace Omnical\Caldav\Tests;

use Omnical\Caldav\CaldavProvider;
use Omnical\Testing\BareTestCase;

/** omnical/caldav's classes load with omnisense/omnical, sabre/vobject, sabre/xml and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return CaldavProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'sabre/vobject', 'sabre/xml', 'sabre/uri'];
    }
}
