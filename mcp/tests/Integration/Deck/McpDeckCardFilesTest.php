<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('deck')]
final class McpDeckCardFilesTest extends IntegrationTestCase {
	private function seed(): array {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		return $f + DeckFixture::run(DeckFixture::OWNER, 'files-prepare', $f);
	}

	private function call(string $uid, array $args, bool $unlink = false, bool $filesRead = true): array {
		return DeckFixture::run($uid, 'mcp-card-files', ['arguments' => $args, 'unlink' => $unlink, 'filesRead' => $filesRead]);
	}

	private function data(array $out): array {
		self::assertArrayHasKey('result', $out, json_encode($out));
		self::assertFalse($out['result']['isError'] ?? false, json_encode($out));
		return $out['result']['structuredContent'] ?? json_decode($out['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	private function args(array $f): array {
		return ['cardId' => $f['a'], 'attachmentId' => $f['visibleShare']];
	}

	private function confirmed(string $uid, array $f): array {
		$args = $this->args($f);
		$p = $this->data($this->call($uid, $args, true));
		self::assertTrue($p['requiresConfirmation']);
		self::assertSame(DeckFixture::OWNER, $p['createdBy']);
		self::assertStringContainsString('all current and future', $p['message']);
		return $args + ['confirm' => true, 'plan_state' => $p['plan_state'], 'file_state' => $p['file_state']];
	}

	private function preserved(array $f): array {
		$observed = DeckFixture::run(DeckFixture::OWNER, 'files-inspect', $f);
		self::assertSame($f['visibleFileId'], $observed['fileId']);
		self::assertSame($f['visibleHash'], $observed['hash']);
		self::assertContains($f['secondShare'], $observed['b']);
		self::assertContains($f['hiddenShare'], $observed['a']);
		self::assertContains($f['folderShare'], $observed['a']);
		self::assertTrue($observed['independentExists']);
		// M1: the independent share must target this exact file, not only its parent folder.
		self::assertSame($f['visibleFileId'], $f['independentFileShare']['fileId']);
		self::assertSame(0, $f['independentFileShare']['type'], 'Native user share, independent of Deck');
		self::assertSame(1, $f['independentFileShare']['permissions']);
		self::assertSame(DeckFixture::EDITOR, $f['independentFileShare']['recipient']);
		self::assertSame($f['independentFileShare'], $observed['independentFileShare'],
			'Direct file share keeps its full/bare ID, fileid, type, permissions and recipient');
		return $observed;
	}

	public function testReadersSeeOnlyVisibleFileReferencesAndCannotBlindlyUnlink(): void {
		$f = $this->seed();
		foreach ([DeckFixture::READER, DeckFixture::EDITOR] as $uid) {
			$out = $this->call($uid, ['cardId' => $f['a']]);
			$items = $this->data($out)['items'];
			self::assertSame([$f['visibleShare']], array_column($items, 'attachmentId'));
			self::assertSame([$f['visibleFileId']], array_column($items, 'fileId'));
			self::assertStringNotContainsString($f['secretName'], json_encode($out));
		}
		$missing = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'attachmentId' => 2147483000], true);
		$hidden = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'attachmentId' => $f['hiddenShare']], true);
		self::assertSame($missing['result'], $hidden['result']);
		$folder = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'attachmentId' => $f['folderShare']], true);
		self::assertTrue($folder['result']['isError']);
		self::assertSame('Folders cannot be linked or unlinked here. Choose a file.', $folder['result']['content'][0]['text']);
		self::assertTrue($this->call(DeckFixture::READER, $this->args($f), true)['result']['isError']);
		self::assertTrue($this->call(DeckFixture::EDITOR, ['cardId' => $f['a']], false, false)['result']['isError']);
		self::assertContains($f['visibleShare'], $this->preserved($f)['a']);
	}

	public function testEditorUnlinksVisibleThirdPartyShareOnlyAfterBothConfirmations(): void {
		$f = $this->seed();
		$args = $this->confirmed(DeckFixture::EDITOR, $f);
		self::assertContains($f['visibleShare'], $this->preserved($f)['a']);
		self::assertSame('shared', $this->data($this->call(DeckFixture::EDITOR, $args, true))['scope']);
		self::assertContains($f['visibleShare'], $this->preserved($f)['a']);
		$r = $this->data($this->call(DeckFixture::EDITOR, $args + ['confirm_shared' => true], true));
		self::assertTrue($r['confirmed']);
		self::assertTrue($r['unlinked']);
		self::assertArrayNotHasKey('file', $r, 'Do not echo a pre-write file snapshot');
		self::assertNotContains($f['visibleShare'], $this->preserved($f)['a']);
		self::assertSame([], $this->data($this->call(DeckFixture::EDITOR, ['cardId' => $f['a']]))['items']);
	}

	public function testOriginalSignedReceiptIsIdempotentButModifiedReceiptAndReplayAreRefused(): void {
		$f = $this->seed();
		$args = $this->confirmed(DeckFixture::OWNER, $f);
		$missing = $this->call(DeckFixture::OWNER, ['cardId' => $f['a'], 'attachmentId' => 2147483000], true)['result'];
		foreach (['fileId' => $f['visibleFileId'] + 1, 'createdBy' => 'forged', 'createdAt' => $args['file_state']['createdAt'] + 1] as $key => $value) {
			$bad = $args;
			$bad['file_state'][$key] = $value;
			self::assertSame($missing, $this->call(DeckFixture::OWNER, $bad, true)['result']);
		}
		self::assertSame($missing, $this->call(DeckFixture::EDITOR, $args + ['confirm_shared' => true], true)['result']);
		$bad = $args;
		$bad['cardId'] = $f['b'];
		self::assertSame($missing, $this->call(DeckFixture::OWNER, $bad, true)['result']);
		self::assertTrue($this->data($this->call(DeckFixture::OWNER, $args, true))['changed']);
		self::assertFalse($this->data($this->call(DeckFixture::OWNER, $args, true))['changed']);
		$bad = $args;
		$bad['file_state']['createdBy'] = 'forged';
		self::assertSame($missing, $this->call(DeckFixture::OWNER, $bad, true)['result']);
		DeckFixture::run(DeckFixture::OWNER, 'files-tag-visible', $f);
		self::assertSame($missing, $this->call(DeckFixture::OWNER, $args, true)['result']);
		self::assertNotContains($f['visibleShare'], $this->preserved($f)['a']);
	}

	public function testScopeTagsFileGrantAndNativeSourceRevocationPreventStaleUnlink(): void {
		foreach (['scope', 'tag', 'files-grant', 'source-acl', 'archive'] as $change) {
			$f = $this->seed();
			$args = $this->confirmed(DeckFixture::EDITOR, $f) + ['confirm_shared' => true];
			if ($change === 'scope') {
				DeckFixture::run(DeckFixture::OWNER, 'files-scope', ['actor' => DeckFixture::EDITOR, 'items' => []]);
				self::assertSame([], $this->data($this->call(DeckFixture::EDITOR, ['cardId' => $f['a']]))['items']);
			} elseif ($change === 'tag') {
				DeckFixture::run(DeckFixture::OWNER, 'files-tag-visible', $f);
			} elseif ($change === 'source-acl') {
				DeckFixture::run(DeckFixture::OWNER, 'label-revoke', $f);
			} elseif ($change === 'archive') {
				DeckFixture::run(DeckFixture::OWNER, 'label-archive', $f + ['target' => 'card']);
			}
			$out = $this->call(DeckFixture::EDITOR, $args, true, $change !== 'files-grant');
			self::assertTrue($out['result']['isError'], json_encode($out));
			self::assertStringNotContainsString('visible.txt', json_encode($out['result']));
			self::assertContains($f['visibleShare'], $this->preserved($f)['a']);
			if ($change === 'scope') {
				DeckFixture::run(DeckFixture::OWNER, 'files-scope', ['actor' => DeckFixture::EDITOR, 'items' => null]);
			}
		}
	}
}
