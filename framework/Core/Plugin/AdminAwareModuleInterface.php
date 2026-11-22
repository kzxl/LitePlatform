<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use DI\Container;
use LiteAdmin\AdminDashboard;

/**
 * Optional capability interface allowing any Plugin Module to register
 * Admin Entities, Custom Pages (e.g. Reports), KPI Metrics, and Custom Fields
 * without modifying framework core or config/app.php.
 */
interface AdminAwareModuleInterface
{
    public function registerAdmin(AdminDashboard $admin, Container $container): void;
}
