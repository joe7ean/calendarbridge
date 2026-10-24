<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int    getId()
 * @method string getFeedUrl()
 * @method void   setFeedUrl(string $feedUrl)
 * @method string getFeedName()
 * @method void   setFeedName(string $feedName)
 * @method string|null getCalendarColor()
 * @method void   setCalendarColor(?string $calendarColor)
 * @method int|null getCalendarId()
 * @method void   setCalendarId(?int $calendarId)
 * @method string getServiceUser()
 * @method void   setServiceUser(string $serviceUser)
 * @method string getTargetGroups()
 * @method void   setTargetGroups(string $targetGroups)
 * @method int    getSyncInterval()
 * @method void   setSyncInterval(int $syncInterval)
 * @method \DateTime|null getLastSyncedAt()
 * @method void   setLastSyncedAt(?\DateTime $lastSyncedAt)
 * @method \DateTime|null getLastManualSyncAt()
 * @method void   setLastManualSyncAt(?\DateTime $lastManualSyncAt)
 * @method string|null getLastEtag()
 * @method void   setLastEtag(?string $lastEtag)
 * @method int    getLastStatus()
 * @method void   setLastStatus(int $lastStatus)
 * @method string|null getLastError()
 * @method void   setLastError(?string $lastError)
 * @method int    getConsecutiveErrors()
 * @method void   setConsecutiveErrors(int $consecutiveErrors)
 * @method int    getStripAttendees()
 * @method void   setStripAttendees(int $stripAttendees)
 * @method string|null getHttpAuthUser()
 * @method void   setHttpAuthUser(?string $httpAuthUser)
 * @method string|null getHttpAuthPass()
 * @method void   setHttpAuthPass(?string $httpAuthPass)
 * @method int    getVerifySsl()
 * @method void   setVerifySsl(int $verifySsl)
 * @method int    getActive()
 * @method void   setActive(int $active)
 * @method string|null getLastModified()
 * @method void   setLastModified(?string $lastModified)
 * @method \DateTime|null getNextAttemptAt()
 * @method void   setNextAttemptAt(?\DateTime $nextAttemptAt)
 * @method \DateTime|null getQuarantineSince()
 * @method void   setQuarantineSince(?\DateTime $quarantineSince)
 * @method int    getQuarantineCount()
 * @method void   setQuarantineCount(int $quarantineCount)
 * @method string|null getQuarantineHash()
 * @method void   setQuarantineHash(?string $quarantineHash)
 * @method string|null getQuarantineReason()
 * @method void   setQuarantineReason(?string $quarantineReason)
 * @method int    getQuarantineHeld()
 * @method void   setQuarantineHeld(int $quarantineHeld)
 * @method string|null getRejectedHash()
 * @method void   setRejectedHash(?string $rejectedHash)
 */
class Feed extends Entity {
    protected string $feedUrl = '';
    protected string $feedName = '';
    protected ?string $calendarColor = '#0082c9';
    protected ?int $calendarId = null;
    protected string $serviceUser = '';
    protected string $targetGroups = '[]';
    protected int $syncInterval = 3600;
    protected ?\DateTime $lastSyncedAt = null;
    protected ?\DateTime $lastManualSyncAt = null;
    protected ?string $lastEtag = null;
    protected int $lastStatus = 0;
    protected ?string $lastError = null;
    protected int $consecutiveErrors = 0;
    protected int $stripAttendees = 1;
    protected ?string $httpAuthUser = null;
    protected ?string $httpAuthPass = null;
    protected int $verifySsl = 1;
    protected int $active = 1;
    protected ?string $lastModified = null;
    protected ?\DateTime $nextAttemptAt = null;
    protected ?\DateTime $quarantineSince = null;
    protected int $quarantineCount = 0;
    protected ?string $quarantineHash = null;
    protected ?string $quarantineReason = null;
    protected int $quarantineHeld = 0;
    protected ?string $rejectedHash = null;

    public const STATUS_OK = 0;
    public const STATUS_ERROR = 1;
    public const STATUS_QUARANTINE = 3;

    public function __construct() {
        $this->addType('calendarId', 'integer');
        $this->addType('syncInterval', 'integer');
        $this->addType('lastSyncedAt', 'datetime');
        $this->addType('lastManualSyncAt', 'datetime');
        $this->addType('lastStatus', 'integer');
        $this->addType('consecutiveErrors', 'integer');
        $this->addType('stripAttendees', 'integer');
        $this->addType('verifySsl', 'integer');
        $this->addType('active', 'integer');
        $this->addType('nextAttemptAt', 'datetime');
        $this->addType('quarantineSince', 'datetime');
        $this->addType('quarantineCount', 'integer');
        $this->addType('quarantineHeld', 'integer');
    }

    public function isInQuarantine(): bool {
        return $this->quarantineHash !== null;
    }

    public function clearQuarantine(): void {
        $this->setQuarantineSince(null);
        $this->setQuarantineCount(0);
        $this->setQuarantineHash(null);
        $this->setQuarantineReason(null);
        $this->setQuarantineHeld(0);
        $this->setRejectedHash(null);
    }

    public function isStripAttendees(): bool {
        return (bool)$this->stripAttendees;
    }

    public function isVerifySsl(): bool {
        return (bool)$this->verifySsl;
    }

    public function isActive(): bool {
        return (bool)$this->active;
    }

    /** @return string[] */
    public function getTargetGroupsArray(): array {
        return json_decode($this->targetGroups, true) ?? [];
    }

    /** @param string[] $groups */
    public function setTargetGroupsArray(array $groups): void {
        $this->setTargetGroups(json_encode(array_values($groups)));
    }

    public function toApiArray(): array {
        return [
            'id'              => $this->getId(),
            'feedUrl'         => $this->feedUrl,
            'feedName'        => $this->feedName,
            'calendarColor'   => $this->calendarColor,
            'calendarId'      => $this->calendarId,
            'serviceUser'     => $this->serviceUser,
            'targetGroups'    => $this->getTargetGroupsArray(),
            'syncInterval'    => $this->syncInterval,
            'lastSyncedAt'    => $this->lastSyncedAt?->format(\DateTime::ATOM),
            'lastManualSyncAt' => $this->lastManualSyncAt?->format(\DateTime::ATOM),
            'lastStatus'      => $this->lastStatus,
            'lastError'       => $this->lastError,
            'consecutiveErrors' => $this->consecutiveErrors,
            'stripAttendees'  => (bool)$this->stripAttendees,
            'httpAuthUser'    => $this->httpAuthUser,
            // Never return the actual password — only a presence flag
            'hasHttpAuth'     => $this->httpAuthPass !== null,
            'verifySsl'       => (bool)$this->verifySsl,
            'active'          => (bool)$this->active,
            'nextAttemptAt'   => $this->nextAttemptAt?->format(\DateTime::ATOM),
            'quarantine'      => $this->quarantineHash === null ? null : [
                'since'    => $this->quarantineSince?->format(\DateTime::ATOM),
                'reason'   => $this->quarantineReason,
                'held'     => $this->quarantineHeld,
                'seen'     => $this->quarantineCount,
                'rejected' => $this->rejectedHash !== null && $this->rejectedHash === $this->quarantineHash,
            ],
        ];
    }
}
