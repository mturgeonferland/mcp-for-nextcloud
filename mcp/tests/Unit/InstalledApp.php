<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;

/**
 * The installed MCP app, as unit tests stand in for IAppManager.
 *
 * The version comes from appinfo/info.xml. The test creates the mock; this helper only
 * configures getAppVersion, which answers solely for mcp and leaves the core cache on.
 */
final class InstalledApp {
	/** App manifest the announced version has to match. */
	private const MANIFEST = __DIR__ . '/../../appinfo/info.xml';

	/**
	 * Version declared in appinfo/info.xml.
	 *
	 * @return string the version element, never empty
	 */
	public static function version(): string {
		$version = (string) simplexml_load_file(self::MANIFEST)->version;
		if ($version === '') {
			throw new \RuntimeException('appinfo/info.xml has no version');
		}
		return $version;
	}

	/**
	 * Point getAppVersion at one version.
	 *
	 * A call for another app, or with the cache turned off, fails the test.
	 *
	 * @param IAppManager&MockObject $apps mock created by the test
	 * @param string|null $version value to return; the manifest version when omitted
	 * @param InvocationOrder|null $calls how often getAppVersion must run; any count when omitted
	 */
	public static function mock(IAppManager&MockObject $apps, ?string $version = null, ?InvocationOrder $calls = null): void {
		$configured = $calls === null ? $apps : $apps->expects($calls);
		$configured->method('getAppVersion')->willReturnCallback(static function (string $appId, bool $useCache = true) use ($version): string {
			if ($appId !== 'mcp' || $useCache !== true) {
				throw new \LogicException('server info reads getAppVersion("mcp") with the core cache on');
			}
			return $version ?? self::version();
		});
	}
}
