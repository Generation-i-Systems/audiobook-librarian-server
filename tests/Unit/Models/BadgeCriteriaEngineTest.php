<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Badge;
use PHPUnit\Framework\TestCase;

class BadgeCriteriaEngineTest extends TestCase
{
    protected function makeBadge(array $criteria): Badge
    {
        return new Badge(['criteria' => $criteria]);
    }

    public function testLegacyFlatMapAllMustMatch(): void
    {
        $badge = $this->makeBadge([
            'books_completed' => 10,
            'current_streak' => 5,
        ]);

        $this->assertTrue($badge->evaluateCriteria(['books_completed' => 10, 'current_streak' => 5]));
        $this->assertFalse($badge->evaluateCriteria(['books_completed' => 9, 'current_streak' => 5]));
    }

    public function testLegacyRangeFormatStillWorks(): void
    {
        $badge = $this->makeBadge([
            'listening_streak' => ['min' => 5, 'max' => 30],
        ]);

        $this->assertTrue($badge->evaluateCriteria(['listening_streak' => 10]));
        $this->assertFalse($badge->evaluateCriteria(['listening_streak' => 3]));
        $this->assertFalse($badge->evaluateCriteria(['listening_streak' => 31]));
    }

    public function testVersion2AndGroupRequiresAllConditions(): void
    {
        $badge = $this->makeBadge([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [
                ['stat' => 'books_completed', 'operator' => '>=', 'value' => 10],
                ['stat' => 'current_streak', 'operator' => '>=', 'value' => 7],
            ],
        ]);

        $this->assertTrue($badge->evaluateCriteria(['books_completed' => 10, 'current_streak' => 7]));
        $this->assertFalse($badge->evaluateCriteria(['books_completed' => 10, 'current_streak' => 6]));
    }

    public function testVersion2OrGroupRequiresAnyCondition(): void
    {
        $badge = $this->makeBadge([
            'version' => 2,
            'logic' => 'OR',
            'conditions' => [
                ['stat' => 'books_completed', 'operator' => '>=', 'value' => 100],
                ['stat' => 'current_streak', 'operator' => '>=', 'value' => 7],
            ],
        ]);

        $this->assertTrue($badge->evaluateCriteria(['books_completed' => 0, 'current_streak' => 7]));
        $this->assertFalse($badge->evaluateCriteria(['books_completed' => 0, 'current_streak' => 6]));
    }

    public function testVersion2NestedGroups(): void
    {
        $badge = $this->makeBadge([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [
                ['stat' => 'books_completed', 'operator' => '>=', 'value' => 5],
                [
                    'logic' => 'OR',
                    'conditions' => [
                        ['stat' => 'current_streak', 'operator' => '>=', 'value' => 30],
                        ['stat' => 'listening_streak', 'operator' => 'between', 'value' => [5, 10]],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($badge->evaluateCriteria(['books_completed' => 5, 'current_streak' => 0, 'listening_streak' => 7]));
        $this->assertFalse($badge->evaluateCriteria(['books_completed' => 5, 'current_streak' => 0, 'listening_streak' => 11]));
        $this->assertFalse($badge->evaluateCriteria(['books_completed' => 4, 'current_streak' => 30, 'listening_streak' => 7]));
    }

    public function testVersion2SupportsAllOperators(): void
    {
        $cases = [
            ['>=', 5, 5, true],
            ['>=', 5, 4, false],
            ['<=', 5, 5, true],
            ['<=', 5, 6, false],
            ['>', 5, 6, true],
            ['>', 5, 5, false],
            ['<', 5, 4, true],
            ['<', 5, 5, false],
            ['==', 5, 5, true],
            ['==', 5, 4, false],
        ];

        foreach ($cases as [$operator, $target, $actual, $expected]) {
            $badge = $this->makeBadge([
                'version' => 2,
                'logic' => 'AND',
                'conditions' => [['stat' => 'stat', 'operator' => $operator, 'value' => $target]],
            ]);

            $this->assertSame(
                $expected,
                $badge->evaluateCriteria(['stat' => $actual]),
                "operator {$operator} with target {$target} and value {$actual}"
            );
        }
    }

    public function testVersion2BetweenOperator(): void
    {
        $badge = $this->makeBadge([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [['stat' => 'stat', 'operator' => 'between', 'value' => [5, 10]]],
        ]);

        $this->assertTrue($badge->evaluateCriteria(['stat' => 5]));
        $this->assertTrue($badge->evaluateCriteria(['stat' => 10]));
        $this->assertFalse($badge->evaluateCriteria(['stat' => 4]));
        $this->assertFalse($badge->evaluateCriteria(['stat' => 11]));
    }

    public function testGetProgressPercentageForVersion2Group(): void
    {
        $badge = $this->makeBadge([
            'version' => 2,
            'logic' => 'AND',
            'conditions' => [
                ['stat' => 'books_completed', 'operator' => '>=', 'value' => 10],
                ['stat' => 'current_streak', 'operator' => '>=', 'value' => 10],
            ],
        ]);

        $this->assertSame(50, $badge->getProgressPercentage(['books_completed' => 10, 'current_streak' => 0]));
        $this->assertSame(100, $badge->getProgressPercentage(['books_completed' => 10, 'current_streak' => 10]));
    }

    public function testEmptyVersion2GroupIsAlwaysSatisfied(): void
    {
        $badge = $this->makeBadge(['version' => 2, 'logic' => 'AND', 'conditions' => []]);

        $this->assertTrue($badge->evaluateCriteria([]));
        $this->assertSame(100, $badge->getProgressPercentage([]));
    }
}
