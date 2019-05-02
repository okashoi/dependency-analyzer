<?php
declare(strict_types=1);

namespace DependencyAnalyzer\DependencyGraph;

use DependencyAnalyzer\DependencyGraph;
use DependencyAnalyzer\DependencyGraph\DependencyTypes\Base as DependencyType;
use DependencyAnalyzer\DependencyGraph\DependencyTypes\ConstantFetch;
use DependencyAnalyzer\DependencyGraph\DependencyTypes\MethodCall;
use DependencyAnalyzer\DependencyGraph\DependencyTypes\NewObject;
use DependencyAnalyzer\DependencyGraph\DependencyTypes\PropertyFetch;
use DependencyAnalyzer\DependencyGraph\FullyQualifiedStructuralElementName as FQSEN;
use DependencyAnalyzer\DependencyGraph\FullyQualifiedStructuralElementName\Class_;
use Fhaculty\Graph\Edge\Directed;

class DependencyArrow
{
    /**
     * @var Directed
     */
    private $edge;

    public function __construct(Directed $edge)
    {
        $this->edge = $edge;
    }

    public function getDependerClass(): Class_
    {
        return FQSEN::createClass($this->edge->getVertexStart()->getId());
    }

    public function getDependeeClass(): Class_
    {
        return FQSEN::createClass($this->edge->getVertexEnd()->getId());
    }

    public function getDependencies(): array
    {
        $ret = [];

        foreach ($this->edge->getAttribute(DependencyGraph::DEPENDENCY_TYPE_KEY) as $dependencyType) {
            /** @var DependencyType $dependencyType */
            if ($dependencyType instanceof MethodCall) {
                $calleeFQSEN = $this->getDependeeClass()->createMethodFQSEN($dependencyType->getCallee());

                $callerFQSEN = !is_null($dependencyType->getCaller()) ?
                    $this->getDependerClass()->createMethodFQSEN($dependencyType->getCaller()) :
                    $this->getDependerClass();

                $ret[] = [$callerFQSEN, $calleeFQSEN];
            } elseif ($dependencyType instanceof PropertyFetch) {
                $calleeFQSEN = $this->getDependeeClass()->createPropertyFQSEN($dependencyType->getPropertyName());

                $callerFQSEN = !is_null($dependencyType->getCaller()) ?
                    $this->getDependerClass()->createMethodFQSEN($dependencyType->getCaller()) :
                    $this->getDependerClass();

                $ret[] = [$callerFQSEN, $calleeFQSEN];
            } elseif ($dependencyType instanceof ConstantFetch) {
                $calleeFQSEN = $this->getDependeeClass()->createClassConstantFQSEN($dependencyType->getConstantName());

                $callerFQSEN = !is_null($dependencyType->getCaller()) ?
                    $this->getDependerClass()->createMethodFQSEN($dependencyType->getCaller()) :
                    $this->getDependerClass();

                $ret[] = [$callerFQSEN, $calleeFQSEN];
            } elseif ($dependencyType instanceof NewObject) {
                $callerFQSEN = !is_null($dependencyType->getCaller()) ?
                    $this->getDependerClass()->createMethodFQSEN($dependencyType->getCaller()) :
                    $this->getDependerClass();

                $ret[] = [$callerFQSEN, $this->getDependeeClass()];
            } else {
                $ret[] = [$this->getDependerClass(), $this->getDependeeClass()];
            }
        }

        return $ret;
    }
}
