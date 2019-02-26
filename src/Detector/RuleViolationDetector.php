<?php
namespace DependencyAnalyzer\Detector;

use DependencyAnalyzer\Detector\RuleViolationDetector\DependencyRule;
use DependencyAnalyzer\DirectedGraph;

class RuleViolationDetector
{
    /**
     * @var DependencyRule[]
     */
    protected $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function inspect(DirectedGraph $graph)
    {
        $ruleViolations = [];
        foreach ($this->rules as $rule) {
            $ruleViolations = array_merge($ruleViolations, $rule->isSatisfyBy($graph));
        }

        return $ruleViolations;
    }
}
