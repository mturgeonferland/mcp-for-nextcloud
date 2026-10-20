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
final class McpDeckDependenciesTest extends IntegrationTestCase {
	private function read(string $uid, int $cardId, bool $grant = true): array {
		return DeckFixture::run($uid, 'dependency-read', ['cardId' => $cardId, 'grant' => $grant]);
	}

	private function data(array $out): array {
		self::assertFalse($out['result']['isError'] ?? false, json_encode($out));
		return json_decode($out['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	public function testActualToolsListAndDirectCallMatchInstalledNativeApi(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$out = $this->read(DeckFixture::READER, $f['a']);
		if ((int)getenv('MCP_IT_NC') < 34) {
			self::assertNotContains('deck_read_card_dependencies', $out['tools']);
			self::assertTrue($out['refused']);
			self::assertTrue($out['directRefused']);
		} else {
			self::assertContains('deck_read_card_dependencies', $out['tools']);
			self::assertSame([], $this->data($out)['edges']);
		}
		$denied = $this->read(DeckFixture::READER, $f['a'], false);
		self::assertNotContains('deck_read_card_dependencies', $denied['tools']);
		self::assertTrue($denied['refused']);
	}

	public function testHiddenEdgesHaveExactlyTheSamePublicAnswerAsAbsentEdgesForReaderAndEditor(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$native = DeckFixture::run(DeckFixture::OWNER, 'dependency-seed', $f);
		if (!$native['nativeAvailable']) {
			self::assertLessThan(34, (int)getenv('MCP_IT_NC'));
			return;
		}
		$before = [];
		foreach ([DeckFixture::READER, DeckFixture::EDITOR] as $uid) {
			$before[$uid] = $this->read($uid, $f['a']);
			$graph = $this->data($before[$uid]);
			self::assertSame([$f['a'], $f['b']], array_column($graph['nodes'], 'id'));
			self::assertSame([['originId' => $f['a'], 'targetId' => $f['b']]], $graph['edges']);
			self::assertArrayNotHasKey('hidden', $graph);
			self::assertArrayNotHasKey('total', $graph);
			self::assertSame([], $this->data($this->read($uid, $f['b']))['edges']);
		}
		DeckFixture::run(DeckFixture::OWNER, 'dependency-hide-remove', $f);
		foreach ([DeckFixture::READER, DeckFixture::EDITOR] as $uid) {
			self::assertSame($before[$uid]['result'], $this->read($uid, $f['a'])['result']);
		}
	}

	public function testReadableCrossBoardTargetRevocationDeletionAndNativeCycle(): void {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		$native = DeckFixture::run(DeckFixture::OWNER, 'dependency-seed', $f);
		if (!$native['nativeAvailable']) {
			self::assertLessThan(34, (int)getenv('MCP_IT_NC'));
			return;
		}
		$hidden = $this->read(DeckFixture::EDITOR, $f['a']);
		DeckFixture::run(DeckFixture::OWNER, 'dependency-private-access', $f + ['allow' => true]);
		$visible = $this->data($this->read(DeckFixture::EDITOR, $f['a']));
		self::assertContains($f['privateCard'], array_column($visible['nodes'], 'id'));
		self::assertCount(2, $visible['edges']);
		DeckFixture::run(DeckFixture::OWNER, 'dependency-private-access', $f + ['allow' => false]);
		self::assertSame($hidden['result'], $this->read(DeckFixture::EDITOR, $f['a'])['result']);
		DeckFixture::run(DeckFixture::OWNER, 'trash-reference', $f);
		self::assertNotContains($f['privateCard'], array_column($this->data($this->read(DeckFixture::OWNER, $f['a']))['nodes'], 'id'));
		DeckFixture::run(DeckFixture::OWNER, 'dependency-cycle', $f);
		$cycle = $this->data($this->read(DeckFixture::EDITOR, $f['a']));
		self::assertTrue($cycle['complete']);
		self::assertCount(2, $cycle['nodes']);
		self::assertCount(2, $cycle['edges']);
		self::assertTrue($this->read(DeckFixture::OUTSIDER, $f['a'])['result']['isError']);
	}
}
