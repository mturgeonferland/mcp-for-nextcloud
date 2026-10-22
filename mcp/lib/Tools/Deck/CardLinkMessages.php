<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use OCA\Mcp\L10n\Translator;

/**
 * Caller-safe wording for the strict native Deck file link contract.
 */
final class CardLinkMessages {
	/**
	 * Explain policy refusal without exposing board participants or administrator values.
	 *
	 * @return string Localized strict-policy refusal.
	 */
	public static function refused(): string {
		return Translator::t('This file cannot be linked under the current sharing policies. Check whether sharing is disabled globally or for your groups, group sharing or resharing is disabled, or Guests, group-only sharing, mandatory internal expiry or unsupported recipients prevent linking.');
	}

	/**
	 * Explain a duplicate only after both the source and file were authorized.
	 *
	 * @return string Localized duplicate refusal.
	 */
	public static function duplicate(): string {
		return Translator::t('This file is already linked to this card. No new link was created.');
	}

	/**
	 * Describe current and future access without promising continuous enforcement.
	 *
	 * @return string Localized mandatory plan warning.
	 */
	public static function warning(): string {
		return Translator::t('Linking grants read-only access (READ=1) to all current board members shown in this plan. Future board, group and team members can also read the file. Unlinking revokes this link for everyone; independent shares may keep access. Policies enabled later and membership changes are not automatically enforced or revoked. Checks and hashes do not make linking atomic, and the server does not attest human consent.');
	}

	/**
	 * Explain an unverified outcome without inviting automatic recreation.
	 *
	 * @return string Localized uncertain-result instruction.
	 */
	public static function uncertain(): string {
		return Translator::t('The file link outcome is uncertain. Inspect the card in Deck before retrying; a visible association alone does not prove which request created it.');
	}

	/**
	 * Clarify the native listing boundary without suggesting hidden shares exist or reporting their count.
	 *
	 * @return string Localized scope of the independent-share preview.
	 */
	public static function shareScope(): string {
		return Translator::t('Only user, group and public-link shares you created are shown. Other shares are not enumerated.');
	}
}
