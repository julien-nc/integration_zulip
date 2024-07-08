<?php
/**
 * Nextcloud - Zulip
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Julien Veyssier 2022
 * @copyright Anupam Kumar 2023
 */

namespace OCA\Zulip\Service;

use DateTime;
use Exception;
use OC\User\NoUserException;
use OCA\Zulip\AppInfo\Application;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\LockedException;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Service to make requests to Zulip API
 */
class ZulipAPIService {

	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IRootFolder $root,
		private ShareManager $shareManager,
		private IURLGenerator $urlGenerator,
		private ICrypto $crypto,
		private NetworkService $networkService,
		IClientService $clientService
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $userId
	 * @param string $zulipUserId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getUserAvatar(string $userId, string $zulipUserId): array {
		$userInfo = $this->request($userId, 'users.info', ['user' => $zulipUserId]);

		if (isset($userInfo['error'])) {
			return ['displayName' => 'User'];
		}

		if (isset($userInfo['user'], $userInfo['user']['profile'], $userInfo['user']['profile']['image_48'])) {
			// due to some Zulip API changes, we now have to sanitize the image url
			//   for some of them
			$parsedUrlObj = parse_url($userInfo['user']['profile']['image_48']);

			if (isset($parsedUrlObj['query'])) {
				parse_str($parsedUrlObj['query'], $params);
				if (!isset($params['d'])) {
					if (isset($userInfo['user'], $userInfo['user']['real_name'])) {
						return ['displayName' => $userInfo['user']['real_name']];
					}

					return ['displayName' => 'User'];
				}

				$image = $this->request($userId, $params['d'], [], 'GET', false, false);
			} else {
				$image = $this->request($userId, $userInfo['user']['profile']['image_48'], [], 'GET', false, false);
			}

			if (!is_array($image)) {
				return ['avatarContent' => $image];
			}
		}

		if (isset($userInfo['user'], $userInfo['user']['real_name'])) {
			return ['displayName' => $userInfo['user']['real_name']];
		}

		return ['displayName' => 'User'];
	}

