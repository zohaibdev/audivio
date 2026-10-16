<?php

namespace App\Services\Seo;

use App\Models\AuditPage;

/**
 * Reads the JSON-LD a page publishes and reports which schema.org types
 * it declares and which documented properties are missing (§44).
 *
 * JSON-LD in the wild is not a flat list of objects. A single script tag
 * may hold a `@graph` of a dozen nodes, an array at the top level, or a
 * node whose `@type` is itself an array. All of those are flattened here
 * before anything is judged, because a Product nested three levels inside
 * a graph is still a Product the site published.
 *
 * **Only JSON-LD is read.** Microdata and RDFa are not extracted by the
 * crawler, so this reports what it can see and the report says so rather
 * than implying a page has no structured data at all.
 */
class SchemaAnalyzer
{
    /**
     * Every schema node on a page, flattened, with its type resolved.
     *
     * @return array<int, array{type: string|null, raw_type: mixed, node: array<string, mixed>}>
     */
    public function nodes(AuditPage $page): array
    {
        $flattened = [];

        foreach ($this->asList($page->structured_data) as $block) {
            $this->flatten($block, $flattened);
        }

        return $flattened;
    }

    /**
     * The distinct schema types declared on a page, as their canonical
     * (alias-resolved) names.
     *
     * @return string[]
     */
    public function typesOn(AuditPage $page): array
    {
        $types = [];

        foreach ($this->nodes($page) as $node) {
            $canonical = $this->canonicalType($node['raw_type']);

            if ($canonical !== null) {
                $types[$canonical] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * Validate one page's schema nodes against the documented property
     * requirements.
     *
     * Only types this product reports on are checked. An unrecognized type
     * is neither validated nor complained about: schema.org is large, and
     * inventing requirements for a type we have no reference for would
     * produce confident nonsense.
     *
     * @return array<int, array{type: string, label: string, missing_required: string[], missing_recommended: string[]}>
     */
    public function validate(AuditPage $page): array
    {
        $definitions = config('seo_schema.types');
        $results = [];

        foreach ($this->nodes($page) as $node) {
            $canonical = $this->canonicalType($node['raw_type']);

            if ($canonical === null || ! isset($definitions[$canonical])) {
                continue;
            }

            $definition = $definitions[$canonical];

            $missingRequired = array_values(array_filter(
                $definition['required'],
                fn (string $property) => ! $this->hasProperty($node['node'], $property),
            ));

            $missingRecommended = array_values(array_filter(
                $definition['recommended'],
                fn (string $property) => ! $this->hasProperty($node['node'], $property),
            ));

            if ($missingRequired === [] && $missingRecommended === []) {
                continue;
            }

            $results[] = [
                'type' => $canonical,
                'label' => $definition['label'],
                'missing_required' => $missingRequired,
                'missing_recommended' => $missingRecommended,
            ];
        }

        return $results;
    }

    /**
     * Resolve a raw `@type` — which may be a string, an array, or a full
     * schema.org URL — to one of the types this product reports on.
     */
    public function canonicalType(mixed $rawType): ?string
    {
        $definitions = config('seo_schema.types');

        foreach ($this->asList($rawType) as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            // "https://schema.org/Product" and "schema:Product" both mean
            // Product; only the last segment carries the type name.
            $name = preg_replace('~^.*[/:#]~', '', trim($candidate));

            if (isset($definitions[$name])) {
                return $name;
            }

            foreach ($definitions as $type => $definition) {
                if (in_array($name, $definition['aliases'], true)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * A property counts as present when it exists and holds something.
     * An empty string or empty array is a declared-but-unfilled property,
     * which is worth exactly as much to a search engine as no property.
     *
     * @param  array<string, mixed>  $node
     */
    private function hasProperty(array $node, string $property): bool
    {
        if (! array_key_exists($property, $node)) {
            return false;
        }

        $value = $node[$property];

        if ($value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }

    /**
     * Walk a JSON-LD block into a flat list of typed nodes, following
     * `@graph` and nested arrays.
     *
     * @param  array<int, array{type: string|null, raw_type: mixed, node: array<string, mixed>}>  $out
     */
    private function flatten(mixed $block, array &$out, int $depth = 0): void
    {
        // Deeply nested graphs exist; unbounded recursion over
        // attacker-supplied JSON does not need to.
        if ($depth > 6 || ! is_array($block)) {
            return;
        }

        if (array_is_list($block)) {
            foreach ($block as $entry) {
                $this->flatten($entry, $out, $depth + 1);
            }

            return;
        }

        if (isset($block['@graph'])) {
            $this->flatten($block['@graph'], $out, $depth + 1);
        }

        if (isset($block['@type'])) {
            $out[] = [
                'type' => is_string($block['@type']) ? $block['@type'] : null,
                'raw_type' => $block['@type'],
                'node' => $block,
            ];
        }

        // Nested typed entities — a Product's `brand`, an Article's
        // `publisher` — are real declarations too, and a site whose only
        // Organization node is nested inside its Article still declares
        // one.
        foreach ($block as $key => $value) {
            if ($key !== '@graph' && is_array($value)) {
                $this->flatten($value, $out, $depth + 1);
            }
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function asList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) && array_is_list($value) ? $value : [$value];
    }
}
