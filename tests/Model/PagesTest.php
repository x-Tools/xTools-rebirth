<?php
/**
 * This file contains only the PagesTest class.
 */

declare(strict_types = 1);

namespace App\Tests\Model;

use App\Model\PageAssessments;
use App\Model\Pages;
use App\Model\Project;
use App\Model\User;
use App\Repository\PagesRepository;
use App\Repository\ProjectRepository;
use App\Tests\TestAdapter;

/**
 * Tests of the Pages class.
 */
class PagesTest extends TestAdapter
{
    /** @var Project The project instance. */
    protected $project;

    /** @var ProjectRepository The project repo instance. */
    protected $projectRepo;

    /** @var User The user instance. */
    protected $user;

    /** @var PagesRepository The user repo instance. */
    protected $pagesRepo;

    /**
     * Set up class instances and mocks.
     */
    public function setUp(): void
    {
        $this->project = $this->createMock(Project::class);
        $paRepo = $this->createMock(PageAssessments::class);
        $paRepo->method('getConfig')
            ->willReturn($this->getAssessmentsConfig());
        $this->project->method('getPageAssessments')
            ->willReturn($paRepo);

        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->projectRepo->method('getMetadata')
            ->willReturn(['namespaces' => [0 => 'Main', 3 => 'User_talk']]);
        $this->project->setRepository($this->projectRepo);
        $this->user = new User('Test user');
        $this->pagesRepo = $this->createMock(PagesRepository::class);
    }

    /**
     * Test the basic getters.
     */
    public function testConstructor(): void
    {
        $pages = new Pages($this->project, $this->user);
        static::assertEquals(0, $pages->getNamespace());
        static::assertEquals($this->project, $pages->getProject());
        static::assertEquals($this->user, $pages->getUser());
        static::assertEquals('noredirects', $pages->getRedirects());
        static::assertEquals(0, $pages->getOffset());
    }

    public function testResults(): void
    {
        $this->setPagesResults();
        $pages = new Pages($this->project, $this->user, 0, 'both');
        $pages->setRepository($this->pagesRepo);
        $pages->prepareData();
        static::assertEquals(3, $pages->getNumResults());
        static::assertEquals(1, $pages->getNumDeleted());
        static::assertEquals(1, $pages->getNumRedirects());

        static::assertEquals([
            0 => [
                'count' => 2,
                'redirects' => 0,
                'deleted' => 1,
                'total_length' => 17,
                'avg_length' => 8.5,
            ],
            1 => [
                'count' => 1,
                'redirects' => 1,
                'deleted' => 0,
                'total_length' => 10,
                'avg_length' => 10,
            ],
        ], $pages->getCounts());

        $results = $pages->getResults();

        static::assertEquals([0, 1], array_keys($results));
        static::assertEquals([
            'namespace' => '0',
            'type' => 'arc',
            'page_title' => 'My fun page',
            'page_is_redirect' => '0',
            'rev_timestamp' => '20160519000000',
            'pa_class' => '',
            'pa_importance' => '',
            'raw_time' => '20160519000000',
            'human_time' => '2016-05-19 00:00',
            'recreated' => '1',
            'rev_len' => '5',
        ], $results[0][0]);
        static::assertTrue($pages->isMultiNamespace());
    }

    public function setPagesResults(): void
    {
        $this->pagesRepo->expects($this->exactly(2))
            ->method('getPagesCreated')
            ->willReturn([
                [
                    'namespace' => '1',
                    'type' => 'rev',
                    'page_title' => 'Gooogle',
                    'page_is_redirect' => '1',
                    'rev_timestamp' => '20160719000000',
                    'rev_len' => 10,
                    'pa_class' => 'A',
                    'pa_importance' => '',
                    'recreated' => null,
                ], [
                    'namespace' => '0',
                    'type' => 'arc',
                    'page_title' => 'My_fun_page',
                    'page_is_redirect' => '0',
                    'rev_timestamp' => '20160519000000',
                    'rev_len' => 5,
                    'pa_class' => '',
                    'pa_importance' => '',
                    'recreated' => '1',
                ], [
                    'namespace' => '0',
                    'type' => 'rev',
                    'page_title' => 'Foo_bar',
                    'page_is_redirect' => '0',
                    'rev_timestamp' => '20160101000000',
                    'rev_len' => 12,
                    'pa_class' => 'FA',
                    'pa_importance' => '',
                    'recreated' => null,
                ],
            ]);
        $this->pagesRepo->expects($this->once())
            ->method('countPagesCreated')
            ->willReturn([
                [
                    'namespace' => 0,
                    'count' => 2,
                    'deleted' => 1,
                    'redirects' => 0,
                    'total_length' => 17,
                ], [
                    'namespace' => 1,
                    'count' => 1,
                    'deleted' => 0,
                    'redirects' => 1,
                    'total_length' => 10,
                ],
            ]);
    }

    /**
     * Mock assessments configuration.
     * @return array
     */
    private function getAssessmentsConfig(): array
    {
        return [
            'test.project.org' => [
                'class' => [
                    'FA' =>  [
                        'badge' => 'b/bc/Featured_article_star.svg',
                    ],
                    'A' => [
                        'badge' => '2/25/Symbol_a_class.svg',
                    ],
                ],
            ],
        ];
    }
}
