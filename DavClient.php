<?php

namespace Omnical\Caldav;

use Omnical\Auth\AccessTokenInterface;
use Omnical\Auth\SecretsInterface;
use Omnical\Exception\AuthenticationException;
use Omnical\Exception\ProviderException;
use Omnical\Http\Answer;
use Omnical\Http\RawClient;
use Sabre\Xml\ParseException;
use Sabre\Xml\Service;

/**
 * WebDAV over the core's RawClient: the credentials (Basic, by reference,
 * read at each call; or a bearer token, refreshed once on a 401), PROPFIND
 * and REPORT bodies written with sabre/xml, their 207 answers read with it
 * (DavResponse), hrefs resolved against the address that was asked
 * (sabre/uri), the other calls (GET, PUT, DELETE) as they are.
 */
final class DavClient
{
    public const DAV = 'DAV:';
    public const CALDAV = 'urn:ietf:params:xml:ns:caldav';
    public const CALSERVER = 'http://calendarserver.org/ns/';
    public const APPLE = 'http://apple.com/ns/ical/';

    public function __construct(
        private readonly RawClient $raw,
        private readonly SecretsInterface $secrets,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?AccessTokenInterface $token = null,
        private readonly string $provider = 'CalDAV',
    ) {
    }

    /**
     * @param list<string> $props Clark names
     *
     * @return list<DavResponse>
     */
    public function propfind(string $url, int $depth, array $props): array
    {
        $body = self::service()->write('{DAV:}propfind', ['{DAV:}prop' => array_fill_keys($props, null)]);

        return $this->multistatus('PROPFIND', $url, $body, $depth);
    }

    /**
     * A REPORT whose answer is a 207 (calendar-query, calendar-multiget).
     *
     * @param array<mixed> $body sabre/xml's shape under the root element
     *
     * @return list<DavResponse>
     */
    public function report(string $url, string $root, array $body, int $depth = 1): array
    {
        return $this->multistatus('REPORT', $url, self::service()->write($root, $body), $depth);
    }

    /**
     * @param array<string, string> $headers
     * @param list<int>             $pass    error statuses given back
     */
    public function send(string $method, string $url, ?string $body = null, array $headers = [], array $pass = [404]): Answer
    {
        $options = null === $body ? [] : ['body' => $body];
        try {
            return $this->raw->request($method, $url, $options, $headers + $this->authorization(false), $pass);
        } catch (AuthenticationException $e) {
            if (null === $this->token) {
                throw $e;
            }

            return $this->raw->request($method, $url, $options, $headers + $this->authorization(true), $pass);
        }
    }

    /** An href resolved against the address it was read from. */
    public static function resolve(string $base, string $href): string
    {
        return \Sabre\Uri\resolve($base, $href);
    }

    public static function service(): Service
    {
        $service = new Service();
        $service->namespaceMap = [self::DAV => 'd', self::CALDAV => 'c', self::CALSERVER => 'cs', self::APPLE => 'a'];

        return $service;
    }

    /** @return list<DavResponse> */
    private function multistatus(string $method, string $url, string $body, int $depth): array
    {
        $answer = $this->send($method, $url, $body, ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'], []);
        if (207 !== $answer->status) {
            throw ProviderException::answered($this->provider, $method.' '.(parse_url($url, \PHP_URL_PATH) ?: '/'), $answer->status, 'A 207 Multi-Status was expected.');
        }

        return self::read($answer->body, $this->provider);
    }

    /** @return list<DavResponse> */
    public static function read(string $xml, string $provider = 'CalDAV'): array
    {
        try {
            $root = null;
            $tree = self::service()->parse($xml, null, $root);
        } catch (ParseException $e) {
            throw new ProviderException(\sprintf('%s answered a Multi-Status that is not XML: %s', $provider, $e->getMessage()), 0);
        }
        if ('{DAV:}multistatus' !== $root) {
            throw new ProviderException(\sprintf('%s answered %s where a multistatus was expected.', $provider, (string) $root), 0);
        }
        $responses = [];
        foreach (DavResponse::elements($tree) as $response) {
            if ('{DAV:}response' !== $response['name']) {
                continue;
            }
            $href = null;
            $props = [];
            foreach (DavResponse::elements($response['value']) as $part) {
                if ('{DAV:}href' === $part['name'] && \is_string($part['value'])) {
                    $href ??= trim($part['value']);
                } elseif ('{DAV:}propstat' === $part['name']) {
                    $status = null;
                    $found = [];
                    foreach (DavResponse::elements($part['value']) as $stat) {
                        if ('{DAV:}status' === $stat['name'] && \is_string($stat['value'])) {
                            $status = $stat['value'];
                        } elseif ('{DAV:}prop' === $stat['name']) {
                            foreach (DavResponse::elements($stat['value']) as $prop) {
                                $found[$prop['name']] = $prop['value'];
                            }
                        }
                    }
                    if (null === $status || preg_match('~\s2\d\d(\s|$)~', $status)) {
                        $props += $found;
                    }
                }
            }
            if (null !== $href) {
                $responses[] = new DavResponse($href, $props);
            }
        }

        return $responses;
    }

    /** @return array<string, string> */
    private function authorization(bool $refresh): array
    {
        if (null !== $this->token) {
            return ['Authorization' => 'Bearer '.($refresh ? $this->token->refresh() : $this->token->token())];
        }
        if (null !== $this->username && null !== $this->password) {
            return ['Authorization' => 'Basic '.base64_encode($this->username.':'.$this->secrets->secret($this->password))];
        }

        return [];
    }
}
