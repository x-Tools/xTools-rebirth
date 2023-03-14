<?php

declare(strict_types = 1);

namespace App\Repository;

use App\Helper\AutomatedEditsHelper;
use App\Model\Edit;
use App\Model\Page;
use App\Model\Project;
use GuzzleHttp\Client;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An EditRepository fetches data about a single revision.
 * @codeCoverageIgnore
 */
class EditRepository extends Repository
{
    protected AutomatedEditsHelper $autoEditsHelper;
    protected PageRepository $pageRepo;

    /**
     * @param ContainerInterface $container
     * @param CacheItemPoolInterface $cache
     * @param Client $guzzle
     * @param LoggerInterface $logger
     * @param bool $isWMF
     * @param int $queryTimeout
     * @param AutomatedEditsHelper $autoEditsHelper
     * @param PageRepository $pageRepo
     */
    public function __construct(
        ContainerInterface $container,
        CacheItemPoolInterface $cache,
        Client $guzzle,
        LoggerInterface $logger,
        bool $isWMF,
        int $queryTimeout,
        AutomatedEditsHelper $autoEditsHelper,
        PageRepository $pageRepo
    ) {
        $this->autoEditsHelper = $autoEditsHelper;
        $this->pageRepo = $pageRepo;
        parent::__construct($container, $cache, $guzzle, $logger, $isWMF, $queryTimeout);
    }

    /**
     * @return AutomatedEditsHelper
     */
    public function getAutoEditsHelper(): AutomatedEditsHelper
    {
        return $this->autoEditsHelper;
    }

    /**
     * Get an Edit instance given the revision ID. This does NOT set the associated User or Page.
     * @param UserRepository $userRepo
     * @param Project $project
     * @param int $revId
     * @param Page|null $page Provide if you already know the Page, so as to point to the same instance.
     *   This should already have the PageRepository set.
     * @return Edit|null Null if not found.
     */
    public function getEditFromRevIdForPage(
        UserRepository $userRepo,
        Project $project,
        int $revId,
        ?Page $page = null
    ): ?Edit {
        $revisionTable = $project->getTableName('revision', '');
        $commentTable = $project->getTableName('comment', 'revision');
        $actorTable = $project->getTableName('actor', 'revision');
        $pageSelect = '';
        $pageJoin = '';

        if (null === $page) {
            $pageTable = $project->getTableName('page');
            $pageSelect = "page_title,";
            $pageJoin = "JOIN $pageTable ON revs.rev_page = page_id";
        }

        $sql = "SELECT $pageSelect
                    revs.rev_id AS id,
                    actor_name AS username,
                    revs.rev_timestamp AS timestamp,
                    revs.rev_minor_edit AS minor,
                    revs.rev_len AS length,
                    (CAST(revs.rev_len AS SIGNED) - IFNULL(parentrevs.rev_len, 0)) AS length_change,
                    comment_text AS comment
                FROM $revisionTable AS revs
                $pageJoin
                JOIN $actorTable ON actor_id = rev_actor
                LEFT JOIN $revisionTable AS parentrevs ON (revs.rev_parent_id = parentrevs.rev_id)
                LEFT OUTER JOIN $commentTable ON (revs.rev_comment_id = comment_id)
                WHERE revs.rev_id = :revId";

        $result = $this->executeProjectsQuery($project, $sql, ['revId' => $revId])
            ->fetchAssociative();

        if (!$result) {
            return null;
        }

        // Create the Page instance.
        if (null === $page) {
            $page = new Page($this->pageRepo, $project, $result['page_title']);
        }

        return new Edit($this, $userRepo, $page, $result);
    }

    /**
     * Use the Compare API to get HTML for the diff.
     * @param Edit $edit
     * @return string|null Raw HTML, must be wrapped in a <table> tag. Null if no comparison found.
     */
    public function getDiffHtml(Edit $edit): ?string
    {
        $params = [
            'action' => 'compare',
            'fromrev' => $edit->getId(),
            'torelative' => 'prev',
        ];

        $res = $this->executeApiRequest($edit->getProject(), $params);
        return $res['compare']['*'] ?? null;
    }
}
