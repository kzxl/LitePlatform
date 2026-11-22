<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use DI\Container;
use Lite\Framework\Seo\SitemapGenerator;

/**
 * Optional capability interface allowing any Plugin Module to contribute
 * database migrations, namespaced LiteView templates, navbar links, and Sitemap URLs.
 */
interface WebModuleInterface
{
    public function getMigrationsPath(): ?string;

    public function getViewNamespace(): ?string;

    public function getViewsPath(): ?string;

    /**
     * @return list<array{label: string, url: string}>
     */
    public function getNavLinks(): array;

    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void;
}
