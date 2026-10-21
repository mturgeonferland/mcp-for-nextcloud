<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\Deck\DeckFixture;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IShare;
use RuntimeException;

/** Native WebDAV and MCP parity for the same guest, URI and file bytes. */
final class GuestDeckParityTest extends IntegrationTestCase {
    private const GUEST = 'mcp-it-parity-guest';
    private const MOUNT = 'mcp-it-parity-ext';
    private static bool $enabledHere = false;
    /** @var array<string, Credential> Native and MCP credentials of this disposable guest. */
    private static array $logins = [];
    /** @var list<string> Full provider IDs owned by this scenario, deleted before the external mount. */
    private array $shares = [];
    private ?int $mountId = null;

    /** Prepare a separate disposable guest and the grants needed by the positive controls.
     * @return void
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $apps = Server::get(IAppManager::class);
        if (!$apps->isEnabledForAnyone('guests')) {
            self::occ('app:enable', 'guests');
            self::$enabledHere = true;
        }
        // Created before this process looks the guest up: the backend remembers an account it did not find.
        putenv('OC_PASS=' . bin2hex(random_bytes(24)));
        try {
            self::occ('guests:add', Fixtures::ALICE, 'mcp-it-parity-guest@example.org', '--uid=' . self::GUEST, '--password-from-env', '--display-name=Guest');
        } finally {
            putenv('OC_PASS');
        }
        GuestDeckParityProbe::registerBackend($apps);
        $guest = Server::get(IUserManager::class)->get(self::GUEST)
            ?? throw new RuntimeException('The guest account is not visible in the test process: ' . implode(' ', self::occ('user:info', self::GUEST)));
        // A guest gets no disk space by default; the sharing scenario uploads one small file.
        $guest->setQuota('10 MB');
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(self::GUEST, true);
        $policy->setConnected(self::GUEST, true);
        $policy->setGrant(self::GUEST, 'files', 'share', true);
        // The editable received-share control requires the Files edit grant.
        $policy->setGrant(self::GUEST, 'files', 'edit', true);
        $policy->setGrant(self::GUEST, 'deck', 'read', true);
    }

    /** Delete this class's account and restore the Guests app lifecycle.
     * @return void
     */
    public static function tearDownAfterClass(): void {
        self::$logins = [];
        try {
            self::occ('user:delete', self::GUEST);
        } finally {
            foreach (['whitelist', 'usewhitelist', 'hide_users', 'allow_external_storage'] as $key) {
                self::occ('config:app:delete', 'guests', $key);
            }
            if (self::$enabledHere) {
                self::occ('app:disable', 'guests');
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * Remove the mount only after the scenario's native resource cleanup has completed.
     *
     * @return void
     */
    protected function tearDown(): void {
        try {
            if ($this->mountId !== null) {
                self::occ('files_external:delete', '--yes', (string)$this->mountId);
                $this->mountId = null;
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Read the exact stored override, preserving inheritance rather than materializing its resolved boolean.
     *
     * @param string $module Module of the temporary grant.
     * @param string $operation Operation being exercised.
     * @return string|null Previous raw override; null means inheritance.
     */
    private function grantState(string $module, string $operation): ?string {
        $values = Server::get(IConfig::class)->getUserValueForUsers('mcp', 'grant_' . $module . '_' . $operation, [self::GUEST]);
        return array_key_exists(self::GUEST, $values) ? (string)$values[self::GUEST] : null;
    }

    /**
     * Restore exactly the policy cell read before a temporary revocation.
     *
     * @param string $module Module of the temporary grant.
     * @param string $operation Operation being restored.
     * @param string|null $previous Exact previous override or inherited state.
     * @return void
     */
    private function restoreGrant(string $module, string $operation, ?string $previous): void {
        if ($previous === null) {
            Server::get(PolicyWriter::class)->resetOverride(self::GUEST, $module, $operation);
        } else {
            Server::get(GrantPolicy::class)->setGrant(self::GUEST, $module, $operation, $previous === '1');
        }
        $this->assertSame($previous, $this->grantState($module, $operation), 'Restore the previous override, including inheritance');
    }

    /**
     * Native received shares remain available when Guests removes the guest's direct external mounts.
     *
     * @return void
     */
    public function testReceivedExternalSharesMatchNativeWebDavOnBothLogins(): void {
        $config = Server::get(IConfig::class);
        $hiddenTags = $config->getAppValue('mcp', 'hidden_system_tags', '[]');
        $policy = Server::get(GrantPolicy::class);
        $config->setAppValue('mcp', 'hidden_system_tags', '[]');
        $previousDeckEdit = $this->grantState('deck', 'edit');
        $f = ['mount' => self::MOUNT, 'suffix' => bin2hex(random_bytes(6))];
        $policy->setGrant(self::GUEST, 'deck', 'edit', true);
        try {
            $this->guests(['usewhitelist' => 'yes', 'whitelist' => 'mcp,deck',
                'allow_external_storage' => 'yes', 'hide_users' => 'no']);
            $out = self::occ('files_external:create', '--output=json', '/' . self::MOUNT, 'local', 'null::null', '-c', 'datadir=/mcp-it-ext');
            $this->mountId = (int)trim((string)end($out));
            $this->assertGreaterThan(0, $this->mountId);
            self::occ('files_external:option', (string)$this->mountId, 'enable_sharing', 'true');
            foreach ([self::GUEST, DeckFixture::OWNER] as $actor) {
                self::occ('files_external:applicable', (string)$this->mountId, '--add-user=' . $actor);
            }
            self::occ('files:scan', DeckFixture::OWNER);
            $f += DeckFixture::run(DeckFixture::OWNER, 'guest-external-prepare', $f + ['guest' => self::GUEST]);
            self::occ('files:scan', DeckFixture::OWNER);
            $f += DeckFixture::run(DeckFixture::OWNER, 'guest-external-associate', $f + ['mount' => self::MOUNT]);
            $this->shares = array_merge($f['shareIds'], [$f['independent']['id']]);
            $f['guestShare'] = GuestDeckParityProbe::editableShare(LoginFactory::basic(DeckFixture::OWNER),
                '/' . self::MOUNT . '/' . $f['editableName'], self::GUEST);
            $this->shares[] = $f['guestShare']['id'];
            $this->assertSame($f['editableFileId'], $f['guestShare']['fileId']);
            self::occ('files:scan', self::GUEST);
            $probe = DeckFixture::run(DeckFixture::OWNER, 'guest-origin-probe', $f + ['guest' => self::GUEST]);
            $this->observe('Native parity origins: ' . json_encode($probe, JSON_THROW_ON_ERROR));
            $aliases = [];
            foreach ([IShare::TYPE_DECK => false, IShare::TYPE_USER => true] as $type => $updateable) {
                $nodes = array_values(array_filter($probe['guest'], static fn (array $node): bool => $node['shareType'] === $type));
                $this->assertCount(1, $nodes);
                $this->assertSame('shared', $nodes[0]['mountType']);
                $this->assertSame($type === IShare::TYPE_DECK ? $f['externalFileId'] : $f['editableFileId'], $nodes[0]['id']);
                $this->assertSame($updateable, $nodes[0]['updateable']);
                $aliases[$type] = $nodes[0]['relativePath'];
            }
            $this->mcp('basic');
            $native = self::$logins['basic'];
            foreach (['yes', 'no'] as $externalAllowed) {
                $this->guests(['allow_external_storage' => $externalAllowed]);
                foreach (['basic', 'bearer'] as $kind) {
                    $client = $this->mcp($kind);
                    // This comparison must fail if a stricter guard rejects a natively readable received alias.
                    $sharedRead = GuestDeckParityProbe::read($native, $client, $aliases[IShare::TYPE_DECK]);
                    $this->assertSame($sharedRead['get'] === 200, $sharedRead['mcpRead'],
                        'Received-share DAV/MCP parity: ' . json_encode($sharedRead, JSON_THROW_ON_ERROR));
                    $deck = $client->tool('deck_list_card_files', ['cardId' => $f['cardId']]);
                    $this->assertTrue($deck->ok(), $deck->raw);
                    $items = $deck->json()['items'];
                    $this->assertCount(2, $items, 'Received external Deck association and internal control both remain visible');
                    $this->assertSame([$f['externalShare'], $f['homeShare']], array_column($items, 'attachmentId'));
                    $this->assertSame([$f['externalFileId'], $f['homeFileId']], array_column($items, 'fileId'));
                    $paths = ['direct' => '/' . self::MOUNT . '/' . $f['externalName'],
                        'deck' => $aliases[IShare::TYPE_DECK], 'user' => $aliases[IShare::TYPE_USER], 'internal' => $items[1]['path']];
                    foreach ($paths as $role => $path) {
                        $row = GuestDeckParityProbe::read($native, $client, $path);
                        $row += ['externalAllowed' => $externalAllowed, 'login' => $kind, 'role' => $role,
                            'deckFileIds' => array_column($items, 'fileId')];
                        $this->observe('PARITY ' . json_encode($row, JSON_THROW_ON_ERROR));
                        $visible = $role !== 'direct' || $externalAllowed === 'yes';
                        $this->assertSame($visible, $row['mcpRead'], json_encode($row));
                        if ($visible) {
                            $this->assertSame(207, $row['propfind']);
                            $this->assertSame(200, $row['get']);
                            $this->assertSame($role === 'internal' ? $f['homeFileId'] : ($role === 'user' ? $f['editableFileId'] : $f['externalFileId']), $row['fileId']);
                            $this->assertSame($role === 'internal' ? $f['homeHash'] : ($role === 'user' ? $f['editableHash'] : $f['externalHash']), $row['davHash']);
                            // Files read/list/tree expose no fileid: MCP identity here is exact URI + bytes.
                            $this->assertSame($row['davHash'], $row['mcpHash']);
                        } else {
                            // Direct ExternalMountPoint is hidden; a received SharedMount is a separate native capability.
                            $this->assertSame(404, $row['propfind']);
                            $this->assertSame(404, $row['get']);
                        }
                    }
                    $this->assertFalse($client->write('files_checkout', ['path' => $paths['deck']])->ok(), 'Native Deck share is read-only');
                    $bytes = GuestDeckParityProbe::dav($native, 'GET', $paths['user'])['body'];
                    $deckBytes = GuestDeckParityProbe::dav($native, 'GET', $paths['deck'])['body'];
                    $this->assertSame(403, GuestDeckParityProbe::dav($native, 'PUT', $paths['deck'], $deckBytes)['status']);
                    $this->assertSame(204, GuestDeckParityProbe::dav($native, 'PUT', $paths['user'], $bytes)['status']);
                    [$download, $upload] = $this->checkout($client, $paths['user']);
                    $previous = $this->grantState('files', 'edit');
                    $policy->setGrant(self::GUEST, 'files', 'edit', false);
                    try {
                        $this->assertFalse($client->write('files_checkout', ['path' => $paths['user']])->ok());
                        $this->assertSame(403, Http::request('PUT', $upload, ['Content-Type: application/octet-stream'], $bytes)['status']);
                    } finally {
                        $this->restoreGrant('files', 'edit', $previous);
                    }
                    $get = Http::request('GET', $download);
                    $this->assertSame(200, $get['status']);
                    $this->assertSame($f['editableHash'], hash('sha256', $get['body']));
                    $this->assertSame(200, Http::request('PUT', $upload, ['Content-Type: application/octet-stream'], $bytes)['status']);
                    $args = ['cardId' => $f['cardId'], 'attachmentId' => $f['externalShare']];
                    $plan = $client->tool('deck_unlink_card_file', $args);
                    $this->assertTrue($plan->ok(), $plan->raw);
                    $this->assertTrue($plan->structured()['requiresConfirmation']);
                    $previous = $this->grantState('deck', 'edit');
                    $policy->setGrant(self::GUEST, 'deck', 'edit', false);
                    try {
                        $confirmed = $args + array_intersect_key($plan->structured(), array_flip(['plan_state', 'file_state']))
                            + ['confirm' => true, 'confirm_shared' => true];
                        $this->assertFalse($client->tool('deck_unlink_card_file', $confirmed)->ok());
                    } finally {
                        $this->restoreGrant('deck', 'edit', $previous);
                    }
                    $previous = $this->grantState('files', 'read');
                    $policy->setGrant(self::GUEST, 'files', 'read', false);
                    try {
                        $this->assertFalse($client->tool('files_read', ['path' => $paths['user']])->ok());
                        $this->assertFalse($client->tool('deck_list_card_files', ['cardId' => $f['cardId']])->ok());
                    } finally {
                        $this->restoreGrant('files', 'read', $previous);
                    }
                    $this->observe("PARITY checkout/unlink $externalAllowed/$kind: native readonly refused, editable DAV+checkout GET/PUT passed; grants revalidated");
                }
            }
            $observed = DeckFixture::run(DeckFixture::OWNER, 'guest-external-inspect', $f + ['mount' => self::MOUNT]);
            $this->assertSame($f['externalFileId'], $observed['fileId']);
            $this->assertSame($f['externalHash'], $observed['hash']);
            $this->assertSame($f['editableFileId'], $observed['editableFileId']);
            $this->assertSame($f['editableHash'], $observed['editableHash']);
            $this->assertSame([$f['externalShare'], $f['homeShare']], $observed['attachments']);
            $this->assertSame($f['independent'], $observed['independent']);
            $this->assertSame($f['guestShare'], $observed['guestShare']);
        } finally {
            try {
                $clean = DeckFixture::run(DeckFixture::OWNER, 'guest-external-cleanup', $f + ['shares' => $this->shares]);
                $this->observe('PARITY cleanup ' . json_encode($clean, JSON_THROW_ON_ERROR));
                $this->assertSame(['shares' => [], 'files' => [], 'boards' => []], $clean);
                $this->shares = [];
            } finally {
                $config->setAppValue('mcp', 'hidden_system_tags', $hiddenTags);
                $this->restoreGrant('deck', 'edit', $previousDeckEdit);
            }
        }
    }

    /**
     * Resolve the download and upload links of one confirmed checkout.
     *
     * @param McpClient $client The guest MCP client.
     * @param string $path Canonical received-share path.
     * @return array{string, string} Download and upload URLs.
     */
    private function checkout(McpClient $client, string $path): array {
        $reply = $client->write('files_checkout', ['path' => $path]);
        $this->assertTrue($reply->ok(), 'files_checkout: ' . $reply->raw);
        $out = (array)$reply->json();
        return [Http::BASE . (string)parse_url((string)$out['download_url'], PHP_URL_PATH),
            Http::BASE . (string)parse_url((string)$out['upload_url'], PHP_URL_PATH)];
    }

    /**
     * Apply the Guests settings needed by the current phase.
     *
     * @param array<string, string> $values Guests settings for this scenario.
     * @return void
     */
    private function guests(array $values): void {
        foreach ($values as $key => $value) {
            self::occ('config:app:set', 'guests', $key, '--value=' . $value);
        }
    }

    /** Build one MCP client for the disposable guest.
     * @param string $kind Basic or Bearer authentication.
     * @return McpClient Client bound to that login.
     */
    private function mcp(string $kind): McpClient {
        return new McpClient(self::$logins[$kind] ??= $kind === 'bearer' ? LoginFactory::bearer(self::GUEST) : LoginFactory::basic(self::GUEST));
    }

    /** Record non-secret native and MCP comparison results.
     * @param string $line Observation written into the run artifacts.
     * @return void
     */
    private function observe(string $line): void {
        file_put_contents('/tmp/mcp-it-observations.txt', '[' . self::class . '] ' . $line . "\n", FILE_APPEND);
    }

    /**
     * Execute the native administrative fixture command inside the disposable container.
     *
     * @param string ...$arguments Native occ command and arguments.
     * @return list<string> Output lines; a nonzero exit throws.
     */
    private static function occ(string ...$arguments): array {
        $command = 'php ' . escapeshellarg((getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/occ') . ' --no-interaction '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('occ ' . implode(' ', $arguments) . " failed ($status): " . implode("\n", $output));
        }
        return $output;
    }
}
