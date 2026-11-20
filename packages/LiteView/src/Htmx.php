<?php

declare(strict_types=1);

namespace LiteView;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

/**
 * First-class HTMX integration utilities for PSR-7 / Slim 4 applications.
 */
final class Htmx
{
    /**
     * Check if current request was initiated by HTMX.
     */
    public static function isHtmx(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('HX-Request')) === 'true';
    }

    /**
     * Check if request was triggered via hx-boost.
     */
    public static function isBoosted(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('HX-Boosted')) === 'true';
    }

    /**
     * Get target element ID from HX-Target header.
     */
    public static function getTarget(ServerRequestInterface $request): ?string
    {
        $target = $request->getHeaderLine('HX-Target');
        return $target !== '' ? $target : null;
    }

    /**
     * Get trigger element ID or name from HX-Trigger header.
     */
    public static function getTrigger(ServerRequestInterface $request): ?string
    {
        $trigger = $request->getHeaderLine('HX-Trigger');
        return $trigger !== '' ? $trigger : null;
    }

    /**
     * Trigger client-side event(s) using HX-Trigger response header.
     *
     * @param string|array<string, mixed> $event Event name or mapping of event names to detail objects
     */
    public static function trigger(ResponseInterface $response, string|array $event): ResponseInterface
    {
        $value = is_array($event) ? json_encode($event, JSON_UNESCAPED_UNICODE) : $event;
        return $response->withHeader('HX-Trigger', (string)$value);
    }

    /**
     * Instruct HTMX to perform a full client-side redirect.
     */
    public static function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withHeader('HX-Redirect', $url);
    }

    /**
     * Instruct HTMX to do a full page refresh.
     */
    public static function refresh(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('HX-Refresh', 'true');
    }

    /**
     * Override swap style via HX-Reswap (e.g. 'outerHTML', 'innerHTML', 'beforeend').
     */
    public static function reswap(ResponseInterface $response, string $swap): ResponseInterface
    {
        return $response->withHeader('HX-Reswap', $swap);
    }

    /**
     * Override target element via HX-Retarget (CSS selector).
     */
    public static function retarget(ResponseInterface $response, string $target): ResponseInterface
    {
        return $response->withHeader('HX-Retarget', $target);
    }

    /**
     * Wrap HTML content with an Out-of-Band (OOB) swap attribute for HTMX.
     *
     * @param string $html Inner or outer HTML to be swapped
     * @param string|bool $oob Either true ("true"), or target selector e.g. "outerHTML:#sidebar"
     */
    public static function oob(string $html, string|bool $oob = true): string
    {
        $attrVal = is_bool($oob) ? ($oob ? 'true' : 'false') : $oob;
        $trimmed = trim($html);

        if (preg_match('/^<([a-zA-Z0-9\-]+)([^>]*)>/s', $trimmed, $matches)) {
            $tag = $matches[1];
            $existingAttrs = $matches[2];
            $newTag = "<{$tag} hx-swap-oob=\"{$attrVal}\"{$existingAttrs}>";
            return preg_replace('/^<' . preg_quote($tag, '/') . '[^>]*>/s', $newTag, $trimmed, 1) ?? $html;
        }

        return "<div hx-swap-oob=\"{$attrVal}\">{$html}</div>";
    }
}
