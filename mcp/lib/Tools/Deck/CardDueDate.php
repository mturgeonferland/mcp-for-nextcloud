<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar-day interpretation and UTC wire values required by Deck's timezone-less datetime binding.
 */
final class CardDueDate {
	/**
	 * Explicit UTC storage form; Deck must receive UTC wall-clock fields before its offset is discarded.
	 */
	private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Convert an already validated calendar day into UTC before calling the native CardService.
	 *
	 * Public input remains date-only. Non-day values are validated native instants preserved by edits
	 * that omit duedate; normalize their offset too, without introducing a public input parser.
	 *
	 * @param string|null $value Validated YYYY-MM-DD, existing native instant, or null to clear the date.
	 * @param DateTimeZone $zone Account timezone used to interpret a calendar day.
	 * @return string|null UTC with Z for a day or existing native instant, or null.
	 */
	public static function forDeck(?string $value, DateTimeZone $zone): ?string {
		if ($value === null) {
			return null;
		}

		$instant = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
			? self::midnight($value, $zone)
			: new DateTimeImmutable($value);

		return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::UTC_FORMAT);
	}

	/**
	 * Interpret a validated day once for both plans and native UTC storage.
	 *
	 * Date-only input follows PHP/tzdata midnight rules: a gap advances to the first valid time;
	 * an overlap keeps the native selected occurrence. Both must render as the requested local day.
	 * Strict local-time rejection belongs to the separate hour-input feature.
	 *
	 * @param string $day Validated YYYY-MM-DD calendar day.
	 * @param DateTimeZone $zone Account timezone, including the offset in effect on that date.
	 * @return DateTimeImmutable Midnight in the account timezone, without mutating a caller's object.
	 */
	public static function midnight(string $day, DateTimeZone $zone): DateTimeImmutable {
		return new DateTimeImmutable($day . ' 00:00:00', $zone);
	}
}
