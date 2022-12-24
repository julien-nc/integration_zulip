<?php

/**
 * Nextcloud - Mattermost
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Mattermost\Service;


use DateInterval;
use DateTime;
use DateTimeImmutable;
use Exception;
use Generator;
use OCA\Mattermost\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\IManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use Throwable;

class WebhookService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IManager
	 */
	private $calendarManager;
	/**
	 * @var ITimeFactory
	 */
	private $timeFactory;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IDateTimeFormatter
	 */
	private $dateTimeFormatter;

	public function __construct (IConfig $config,
								LoggerInterface $logger,
								IClientService $clientService,
								IManager $calendarManager,
								ITimeFactory $timeFactory,
								IURLGenerator $urlGenerator,
								IDateTimeFormatter $dateTimeFormatter,
								IUserManager $userManager) {
		$this->client = $clientService->newClient();
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->calendarManager = $calendarManager;
		$this->timeFactory = $timeFactory;
		$this->urlGenerator = $urlGenerator;
		$this->dateTimeFormatter = $dateTimeFormatter;
	}

	/**
	 * @return Generator
	 * @throws PreConditionNotMetException
	 */
	public function dailySummaryWebhook(): Generator {
		$userIds = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$userIds) {
			if ($user->isEnabled()) {
				$userIds[] = $user->getUID();
			}
		});

		foreach ($userIds as $userId) {
			yield [
				'user_id' => $userId,
				'job_info' => $this->userDailySummaryWebhook($userId),
			];
		}
		return [];
	}

	/**
	 * @param string $userId
	 * @return array|null
	 * @throws PreConditionNotMetException
	 */
	public function userDailySummaryWebhook(string $userId): ?array {
		$url = $this->config->getUserValue($userId, Application::APP_ID, Application::DAILY_SUMMARY_WEBHOOK_CONFIG_KEY);
		if ($url === '') {
			return [
				'message' => 'No webhook url configured for daily summary',
				'nb_events' => null,
			];
		}
		$webhooksEnabled = $this->config->getUserValue($userId, Application::APP_ID, Application::WEBHOOKS_ENABLED_CONFIG_KEY) === '1';
		if (!$webhooksEnabled) {
			return [
				'message' => 'Mattermost webhooks disabled for this user',
				'nb_events' => null,
			];
		}

		// check if it has already run today
		$now = new DateTimeImmutable();
		// TODO use the user timezone or at least the server timezone
		$dayNow = new DateTimeImmutable($now->format('Y-m-d'));
		$lastDailyJobDate = $this->config->getUserValue($userId, Application::APP_ID, Application::DAILY_SUMMARY_WEBHOOK_LAST_DATE_CONFIG_KEY);
		if ($lastDailyJobDate !== '') {
			$lastDailyJobDatetime = new DateTimeImmutable($lastDailyJobDate);
			if ($lastDailyJobDatetime >= $dayNow) {
				return [
					'message' => 'Job has already been executed today',
					'nb_events' => null,
				];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, Application::DAILY_SUMMARY_WEBHOOK_LAST_DATE_CONFIG_KEY, $now->format('Y-m-d'));

		$endDate = $dayNow->add(new DateInterval('P1D'));
		$content = [
			'calendarEvents' => $this->getEvents($userId, $dayNow, $endDate),
			'eventType' => 'dailySummary',
		];
		$secret = $this->config->getUserValue($userId, Application::APP_ID, Application::WEBHOOK_SECRET_CONFIG_KEY);
		$this->sendWebhook($url, $content, $secret);
		return [
			'nb_events' => count($content['calendarEvents']),
		];
	}

	/**
	 * @return Generator
	 * @throws PreConditionNotMetException
	 */
	public function imminentEventsWebhook(): Generator {
		$userIds = [];
		$this->userManager->callForAllUsers(function (IUser $user) use (&$userIds) {
			if ($user->isEnabled()) {
				$userIds[] = $user->getUID();
			}
		});

		foreach ($userIds as $userId) {
			yield [
				'user_id' => $userId,
				'job_info' => $this->userImminentEventsWebhook($userId),
			];
		}
		return [];
	}

	/**
	 * @param string $userId
	 * @return array|null
	 * @throws PreConditionNotMetException
	 */
	public function userImminentEventsWebhook(string $userId): ?array {
		$url = $this->config->getUserValue($userId, Application::APP_ID, Application::IMMINENT_EVENTS_WEBHOOK_CONFIG_KEY);
		if ($url === '') {
			return [
				'message' => 'No webhook url configured for imminent events',
				'nb_events' => null,
			];
		}
		$webhooksEnabled = $this->config->getUserValue($userId, Application::APP_ID, Application::WEBHOOKS_ENABLED_CONFIG_KEY) === '1';
		if (!$webhooksEnabled) {
			return [
				'message' => 'Mattermost webhooks disabled for this user',
				'nb_events' => null,
			];
		}

		// check if it has already run today
		// TODO use the user timezone or at least the server timezone
		$now = new DateTimeImmutable();
		$nowTs = $now->getTimestamp();
		$lastImminentJobTimestamp = (int) $this->config->getUserValue($userId, Application::APP_ID, Application::IMMINENT_EVENTS_WEBHOOK_LAST_TS_CONFIG_KEY);

		if ($nowTs < $lastImminentJobTimestamp + (30 * 60)) {
			$ago = intdiv($nowTs - $lastImminentJobTimestamp, 60);
			return [
				'message' => 'Last "imminent events" job has run too recently (' . $ago . ' minutes ago)',
				'nb_events' => null,
			];
		}

		$endDate = $now->add(new DateInterval('PT30M'));
		$this->config->setUserValue($userId, Application::APP_ID, Application::IMMINENT_EVENTS_WEBHOOK_LAST_TS_CONFIG_KEY, (string) $nowTs);

		$content = [
			'calendarEvents' => $this->getEvents($userId, $now, $endDate),
			'eventType' => 'imminentEvents',
		];
		error_log('imminent events '.json_encode($content['calendarEvents']));
		$secret = $this->config->getUserValue($userId, Application::APP_ID, Application::WEBHOOK_SECRET_CONFIG_KEY);
		$this->sendWebhook($url, $content, $secret);
		return [
			'nb_events' => count($content['calendarEvents']),
		];
	}

	/**
	 * @param string $userId
	 * @param DateTimeImmutable $startDate
	 * @param DateTimeImmutable $endDate
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	private function getEvents(string $userId, DateTimeImmutable $startDate, DateTimeImmutable $endDate, int $limit = 20): array {
		$calendars = $this->calendarManager->getCalendarsForPrincipal('principals/users/' . $userId);
		$count = count($calendars);
		if ($count === 0) {
			return [];
		}
		$options = [
			'timerange' => [
				'start' => $startDate,
				'end' => $endDate,
			]
		];
		$events = [];
		foreach ($calendars as $calendar) {
			$searchResult = $calendar->search('', [], $options, $limit);
			foreach ($searchResult as $calendarEvent) {
				/** @var DateTimeImmutable $startDatetimeImm */
				$startDatetimeImm = $calendarEvent['objects'][0]['DTSTART'][0];
				$startDatetime = DateTime::createFromImmutable($startDatetimeImm);
				/** @var DateTimeImmutable $endDatetimeImm */
				$endDatetimeImm = $calendarEvent['objects'][0]['DTEND'][0];
				$endDatetime = DateTime::createFromImmutable($endDatetimeImm);
				$title = $calendarEvent['objects'][0]['SUMMARY'][0] ?? '';
				$description = $calendarEvent['objects'][0]['DESCRIPTION'][0] ?? '';
				$eventArray = [
					'title' => $title,
					'relative' => $this->dateTimeFormatter->formatTimeSpan($startDatetime),
					'url' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('calendar.view.index', ['objectId' => $calendarEvent['uid']])),
					'dot_url' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('calendar.view.getCalendarDotSvg', ['color' => $calendar->getDisplayColor() ?? '#0082c9'])), // default NC blue fallback
					'timestamp_start' => $startDatetimeImm->getTimestamp(),
					'timestamp_end' => $endDatetimeImm->getTimestamp(),
					'date_start' => $this->dateTimeFormatter->formatDateTime($startDatetime),
					'date_end' => $this->dateTimeFormatter->formatDateTime($endDatetime),
					'description' => $description,
					'attendees' => $calendarEvent['objects'][0]['ATTENDEE'] ?? [],
					'organizer' => $calendarEvent['objects'][0]['ORGANIZER'] ?? [],
				];
				$events[] = $eventArray;
			}
		}
		return $events;
	}

	/**
	 * @param string $url
	 * @param array $content
	 * @param string $secret
	 * @return void
	 */
	public function sendWebhook(string $url, array $content, string $secret): void {
		try {
			$stringContent = json_encode($content);
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
				'body' => $stringContent,
			];
			if ($secret !== '') {
				$hash = hash('sha256', $stringContent . $secret);
				$options['headers']['X-Webhook-Signature'] = $hash;
			}
			$this->client->post($url, $options);
		} catch (Exception | Throwable $e) {
			$this->logger->error('Mattermost Webhook error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}
}
