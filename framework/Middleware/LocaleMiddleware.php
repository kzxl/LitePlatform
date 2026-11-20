<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Lite\Framework\I18n\Translator;
use LiteView\ViewEngine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves and persists active UI locale per request without PHP session locking:
 * Priority: ?lang=xx query param -> lite_locale cookie -> Accept-Language header -> default.
 * Automatically shares $locale, $supportedLocales, and $t() translator helper into LiteView.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'lite_locale';
    public const QUERY_PARAM = 'lang';

    public function __construct(
        private readonly Translator $translator,
        private readonly ?ViewEngine $view = null,
        private readonly bool $detectHeader = true,
        private readonly bool $secureCookie = false
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = $request->getQueryParams();
        $cookies = $request->getCookieParams();
        $shouldPersistCookie = false;

        $resolved = null;

        // 1. Explicit query parameter (?lang=vi or ?lang=en)
        if (isset($query[self::QUERY_PARAM])) {
            $candidate = strtolower(trim((string)$query[self::QUERY_PARAM]));
            if ($this->translator->isSupported($candidate)) {
                $resolved = $candidate;
                $shouldPersistCookie = true;
            }
        }

        // 2. Persisted locale cookie
        if ($resolved === null && isset($cookies[self::COOKIE_NAME])) {
            $candidate = strtolower(trim((string)$cookies[self::COOKIE_NAME]));
            if ($this->translator->isSupported($candidate)) {
                $resolved = $candidate;
            }
        }

        // 3. Browser Accept-Language negotiation
        if ($resolved === null && $this->detectHeader) {
            $resolved = $this->translator->negotiateFromHeader($request->getHeaderLine('Accept-Language'));
        }

        // 4. Fallback to configured default
        if ($resolved !== null) {
            $this->translator->setLocale($resolved);
        }

        $activeLocale = $this->translator->getLocale();

        if ($this->view !== null) {
            $translator = $this->translator;
            $this->view->share('locale', $activeLocale);
            $this->view->share('supportedLocales', $translator->getSupportedLocales());
            $this->view->share('translator', $translator);
            $this->view->share('t', static fn(string $key, array $replace = []): string => $translator->get($key, $replace));
        }

        $request = $request
            ->withAttribute('locale', $activeLocale)
            ->withAttribute('translator', $this->translator);

        $response = $handler->handle($request);

        if ($shouldPersistCookie) {
            $secureFlag = $this->secureCookie ? '; Secure' : '';
            $cookie = sprintf(
                '%s=%s; Path=/; Max-Age=31536000; SameSite=Lax%s',
                self::COOKIE_NAME,
                $activeLocale,
                $secureFlag
            );
            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response->withHeader('Content-Language', $activeLocale);
    }
}
