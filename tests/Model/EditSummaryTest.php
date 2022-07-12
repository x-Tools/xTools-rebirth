<?php
/**
 * This file contains only the EditSummaryTest class.
 */

declare(strict_types = 1);

namespace App\Tests\Model;

use App\Helper\I18nHelper;
use App\Model\EditSummary;
use App\Model\Project;
use App\Model\User;
use App\Tests\TestAdapter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Tests for EditSummary.
 */
class EditSummaryTest extends TestAdapter
{
    /** @var EditSummary The article info instance. */
    protected $editSummary;

    /** @var User The user instance. */
    protected $user;

    /** @var Project The project instance. */
    protected $project;

    /** @var \ReflectionClass So we can test private methods. */
    private $reflectionClass;

    /**
     * Set up shared mocks and class instances.
     */
    public function setUp(): void
    {
        $client = static::createClient();
        $this->project = new Project('TestProject');
        $this->user = new User('Test user');
        $this->editSummary = new EditSummary($this->project, $this->user, 'all', false, false, 1);

        $stack = new RequestStack();
        $session = new Session();
        $i18nHelper = new I18nHelper($client->getContainer(), $stack, $session);
        $this->editSummary->setI18nHelper($i18nHelper);

        // Don't care that private methods "shouldn't" be tested...
        // With EditSummary many are very testworthy and otherwise fragile.
        $this->reflectionClass = new \ReflectionClass($this->editSummary);
    }

    public function testHasSummary(): void
    {
        $method = $this->reflectionClass->getMethod('hasSummary');
        $method->setAccessible(true);

        static::assertFalse(
            $method->invoke($this->editSummary, ['comment' => ''])
        );
        static::assertTrue(
            $method->invoke($this->editSummary, ['comment' => 'Foo'])
        );
        static::assertFalse(
            $method->invoke($this->editSummary, ['comment' => '/* section title */  '])
        );
        static::assertTrue(
            $method->invoke($this->editSummary, ['comment' => ' /* section title */'])
        );
        static::assertTrue(
            $method->invoke($this->editSummary, ['comment' => '/* section title */ Foo'])
        );
    }

    /**
     * Test that the class properties were properly updated after processing rows.
     */
    public function testGetters(): void
    {
        $method = $this->reflectionClass->getMethod('processRow');
        $method->setAccessible(true);

        foreach ($this->getRevisions() as $revision) {
            $method->invoke($this->editSummary, $revision);
        }

        static::assertEquals(4, $this->editSummary->getTotalEdits());
        static::assertEquals(2, $this->editSummary->getTotalEditsMinor());
        static::assertEquals(2, $this->editSummary->getTotalEditsMajor());

        // In self::setUp() we set the treshold for recent edits to 1.
        static::assertEquals(1, $this->editSummary->getRecentEditsMinor());
        static::assertEquals(1, $this->editSummary->getRecentEditsMajor());

        static::assertEquals(2, $this->editSummary->getTotalSummaries());
        static::assertEquals(1, $this->editSummary->getTotalSummariesMinor());
        static::assertEquals(1, $this->editSummary->getTotalSummariesMajor());

        static::assertEquals(0, $this->editSummary->getRecentSummariesMinor());
        static::assertEquals(1, $this->editSummary->getRecentSummariesMajor());

        static::assertEquals([
            '2016-07' => [
                'total' => 2,
                'summaries' => 1,
            ],
            '2016-10' => [
                'total' => 1,
                'summaries' => 1,
            ],
            '2016-11' => [
                'total' => 1,
            ],
        ], $this->editSummary->getMonthCounts());
    }

    /**
     * Get test revisions.
     * @return string[] Rows with keys 'comment', 'rev_timestamp' and 'rev_minor_edit'.
     */
    private function getRevisions(): array
    {
        // Ordered by rev_timestamp DESC.
        return [
            [
                'comment' => '/* Section title */',
                'rev_timestamp' => '20161103010000',
                'rev_minor_edit' => '1',
            ], [
                'comment' => 'Weeee',
                'rev_timestamp' => '20161003000000',
                'rev_minor_edit' => '0',
            ], [
                'comment' => 'This is an edit summary',
                'rev_timestamp' => '20160705000000',
                'rev_minor_edit' => '1',
            ], [
                'comment' => '',
                'rev_timestamp' => '20160701101205',
                'rev_minor_edit' => '0',
            ],
        ];
    }
}