	/**
	 * @param string $userId
	 * @param string $zulipUserId
	 * @return string|null
	 */
	private function getUserRealName(string $userId, string $zulipUserId): string|null {
		$userInfo = $this->request($userId, 'users.info', ['user' => $zulipUserId]);
		if (isset($userInfo['error'])) {
			return null;
		}
		if (!isset($userInfo['user'], $userInfo['user']['real_name'])) {
			return null;
		}
		return $userInfo['user']['real_name'];
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getMyChannels(string $userId): array {
		$channelResult = $this->request($userId, 'streams', [
			'include_web_public' => 'true',
		]);

		if (isset($channelResult['error'])) {
			return (array) $channelResult;
		}

		if (!isset($channelResult['streams']) || !is_array($channelResult['streams'])) {
			return ['error' => 'No channels found'];
		}

		$channels = [];

		foreach($channelResult['streams'] as $channel) {
			$channels[] = [
				'type' => 'channel',
				'id' => $channel['stream_id'],
				'name' => $channel['name'],
				'invite_only' => $channel['invite_only'],
				'is_web_public' => $channel['is_web_public'],
			];
		}

		return $channels;
	}

	/**
	 * @param string $userId
	 * @param int $channelId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getMyTopics(string $userId, int $channelId): array {
		$topicResult = $this->request($userId, 'users/me/' . $channelId . '/topics');

		if (isset($topicResult['error'])) {
			return (array) $topicResult;
		}

		if (!isset($topicResult['topics']) || !is_array($topicResult['topics'])) {
			return ['error' => 'No topics found'];
		}

		return $topicResult['topics'];
	}

	/**
	 * @param string $userId
	 * @param string $message
	 * @param string $channelId
	 * @return array|string[]
	 * @throws PreConditionNotMetException
	 */
	public function sendMessage(string $userId, string $message, string $channelId): array {
		$params = [
			'as_user' => true, // legacy but we'll use it for now
			'link_names' => false, // we onlu send links (public and internal)
			'parse' => 'full',
			'unfurl_links' => true,
			'unfurl_media' => true,
			'channel' => $channelId,
			'text' => $message,
		];
		return $this->request($userId, 'chat.postMessage', $params, 'POST');
	}

	/**
	 * @param string $userId
	 * @param array $fileIds
	 * @param string $channelId
	 * @param string $channelName
	 * @param string $comment
	 * @param string $permission
	 * @param string|null $expirationDate
	 * @param string|null $password
	 * @return array|string[]
	 * @throws NoUserException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 */
	public function sendPublicLinks(string $userId, array $fileIds,
		string $channelId, string $channelName, string $comment,
		string $permission, ?string $expirationDate = null, ?string $password = null): array {
		$links = [];
		$userFolder = $this->root->getUserFolder($userId);

		// create public links
		foreach ($fileIds as $fileId) {
			$nodes = $userFolder->getById($fileId);
			// if (count($nodes) > 0 && $nodes[0] instanceof File) {
			if (count($nodes) > 0 && ($nodes[0] instanceof File || $nodes[0] instanceof Folder)) {
				$node = $nodes[0];

				$share = $this->shareManager->newShare();
				$share->setNode($node);

				if ($permission === 'edit') {
					$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
				} else {
					$share->setPermissions(Constants::PERMISSION_READ);
				}

				$share->setShareType(IShare::TYPE_LINK);
				$share->setSharedBy($userId);
				$share->setLabel('Zulip (' . $channelName . ')');

				if ($expirationDate !== null) {
					$share->setExpirationDate(new DateTime($expirationDate));
				}

				if ($password !== null) {
					try {
						$share->setPassword($password);
					} catch (Exception $e) {
						return ['error' => $e->getMessage()];
					}
				}

				try {
					$share = $this->shareManager->createShare($share);
					if ($expirationDate === null) {
						$share->setExpirationDate(null);
						$this->shareManager->updateShare($share);
					}
				} catch (Exception $e) {
					return ['error' => $e->getMessage()];
				}

				$token = $share->getToken();
				$linkUrl = $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute('files_sharing.Share.showShare', [
						'token' => $token,
					])
				);

				$links[] = [
					'name' => $node->getName(),
					'url' => $linkUrl,
				];
			}
		}

		if (count($links) === 0) {
			return ['error' => 'Files not found'];
		}

		$message = ($comment !== ''
			? $comment . "\n\n"
			: '') .  join("\n", array_map(fn ($link) => $link['name'] . ': ' . $link['url'], $links));

		return $this->sendMessage($userId, $message, $channelId);
	}

	/**
	 * @param string $userId
	 * @param int $fileId
	 * @param string $channelId
	 * @param string $comment
	 * @return array|string[]
	 * @throws NoUserException
	 * @throws NotPermittedException
	 * @throws LockedException
	 */
	public function sendFile(string $userId, int $fileId, string $channelId, string $comment = ''): array {
		$userFolder = $this->root->getUserFolder($userId);
		$files = $userFolder->getById($fileId);

		if (count($files) > 0 && $files[0] instanceof File) {
			$file = $files[0];

			$params = [
				'channels' => $channelId,
				'filename' => $file->getName(),
				'filetype' => 'auto',
				'content' => $file->getContent(),
			];
			if ($comment !== '') {
				$params['initial_comment'] = $comment;
			}

			$sendResult = $this->request($userId, 'files.upload', $params, 'POST');

			if (isset($sendResult['error'])) {
				return (array) $sendResult;
			}

			return ['success' => true];
		} else {
			return ['error' => 'File not found'];
		}
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @param bool $zulipApiRequest
	 * @return array|mixed|resource|string|string[]
	 * @throws PreConditionNotMetException
	 */
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET',
		bool $jsonResponse = true, bool $zulipApiRequest = true) {
		return $this->networkService->request($userId, $endPoint, $params, $method, $jsonResponse, $zulipApiRequest);
	}
}
