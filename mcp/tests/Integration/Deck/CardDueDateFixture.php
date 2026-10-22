<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCP\Server;
use RuntimeException;

/**
 * Disposable native resources and fresh persisted instants for the date-only UTC adapter proof.
 */
final class CardDueDateFixture {
	/**
	 * Dispatch one native action after actor.php has bound the disposable account.
	 *
	 * @param string $uid Disposable account owning the board and cards.
	 * @param string $action Exact date fixture action.
	 * @param array<string, mixed> $input Owned resource identities and optional native UTC instant.
	 * @return array<string, mixed> Native identities or a freshly hydrated instant.
	 */
	public static function run(string $uid, string $action, array $input): array {
		$boards = Server::get(BoardService::class);
		$cards = Server::get(CardService::class);
		if ($action === 'date-seed') {
			$board = $boards->create('Calendar day adapter proof', $uid, '008800');
			$stack = Server::get(StackService::class)->create('Dates', $board->getId(), 0);
			$card = $cards->create('Native existing card', $stack->getId(), 'plain', 0, $uid, '', $input['instant'] ?? null);
			return ['board' => $board->getId(), 'stack' => $stack->getId(), 'card' => $card->getId()];
		}
		if ($action === 'date-read') {
			$date = $cards->find($input['card'])->getDuedate();
			return ['instant' => $date?->format(\DateTimeInterface::ATOM), 'timestamp' => $date?->getTimestamp()];
		}
		if ($action === 'date-cleanup') {
			$boards->delete($input['board']);
			return ['cleaned' => true];
		}
		throw new RuntimeException('Unknown date fixture action');
	}
}
