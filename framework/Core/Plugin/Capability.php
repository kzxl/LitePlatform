<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

/**
 * Least-privilege capabilities a LitePlatform plugin may declare in its PluginManifest.
 * Requesting an undeclared capability through PluginContext throws a RuntimeException.
 */
enum Capability: string
{
    case DB_READ         = 'db.read';
    case DB_WRITE        = 'db.write';
    case CACHE_SCOPED    = 'cache.scoped';
    case MAIL_SEND       = 'mail.send';
    case HTTP_OUTBOUND   = 'http.outbound';
    case ADMIN_UI        = 'admin.ui';
    case PUBLIC_ROUTES   = 'public.routes';
    case HOOKS_SUBSCRIBE = 'hooks.subscribe';
    case SECRETS_SCOPED  = 'secrets.scoped';
    case FILES_UPLOAD    = 'files.upload';
}
