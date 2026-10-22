<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use PHPUnit\Framework\TestCase;

/** Recursive companion to NoFileDeletionTest, including the new handler subdirectory and the unchanged sole allowance. */
final class TreeDeletionBoundaryTest extends TestCase {
	/** The only delete remains the guarded empty folder created by that very batch, in its undo handler.
	 * @return void
	 */
	public function testNoNewHandlerMayDeleteAFileOrUndoAnUnrecordedFolder(): void {
		$base = __DIR__ . '/../../../../lib/Tools/Files';
		$found = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$source = (string)file_get_contents($file->getPathname());
			if (!str_contains($source, '->delete()')) {
				continue;
			}
			$found[] = $file->getBasename();
			self::assertSame('UndoBatchHandler.php', $file->getBasename());
			self::assertSame(1, substr_count($source, '->delete()'));
			self::assertStringContainsString('$this->context->reorganization->byDepth($batch->dirs)', $source);
			self::assertStringContainsString('!$folder instanceof Folder || !$folder->isDeletable() || $folder->getDirectoryListing() !== []', $source);
		}
		self::assertSame(['UndoBatchHandler.php'], $found);
	}
}
