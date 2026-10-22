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
final class McpDeckDependencyWritesTest extends IntegrationTestCase {
	private function call(string $uid, array $args, bool $remove = false, bool $grant = true): array {
		return DeckFixture::run($uid, 'mcp-dependency', ['arguments' => $args, 'remove' => $remove, 'grant' => $grant]);
	}

	private function data(array $out): array {
		self::assertFalse($out['result']['isError'] ?? false, json_encode($out));
		return $out['result']['structuredContent']
			?? json_decode($out['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}

	private function seed(): ?array {
		$f = DeckFixture::run(DeckFixture::OWNER, 'seed');
		if ((int)getenv('MCP_IT_NC') < 34) {
			foreach ([false, true] as $remove) {
				$out = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'targetId' => $f['b']], $remove);
				self::assertTrue($out['refused']);
				self::assertTrue($out['directRefused']);
				self::assertNotContains('deck_add_card_dependency', $out['tools']);
				self::assertNotContains('deck_remove_card_dependency', $out['tools']);
			}
			return null;
		}
		return $f;
	}

	private function ids(array $f): array {
		return DeckFixture::run(DeckFixture::OWNER, 'dependency-ui-state', $f)['ids'];
	}

	private function native(int $a, int $b, bool $remove = false): void {
		DeckFixture::run(DeckFixture::OWNER, 'dependency-native-link', ['origin' => $a, 'target' => $b, 'remove' => $remove]);
	}

	public function testPreviewConfirmedNativeUiStateIdempotenceAndUnrelatedPreservation(): void {
		$f = $this->seed();
		if ($f === null) { return; }
		foreach ([DeckFixture::OWNER, DeckFixture::EDITOR] as $uid) {
			$this->native($f['a'], $f['privateCard']);
			$args = ['cardId' => $f['a'], 'targetId' => $f['b']];
			$p = $this->data($this->call($uid, $args));
			self::assertTrue($p['requiresConfirmation']);
			self::assertSame([$f['privateCard']], $this->ids($f));
			$confirmed = $args + ['confirm' => true, 'plan_state' => $p['plan_state']];
			if ($uid === DeckFixture::EDITOR) {
				self::assertSame('shared', $this->data($this->call($uid, $confirmed))['scope']);
				self::assertSame([$f['privateCard']], $this->ids($f));
			}
			$r = $this->data($this->call($uid, $confirmed + ['confirm_shared' => true]));
			self::assertTrue($r['confirmed']);
			$p = $this->data($this->call($uid, $args));
			$r = $this->data($this->call($uid, $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $p['plan_state']]));
			self::assertFalse($r['changed']);
			$expected = [$f['b'], $f['privateCard']]; sort($expected);
			self::assertSame($expected, $this->ids($f), 'Native cardinality after duplicate');
			$p = $this->data($this->call($uid, $args, true));
			self::assertTrue($this->data($this->call($uid, $args + ['confirm' => true, 'confirm_shared' => true,
				'plan_state' => $p['plan_state']], true))['confirmed']);
			self::assertSame([$f['privateCard']], $this->ids($f), 'Native UI keeps unrelated link');
			$p = $this->data($this->call($uid, $args, true));
			self::assertFalse($this->data($this->call($uid, $args + ['confirm' => true, 'confirm_shared' => true,
				'plan_state' => $p['plan_state']], true))['changed']);
		}
	}

	public function testRefusesSelfTwoAndThreeNodeCyclesThatNativeDeckAccepts(): void {
		$f = $this->seed(); if ($f === null) { return; }
		$f += DeckFixture::run(DeckFixture::OWNER, 'dependency-extra', $f);
		foreach ([[$f['a'], $f['a'], []], [$f['a'], $f['b'], [[$f['b'], $f['a']]]],
			[$f['a'], $f['b'], [[$f['b'], $f['c']], [$f['c'], $f['a']]]]] as [$a, $b, $edges]) {
			foreach ($edges as [$from, $to]) { $this->native($from, $to); }
			$out = $this->call(DeckFixture::EDITOR, ['cardId' => $a, 'targetId' => $b]);
			self::assertTrue($out['result']['isError']);
			self::assertNotContains($b, $this->ids($f));
			$this->native($a, $b);
			self::assertContains($b, $this->ids($f), 'Native accepts the same self/cycle relation');
			$this->native($a, $b, true);
			foreach ($edges as [$from, $to]) { $this->native($from, $to, true); }
		}
	}

