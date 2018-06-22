<?php
/**
 * This file contains only the CategoryEditsRepository class.
 */

namespace Xtools;

use AppBundle\Helper\AutomatedEditsHelper;
use DateInterval;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * CategoryEditsRepository is responsible for retrieving data from the database
 * about the edits made by a user to pages in a set of given categories.
 * @codeCoverageIgnore
 */
class CategoryEditsRepository extends Repository
{
    /** @var AutomatedEditsHelper Used for fetching the tool list and filtering it. */
    private $aeh;

    /**
     * Method to give the repository access to the AutomatedEditsHelper.
     */
    public function getHelper()
    {
        if (!isset($this->aeh)) {
            $this->aeh = $this->container->get('app.automated_edits_helper');
        }
        return $this->aeh;
    }

    /**
     * Get the number of edits this user made to the given categories.
     * @param Project $project
     * @param User $user
     * @param string[] $categories
     * @param string $start Start date in a format accepted by strtotime()
     * @param string $end End date in a format accepted by strtotime()
     * @return int Result of query, see below.
     */
    public function countCategoryEdits(
        Project $project,
        User $user,
        array $categories,
        $start = '',
        $end = ''
    ) {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_categoryeditcount');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }
        $this->stopwatch->start($cacheKey, 'XTools');

        $revisionTable = $project->getTableName('revision');
        $categorylinksTable = $project->getTableName('categorylinks');

        $query = $this->getProjectsConnection()->createQueryBuilder();
        $query->select(['COUNT(DISTINCT(revs.rev_id))'])
            ->from($revisionTable, 'revs')
            ->join('revs', $categorylinksTable, null, 'cl_from = rev_page')
            ->where('revs.rev_user_text = :username')
            ->andWhere($query->expr()->in('cl_to', ':categories'));

        $result = (int)$this->executeStmt($query, $user, $categories, $start, $end)->fetchColumn();

        // Cache and return.
        $this->stopwatch->stop($cacheKey);
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get number of edits within each individual category.
     * @param  Project $project
     * @param  User    $user
     * @param  array   $categories
     * @param  string  $start
     * @param  string  $end
     * @return string[] With categories as keys, counts as values.
     */
    public function getCategoryCounts(
        Project $project,
        User $user,
        array $categories,
        $start = '',
        $end = ''
    ) {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_categorycounts');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }
        $this->stopwatch->start($cacheKey, 'XTools');

        $revisionTable = $project->getTableName('revision');
        $categorylinksTable = $project->getTableName('categorylinks');

        $query = $this->getProjectsConnection()->createQueryBuilder();
        $query->select(['cl_to', 'COUNT(rev_id)'])
            ->from($revisionTable, 'revs')
            ->join('revs', $categorylinksTable, null, 'cl_from = rev_page')
            ->where('revs.rev_user_text = :username')
            ->andWhere($query->expr()->in('cl_to', ':categories'))
            ->groupBy('cl_to');

        $result = $this->executeStmt($query, $user, $categories, $start, $end)->fetchAll(\PDO::FETCH_KEY_PAIR);

        // Cache and return.
        $this->stopwatch->stop($cacheKey);
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get contributions made to the given categories.
     * @param Project $project
     * @param User $user
     * @param string[] $categories
     * @param string $start Start date in a format accepted by strtotime()
     * @param string $end End date in a format accepted by strtotime()
     * @param int $offset Used for pagination, offset results by N edits
     * @return string[] Result of query, with columns 'page_title',
     *   'page_namespace', 'rev_id', 'timestamp', 'minor',
     *   'length', 'length_change', 'comment'
     */
    public function getCategoryEdits(
        Project $project,
        User $user,
        array $categories,
        $start = '',
        $end = '',
        $offset = 0
    ) {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_categoryedits');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }
        $this->stopwatch->start($cacheKey, 'XTools');

        $pageTable = $project->getTableName('page');
        $revisionTable = $project->getTableName('revision');
        $categorylinksTable = $project->getTableName('categorylinks');

        $query = $this->getProjectsConnection()->createQueryBuilder();
        $query->select([
                'page_title',
                'page_namespace',
                'revs.rev_id AS rev_id',
                'revs.rev_timestamp AS timestamp',
                'revs.rev_minor_edit AS minor',
                'revs.rev_len AS length',
                '(CAST(revs.rev_len AS SIGNED) - IFNULL(parentrevs.rev_len, 0)) AS length_change',
                'revs.rev_comment AS comment',
            ])
            ->from($pageTable)
            ->join($pageTable, $revisionTable, 'revs', 'page_id = revs.rev_page')
            ->join('revs', $categorylinksTable, null, 'cl_from = rev_page')
            ->leftJoin('revs', $revisionTable, 'parentrevs', 'revs.rev_parent_id = parentrevs.rev_id')
            ->where('revs.rev_user_text = :username')
            ->andWhere($query->expr()->in('cl_to', ':categories'))
            ->groupBy('revs.rev_id')
            ->orderBy('revs.rev_timestamp', 'DESC')
            ->setMaxResults(50)
            ->setFirstResult($offset);

        $result = $this->executeStmt($query, $user, $categories, $start, $end)->fetchAll();

        // Cache and return.
        $this->stopwatch->stop($cacheKey);
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Bind dates, username and categories then execute the query.
     * @param  \Doctrine\DBAL\Query\QueryBuilder $query
     * @param User $user
     * @param string[] $categories
     * @param string $start Start date in a format accepted by strtotime()
     * @param string $end End date in a format accepted by strtotime()
     * @return \Doctrine\DBAL\Driver\Statement
     */
    private function executeStmt(\Doctrine\DBAL\Query\QueryBuilder $query, User $user, array $categories, $start, $end)
    {
        if (!empty($start)) {
            $query->andWhere('revs.rev_timestamp >= :start');
            $query->setParameter('start', $start);
        }
        if (!empty($end)) {
            $query->andWhere('revs.rev_timestamp <= DATE_FORMAT(:end, "%Y%m%d235959")');
            $query->setParameter('end', $end);
        }

        $username = $user->getUsername();
        $query->setParameter('username', $username);
        $query->setParameter('categories', $categories, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);

        return $this->executeQueryBuilder($query);
    }
}
