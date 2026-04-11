<?php

declare(strict_types=1);

namespace OCA\CalendarBridge\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Feed>
 */
class FeedMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'calbridge_feeds', Feed::class);
    }

    /** @return Feed[] */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('feed_name', 'ASC');
        return $this->findEntities($qb);
    }

    /** @return Feed[] */
    public function findAllActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        return $this->findEntities($qb);
    }

    public function findById(int $id): Feed {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Check if any feed (across all service users) already uses this URL.
     * Used for duplicate-URL warnings in the UI.
     *
     * @return Feed[]
     */
    public function findByUrl(string $url): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('feed_url', $qb->createNamedParameter($url)));
        return $this->findEntities($qb);
    }

    /**
     * Find feeds that belong to a specific service user.
     *
     * @return Feed[]
     */
    public function findByServiceUser(string $serviceUser): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('service_user', $qb->createNamedParameter($serviceUser)));
        return $this->findEntities($qb);
    }

    /**
     * Find feeds associated with a calendar that no longer exists,
     * indicated by calendar_id being set but the calendar is missing.
     *
     * @return Feed[]
     */
    public function findWithErrors(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('last_status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }
}
