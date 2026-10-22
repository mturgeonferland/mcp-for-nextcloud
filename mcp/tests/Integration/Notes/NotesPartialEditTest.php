<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Notes;

use OCA\Mcp\Tests\Integration\Exclusion\ExclusionTestCase;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\Group;

/**
 * NT-1 against a real Nextcloud with the Notes app: notes_edit appends, replaces one passage and still rewrites the
 * whole note, over the OAuth access token a client gets. The plan of an append or a replace carries the etag, the
 * plan_state and the diff; the confirmation sends only the change plus those two values. A note changed in between
 * is refused with the current etag, or, when Nextcloud kept the etag (a write within the same second), answered with
 * the new plan; either way the other edit stays intact.
 */
#[Group('notes')]
final class NotesPartialEditTest extends ExclusionTestCase {
	private McpClient $alice;
	private File $note;

	/** A note of its own in a category of this run, and the OAuth client of alice. */
	protected function setUp(): void {
		parent::setUp();
		$category = $this->ensureFolder(self::ALICE, 'Notes/' . $this->run);
		$this->later(fn () => $this->deleteQuietly(self::ALICE, 'Notes/' . $this->run));
		$this->note = $category->newFile('Chamados.md', "# Chamados\n- 101 aberto\n");
		$this->alice = $this->mcp(self::ALICE, 'bearer');
		self::nextSecond();
	}

	/**
	 * Nextcloud keeps a file's etag when it is written again within the same second (the scanner reuses it while
	 * storage_mtime is unchanged), so a write that must get a new etag starts in a second of its own.
	 */
	private static function nextSecond(): void {
		$now = time();
		while (time() === $now) {
			usleep(50000);
		}
	}

	/** @return string the note's path relative to alice's folder */
	private function notePath(): string {
		return 'Notes/' . $this->run . '/Chamados.md';
	}

	/** @return string the note's content as the server stores it now */
	private function stored(): string {
		$node = $this->node(self::ALICE, $this->notePath());
		$this->assertInstanceOf(File::class, $node);
		return (string)$node->getContent();
	}

