<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SecretSanta\Service;

use OCA\SecretSanta\Db\SecretSantaList;
use OCA\SecretSanta\Db\SecretSantaListMapper;
use OCA\SecretSanta\Db\Share;
use OCA\SecretSanta\Db\ShareMapper;
use OCA\SecretSanta\Exception\InvalidInputException;
use OCA\SecretSanta\Interaction\ListResource;
use OCA\SecretSanta\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Interaction\Actions\ShareAction;
use OCP\Interaction\Receivers\GroupReceiver;
use OCP\Interaction\RestrictInteractionEvent;

/**
 * Shares lists with groups, all members of the groups can change the list except deleting and sharing it
 *
 * @psalm-import-type SecretSantaShare from ResponseDefinitions
 */
final class ShareService {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IL10N $l10n,
		private IGroupManager $groupManager,
		private ShareMapper $shareMapper,
		private SecretSantaListMapper $listMapper,
		private ListService $listService,
	) {
	}

	/**
	 * @return list<Share>
	 */
	public function getShares(SecretSantaList $list): array {
		return $this->shareMapper->getShares($list);
	}

	/**
	 * Share a list with a group, following the sharing restrictions of the server for the given user.
	 *
	 * @param string $userId The user who shares the list
	 * @throws InvalidInputException If the group does not exist, the user may not share with it or the list is already shared with it
	 */
	public function share(SecretSantaList $list, string $groupId, string $userId): Share {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			throw new InvalidInputException('Unknown group.', $this->l10n->t('The group does not exist.'));
		}

		$restriction = (new RestrictInteractionEvent(
			$userId,
			null,
			[new ListResource($list->getId())],
			new ShareAction(),
			[new GroupReceiver($group->getGID(), $group)],
		))->isInteractionRestricted();
		if ($restriction !== false) {
			throw new InvalidInputException('Sharing with the group is not allowed.', $restriction);
		}

		try {
			$this->shareMapper->insert($list, $group->getGID());
		} catch (Exception $error) {
			if ($error->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $error;
			}
			throw new InvalidInputException(
				'The list is already shared with the group.',
				$this->l10n->t('The list is already shared with %s.', [$group->getDisplayName()]),
			);
		}
		return new Share($list->getId(), $group->getGID());
	}

	public function unshare(SecretSantaList $list, string $groupId): void {
		$this->shareMapper->delete($list, $groupId);
	}

	/**
	 * Remove the shares with a deleted group, lists without owner and shares are deleted.
	 */
	public function removeGroup(string $groupId): void {
		$shares = $this->shareMapper->getSharesWithGroups([$groupId]);
		$this->shareMapper->deleteByGroup($groupId);
		foreach ($shares as $share) {
			try {
				$list = $this->listMapper->getList($share->listId);
			} catch (DoesNotExistException) {
				continue;
			}
			if ($list->getOwner() === null && $this->shareMapper->getShares($list) === []) {
				$this->listService->delete($list);
			}
		}
	}

	/**
	 * @return SecretSantaShare
	 */
	public function serialize(Share $share): array {
		return [
			'groupId' => $share->groupId,
			'displayName' => $this->groupManager->getDisplayName($share->groupId) ?? $share->groupId,
		];
	}
}
