<?php

declare(strict_types = 1);

namespace App\Tests\Model;

use App\Model\Authorship;
use App\Model\Page;
use App\Model\Project;
use App\Repository\AuthorshipRepository;
use App\Repository\PageRepository;
use App\Tests\TestAdapter;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \App\Model\Authorship
 */
class AuthorshipTest extends TestAdapter
{
    /**
     * Authorship stats from WhoColor API.
     */
    public function testAuthorship(): void
    {
        /** @var AuthorshipRepository|MockObject $authorshipRepo */
        $authorshipRepo = $this->createMock(AuthorshipRepository::class);
        $authorshipRepo->expects($this->once())
            ->method('getData')
            ->willReturn([
                'revisions' => [[
                    '123' => [
                        'time' => '2018-04-16T13:51:11Z',
                        'tokens' => [
                            [
                                'editor' => '1',
                                'str' => 'foo',
                            ], [
                                'editor' => '0|192.168.0.1',
                                'str' => 'bar',
                            ], [
                                'editor' => '0|192.168.0.1',
                                'str' => 'baz',
                            ], [
                                'editor' => '2',
                                'str' => 'foobar',
                            ],
                        ],
                    ],
                ]],
            ]);
        $authorshipRepo->expects($this->once())
            ->method('getUsernamesFromIds')
            ->willReturn([
                ['user_id' => 1, 'user_name' => 'Mick Jagger'],
                ['user_id' => 2, 'user_name' => 'Mr. Rogers'],
            ]);
        $project = new Project('test.example.org');
        $pageRepo = $this->createMock(PageRepository::class);
        $page = new Page($pageRepo, $project, 'Test page');
        $authorship = new Authorship($authorshipRepo, $page, null, 2);
        $authorship->prepareData();

        static::assertEquals(
            [
                'Mr. Rogers' => [
                    'count' => 6,
                    'percentage' => 40.0,
                ],
                '192.168.0.1' => [
                    'count' => 6,
                    'percentage' => 40.0,
                ],
            ],
            $authorship->getList()
        );

        static::assertEquals(3, $authorship->getTotalAuthors());
        static::assertEquals(15, $authorship->getTotalCount());
        static::assertEquals([
            'count' => 3,
            'percentage' => 20.0,
            'numEditors' => 1,
        ], $authorship->getOthers());
    }
}
