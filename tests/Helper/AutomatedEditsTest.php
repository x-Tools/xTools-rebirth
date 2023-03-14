<?php

declare(strict_types = 1);

namespace App\Tests\Helper;

use App\Helper\AutomatedEditsHelper;
use App\Model\Project;
use App\Repository\ProjectRepository;
use App\Tests\TestAdapter;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;

/**
 * Tests of the AutomatedEditsHelper class.
 * @group integration
 * @covers \App\Helper\AutomatedEditsHelper
 */
class AutomatedEditsTest extends TestAdapter
{
    use ArraySubsetAsserts;

    protected AutomatedEditsHelper $aeh;
    protected Project $project;

    /**
     * Set up the AutomatedEditsHelper object for testing.
     */
    public function setUp(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $this->aeh = new AutomatedEditsHelper($container);
    }

    /**
     * Test that the merge of per-wiki config and global config works
     */
    public function testTools(): void
    {
        $this->setProject();
        $tools = $this->aeh->getTools($this->project);

        static::assertArraySubset(
            [
                'regex' => '\(\[\[WP:HG',
                'tags' => ['huggle'],
                'link' => 'w:en:Wikipedia:Huggle',
                'revert' => 'Reverted edits by.*?WP:HG',
            ],
            $tools['Huggle']
        );

        static::assertEquals(1, array_count_values(array_keys($tools))['Huggle']);
    }

    /**
     * Make sure the right tool is detected
     */
    public function testTool(): void
    {
        $this->setProject();
        static::assertArraySubset(
            [
                'name' => 'Huggle',
                'regex' => '\(\[\[WP:HG',
                'tags' => ['huggle'],
                'link' => 'w:en:Wikipedia:Huggle',
                'revert' => 'Reverted edits by.*?WP:HG',
            ],
            $this->aeh->getTool(
                'Level 2 warning re. [[Barack Obama]] ([[WP:HG|HG]]) (3.2.0)',
                $this->project
            )
        );
    }

    /**
     * Tests that given edit summary is properly asserted as a revert
     */
    public function testIsAutomated(): void
    {
        $this->setProject();
        static::assertTrue($this->aeh->isAutomated(
            'Level 2 warning re. [[Barack Obama]] ([[WP:HG|HG]]) (3.2.0)',
            $this->project
        ));
        static::assertFalse($this->aeh->isAutomated(
            'You should try [[WP:Huggle]]',
            $this->project
        ));
    }

    /**
     * Test that the revert-related tools of getTools() are properly fetched
     */
    public function testRevertTools(): void
    {
        $this->setProject();
        $tools = $this->aeh->getTools($this->project);

        static::assertArraySubset(
            ['Huggle' => [
                'regex' => '\(\[\[WP:HG',
                'tags' => ['huggle'],
                'link' => 'w:en:Wikipedia:Huggle',
                'revert' => 'Reverted edits by.*?WP:HG',
            ]],
            $tools
        );

        static::assertContains('Undo', array_keys($tools));
    }

    /**
     * Test that regex is properly concatenated when merging rules.
     */
    public function testRegexConcat(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->expects($this->once())
            ->method('getOne')
            ->willReturn([
                'url' => 'https://ar.wikipedia.org',
                'dbName' => 'arwiki',
                'lang' => 'ar',
            ]);
        $project = new Project('ar.wikipedia.org');
        $project->setRepository($projectRepo);

        static::assertArraySubset(
            ['HotCat' => [
                'regex' => 'باستخدام \[\[ويكيبيديا:المصناف الفوري|\|HotCat\]\]' .
                    '|Gadget-Hotcat(?:check)?\.js\|Script|\]\] via HotCat|\[\[WP:HC\|',
                'link' => 'ويكيبيديا:المصناف الفوري',
                'label' => 'المصناف الفوري',
            ]],
            $this->aeh->getTools($project)
        );
    }

    /**
     * Was the edit a revert, based on the edit summary?
     */
    public function testIsRevert(): void
    {
        $this->setProject();
        static::assertTrue($this->aeh->isRevert(
            'Reverted edits by Mogultalk (talk) ([[WP:HG|HG]]) (3.2.0)',
            $this->project
        ));
        static::assertFalse($this->aeh->isRevert(
            'You should have reverted this edit using [[WP:HG|Huggle]]',
            $this->project
        ));
    }

    /**
     * Set the Project. This is done here because we don't want to use
     * en.wikipedia for self::testRegexConcat().
     */
    private function setProject(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->expects($this->once())
            ->method('getOne')
            ->willReturn([
                'url' => 'https://en.wikipedia.org',
                'dbName' => 'enwiki',
                'lang' => 'en',
            ]);
        $this->project = new Project('en.wikipedia.org');
        $this->project->setRepository($projectRepo);
    }
}
