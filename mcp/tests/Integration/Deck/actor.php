<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Deck\DeckFixture as F;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\AttachmentService;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Files\IRootFolder;

require dirname(__DIR__) . '/bootstrap.php';
[$script, $uid, $action] = $argv;
if (!in_array($uid, [F::OWNER, F::READER, F::EDITOR, F::OUTSIDER], true)) {
    throw new RuntimeException('Not a disposable Deck fixture actor');
}
Server::get(OCP\IUserSession::class)->setUser(Server::get(OCP\IUserManager::class)->get($uid));
Server::get(OCP\ISession::class)->set('user_id', $uid);
OC_Util::setupFS($uid);
$apps = Server::get(OCP\App\IAppManager::class);
if (!$apps->isEnabledForAnyone('deck')) {
    throw new RuntimeException('Deck must be provisioned; this scenario never skips');
}
$apps->loadApp('deck');
$boards = Server::get(BoardService::class);
$boards->setUserId($uid);
$cards = Server::get(CardService::class);
$stacks = Server::get(StackService::class);
$labels = Server::get(LabelService::class);
$attachments = Server::get(AttachmentService::class);
$root = Server::get(IRootFolder::class);
$shares = Server::get(IManager::class);
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function attempt(Closure $fn): array {
    try { $fn(); return ['accepted' => true, 'exception' => null]; }
    catch (OCA\Deck\NoPermissionException|OCA\Deck\StatusException|OCP\Share\Exceptions\GenericShareException $e) {
        return ['accepted' => false, 'exception' => $e::class];
    }
}
function labelIds(object $card): array {
    $ids = array_map(fn ($l) => (int)$l->getId(), $card->getLabels()); sort($ids); return $ids;
}
function dependentIds(object $card): array {
    $ids = $card->getDependentCards(); sort($ids); return $ids;
}
function cardReadable(CardService $cards, int $id): bool {
    try { $cards->find($id); return true; }
    catch (OCA\Deck\NoPermissionException $e) { return false; }
}
switch ($action) {
    case 'invalid-provisioning':
        $commands = [
            'manifest' => [PHP_BINARY, dirname(__DIR__) . '/ci/check-deck-manifest.php',
                getenv('NEXTCLOUD_ROOT') . '/custom_apps/deck/appinfo/info.xml', getenv('MCP_IT_NC'), '0.0.0'],
            // In-container pin_releases guard only; this is not the host run-local provisioning flow.
            'major' => ['sh', dirname(__DIR__) . '/ci/harness.sh', 'provision'],
        ];
        foreach ($commands as $name => $command) {
            $env = array_merge(getenv(), ['MCP_IT_LOCAL' => '1', 'MCP_IT_NC' => '99',
                'RUNNER_TEMP' => '/tmp/mcp-it-invalid-pin-' . bin2hex(random_bytes(6))]);
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start negative provisioning probe'); }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $out[$name] = ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        }
        break;
    case 'seed':
    case 'private-seed':
        $suffix = bin2hex(random_bytes(6));
        $board = $boards->create('Integration Deck ' . $suffix, $uid, '008800');
        $stack = $stacks->create('To do', $board->getId(), 0);
        $card = $cards->create('Design ' . $suffix, $stack->getId(), 'plain', 0, $uid);
        $out = ['board' => $board->getId(), 'stack' => $stack->getId(), 'a' => $card->getId()];
        if ($action === 'private-seed') { break; }
        $out['b'] = $cards->create('Development', $stack->getId(), 'plain', 1, $uid)->getId();
        $private = $boards->create('Private ' . $suffix, $uid, '880000');
        $privateStack = $stacks->create('Private', $private->getId(), 0);
        $out['privateCard'] = $cards->create('Private reference', $privateStack->getId(), 'plain', 0, $uid)->getId();
        $out['foreignLabel'] = $labels->create('Other board', '880000', $private->getId())->getId();
        $out['keep'] = $labels->create('Keep', '008800', $board->getId())->getId();
        $out['toggle'] = $labels->create('Toggle', '880000', $board->getId())->getId();
        $boards->addAcl($board->getId(), 0, F::READER, false, false, false);
        $boards->addAcl($board->getId(), 0, F::EDITOR, true, false, false);
        $out['fileName'] = 'deck-fixture-' . $suffix . '.txt';
        $file = $root->getUserFolder($uid)->newFile($out['fileName'], 'Native Deck fixture bytes ' . $suffix);
        $out['fileId'] = $file->getId();
        $out['hash'] = hash('sha256', $file->getContent());
        if ($in['collaboration'] ?? false) {
            $groupBoard = $boards->create('Group ' . $suffix, $uid, '008800');
            $groupStack = $stacks->create('Group', $groupBoard->getId(), 0);
            $out['groupCard'] = $cards->create('Group card', $groupStack->getId(), 'plain', 0, $uid)->getId();
            $boards->addAcl($groupBoard->getId(), 1, F::GROUP, false, false, false);
            if (!$apps->isEnabledForAnyone('circles')) { throw new RuntimeException('Circles fixture app is missing'); }
            $apps->loadApp('circles');
            $circles = Server::get(OCA\Circles\CirclesManager::class);
            $circles->startSession($circles->getLocalFederatedUser($uid), true);
            $circle = $circles->createCircle('Deck fixture ' . $suffix, null, false, true, false);
            $circles->addMember($circle->getSingleId(), $circles->getLocalFederatedUser(F::EDITOR));
            $out['circle'] = $circle->getSingleId();
            $circleBoard = $boards->create('Circle ' . $suffix, $uid, '008800');
            $circleStack = $stacks->create('Circle', $circleBoard->getId(), 0);
            $out['circleCard'] = $cards->create('Circle card', $circleStack->getId(), 'plain', 0, $uid)->getId();
            $boards->addAcl($circleBoard->getId(), 7, $out['circle'], false, false, false);
        }
        break;
    case 'observe':
        $out = ['source' => cardReadable($cards, $in['a']), 'private' => cardReadable($cards, $in['privateCard'])];
        foreach (['groupCard', 'circleCard'] as $key) {
            if (isset($in[$key])) { $out[$key] = cardReadable($cards, $in[$key]); }
        }
        if ($out['source'] && method_exists($cards, 'assignDependentCard')) {
            $out['dependentCards'] = dependentIds($cards->find($in['a']));
        }
        $out['files'] = array_map(fn ($n) => ['id' => $n->getId(), 'hash' => hash('sha256', $n->getContent())],
            $root->getUserFolder($uid)->getById($in['fileId']));
        break;
    case 'labels':
        $out['available'] = array_map(fn ($l) => (int)$l->getId(), $boards->find($in['board'])->getLabels());
        $cards->assignLabel($in['a'], $in['keep']); $cards->assignLabel($in['a'], $in['toggle']);
        $out['both'] = labelIds($cards->find($in['a']));
        $cards->removeLabel($in['a'], $in['toggle']);
        $out['after'] = labelIds($cards->find($in['a']));
        $out['foreign'] = attempt(fn () => $cards->assignLabel($in['a'], $in['foreignLabel']));
        break;
    case 'label-write':
        $out = attempt(fn () => $cards->assignLabel($in['a'], $in['toggle']));
        break;
    case 'dependencies':
        $out['available'] = method_exists($cards, 'assignDependentCard');
        if (!$out['available']) { break; }
        $cards->assignDependentCard($in['a'], $in['b']);
        $out['forward'] = dependentIds($cards->find($in['a']));
        $out['reverse'] = dependentIds($cards->find($in['b']));
        $cards->assignDependentCard($in['a'], $in['b']);
        $out['afterDuplicate'] = dependentIds($cards->find($in['a']));
        $cards->assignDependentCard($in['b'], $in['a']);
        $out['cycle'] = dependentIds($cards->find($in['b']));
        $cards->assignDependentCard($in['a'], $in['a']);
        $cards->assignDependentCard($in['a'], $in['privateCard']);
        $out['selfAndCrossBoard'] = dependentIds($cards->find($in['a']));
        $out['hidden'] = attempt(fn () => $cards->assignDependentCard($in['a'], $in['hiddenCard']));
        $cards->removeDependentCard($in['a'], $in['b']);
        $out['afterRemoval'] = dependentIds($cards->find($in['a']));
        break;
    case 'dependency-write':
        $out = attempt(fn () => $cards->assignDependentCard($in['a'], $in['b']));
        break;
    case 'share':
        $node = $root->getUserFolder($uid)->get($in['fileName']);
        $share = $shares->newShare()->setNode($node)->setShareType(IShare::TYPE_DECK)
            ->setSharedWith((string)$in['a'])->setPermissions(1)->setSharedBy($uid);
        $share = $shares->createShare($share);
        $out = ['shareId' => (int)$share->getId(), 'fileId' => $share->getNodeId(), 'type' => $share->getShareType(),
            'permissions' => $share->getPermissions(), 'count' => count($attachments->findAll($in['a']))];
        break;
    case 'unlink':
        $attachments->delete($in['a'], $in['shareId'], 'file');
        $out = ['count' => count($attachments->findAll($in['a']))];
        break;
    default:
        throw new RuntimeException('Unknown fixture action');
}
echo json_encode($out, JSON_THROW_ON_ERROR);
