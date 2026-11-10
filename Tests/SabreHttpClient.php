<?php

namespace Omnical\Caldav\Tests;

use Omnical\Testing\RecordedResponse;
use Sabre\CalDAV\Backend\PDO as CalendarBackend;
use Sabre\CalDAV\CalendarRoot;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\CalDAV\Principal\Collection as Principals;
use Sabre\DAV\Auth\Backend\BasicCallBack;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\HTTP\Sapi;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client whose every request is served by a sabre/dav CalDAV server
 * in this process - the CalDAV, ACL and Basic authentication plugins over
 * sabre's PDO backends on an SQLite database in memory, one user ("anna",
 * password "app-password") and her calendar "work" - so that the provider's
 * requests are checked against a real implementation of RFC 4791, with no
 * network. Requests are kept ($requests), as RecordedHttpClient keeps them.
 */
final class SabreHttpClient implements HttpClientInterface
{
    public const BASE = 'https://dav.test/dav/';

    /** @var list<array{method: string, url: string, options: array<string, mixed>, status: int, warning: ?string}> */
    public array $requests = [];

    public readonly CalendarBackend $calendars;

    private readonly Server $server;

    public function __construct()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(__DIR__.'/Fixtures/schema.sqlite.sql'));
        $pdo->exec("INSERT INTO principals (uri, email, displayname) VALUES ('principals/anna', 'anna@example.org', 'Anna')");
        $principals = new PrincipalBackend($pdo);
        $this->calendars = new CalendarBackend($pdo);
        $this->calendars->createCalendar('principals/anna', 'work', [
            '{DAV:}displayname' => 'Cabinet',
            '{http://apple.com/ns/ical/}calendar-color' => '#3A87ADFF',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Rendez-vous du cabinet',
        ]);
        $this->server = new Server([new Principals($principals), new CalendarRoot($principals, $this->calendars)]);
        $this->server->setBaseUri('/dav/');
        $this->server->sapi = new class extends Sapi {
            public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void
            {
            }
        };
        $this->server->addPlugin(new AuthPlugin(new BasicCallBack(static fn (string $user, string $password) => 'anna' === $user && 'app-password' === $password)));
        $this->server->addPlugin(new AclPlugin());
        $this->server->addPlugin(new CalDavPlugin());
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = [];
        foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }
        $query = \is_array($options['query'] ?? null) && [] !== $options['query'] ? '?'.http_build_query($options['query']) : '';
        $body = \is_string($options['body'] ?? null) ? $options['body'] : null;
        $request = new Request($method, (string) parse_url($url, \PHP_URL_PATH).$query, $headers, $body);
        $this->server->httpRequest = $request;
        $this->server->httpResponse = $response = new Response();
        $this->server->start();
        $answer = [];
        foreach ($response->getHeaders() as $name => $values) {
            $answer[strtolower((string) $name)] = array_values(array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', \is_array($values) ? $values : [$values]));
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options, 'status' => $response->getStatus(), 'warning' => $answer['x-sabre-ew-gross'][0] ?? null];

        return new RecordedResponse($response->getStatus(), $response->getBodyAsString(), $answer, $url);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('No streaming here.');
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
