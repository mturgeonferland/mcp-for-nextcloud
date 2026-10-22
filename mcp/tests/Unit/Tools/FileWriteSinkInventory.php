<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

/**
 * Syntactic regression check for accidental new sinks in this project's code.
 *
 * This is not a defense against deliberate obfuscation: concatenation, dynamic name assembly,
 * eval and variable variables are outside its scope.
 * Runtime protection comes from the final-name guard at each sink (T15-3+), not this inventory.
 */
final class FileWriteSinkInventory {
	private const METHODS = ['newfile', 'putcontent', 'move', 'copy', 'rollback', 'newfolder',
		'fopen', 'touch', 'file_put_contents', 'rename', 'copyfromstorage', 'movefromstorage', 'fwrite', 'ftruncate', 'writestream', 'fputs'];

	/**
	 * @param string $root Root directory whose entire PHP tree is inventoried.
	 * @return array<string, string> Relative PHP paths and source, recursively.
	 */
	public static function sources(string $root): array {
		$sources = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
				$sources[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}
		ksort($sources);
		return $sources;
	}

	/**
	 * Inventory calls, including unresolved dynamic members and every literal sink name, regardless of its position.
	 *
	 * @param array<string, string> $sources Relative paths and PHP source.
	 * @return list<string> file#method:operation:occurrence identities, sorted.
	 */
	public static function calls(array $sources): array {
		$found = [];
		foreach ($sources as $path => $source) {
			$tokens = array_values(array_filter(token_get_all($source), static fn ($t): bool => !is_array($t)
				|| !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
			$depth = 0;
			$stack = [['name' => '<global>', 'depth' => 0]];
			$pending = null;
			$counts = [];
			foreach ($tokens as $i => $token) {
				if (is_array($token) && $token[0] === T_FUNCTION) {
					$next = $i + 1;
					if (($tokens[$next] ?? null) === '&' || (is_array($tokens[$next] ?? null)
						&& in_array($tokens[$next][0], [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true))) {
						$next++;
					}
					if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_STRING) {
						$pending = $tokens[$next][1];
					}
				}
				if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
					$depth++;
					if ($pending !== null) {
						$stack[] = ['name' => $pending, 'depth' => $depth];
						$pending = null;
					}
				} elseif ($token === '}') {
					if (count($stack) > 1 && end($stack)['depth'] === $depth) {
						array_pop($stack);
					}
					$depth--;
				} elseif ($token === ';') {
					$pending = null;
				}
				$operation = self::operation($tokens, $i);
				if ($operation !== null) {
					$key = $path . '#' . end($stack)['name'] . ':' . $operation;
					$counts[$key] = ($counts[$key] ?? 0) + 1;
					$found[] = $key . ':' . $counts[$key];
				}
			}
		}
		sort($found, SORT_STRING);
		return $found;
	}

	/**
	 * @param array<int, array|string> $tokens Significant PHP tokens.
	 * @param int $i Current token offset.
	 * @return string|null Sink, unresolved member or literal identity, or null for unrelated syntax.
	 */
	private static function operation(array $tokens, int $i): ?string {
		$token = $tokens[$i];
		if (!is_array($token)) {
			return null;
		}
		$member = in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
		if ($member) {
			$next = $tokens[$i + 1] ?? null;
			if (is_array($next) && $next[0] === T_STRING && ($tokens[$i + 2] ?? null) === '(') {
				return in_array(strtolower($next[1]), self::METHODS, true) ? strtolower($next[1]) : null;
			}
			if (is_array($next) && $next[0] === T_VARIABLE && ($tokens[$i + 2] ?? null) === '(') {
				return 'dynamic-member';
			}
			if ($next === '{') {
				$level = 1;
				for ($end = $i + 2; $end < count($tokens); $end++) {
					$level += $tokens[$end] === '{' ? 1 : ($tokens[$end] === '}' ? -1 : 0);
					if ($level === 0) {
						return ($tokens[$end + 1] ?? null) === '(' ? 'dynamic-member' : null;
					}
				}
			}
			return null;
		}
		$previous = $tokens[$i - 1] ?? null;
		if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
			&& ($tokens[$i + 1] ?? null) === '(' && !self::isMemberOrDeclaration($previous)) {
			$name = strtolower(ltrim($token[1], '\\'));
			return in_array($name, self::METHODS, true) ? $name : null;
		}
		if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
			if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
				$raw = substr($token[1], 1, -1);
				$name = $token[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $raw) : stripcslashes($raw);
			} else {
				$name = trim($token[1]);
			}
			$name = strtolower(ltrim($name, '\\'));
			$separator = strrpos($name, '::');
			if ($separator !== false) {
				$name = substr($name, $separator + 2);
			}
			if (in_array($name, self::METHODS, true)) {
				return 'literal-' . $name;
			}
		}
		return null;
	}

	/**
	 * @param array|string|null $token Previous token.
	 * @return bool Whether a free-function match would double-count a member or declaration.
	 */
	private static function isMemberOrDeclaration(array|string|null $token): bool {
		return is_array($token) && in_array($token[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true);
	}

}
