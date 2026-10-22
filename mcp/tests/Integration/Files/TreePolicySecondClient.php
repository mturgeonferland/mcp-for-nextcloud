<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Files;

use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;

/**
 * The other client of the D10 proof (issue #15, T15-0): a separate Apache process that changes the user's files
 * through the server's own WebDAV endpoint, the way a desktop or web client does. Unlike the suite's DavClient, it
 * never throws on an unexpected status: the HTTP status is the observation (423 Locked or 201/204 Created).
 */
final class TreePolicySecondClient {
	/**
	 * @param Credential $credential app password of the user whose files the client changes
	 */
	public function __construct(
		private readonly Credential $credential,
	) {
	}

	/**
	 * Uploads a file.
	 *
	 * @param string $path path inside the user's files
	 * @param string $content bytes of the file
	 * @return int HTTP status of the PUT
	 */
	public function put(string $path, string $content): int {
		return $this->send('PUT', $path, [], $content);
	}

	/**
	 * Creates a folder.
	 *
	 * @param string $path path inside the user's files
	 * @return int HTTP status of the MKCOL
	 */
	public function mkcol(string $path): int {
		return $this->send('MKCOL', $path);
	}

	/**
	 * Renames or moves a node.
	 *
	 * @param string $from current path inside the user's files
	 * @param string $to new path inside the user's files
	 * @return int HTTP status of the MOVE
	 */
	public function move(string $from, string $to): int {
		return $this->send('MOVE', $from, ['Destination: ' . $this->url($to)]);
	}

	/**
	 * Builds the WebDAV URL of a path of the user's files.
	 *
	 * @param string $path path inside the user's files
	 * @return string absolute URL on the container's Apache
	 */
	private function url(string $path): string {
		$segments = array_map('rawurlencode', array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')));
		return Http::BASE . '/remote.php/dav/files/' . rawurlencode($this->credential->uid) . '/' . implode('/', $segments);
	}

	/**
	 * Sends one WebDAV request as the user.
	 *
	 * @param string $method HTTP method
	 * @param string $path path inside the user's files
	 * @param list<string> $headers extra request headers
	 * @param string|null $body request body
	 * @return int HTTP status of the answer
	 */
	private function send(string $method, string $path, array $headers = [], ?string $body = null): int {
		$response = Http::request($method, $this->url($path), array_merge(['Authorization: ' . $this->credential->authorization()], $headers), $body);
		return $response['status'];
	}
}
