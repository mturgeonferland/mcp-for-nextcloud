<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Guests;

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA;
use OCP;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Files\IRootFolder;
use OC_Util;
use RuntimeException;

/** Native resources and read-only probes owned solely by the guest parity scenario. */
final class GuestDeckParityFixture {
    /**
     * Execute one native action in the fresh owner process booted by DeckFixture.
     *
     * @param string $uid Disposable owner identity.
     * @param string $action Explicit parity fixture action.
     * @param array<string, mixed> $in Resource ledger and exact fixture inputs.
     * @return array<string, mixed> Native observations or remaining resources after cleanup.
     */
    public static function run(string $uid, string $action, array $in): array {
        if ($uid !== F::OWNER) {
            throw new RuntimeException('Only the disposable owner prepares this guest fixture');
        }
        $apps = Server::get(OCP\App\IAppManager::class);
        GuestDeckParityProbe::registerBackend($apps);
        $boards = Server::get(BoardService::class);
        $boards->setUserId($uid);
        $cards = Server::get(CardService::class);
        $stacks = Server::get(StackService::class);
        $root = Server::get(IRootFolder::class);
        $shares = Server::get(IManager::class);
        switch ($action) {
            case 'guest-external-prepare':
                if ($uid !== F::OWNER || $in['guest'] !== 'mcp-it-parity-guest') {
                    throw new RuntimeException('Only the disposable owner prepares this guest fixture');
                }
                $apps->loadApp('files_external');
                OC_Util::tearDownFS();
                OC_Util::setupFS($uid);
                $suffix = $in['suffix'];
                $external = $root->getUserFolder($uid)->get($in['mount'])
                    ->newFile('guest-deck-external-' . $suffix . '.txt', 'External Deck bytes ' . $suffix);
                $editable = $root->getUserFolder($uid)->get($in['mount'])
                    ->newFile('guest-share-editable-' . $suffix . '.txt', 'Editable share bytes ' . $suffix);
                $home = $root->getUserFolder($uid)->newFile('guest-deck-home-' . $suffix . '.txt', 'Home Deck bytes');
                $board = $boards->create('Guest external ' . $suffix, $uid, '008800');
                $stack = $stacks->create('Files', $board->getId(), 0);
                $card = $cards->create('External file visibility', $stack->getId(), 'plain', 0, $uid);
                $boards->addAcl($board->getId(), 0, $in['guest'], true, false, false);
                $out = ['boardId' => $board->getId(), 'homeName' => $home->getName(), 'cardId' => $card->getId(), 'externalFileId' => $external->getId(),
                    'externalName' => $external->getName(), 'externalHash' => hash('sha256', $external->getContent()),
                    'externalMountType' => $external->getMountPoint()->getMountType(),
                    'editableFileId' => $editable->getId(), 'editableName' => $editable->getName(),
                    'editableHash' => hash('sha256', $editable->getContent()),
                    'homeFileId' => $home->getId(), 'homeHash' => hash('sha256', $home->getContent())];
                break;
            case 'guest-external-associate':
                // A fresh native process sees files scanned after creation (NC32 caches misses during post_create).
                $apps->loadApp('files_external');
                OC_Util::tearDownFS();
                OC_Util::setupFS($uid);
                $ownerRoot = $root->getUserFolder($uid);
                $external = $ownerRoot->get($in['mount'] . '/' . $in['externalName']);
                $home = $ownerRoot->getById($in['homeFileId'])[0];
                $out = [];
                foreach (['externalShare' => $external, 'homeShare' => $home] as $key => $file) {
                    $created = $shares->createShare($shares->newShare()->setNode($file)->setShareType(IShare::TYPE_DECK)
                        ->setSharedWith((string)$in['cardId'])->setPermissions(1)->setSharedBy($uid));
                    $out[$key] = (int)$created->getId();
                    $out['shareIds'][] = $created->getFullId();
                }
                $independent = $shares->createShare($shares->newShare()->setNode($external)->setShareType(IShare::TYPE_USER)
                    ->setSharedWith('mcp-it-bob')->setPermissions(1)->setSharedBy($uid));
                $out['independent'] = ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
                    'permissions' => $independent->getPermissions(), 'recipient' => $independent->getSharedWith()];
                break;
            case 'guest-origin-probe':
                // Read-only native metadata: no mounted path is used to infer the source.
                $describe = static function ($node): array {
                    $storage = $node->getStorage();
                    $wrappers = [];
                    $seen = [];
                    $shareType = null;
                    for ($depth = 0; $depth < 12; $depth++) {
                        if (isset($seen[spl_object_id($storage)])) { break; }
                        $seen[spl_object_id($storage)] = true;
                        $entry = ['class' => $storage::class, 'localShare' => (bool)$storage->instanceOfStorage(OCP\Files\Storage\ISharedStorage::class)];
                        if ($storage instanceof OCP\Files\Storage\ISharedStorage) {
                            $share = $storage->getShare();
                            $shareType = $share->getShareType();
                            $entry['share'] = ['type' => $share->getShareType(), 'sourceId' => $share->getNodeId(), 'owner' => $share->getShareOwner()];
                        }
                        $wrappers[] = $entry;
                        if (!method_exists($storage, 'getWrapperStorage')) { break; }
                        $storage = $storage->getWrapperStorage();
                    }
                    return ['id' => $node->getId(), 'path' => $node->getPath(), 'owner' => $node->getOwner()?->getUID(),
                        'mountType' => $node->getMountPoint()->getMountType(), 'shareType' => $shareType, 'updateable' => $node->isUpdateable(), 'storage' => $wrappers];
                };
                $out = ['owner' => [], 'guest' => []];
                foreach (['owner' => $uid, 'guest' => $in['guest']] as $key => $actor) {
                    $actorRoot = $root->getUserFolder($actor);
                    foreach ([$in['externalFileId'], $in['editableFileId']] as $fileId) {
                        foreach ($actorRoot->getById($fileId) as $node) {
                            $out[$key][] = $describe($node) + ['relativePath' => $actorRoot->getRelativePath($node->getPath())];
                        }
                    }
                }
                break;
            case 'guest-external-inspect':
                $apps->loadApp('files_external');
                OC_Util::tearDownFS();
                OC_Util::setupFS($uid);
                $file = $root->getUserFolder($uid)->get($in['mount'] . '/' . $in['externalName']);
                $independent = $shares->getShareById($in['independent']['id']);
                $guestShare = $shares->getShareById($in['guestShare']['id']);
                $editable = $root->getUserFolder($uid)->get($in['mount'] . '/' . $in['editableName']);
                $out = ['fileId' => $file->getId(), 'hash' => hash('sha256', $file->getContent()),
                    'editableFileId' => $editable->getId(), 'editableHash' => hash('sha256', $editable->getContent()),
                    'attachments' => array_map(static fn ($s): int => (int)$s->getId(),
                        Server::get(OCA\Deck\Sharing\DeckShareProvider::class)->getSharedWithByType($in['cardId'], IShare::TYPE_DECK, -1, 0)),
                    'guestShare' => ['id' => $guestShare->getFullId(), 'fileId' => (int)$guestShare->getNodeId(),
                        'permissions' => $guestShare->getPermissions(), 'recipient' => $guestShare->getSharedWith()],
                    'independent' => ['id' => $independent->getFullId(), 'fileId' => (int)$independent->getNodeId(),
                        'permissions' => $independent->getPermissions(), 'recipient' => $independent->getSharedWith()]];
                break;
            case 'guest-external-cleanup':
                $apps->loadApp('files_external');
                OC_Util::tearDownFS();
                OC_Util::setupFS($uid);
                $owner = $root->getUserFolder($uid);
                $out = ['shares' => [], 'files' => [], 'boards' => []];
                // Also recover partially prepared fixtures using their exact, parent-owned random suffix.
                $names = [$in['mount'] . '/guest-deck-external-' . $in['suffix'] . '.txt',
                    $in['mount'] . '/guest-share-editable-' . $in['suffix'] . '.txt',
                    'guest-deck-home-' . $in['suffix'] . '.txt'];
                $ids = $in['shares'];
                foreach ($names as $name) {
                    if ($owner->nodeExists($name)) {
                        $file = $owner->get($name);
                        foreach ([IShare::TYPE_DECK, IShare::TYPE_USER] as $type) {
                            foreach ($shares->getSharesBy($uid, $type, $file, false, -1, 0) as $share) {
                                $ids[] = $share->getFullId();
                            }
                        }
                    }
                }
                foreach (array_unique($ids) as $id) {
                    try {
                        $shares->deleteShare($shares->getShareById($id));
                    } catch (OCP\Share\Exceptions\ShareNotFound) {
                        // A native board/file deletion may already have removed this scenario's share.
                    }
                    try {
                        $shares->getShareById($id);
                        $out['shares'][] = $id;
                    } catch (OCP\Share\Exceptions\ShareNotFound) {
                    }
                }
                foreach ($boards->findAll(-2, false, true) as $board) {
                    if ($board->getTitle() === 'Guest external ' . $in['suffix']) {
                        $boards->delete($board->getId());
                        $boards->deleteForce($board->getId());
                    }
                }
                foreach ($boards->findAll(-2, false, true) as $board) {
                    if ($board->getTitle() === 'Guest external ' . $in['suffix']) {
                        $out['boards'][] = $board->getId();
                    }
                }
                foreach ($names as $name) {
                    if ($owner->nodeExists($name)) {
                        $owner->get($name)->delete();
                    }
                    if ($owner->nodeExists($name)) {
                        $out['files'][] = $name;
                    }
                }
                break;
            default:
                throw new RuntimeException('Unknown guest parity action');
        }
        return $out;
    }
}
