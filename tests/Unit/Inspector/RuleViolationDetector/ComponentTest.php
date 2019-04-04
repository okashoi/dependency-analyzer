<?php
declare(strict_types=1);

namespace Tests\Unit\DependencyAnalyzer\Inspector\RuleViolationDetector;

use DependencyAnalyzer\Inspector\RuleViolationDetector\Component;
use DependencyAnalyzer\Matcher\ClassNameMatcher;
use Tests\TestCase;

class ComponentTest extends TestCase
{
    public function testGetName()
    {
        $pattern = $this->createMock(ClassNameMatcher::class);
        $component = new Component('componentName', $pattern);

        $this->assertSame('componentName', $component->getName());
    }

    public function provideIsBelongedTo()
    {
        return [
            [true, true],
            [false, false]
        ];
    }

    /**
     * @param bool $return
     * @param bool $expected
     * @dataProvider provideIsBelongedTo
     */
    public function testIsBelongedTo(bool $return, bool $expected)
    {
        $className = 'className';
        $pattern = $this->createMock(ClassNameMatcher::class);
        $pattern->method('isMatch')->with($className)->willReturn($return);
        $component = new Component('componentName', $pattern);

        $this->assertSame($expected, $component->isBelongedTo($className));
    }

    public function provideVerifyDepender()
    {
        return [
            [true, false, true],
            [false, true, true],
            [false, false, false],
        ];
    }

    /**
     * @param bool $matchSameComponent
     * @param bool $matchDependerPattern
     * @param bool $expected
     * @dataProvider provideVerifyDepender
     */
    public function testVerifyDepender(bool $matchSameComponent, bool $matchDependerPattern, bool $expected)
    {
        $className = 'className';
        $componentPattern = $this->createMock(ClassNameMatcher::class);
        $componentPattern->method('isMatch')->with($className)->willReturn($matchSameComponent);
        $dependerPattern = $this->createMock(ClassNameMatcher::class);
        $dependerPattern->method('isMatch')->with($className)->willReturn($matchDependerPattern);
        $component = new Component('componentName', $componentPattern, $dependerPattern);

        $this->assertSame($expected, $component->verifyDepender($className));
    }

    public function provideVerifyDependee()
    {
        return [
            [true, false, true],
            [false, true, true],
            [false, false, false],
        ];
    }

    /**
     * @param bool $matchSameComponent
     * @param bool $matchDependeePattern
     * @param bool $expected
     * @dataProvider provideVerifyDependee
     */
    public function testVerifyDependee(bool $matchSameComponent, bool $matchDependeePattern, bool $expected)
    {
        $className = 'className';
        $componentPattern = $this->createMock(ClassNameMatcher::class);
        $componentPattern->method('isMatch')->with($className)->willReturn($matchSameComponent);
        $dependeePattern = $this->createMock(ClassNameMatcher::class);
        $dependeePattern->method('isMatch')->with($className)->willReturn($matchDependeePattern);
        $component = new Component('componentName', $componentPattern, null, $dependeePattern);

        $this->assertSame($expected, $component->verifyDependee($className));
    }
}
