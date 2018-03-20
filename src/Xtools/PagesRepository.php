<?php
/**
 * This file contains only the PagesRepository class.
 */

namespace Xtools;

/**
 * An PagesRepository is responsible for retrieving information from the
 * databases for the Pages Created tool. It does not do any post-processing
 * of that data.
 * @codeCoverageIgnore
 */
class PagesRepository extends Repository
{
    /**
     * Count the number of pages created by a user.
     * @param Project $project
     * @param User $user
     * @param string|int $namespace Namespace ID or 'all'.
     * @param string $redirects One of 'noredirects', 'onlyredirects' or blank for both.
     * @param string $deleted One of 'live', 'deleted' or blank for both.
     * @return string[] Result of query, see below. Includes live and deleted pages.
     */
    public function countPagesCreated(
        Project $project,
        User $user,
        $namespace,
        $redirects,
        $deleted
    ) {
        $cacheKey = $this->getCacheKey(func_get_args(), 'num_user_pages_created');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }

        $conditions = [
            'paSelects' => '',
            'paSelectsArchive' => '',
            'paJoin' => '',
            'revPageGroupBy' => '',
        ];
        $conditions = array_merge(
            $conditions,
            $this->getNamespaceRedirectAndDeletedPagesConditions($namespace, $redirects),
            $this->getUserConditions($project, $user)
        );

        $sql = "SELECT namespace,
                    COUNT(page_title) AS count,
                    SUM(CASE WHEN type = 'arc' THEN 1 ELSE 0 END) AS deleted,
                    SUM(page_is_redirect) AS redirects
                FROM (".
                    $this->getPagesCreatedInnerSql($project, $conditions, $deleted)."
                ) a ".
                "GROUP BY namespace";

        $result = $this->executeProjectsQuery($sql)->fetchAll();

        // Cache and return.
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get pages created by a user.
     * @param Project $project
     * @param User $user
     * @param string|int $namespace Namespace ID or 'all'.
     * @param string $redirects One of 'noredirects', 'onlyredirects' or blank for both.
     * @param string $deleted One of 'live', 'deleted' or blank for both.
     * @param int|null $limit Number of results to return, or blank to return all.
     * @param int $offset Number of pages past the initial dataset. Used for pagination.
     * @return string[] Result of query, see below. Includes live and deleted pages.
     */
    public function getPagesCreated(
        Project $project,
        User $user,
        $namespace,
        $redirects,
        $deleted,
        $limit = 1000,
        $offset = 0
    ) {
        $cacheKey = $this->getCacheKey(func_get_args(), 'user_pages_created');
        if ($this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey)->get();
        }
        $this->stopwatch->start($cacheKey, 'XTools');

        $conditions = [
            'paSelects' => '',
            'paSelectsArchive' => '',
            'paJoin' => '',
            'revPageGroupBy' => '',
        ];

        $conditions = array_merge(
            $conditions,
            $this->getNamespaceRedirectAndDeletedPagesConditions($namespace, $redirects),
            $this->getUserConditions($project, $user)
        );

        $pageAssessmentsTable = $project->getTableName('page_assessments');

        $hasPageAssessments = $this->isLabs() && $project->hasPageAssessments();
        if ($hasPageAssessments) {
            $conditions['paSelects'] = ', pa_class, pa_importance, pa_page_revision';
            $conditions['paSelectsArchive'] = ', NULL AS pa_class, NULL AS pa_page_id, '.
                'NULL AS pa_page_revision';
            $conditions['paJoin'] = "LEFT JOIN $pageAssessmentsTable ON rev_page = pa_page_id";
            $conditions['revPageGroupBy'] = 'GROUP BY rev_page';
        }

