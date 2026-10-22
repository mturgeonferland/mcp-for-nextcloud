<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks\Handler;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies the empty-page note of `tasks_list_tasks` when a category filter matches nothing.
 *
 * The note tells a miss apart from an empty calendar. A label that exists only on a protected task stays
 * indistinguishable from a label that does not exist, and a symbol stored on a real task still matches.
 */
final class ListTasksHandlerTest extends TaskListingCase {
	/**
	 * Verifies that an unmatched filter explains the scan and how to remove the filter.
	 *
	 * @return void
	 */
	public function testAFilterWithoutAMatchExplainsTheEmptyResult(): void {
		$result = $this->list(['category' => 'Errands']);
		self::assertSame([], $result['tasks']);
		self::assertFalse($result['hasMore']);
		self::assertNull($result['nextOffset']);
		self::assertArrayHasKey('note', $result);
		self::assertSame(
			'No visible task checked in this list has the category "Errands". Omit the category field to remove the category filter.'
				. ' Completed tasks were not checked. Set include_completed to true to include them.',
			$result['note']
		);
	}

	/**
	 * Supplies placeholder values models send to mean "no filter".
	 *
	 * @return array<string, list<string>> placeholder values
	 */
	public static function placeholderCategoryProvider(): array {
		return [
			'dot' => ['.'],
			'dash' => ['-'],
			'star' => ['*'],
			'percent' => ['%'],
			'space' => [' '],
		];
	}

	/**
	 * Verifies that a placeholder miss receives the extra explanation.
	 *
	 * @param string $category placeholder supplied by the caller
	 * @return void
	 */
	#[DataProvider('placeholderCategoryProvider')]
	public function testAPlaceholderFilterGetsAnExtraSentence(string $category): void {
		$result = $this->list(['category' => $category]);
		self::assertSame([], $result['tasks']);
		self::assertArrayHasKey('note', $result);
		self::assertSame(
			'No visible task checked in this list has the category ' . json_encode($category) . '. Omit the category field to remove the category filter.'
				. ' Completed tasks were not checked. Set include_completed to true to include them.'
				. ' It has no letter or digit, so it looks like a placeholder rather than a real label.',
			$result['note']
		);
	}

	/**
	 * Keeps labels found only on unqueried completed tasks indistinguishable from missing labels.
	 *
	 * @return void
	 */
	public function testALabelOnlyOnCompletedTasksGetsTheSameNoteAsAMissingLabel(): void {
		$this->tasks['t2'] = str_replace(
			'STATUS:NEEDS-ACTION',
			'STATUS:COMPLETED',
			$this->task('t2', 'Done', "CATEGORIES:Finished\r\n")
		);
		$completed = $this->list(['category' => 'Finished']);
		$missing = $this->list(['category' => 'Missing']);
		self::assertSame([], $completed['tasks']);
		self::assertSame([], $missing['tasks']);
		self::assertSame(
			'No visible task checked in this list has the category "Finished". Omit the category field to remove the category filter.'
				. ' Completed tasks were not checked. Set include_completed to true to include them.',
			$completed['note']
		);
		self::assertSame(str_replace('"Missing"', '"Finished"', $missing['note']), $completed['note']);
		self::assertSame($completed, $this->list(['category' => 'Finished', 'include_completed' => false]));
		$included = $this->list(['category' => 'Finished', 'include_completed' => true]);
		self::assertSame(['t2'], array_column($included['tasks'], 'uid'));
		self::assertArrayNotHasKey('note', $included);
	}

	/**
	 * Describes a miss without claiming completed tasks were excluded when they were queried.
	 *
	 * @return void
	 */
	public function testAMissIncludingCompletedTasksOmitsTheCompletedTasksSentence(): void {
		$result = $this->list(['category' => 'Missing', 'include_completed' => true]);
		self::assertSame([], $result['tasks']);
		self::assertSame(
			'No visible task checked in this list has the category "Missing". Omit the category field to remove the category filter.',
			$result['note']
		);
	}

	/**
	 * Preserves accented characters and slashes literally in the echoed filter value.
	 *
	 * @return void
	 */
	public function testAnUnmatchedUnicodeCategoryAndSlashStayLiteralInTheNote(): void {
		$result = $this->list(['category' => 'Família/Ação', 'include_completed' => true]);
		self::assertSame([], $result['tasks']);
		self::assertSame(
			'No visible task checked in this list has the category "Família/Ação". Omit the category field to remove the category filter.',
			$result['note']
		);
	}

	/**
	 * Verifies that a filter with matches keeps the page shape and adds no note.
	 *
	 * An empty page past the last match is not a miss: the note stays absent there too.
	 *
	 * @return void
	 */
	public function testAMatchingFilterAddsNoNote(): void {
		$this->tasks['t1'] = $this->task('t1', 'Work', "CATEGORIES:Work\r\n");
		$result = $this->list(['category' => 'work']);
		self::assertSame(['t1'], array_column($result['tasks'], 'uid'));
		self::assertSame(['tasks', 'hasMore', 'nextOffset'], array_keys($result), 'a match keeps the output byte-identical');
		$pastTheEnd = $this->list(['category' => 'work', 'offset' => 5]);
		self::assertSame([], $pastTheEnd['tasks']);
		self::assertSame(['tasks', 'hasMore', 'nextOffset'], array_keys($pastTheEnd), 'an empty page after matches is not a miss');
	}

	/**
	 * Verifies that a listing without a category filter adds no note.
	 *
	 * @return void
	 */
	public function testAListingWithoutACategoryAddsNoNote(): void {
		$result = $this->list([]);
		self::assertSame(['t1'], array_column($result['tasks'], 'uid'));
		self::assertSame(['tasks', 'hasMore', 'nextOffset'], array_keys($result));
	}

	/**
	 * Verifies that a label carried only by protected tasks gets the same note as a missing label.
	 *
	 * @return void
	 */
	public function testALabelThatOnlyProtectedTasksCarryGetsTheSameGenericNote(): void {
		$this->owner = 'principals/users/bob';
		$this->tasks = [
			't1' => $this->task('t1', 'Private plan', "CLASS:PRIVATE\r\nCATEGORIES:Secret\r\n"),
			't2' => $this->task('t2', 'Shared plan', "CATEGORIES:Shared\r\n"),
		];
		$protected = $this->list(['category' => 'Secret']);
		$missing = $this->list(['category' => 'Missing']);
		self::assertSame([], $protected['tasks']);
		self::assertArrayHasKey('note', $protected);
		self::assertArrayHasKey('note', $missing);
		self::assertSame(
			str_replace('"Missing"', '"Secret"', $missing['note']),
			$protected['note'],
			'a label that exists only on protected tasks is indistinguishable from one that does not exist'
		);
		$payload = $protected;
		unset($payload['note']);
		self::assertStringNotContainsString('Private', json_encode($payload), 'protected task data never leaks');
	}

	/**
	 * Verifies that a symbol-only label stored on a task still matches and adds no note.
	 *
	 * @return void
	 */
	public function testASymbolOnlyLabelStoredForRealStillMatches(): void {
		$this->tasks['t2'] = $this->task('t2', 'Dash task', "CATEGORIES:-\r\n");
		$result = $this->list(['category' => '-']);
		self::assertSame(['t2'], array_column($result['tasks'], 'uid'));
		self::assertSame(['-'], $result['tasks'][0]['categories'], 'the stored symbol label survives intact');
		self::assertSame(['tasks', 'hasMore', 'nextOffset'], array_keys($result), 'a real match never carries the note');
	}
}