	public function testHiddenCycleHasIdenticalPublicPlanAndDirectHiddenTargetMatchesMissing(): void {
		$f = $this->seed(); if ($f === null) { return; }
		$args = ['cardId' => $f['a'], 'targetId' => $f['b']];
		$this->native($f['b'], $f['privateCard']);
		$this->native($f['privateCard'], $f['a']);
		$before = $this->call(DeckFixture::EDITOR, $args);
		$this->native($f['b'], $f['privateCard'], true);
		self::assertSame($before['result'], $this->call(DeckFixture::EDITOR, $args)['result'], 'Includes identical opaque plan_state');
		$this->native($f['b'], $f['privateCard']);
		$p = $this->data($before);
		$r = $this->data($this->call(DeckFixture::EDITOR, $args + ['confirm' => true, 'confirm_shared' => true,
			'plan_state' => $p['plan_state']]));
		self::assertTrue($r['confirmed']);
		self::assertStringContainsString('cards you cannot read', $r['message']);
		self::assertStringContainsString('those cards', $r['message']);
		foreach ([false, true] as $remove) {
			$hidden = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'targetId' => $f['privateCard']], $remove);
			$missing = $this->call(DeckFixture::EDITOR, ['cardId' => $f['a'], 'targetId' => 2147483000], $remove);
			self::assertSame($missing['result'], $hidden['result']);
			self::assertTrue($hidden['result']['isError']);
			self::assertTrue($this->call(DeckFixture::READER, $args, $remove)['result']['isError']);
		}
	}

	public function testChangedRelationGraphRevocationDeletionAndGrantRequireFreshApproval(): void {
		foreach (['relation', 'graph', 'label-revoke', 'trash-reference', 'target-revoke', 'card-archive', 'board-archive', 'grant'] as $change) {
			$f = $this->seed(); if ($f === null) { return; }
			$target = in_array($change, ['trash-reference', 'target-revoke'], true) ? $f['privateCard'] : $f['b'];
			if (in_array($change, ['trash-reference', 'target-revoke'], true)) {
				DeckFixture::run(DeckFixture::OWNER, 'dependency-private-access', $f + ['allow' => true]);
			}
			$args = ['cardId' => $f['a'], 'targetId' => $target];
			$p = $this->data($this->call(DeckFixture::EDITOR, $args));
			if ($change === 'relation') { $this->native($f['a'], $target); }
			elseif ($change === 'graph') { $this->native($target, $f['a']); }
			elseif ($change === 'target-revoke') {
				DeckFixture::run(DeckFixture::OWNER, 'dependency-private-access', $f + ['allow' => false]);
			} elseif (in_array($change, ['card-archive', 'board-archive'], true)) {
				DeckFixture::run(DeckFixture::OWNER, 'label-archive', $f + ['target' => $change === 'card-archive' ? 'card' : 'board']);
			} elseif ($change !== 'grant') { DeckFixture::run(DeckFixture::OWNER, $change, $f); }
			$out = $this->call(DeckFixture::EDITOR, $args + ['confirm' => true, 'confirm_shared' => true,
				'plan_state' => $p['plan_state']], false, $change !== 'grant');
			if ($change === 'relation') { self::assertTrue($this->data($out)['requiresConfirmation']); }
			else { self::assertTrue(($out['result']['isError'] ?? false) || ($out['refused'] ?? false)); }
			if ($change !== 'relation') { self::assertNotContains($target, $this->ids($f)); }
		}
	}
}