	/** @return string the note's etag as the server reports it now */
	private function etag(): string {
		return (string)$this->node(self::ALICE, $this->notePath())->getEtag();
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments besides the id
	 * @return array<string, mixed> the plan, checked to have written nothing
	 */
	private function plan(array $arguments): array {
		$before = $this->stored();
		$reply = $this->alice->tool('notes_edit', ['id' => (int)$this->note->getId()] + $arguments);
		$this->assertTrue($reply->ok(), 'plan: ' . $reply->describe());
		$plan = $reply->structured();
		$this->assertTrue($plan['requiresConfirmation'] ?? false, 'the answer is a plan: ' . $reply->describe());
		$this->assertSame($before, $this->stored(), 'a plan writes nothing');
		return $plan;
	}

	/**
	 * @param array<string, mixed> $arguments notes_edit arguments besides the id
	 * @return ToolReply the answer of the call with confirm: true
	 */
	private function confirm(array $arguments): ToolReply {
		return $this->alice->write('notes_edit', ['id' => (int)$this->note->getId()] + $arguments);
	}

	/**
	 * @param array<string, mixed> $plan a plan of append or replace
	 * @return array<string, string> the etag and plan_state a confirmation sends back
	 */
	private static function approval(array $plan): array {
		return ['etag' => $plan['etag'], 'plan_state' => $plan['plan_state']];
	}

	/** notes_read gives the path the Files tools take and the etag. */
	public function testReadGivesThePathAndTheEtag(): void {
		$read = $this->alice->tool('notes_read', ['id' => (int)$this->note->getId()]);
		$this->assertTrue($read->ok(), $read->describe());
		$this->assertSame('/' . $this->notePath(), $read->json()['path']);
		$this->assertSame($this->etag(), $read->json()['etag']);
	}

	/** Plan and confirmation of an append, with the etag and plan_state readable in the text too. */
	public function testAppendSendsOnlyTheNewLineAndAnswersWithTheNewEtag(): void {
		$plan = $this->plan(['append' => '- 102 aberto']);
		$this->assertSame('append', $plan['mode']);
		$this->assertStringContainsString('+- 102 aberto', $plan['diff']);
		$this->assertArrayNotHasKey('content', $plan['note']['before']);
		$text = $this->alice->tool('notes_edit', ['id' => (int)$this->note->getId(), 'append' => '- 102 aberto'])->text();
		$this->assertStringContainsString($plan['etag'], $text, 'a client reading only the text gets the etag');
		$this->assertStringContainsString($plan['plan_state'], $text, 'and the plan_state');

		$written = $this->confirm(['append' => '- 102 aberto'] + self::approval($plan));
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame("# Chamados\n- 101 aberto\n- 102 aberto\n", $this->stored());
		$this->assertArrayNotHasKey('content', $written->json());
		$this->assertSame($this->etag(), $written->json()['etag']);
		$this->assertNotSame($plan['etag'], $written->json()['etag']);
	}

	/** A unique passage is replaced; an absent or repeated one is refused without writing. */
	public function testReplaceChangesOnlyTheUniquePassageAndRefusesAnAmbiguousOne(): void {
		$plan = $this->plan(['old' => '101 aberto', 'new' => '101 fechado']);
		$this->assertSame('replace', $plan['mode']);
		$written = $this->confirm(['old' => '101 aberto', 'new' => '101 fechado'] + self::approval($plan));
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->stored());

		foreach (['999', 'a'] as $old) {
			$refused = $this->alice->tool('notes_edit', ['id' => (int)$this->note->getId(), 'old' => $old, 'new' => 'x']);
			$this->assertTrue($refused->isError(), 'missing or repeated passage: ' . $refused->describe());
		}
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->stored());
	}

	/** The whole-content mode is unchanged. */
	public function testContentStillRewritesTheWholeNote(): void {
		$written = $this->confirm(['content' => "nova\n"]);
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame("nova\n", $this->stored());
	}

	/** An outside edit with a new etag: refused with the current etag, the other edit intact, then applied on top of it. */
	public function testAChangeInBetweenRefusesTheConfirmationWithTheCurrentEtag(): void {
		$plan = $this->plan(['append' => '- 102 aberto']);
		// Somebody edits the note in Nextcloud between the plan and the confirmation, in a second of its own.
		self::nextSecond();
		$this->node(self::ALICE, $this->notePath())->putContent("# Chamados\n- 101 fechado\n");
		$current = $this->etag();
		$this->assertNotSame($plan['etag'], $current);

		$refused = $this->confirm(['append' => '- 102 aberto'] + self::approval($plan));
		$this->assertTrue($refused->isError(), $refused->describe());
		$this->assertStringContainsString($current, $refused->text());
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->stored(), 'the other edit is intact');

		$withoutEtag = $this->confirm(['append' => '- 102 aberto', 'plan_state' => $plan['plan_state']]);
		$this->assertTrue($withoutEtag->isError(), $withoutEtag->describe());
		$withoutState = $this->confirm(['append' => '- 102 aberto', 'etag' => $current]);
		$this->assertFalse($withoutState->ok(), 'no plan_state: ' . $withoutState->describe());
		$mixed = $this->confirm(['append' => 'x', 'content' => 'y', 'etag' => $current]);
		$this->assertTrue($mixed->isError(), $mixed->describe());
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->stored(), 'still intact after the refusals');

		$again = $this->plan(['append' => '- 102 aberto']);
		$written = $this->confirm(['append' => '- 102 aberto'] + self::approval($again));
		$this->assertTrue($written->ok(), $written->describe());
		$this->assertSame("# Chamados\n- 101 fechado\n- 102 aberto\n", $this->stored());
	}

	/**
	 * An outside edit under the etag of the plan, which is what Nextcloud does for a write within the same second
	 * (the scanner reuses the etag while storage_mtime is unchanged). The etag is pinned through the server's own file
	 * cache, so the case does not depend on the clock: only the plan_state, which covers the content, tells the two
	 * notes apart, and the confirmation answers with the new plan instead of writing.
	 */
	public function testAChangeUnderTheSameEtagAnswersWithTheNewPlanAndKeepsTheOtherEdit(): void {
		$plan = $this->plan(['append' => '- 102 aberto']);
		$node = $this->node(self::ALICE, $this->notePath());
		$node->putContent("# Chamados\n- 101 fechado\n");
		$node->getStorage()->getCache()->update((int)$node->getId(), ['etag' => $plan['etag']]);
		$this->assertSame($plan['etag'], $this->etag(), 'the outside edit kept the etag of the plan');

		$answer = $this->confirm(['append' => '- 102 aberto'] + self::approval($plan));
		$this->assertTrue($answer->ok(), $answer->describe());
		$new = $answer->structured();
		$this->assertTrue($new['requiresConfirmation'] ?? false, 'the answer is the new plan: ' . $answer->describe());
		$this->assertNotSame($plan['plan_state'], $new['plan_state']);
		$this->assertNotEmpty($new['warnings'] ?? [], 'the new plan says the note changed');
		$this->assertStringContainsString('- 101 fechado', $new['diff']);
		$this->assertSame("# Chamados\n- 101 fechado\n", $this->stored(), 'the other edit is intact');
	}
}
