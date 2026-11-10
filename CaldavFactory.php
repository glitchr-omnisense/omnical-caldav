<?php

namespace Omnical\Caldav;

use Omnical\Auth\AccessTokenInterface;
use Omnical\Auth\EnvSecrets;
use Omnical\Auth\SecretsInterface;
use Omnical\Auth\StaticAccessToken;
use Omnical\Config;
use Omnical\Exception\InvalidConfigException;
use Omnical\Http\RawClient;
use Omnical\ProviderFactory;
use Omnical\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "caldav": options base_uri (required: the server's address -
 * https://caldav.icloud.com/, https://caldav.fastmail.com/,
 * https://cloud.example.org/remote.php/dav), username and password (the
 * password a secret's REFERENCE, "CALDAV_PASSWORD", read at each call: an
 * app-specific password for iCloud and Fastmail), or token (the reference
 * of a bearer token; an AccessTokenInterface given to the constructor
 * instead refreshes itself), calendar_home (the calendar home's address:
 * no discovery), user_agent, timeout (seconds); policy, usage.
 */
final class CaldavFactory extends ProviderFactory
{
    public function __construct(
        private readonly ?HttpClientInterface $http = null,
        private readonly ?SecretsInterface $secrets = null,
        private readonly ?AccessTokenInterface $token = null,
    ) {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnical.factory_name' => 'caldav',
            'omnical.factory_title' => 'CalDAV',
            'omnical.required_options' => ['base_uri'],
            'username' => null,
            'password' => null,
            'token' => null,
            'calendar_home' => null,
            'user_agent' => null,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $base = (string) $c->string('base_uri');
        if (!preg_match('~^https?://[^/\s]+~i', $base)) {
            throw new InvalidConfigException(\sprintf('The "caldav" provider needs a base_uri such as https://caldav.icloud.com/, not "%s".', $base));
        }
        $username = $c->string('username');
        $password = $c->string('password');
        if (null !== $password && null === $username) {
            throw new InvalidConfigException('The "caldav" provider\'s password goes with its username.');
        }
        $secrets = $this->secrets ?? new EnvSecrets();
        $token = $this->token ?? (null === ($reference = $c->string('token')) ? null : new StaticAccessToken($secrets, $reference, 'CalDAV'));
        $http = $this->http ?? HttpClient::create(['timeout' => 30]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }
        $headers = null === ($agent = $c->string('user_agent')) ? [] : ['User-Agent' => $agent];
        $dav = new DavClient(new RawClient($http, 'CalDAV', $headers, $c->backoff()), $secrets, $username, $password, $token);

        return new CaldavProvider($dav, $base, $c->string('calendar_home'));
    }
}