        $sql = "SELECT * FROM (".
                    $this->getPagesCreatedInnerSql($project, $conditions, $deleted)."
                ) a ".
                "ORDER BY rev_timestamp DESC
                ".(!empty($limit) ? "LIMIT $limit OFFSET $offset" : '');

        $result = $this->executeProjectsQuery($sql)->fetchAll();

        // Cache and return.
        $this->stopwatch->stop($cacheKey);
        return $this->setCache($cacheKey, $result);
    }

    /**
     * Get SQL fragments for the namespace and redirects,
     * to be used in self::getPagesCreatedInnerSql().
     * @param  string|int $namespace Namespace ID or 'all'.
     * @param  string $redirects One of 'noredirects', 'onlyredirects' or blank for both.
     * @return string[] With keys 'namespaceRev', 'namespaceArc' and 'redirects'
     */
    private function getNamespaceRedirectAndDeletedPagesConditions($namespace, $redirects)
    {
        $conditions = [
            'namespaceArc' => '',
            'namespaceRev' => '',
            'redirects' => ''
        ];

        if ($namespace !== 'all') {
            $conditions['namespaceRev'] = " AND page_namespace = '".intval($namespace)."' ";
            $conditions['namespaceArc'] = " AND ar_namespace = '".intval($namespace)."' ";
        }

        if ($redirects == 'onlyredirects') {
            $conditions['redirects'] = " AND page_is_redirect = '1' ";
        } elseif ($redirects == 'noredirects') {
            $conditions['redirects'] = " AND page_is_redirect = '0' ";
        }

        return $conditions;
    }

    /**
     * Get SQL fragments for rev_user or rev_user_text, depending on if the user is logged out.
     * Used in self::getPagesCreatedInnerSql().
     * @param  Project $project
     * @param  User $user
     * @return string[] Keys 'whereRev' and 'whereArc'.
     */
    private function getUserConditions(Project $project, User $user)
    {
        $username = $user->getUsername();
        $userId = $user->getId($project);

        if ($userId == 0) { // IP Editor or undefined username.
            return [
                'whereRev' => " rev_user_text = '$username' AND rev_user = '0' ",
                'whereArc' => " ar_user_text = '$username' AND ar_user = '0' ",
            ];
        } else {
            return [
                'whereRev' => " rev_user = '$userId' AND rev_timestamp > 1 ",
                'whereArc' => " ar_user = '$userId' AND ar_timestamp > 1 ",
            ];
        }
    }

    /**
     * Inner SQL for getting or counting pages created by the user.
     * @param  Project $project
     * @param  string[] $conditions Conditions for the SQL, must include 'paSelects',
     *     'paSelectsArchive', 'paJoin', 'whereRev', 'whereArc', 'namespaceRev', 'namespaceArc',
     *     'redirects' and 'revPageGroupBy'.
     * @param  string $deleted One of 'live', 'deleted' or blank for both.
     * @return string Raw SQL.
     */
    private function getPagesCreatedInnerSql(Project $project, $conditions, $deleted)
    {
        $pageTable = $project->getTableName('page');
        $revisionTable = $project->getTableName('revision');
        $archiveTable = $project->getTableName('archive');
        $logTable = $project->getTableName('logging', 'logindex');

        $revisionsSelect = "
            SELECT DISTINCT page_namespace AS namespace, 'rev' AS type, page_title AS page_title,
                page_len, page_is_redirect, rev_timestamp AS rev_timestamp, rev_user,
                rev_user_text AS username, rev_len, rev_id, NULL AS recreated ".$conditions['paSelects']."
            FROM $pageTable
            JOIN $revisionTable ON page_id = rev_page ".
            $conditions['paJoin']."
            WHERE ".$conditions['whereRev']."
                AND rev_parent_id = '0'".
                $conditions['namespaceRev'].
                $conditions['redirects'].
            $conditions['revPageGroupBy'];

        $archiveSelect = "
            SELECT ar_namespace AS namespace, 'arc' AS type, ar_title AS page_title,
                NULL AS page_len, '0' AS page_is_redirect, MIN(ar_timestamp) AS rev_timestamp,
                ar_user AS rev_user, ar_user_text AS username, ar_len AS rev_len, ar_rev_id AS rev_id,
                EXISTS(
                    SELECT 1 FROM $pageTable
                    WHERE page_namespace = ar_namespace
                    AND page_title = ar_title
                ) AS recreated
                ".$conditions['paSelectsArchive']."
            FROM $archiveTable
            LEFT JOIN $logTable ON log_namespace = ar_namespace AND log_title = ar_title
                AND log_user = ar_user AND (log_action = 'move' OR log_action = 'move_redir')
                AND log_type = 'move'
            WHERE ".$conditions['whereArc']."
                AND ar_parent_id = '0' ".
                $conditions['namespaceArc']."
                AND log_action IS NULL
            GROUP BY ar_namespace, ar_title";

        if ($deleted == 'live') {
            return $revisionsSelect;
        } elseif ($deleted == 'deleted') {
            return $archiveSelect;
        }

        return "($revisionsSelect) UNION ($archiveSelect)";
    }
}
