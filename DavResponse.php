<?php

namespace Omnical\Caldav;

/**
 * One resource of a 207 Multi-Status answer (RFC 4918 §13): its href as the
 * server wrote it, and the properties it answered 200 for, by Clark name
 * ("{DAV:}displayname"), each as sabre/xml read it - a text, a list of
 * elements (["name" => ..., "value" => ..., "attributes" => ...]), or null
 * for an empty element.
 */
final readonly class DavResponse
{
    /** @param array<string, mixed> $props */
    public function __construct(public string $href, public array $props)
    {
    }

    public function has(string $prop): bool
    {
        return \array_key_exists($prop, $this->props);
    }

    /** A property's text; null when absent or not text. */
    public function text(string $prop): ?string
    {
        $value = $this->props[$prop] ?? null;
        if (\is_string($value)) {
            return '' === trim($value) ? null : $value;
        }

        return null;
    }

    /** @return list<string> the hrefs a property holds ("{DAV:}current-user-principal", "calendar-home-set") */
    public function hrefs(string $prop): array
    {
        $hrefs = [];
        foreach (self::elements($this->props[$prop] ?? null) as $element) {
            if ('{DAV:}href' === $element['name'] && \is_string($element['value']) && '' !== trim($element['value'])) {
                $hrefs[] = trim($element['value']);
            }
        }

        return $hrefs;
    }

    /**
     * Every element name a property holds, at any depth: a resourcetype's
     * ("{DAV:}collection", "{urn:ietf:params:xml:ns:caldav}calendar"), a
     * privilege set's ("{DAV:}write").
     *
     * @return list<string>
     */
    public function names(string $prop): array
    {
        $names = [];
        $walk = static function (mixed $value) use (&$walk, &$names): void {
            foreach (self::elements($value) as $element) {
                $names[] = $element['name'];
                $walk($element['value']);
            }
        };
        $walk($this->props[$prop] ?? null);

        return array_values(array_unique($names));
    }

    /**
     * An attribute of each element a property holds: the "name" of
     * supported-calendar-component-set's comps.
     *
     * @return list<string>
     */
    public function attributes(string $prop, string $element, string $attribute): array
    {
        $values = [];
        foreach (self::elements($this->props[$prop] ?? null) as $one) {
            $value = $one['attributes'][$attribute] ?? null;
            if ($one['name'] === $element && \is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<array{name: string, value: mixed, attributes: array<string, mixed>}> */
    public static function elements(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $elements = [];
        foreach ($value as $one) {
            if (\is_array($one) && \is_string($one['name'] ?? null)) {
                $attributes = [];
                foreach (\is_array($one['attributes'] ?? null) ? $one['attributes'] : [] as $key => $attr) {
                    $attributes[(string) $key] = $attr;
                }
                $elements[] = ['name' => $one['name'], 'value' => $one['value'] ?? null, 'attributes' => $attributes];
            }
        }

        return $elements;
    }
}
