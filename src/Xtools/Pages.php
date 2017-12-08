<?php
/**
 * This file contains only the Pages class.
 */

namespace Xtools;

use Xtools\Project;
use Xtools\User;
use DateTime;

/**
 * A Pages provides statistics about the pages created by a given User.
 */
class Pages extends Model
{
    const RESULTS_LIMIT_SINGLE_NAMESPACE = 1000;
    const RESULTS_LIMIT_ALL_NAMESPACES = 100;

    /** @var Project The project. */
    protected $project;

    /** @var User The user. */
    protected $user;

    /** @var string Which namespace we are querying for. */
    protected $namespace;

    /** @var string One of 'noredirects', 'onlyredirects' or blank for both. */
    protected $redirects;

    /** @var int Pagination offset. */
    protected $offset;

    /** @var array The list of pages including various statistics. */
    protected $pages;

    /** @var array Number of redirects/pages that were created/deleted, broken down by namespace. */
    protected $countsByNamespace;

    /** @var bool Whether or not the Project supports page assessments. */
    protected $hasPageAssessments;

    /**
     * Pages constructor.
     * @param Project    $project
     * @param User       $user
     * @param string|int $namespace Namespace ID or 'all'.
     * @param string     $redirects One of 'noredirects', 'onlyredirects' or blank for both.
     * @param int        $offset    Pagination offset.
     */
    public function __construct(
        Project $project,
        User $user,
        $namespace = 0,
        $redirects = 'noredirects',
        $offset = 0
    ) {
        $this->project = $project;
        $this->user = $user;
        $this->namespace = $namespace === 'all' ? 'all' : (string)$namespace;
        $this->redirects = $redirects;
        $this->offset = $offset;
    }

    /**
     * The project associated with this Pages instance.
     * @return Project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * The user associated with this Pages instance.
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * The namespace associated with this Pages instance.
     * @return int|string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * The redirects option associated with this Pages instance.
     * @return string
     */
    public function getRedirects()
    {
        return $this->redirects;
    }

    /**
     * The pagination offset associated with this Pages instance.
     * @return int
     */
    public function getOffset()
    {
        return $this->offset;
    }

    /**
     * Fetch and prepare the pages created by the user.
     * @codeCoverageIgnore
     */
    public function prepareData()
    {
        $this->pages = [];

        foreach ($this->getNamespaces() as $ns) {
            $data = $this->fetchPagesCreated($ns);
            $this->pages[$ns] = $this->formatPages($data)[$ns];
        }

        return $this->pages;
    }

    /**
     * The public function to get the list of all pages created by the user,
     * up to self::resultsPerPage(), across all namespaces.
     * @return array
     */
    public function getResults()
    {
        if ($this->pages === null) {
            $this->prepareData();
        }
        return $this->pages;
    }

    /**
     * Get the total number of pages the user has created.
     * @return int
     */
    public function getNumPages()
    {
        $total = 0;
        foreach ($this->getCounts() as $ns => $values) {
            $total += $values['count'];
        }
        return $total;
    }

    /**
     * Get the total number of pages we're showing data for.
     * @return int
     */
    public function getNumResults()
    {
        $total = 0;
        foreach ($this->getResults() as $ns => $pages) {
            $total += count($pages);
        }
        return $total;
    }

    /**
     * Get the total number of pages that are currently deleted.
     * @return int
     */
    public function getNumDeleted()
    {
        $total = 0;
        foreach ($this->getCounts() as $ns => $values) {
            $total += $values['deleted'];
        }
        return $total;
    }

    /**
     * Get the total number of pages that are currently redirects.
     * @return int
     */
    public function getNumRedirects()
    {
        $total = 0;
        foreach ($this->getCounts() as $ns => $values) {
            $total += $values['redirects'];
        }
        return $total;
    }

    /**
     * Get the namespaces in which this user has created pages.
     * @return string[] The IDs.
     */
    public function getNamespaces()
    {
        return array_keys($this->getCounts());
    }

    /**
     * Number of namespaces being reported.
     * @return int
     */
    public function getNumNamespaces()
    {
        return count(array_keys($this->getCounts()));
    }

    /**
     * Number of redirects/pages that were created/deleted, broken down by namespace.
     * @return array Namespace IDs as the keys, with values 'count', 'deleted' and 'redirects'.
     */
    public function getCounts()
    {
        if ($this->countsByNamespace !== null) {
            return $this->countsByNamespace;
        }

        $counts = [];

        foreach ($this->countPagesCreated() as $row) {
            $counts[$row['namespace']] = [
                'count' => (int)$row['count'],
                'deleted' => (int)$row['deleted'],
            ];

            if (!in_array($this->redirects, ['noredirects', 'onlyredirects'])) {
                $counts[$row['namespace']]['redirects'] = (int)$row['redirects'];
            }
        }

        $this->countsByNamespace = $counts;
        return $this->countsByNamespace;
    }

    /**
     * Number of results to show, depending on the namespace.
     * @return int
     */
    public function resultsPerPage()
    {
        if ($this->namespace === 'all') {
            return self::RESULTS_LIMIT_ALL_NAMESPACES;
        }
        return self::RESULTS_LIMIT_SINGLE_NAMESPACE;
    }

    /**
     * Whether or not the results include page assessments.
     * @return bool
     */
    public function hasPageAssessments()
    {
        if ($this->hasPageAssessments === null) {
            $this->hasPageAssessments = $this->project->hasPageAssessments();
        }
        return $this->hasPageAssessments;
    }

    /**
     * Run the query to get pages created by the user with options.
     * This is ran independently for each namespace if $this->namespace is 'all'.
     * @param string $namespace Namespace ID.
     * @return array
     */
    private function fetchPagesCreated($namespace)
    {
        return $this->user->getRepository()->getPagesCreated(
            $this->project,
            $this->user,
            $namespace,
            $this->redirects,
            $this->resultsPerPage(),
            $this->offset * $this->resultsPerPage()
        );
    }

    /**
     * Run the query to get the number of pages created by the user
     * with given options.
     * @return array
     */
    private function countPagesCreated()
    {
        return $this->user->getRepository()->countPagesCreated(
            $this->project,
            $this->user,
            $this->namespace,
            $this->redirects
        );
    }

    /**
     * Format the data, adding humanized timestamps, page titles, assessment badges,
     * and sorting by namespace and then timestamp.
     * @param  array $pages As returned by self::fetchPagesCreated()
     * @return array
     */
    private function formatPages($pages)
    {
        $results = [];

        foreach ($pages as $row) {
            // if (!isset($results[$row['namespace']])) {
            //     $results[$row['namespace']] = [];
            // }

            $datetime = DateTime::createFromFormat('YmdHis', $row['rev_timestamp']);
            $datetimeKey = $datetime->format('YmdHi');
            $datetimeHuman = $datetime->format('Y-m-d H:i');

            $pageData = array_merge($row, [
                'raw_time' => $row['rev_timestamp'],
                'human_time' => $datetimeHuman,
                'page_title' => str_replace('_', ' ', $row['page_title'])
            ]);

            if ($this->hasPageAssessments()) {
                $pageData['badge'] = $this->project->getAssessmentBadgeURL($pageData['pa_class']);
            }

            $results[$row['namespace']][] = $pageData;
        }

        // ksort($results);

        // foreach (array_keys($results) as $key) {
        //     krsort($results[$key]);
        // }

        return $results;
    }
}
