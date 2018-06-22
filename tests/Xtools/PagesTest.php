<?php
/**
 * This file contains only the PagesTest class.
 */

namespace Tests\Xtools;

use PHPUnit_Framework_TestCase;
use Xtools\PageAssessments;
use Xtools\Pages;
use Xtools\PagesRepository;
use Xtools\User;
use Xtools\Project;
use Xtools\ProjectRepository;

/**
 * Tests of the Pages class.
 */
class PagesTest extends PHPUnit_Framework_TestCase
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
     * Set up container, class instances and mocks.
     */
    public function setUp()
    {
        $this->project = $this->getMock(Project::class, [], ['test.wikipedia.org']);
        // $this->project = new Project('test.project.org');
        $paRepo = $this->getMock(PageAssessments::class, ['getConfig'], [$this->project]);
        $paRepo->method('getConfig')
            ->willReturn($this->getAssessmentsConfig());
        $this->project->method('getPageAssessments')
            ->willReturn($paRepo);

        $this->projectRepo = $this->getMock(ProjectRepository::class);
        $this->projectRepo->method('getMetadata')
            ->willReturn(['namespaces' => [0 => 'Main', 3 => 'User_talk']]);
        $this->project->setRepository($this->projectRepo);
        $this->user = new User('Test user');
        $this->pagesRepo = $this->getMock(PagesRepository::class);
    }

    /**
     * Test the basic getters.
     */
    public function testConstructor()
    {
        $pages = new Pages($this->project, $this->user);
        $this->assertEquals(0, $pages->getNamespace());
        $this->assertEquals($this->project, $pages->getProject());
        $this->assertEquals($this->user, $pages->getUser());
        $this->assertEquals('noredirects', $pages->getRedirects());
        $this->assertEquals(0, $pages->getOffset());
    }

    public function testResults()
    {
        $this->setPagesResults();
        $pages = new Pages($this->project, $this->user, 0, '');
        $pages->setRepository($this->pagesRepo);
        $pages->prepareData();
        $this->assertEquals(3, $pages->getNumResults());
        $this->assertEquals(1, $pages->getNumDeleted());
        $this->assertEquals(1, $pages->getNumRedirects());

        $this->assertEquals([
            0 => [
                'count' => 2,
                'redirects' => 0,
                'deleted' => 1,
            ],
            1 => [
                'count' => 1,
                'redirects' => 1,
                'deleted' => 0,
            ],
        ], $pages->getCounts());

        $results = $pages->getResults();

        $this->assertEquals([0, 1], array_keys($results));
        $this->assertEquals([
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
        ], $results[0][0]);
    }

    public function setPagesResults()
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
                    'pa_class' => 'A',
                    'pa_importance' => '',
                    'recreated' => null,
                ], [
                    'namespace' => '0',
                    'type' => 'arc',
                    'page_title' => 'My_fun_page',
                    'page_is_redirect' => '0',
                    'rev_timestamp' => '20160519000000',
                    'pa_class' => '',
                    'pa_importance' => '',
                    'recreated' => '1',
                ], [
                    'namespace' => '0',
                    'type' => 'rev',
                    'page_title' => 'Foo_bar',
                    'page_is_redirect' => '0',
                    'rev_timestamp' => '20160101000000',
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
                ], [
                    'namespace' => 1,
                    'count' => 1,
                    'deleted' => 0,
                    'redirects' => 1,
                ]
            ]);
    }

    private function getAssessmentsConfig()
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
