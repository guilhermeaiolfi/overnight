<?php

declare(strict_types=1);

namespace ON\Maintenance;

interface MaintenanceModeInterface
{
	public function isMaintenanceMode(): bool;
}
