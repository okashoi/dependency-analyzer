<?php
declare(strict_types = 1);

namespace DependencyAnalyzer\DependencyDumper;

use PHPStan\Analyser\Scope;
use PHPStan\Broker\Broker;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ParametersAcceptorWithPhpDocs;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Reflection\Php\PhpPropertyReflection;
use PHPStan\Reflection\ReflectionWithFilename;
use PHPStan\Type\ClosureType;
use PHPStan\Type\TypeWithClassName;

class NodeDependencyResolver
{
    /**
     * @var Broker
     */
    protected $broker;

    public function __construct(Broker $broker)
    {
        $this->broker = $broker;
    }

    /**
     * @param \PhpParser\Node $node
     * @param Scope $scope
     * @return ReflectionWithFilename[]
     * @throws \PHPStan\Reflection\MissingMethodFromReflectionException
     * @throws \PHPStan\Reflection\MissingPropertyFromReflectionException
     */
    public function resolveDependencies(\PhpParser\Node $node, Scope $scope): array
    {
        $dependenciesReflections = [];

        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            if ($node->extends !== null) {
                $this->addClassToDependencies($node->extends->toString(), $dependenciesReflections);
            }
            foreach ($node->implements as $className) {
                $this->addClassToDependencies($className->toString(), $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\Interface_) {
            if ($node->extends !== null) {
                foreach ($node->extends as $className) {
                    $this->addClassToDependencies($className->toString(), $dependenciesReflections);
                }
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\ClassMethod) {
            if (!$scope->isInClass()) {
                throw new \PHPStan\ShouldNotHappenException();
            }
            $nativeMethod = $scope->getClassReflection()->getNativeMethod($node->name->name);
            if ($nativeMethod instanceof PhpMethodReflection) {
                /** @var \PHPStan\Reflection\ParametersAcceptorWithPhpDocs $parametersAcceptor */
                $parametersAcceptor = ParametersAcceptorSelector::selectSingle($nativeMethod->getVariants());

                $this->extractFromParametersAcceptor($parametersAcceptor, $dependenciesReflections);
            }
//        } elseif ($node instanceof Function_) {
//            $functionName = $node->name->name;
//            if (isset($node->namespacedName)) {
//                $functionName = (string) $node->namespacedName;
//            }
//            $functionNameName = new Name($functionName);
//            if ($this->broker->hasCustomFunction($functionNameName, null)) {
//                $functionReflection = $this->broker->getCustomFunction($functionNameName, null);
//
//                /** @var \PHPStan\Reflection\ParametersAcceptorWithPhpDocs $parametersAcceptor */
//                $parametersAcceptor = ParametersAcceptorSelector::selectSingle($functionReflection->getVariants());
//                $this->extractFromParametersAcceptor($parametersAcceptor, $dependenciesReflections);
//            }
        } elseif ($node instanceof \PhpParser\Node\Expr\Closure) {
            /** @var ClosureType $closureType */
            $closureType = $scope->getType($node);
            foreach ($closureType->getParameters() as $parameter) {
                $referencedClasses = $parameter->getType()->getReferencedClasses();
                foreach ($referencedClasses as $referencedClass) {
                    $this->addClassToDependencies($referencedClass, $dependenciesReflections);
                }
            }

            $returnTypeReferencedClasses = $closureType->getReturnType()->getReferencedClasses();
            foreach ($returnTypeReferencedClasses as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Expr\FuncCall) {
            $functionName = $node->name;
            if ($functionName instanceof \PhpParser\Node\Name) {
                try {
                    $dependenciesReflections[] = $this->getFunctionReflection($functionName, $scope);
                } catch (\PHPStan\Broker\FunctionNotFoundException $e) {
                    // pass
                }
            } else {
                $variants = $scope->getType($functionName)->getCallableParametersAcceptors($scope);
                foreach ($variants as $variant) {
                    $referencedClasses = $variant->getReturnType()->getReferencedClasses();
                    foreach ($referencedClasses as $referencedClass) {
                        $this->addClassToDependencies($referencedClass, $dependenciesReflections);
                    }
                }
            }

            $returnType = $scope->getType($node);
            foreach ($returnType->getReferencedClasses() as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Expr\MethodCall || $node instanceof \PhpParser\Node\Expr\PropertyFetch) {
            $classNames = $scope->getType($node->var)->getReferencedClasses();
            foreach ($classNames as $className) {
                $this->addClassToDependencies($className, $dependenciesReflections);
            }

            $returnType = $scope->getType($node);
            foreach ($returnType->getReferencedClasses() as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif (
            $node instanceof \PhpParser\Node\Expr\StaticCall
            || $node instanceof \PhpParser\Node\Expr\ClassConstFetch
            || $node instanceof \PhpParser\Node\Expr\StaticPropertyFetch
        ) {
            if ($node->class instanceof \PhpParser\Node\Name) {
                $this->addClassToDependencies($scope->resolveName($node->class), $dependenciesReflections);
            } else {
                foreach ($scope->getType($node->class)->getReferencedClasses() as $referencedClass) {
                    $this->addClassToDependencies($referencedClass, $dependenciesReflections);
                }
            }

            $returnType = $scope->getType($node);
            foreach ($returnType->getReferencedClasses() as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif (
            $node instanceof \PhpParser\Node\Expr\New_
            && $node->class instanceof \PhpParser\Node\Name
        ) {
            $this->addClassToDependencies($scope->resolveName($node->class), $dependenciesReflections);
        } elseif ($node instanceof \PhpParser\Node\Stmt\TraitUse) {
            foreach ($node->traits as $traitName) {
                $this->addClassToDependencies($traitName->toString(), $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Expr\Instanceof_) {
            if ($node->class instanceof \PhpParser\Node\Name) {
                $this->addClassToDependencies($scope->resolveName($node->class), $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addClassToDependencies($scope->resolveName($type), $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Expr\ArrayDimFetch && $node->dim !== null) {
            $varType = $scope->getType($node->var);
            $dimType = $scope->getType($node->dim);

            foreach ($varType->getOffsetValueType($dimType)->getReferencedClasses() as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Stmt\Foreach_) {
            $exprType = $scope->getType($node->expr);
            if ($node->keyVar !== null) {
                foreach ($exprType->getIterableKeyType()->getReferencedClasses() as $referencedClass) {
                    $this->addClassToDependencies($referencedClass, $dependenciesReflections);
                }
            }

            foreach ($exprType->getIterableValueType()->getReferencedClasses() as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        } elseif ($node instanceof \PhpParser\Node\Expr\Array_) {
            $arrayType = $scope->getType($node);
            if (!$arrayType->isCallable()->no()) {
                foreach ($arrayType->getCallableParametersAcceptors($scope) as $variant) {
                    $referencedClasses = $variant->getReturnType()->getReferencedClasses();
                    foreach ($referencedClasses as $referencedClass) {
                        $this->addClassToDependencies($referencedClass, $dependenciesReflections);
                    }
                }
            }
        }
        // TODO: Additional logic...
        elseif ($node instanceof \PhpParser\Node\Stmt\PropertyProperty) {
            if (!$scope->isInClass()) {
                throw new \PHPStan\ShouldNotHappenException();
            }
            $nativeProperty = $scope->getClassReflection()->getNativeProperty($node->name->name);
            if ($nativeProperty instanceof PhpPropertyReflection) {
                $type = $nativeProperty->getType();
                if ($type instanceof TypeWithClassName) {
                    $this->addClassToDependencies($type->getClassName(), $dependenciesReflections);
                }
            }
        }

        return $dependenciesReflections;
    }

    /**
     * @param string $className
     * @param ReflectionWithFilename[] $dependenciesReflections
     */
    protected function addClassToDependencies(string $className, array &$dependenciesReflections): void
    {
        try {
            $classReflection = $this->broker->getClass($className);
        } catch (\PHPStan\Broker\ClassNotFoundException $e) {
            return;
        }

        $dependenciesReflections[] = $classReflection;
    }

    protected function getFunctionReflection(\PhpParser\Node\Name $nameNode, ?Scope $scope): ReflectionWithFilename
    {
        $reflection = $this->broker->getFunction($nameNode, $scope);
        if (!$reflection instanceof ReflectionWithFilename) {
            throw new \PHPStan\Broker\FunctionNotFoundException((string) $nameNode);
        }

        return $reflection;
    }

    /**
     * @param ParametersAcceptorWithPhpDocs $parametersAcceptor
     * @param ReflectionWithFilename[] $dependenciesReflections
     */
    protected function extractFromParametersAcceptor(
        ParametersAcceptorWithPhpDocs $parametersAcceptor,
        array &$dependenciesReflections
    ): void
    {
        foreach ($parametersAcceptor->getParameters() as $parameter) {
            $referencedClasses = array_merge(
                $parameter->getNativeType()->getReferencedClasses(),
                $parameter->getPhpDocType()->getReferencedClasses()
            );

            foreach ($referencedClasses as $referencedClass) {
                $this->addClassToDependencies($referencedClass, $dependenciesReflections);
            }
        }

        $returnTypeReferencedClasses = array_merge(
            $parametersAcceptor->getNativeReturnType()->getReferencedClasses(),
            $parametersAcceptor->getPhpDocReturnType()->getReferencedClasses()
        );
        foreach ($returnTypeReferencedClasses as $referencedClass) {
            $this->addClassToDependencies($referencedClass, $dependenciesReflections);
        }
    }

}
