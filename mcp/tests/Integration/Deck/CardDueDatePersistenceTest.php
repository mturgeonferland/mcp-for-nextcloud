<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Date-only writes survive native timezone-less storage and every existing read surface.
 */
#[Group('deck')]
final class CardDueDatePersistenceTest extends IntegrationTestCase {
	/**
	 * Accounts on opposite sides of UTC and their independent expected midnight wire values.
	 *
	 * @return iterable<string, array{string, string, string}> UID, account timezone and October1 midnight UTC.
	 */
	public static function accounts(): iterable {
		yield 'Brazil' => [DeckFixture::OWNER, 'America/Sao_Paulo', '2026-10-01T03:00:00Z'];
		yield 'Japan' => [DeckFixture::EDITOR, 'Asia/Tokyo', '2026-09-30T15:00:00Z'];
	}

	/**
	 * Run an HTTP scenario with exact restoration of account timezone and the app's configuration.
	 *
	 * @param string $uid Disposable account to authenticate over HTTP Basic.
	 * @param string $zone Account preference, without changing PHP's or the server's global timezone.
	 * @param string|null $instant Optional native UTC fixture instant.
	 * @param callable(McpClient, array<string, int>):void $body Scenario using the owned resource ledger.
	 * @return void
	 */
	private function scenario(string $uid, string $zone, ?string $instant, callable $body): void {
		$config = Server::get(IConfig::class);
		$oldZone = $config->getUserValue($uid, 'core', 'timezone', '__missing__');
		$oldApp = [];
		foreach ($config->getAppKeys('mcp') as $key) {
			$oldApp[$key] = $config->getAppValue('mcp', $key);
		}
		$f = null;
		try {
			$config->setUserValue($uid, 'core', 'timezone', $zone);
			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			foreach (['read', 'create', 'edit'] as $operation) {
				$policy->setGrant($uid, 'deck', $operation, true);
			}
			$f = DeckFixture::run($uid, 'date-seed', ['instant' => $instant]);
			$body(new McpClient(LoginFactory::basic($uid)), $f);
		} finally {
			if ($f !== null) {
				DeckFixture::run($uid, 'date-cleanup', $f);
			}
			if ($oldZone === '__missing__') {
				$config->deleteUserValue($uid, 'core', 'timezone');
			} else {
				$config->setUserValue($uid, 'core', 'timezone', $oldZone);
			}
			foreach ($config->getAppKeys('mcp') as $key) {
				if (!array_key_exists($key, $oldApp)) {
					$config->deleteAppValue('mcp', $key);
				}
			}
			foreach ($oldApp as $key => $value) {
				$config->setAppValue('mcp', $key, $value);
			}
		}
	}

	/**
	 * Decode only a successful authenticated tool response.
	 *
	 * @param ToolReply $reply Actual HTTP response, never an in-process module call.
	 * @return array<string, mixed> Public tool payload.
	 */
	private function payload(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		$data = $reply->structured();
		if ($data === []) {
			$data = $reply->json();
		}
		self::assertIsArray($data, $reply->describe());
		return $data;
	}

	/**
	 * Assert the same local day on read, list and followup after a separate persisted write.
	 *
	 * @param McpClient $client Authenticated client with the configured account timezone.
	 * @param array<string, int> $f Owned board and stack identities.
	 * @param int $cardId Persisted card to find on every read surface.
	 * @param string $day Independent expected local day.
	 * @return void
	 */
	private function assertReadSurfaces(McpClient $client, array $f, int $cardId, string $day): void {
		self::assertSame($day, $this->payload($client->tool('deck_read_card', ['cardId' => $cardId]))['duedate']);
		$list = $this->payload($client->tool('deck_list_cards', ['stackId' => $f['stack']]));
		$followup = $this->payload($client->tool('deck_followup_cards', ['status' => 'all', 'boardId' => $f['board']]));
		$followupCards = [];
		foreach ($followup['groups'] as $group) {
			array_push($followupCards, ...$group['cards']);
		}
		foreach ([$list['cards'], $followupCards] as $cards) {
			$indexed = array_column($cards, null, 'id');
			self::assertArrayHasKey($cardId, $indexed);
			self::assertSame($day, $indexed[$cardId]['duedate']);
		}
	}

	/**
	 * Create and edit only calendar dates, proving native UTC persistence and the unchanged public day.
	 *
	 * @param string $uid Disposable account.
	 * @param string $zone Account timezone west or east of UTC.
	 * @param string $utc Independent expected UTC instant for the input day.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testDateOnlyRoundTrip(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, null, function (McpClient $client, array $f) use ($uid, $zone, $utc): void {
			$created = $this->payload($client->write('deck_create_card', ['stackId' => $f['stack'], 'title' => 'Local calendar day', 'duedate' => '2026-10-01']));
			self::assertSame('2026-10-01', $created['duedate']);
			$cardId = (int)$created['id'];
			$this->assertReadSurfaces($client, $f, $cardId, '2026-10-01');
			$native = DeckFixture::run($uid, 'date-read', ['card' => $cardId]);
			self::assertSame((new \DateTimeImmutable($utc))->getTimestamp(), $native['timestamp']);
			$edited = $this->payload($client->write('deck_edit_card', ['cardId' => $f['card'], 'duedate' => '2026-10-01']));
			self::assertSame('2026-10-01', $edited['duedate']);
			$this->assertReadSurfaces($client, $f, $f['card'], '2026-10-01');
			$nativeEdited = DeckFixture::run($uid, 'date-read', $f);
			self::assertSame($native['timestamp'], $nativeEdited['timestamp']);
			$this->payload($client->write('deck_edit_card', ['cardId' => $cardId, 'title' => 'Same due instant']));
			self::assertSame($native, DeckFixture::run($uid, 'date-read', ['card' => $cardId]));
			file_put_contents('/tmp/mcp-it-observations.txt', 'Date-only roundtrip ' . $zone . ': create/edit/read/list/followup=2026-10-01; native=' . $native['instant'] . PHP_EOL, FILE_APPEND);
		});
	}

	/**
	 * Existing non-midnight UI instants remain unchanged to the second when an edit omits duedate.
	 *
	 * @param string $uid Disposable account.
	 * @param string $zone Account timezone.
	 * @param string $utc Unused provider midnight, retained for a shared account table.
	 * @return void
	 */
	#[DataProvider('accounts')]
	public function testEditWithoutDueDatePreservesNativeInstant(string $uid, string $zone, string $utc): void {
		$this->scenario($uid, $zone, '2026-10-03T02:47:19Z', function (McpClient $client, array $f) use ($uid, $zone): void {
			$before = DeckFixture::run($uid, 'date-read', $f);
			$this->payload($client->write('deck_edit_card', ['cardId' => $f['card'], 'description' => 'Preserve the native UI time']));
			self::assertSame($before, DeckFixture::run($uid, 'date-read', $f));
			$day = $zone === 'America/Sao_Paulo' ? '2026-10-02' : '2026-10-03';
			$this->assertReadSurfaces($client, $f, $f['card'], $day);
		});
	}
}
