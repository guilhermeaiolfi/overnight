<?php

declare(strict_types=1);

namespace Tests\ON\Maintenance;

use Laminas\Diactoros\ServerRequest;
use ON\Application;
use ON\Maintenance\MaintenanceCommand;
use ON\Maintenance\MaintenanceExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

final class MaintenanceCommandTest extends TestCase
{
	public function testUpdatesEnvWhilePreservingCommentsAndBlankLines(): void
	{
		$originalDirectory = getcwd();
		$directory = sys_get_temp_dir() . '/on-maintenance-' . bin2hex(random_bytes(8));
		$this->assertTrue(mkdir($directory));
		$file = $directory . '/.env';

		try {
			file_put_contents($file, "# Production settings\n\nSERVICE_NAME=overnight\nAPP_MAINTENANCE=false");
			chdir($directory);
			$command = new MaintenanceCommand($this->createStub(Application::class));
			$command->updateEnv(['APP_MAINTENANCE' => 'true']);

			$this->assertSame(
				"# Production settings\n\nSERVICE_NAME=overnight\nAPP_MAINTENANCE=true\n",
				file_get_contents($file)
			);
		} finally {
			chdir($originalDirectory);
			unlink($file);
			rmdir($directory);
		}
	}

	public function testRendersTheBundledMaintenancePage(): void
	{
		$extension = new MaintenanceExtension($this->createStub(Application::class));
		$response = $extension->process(new ServerRequest(), $this->createStub(RequestHandlerInterface::class));

		$this->assertSame(503, $response->getStatusCode());
		$this->assertStringContainsString('maintenance', strtolower((string) $response->getBody()));
	}
}
