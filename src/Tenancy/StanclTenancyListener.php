<?php

declare(strict_types=1);

namespace Laravel\RedisShard\Tenancy;

/**
 * Listener for stancl/tenancy's TenancyInitialized event. Duck-typed so the
 * bridge loads even when the package is absent; the provider only registers
 * the listener when the package is installed and the driver is enabled.
 */
class StanclTenancyListener
{
    public function __construct(protected TenancyShardBridge $bridge)
    {
    }

    /**
     * @param object $event Stancl\Tenancy\Events\TenancyInitialized
     */
    public function handle(object $event): void
    {
        $tenant = $event->tenancy->tenant ?? null;

        if ($tenant === null) {
            return;
        }

        $tenantId = method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : $tenant->getKey();

        $this->bridge->makeCurrent($tenantId);
    }
}
