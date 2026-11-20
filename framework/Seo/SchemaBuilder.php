<?php

declare(strict_types=1);

namespace Lite\Framework\Seo;

/**
 * Schema.org JSON-LD builders for common high-impact SEO rich results.
 */
final class SchemaBuilder
{
    /**
     * @param list<string> $sameAs
     * @return array<string, mixed>
     */
    public static function organization(string $name, string $url, ?string $logo = null, array $sameAs = []): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => $name,
            'url'      => rtrim($url, '/'),
        ];

        if ($logo !== null && $logo !== '') {
            $schema['logo'] = $logo;
        }
        if ($sameAs !== []) {
            $schema['sameAs'] = array_values($sameAs);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public static function website(string $name, string $url, ?string $searchUrlTemplate = null): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => rtrim($url, '/'),
        ];

        if ($searchUrlTemplate !== null && $searchUrlTemplate !== '') {
            $schema['potentialAction'] = [
                '@type'       => 'SearchAction',
                'target'      => $searchUrlTemplate,
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $schema;
    }

    /**
     * @param list<array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    public static function breadcrumb(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $index => $item) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => $item['name'],
                'item'     => $item['url'],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function article(
        string $headline,
        string $url,
        string $datePublished,
        ?string $dateModified = null,
        ?string $authorName = null,
        ?string $image = null,
        ?string $description = null
    ): array {
        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $headline,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'datePublished'    => $datePublished,
            'dateModified'     => $dateModified ?? $datePublished,
        ];

        if ($authorName !== null && $authorName !== '') {
            $schema['author'] = ['@type' => 'Person', 'name' => $authorName];
        }
        if ($image !== null && $image !== '') {
            $schema['image'] = [$image];
        }
        if ($description !== null && $description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public static function product(
        string $name,
        string $url,
        float $price,
        string $currency = 'VND',
        bool $inStock = true,
        ?string $image = null,
        ?string $description = null,
        ?string $sku = null
    ): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $name,
            'offers'   => [
                '@type'         => 'Offer',
                'url'           => $url,
                'priceCurrency' => strtoupper($currency),
                'price'         => $price,
                'availability'  => $inStock
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ];

        if ($image !== null && $image !== '') {
            $schema['image'] = [$image];
        }
        if ($description !== null && $description !== '') {
            $schema['description'] = $description;
        }
        if ($sku !== null && $sku !== '') {
            $schema['sku'] = $sku;
        }

        return $schema;
    }

    /**
     * @param list<array{question: string, answer: string}> $entries
     * @return array<string, mixed>
     */
    public static function faq(array $entries): array
    {
        $questions = [];
        foreach ($entries as $entry) {
            $questions[] = [
                '@type'          => 'Question',
                'name'           => $entry['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $entry['answer'],
                ],
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }
}
