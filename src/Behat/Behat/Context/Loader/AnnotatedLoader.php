<?php

namespace Behat\Behat\Context\Loader;

use Behat\Behat\Context\ContextInterface,
    Behat\Behat\Definition\DefinitionDispatcher,
    Behat\Behat\Definition\DefinitionInterface,
    Behat\Behat\Definition\TransformationInterface,
    Behat\Behat\Hook\HookDispatcher,
    Behat\Behat\Hook\HookInterface,
    Behat\Behat\Annotation\AnnotationInterface;

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Annotated contexts reader.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class AnnotatedLoader implements LoaderInterface
{
    private $definitionDispatcher;
    private $hookDispatcher;
    private $annotationClasses = array(
        'given'          => 'Behat\Behat\Definition\Annotation\Given',
        'when'           => 'Behat\Behat\Definition\Annotation\When',
        'then'           => 'Behat\Behat\Definition\Annotation\Then',
        'transform'      => 'Behat\Behat\Definition\Annotation\Transformation',
        'beforesuite'    => 'Behat\Behat\Hook\Annotation\BeforeSuite',
        'aftersuite'     => 'Behat\Behat\Hook\Annotation\AfterSuite',
        'beforefeature'  => 'Behat\Behat\Hook\Annotation\BeforeFeature',
        'afterfeature'   => 'Behat\Behat\Hook\Annotation\AfterFeature',
        'beforescenario' => 'Behat\Behat\Hook\Annotation\BeforeScenario',
        'afterscenario'  => 'Behat\Behat\Hook\Annotation\AfterScenario',
        'beforestep'     => 'Behat\Behat\Hook\Annotation\BeforeStep',
        'afterstep'      => 'Behat\Behat\Hook\Annotation\AfterStep'
    );
    private $availableAnnotations;

    /**
     * Initializes context loader.
     *
     * @param DefinitionDispatcher $definitionDispatcher
     * @param HookDispatcher       $hookDispatcher
     */
    public function __construct(DefinitionDispatcher $definitionDispatcher, HookDispatcher $hookDispatcher)
    {
        $this->definitionDispatcher = $definitionDispatcher;
        $this->hookDispatcher       = $hookDispatcher;
        $this->availableAnnotations = implode("|", array_keys($this->annotationClasses));
    }

    /**
     * Checks if loader supports provided context.
     *
     * @param ContextInterface $context
     *
     * @return Boolean
     */
    public function supports(ContextInterface $context)
    {
        return true;
    }

    /**
     * Loads definitions and translations from provided context.
     *
     * @param ContextInterface $context
     */
    public function load(ContextInterface $context)
    {
        $reflection = new \ReflectionObject($context);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $methodRefl) {
            foreach ($this->readMethodAnnotations($reflection->getName(), $methodRefl) as $annotation) {
                if ($annotation instanceof DefinitionInterface) {
                    $this->definitionDispatcher->addDefinition($annotation);
                } elseif ($annotation instanceof TransformationInterface) {
                    $this->definitionDispatcher->addTransformation($annotation);
                } elseif ($annotation instanceof HookInterface) {
                    $this->hookDispatcher->addHook($annotation);
                }
            }
        }
    }

    /**
     * Reads all supported method annotations.
     *
     * @param stirng            $className
     * @param \ReflectionMethod $method
     *
     * @return array
     */
    private function readMethodAnnotations($className, \ReflectionMethod $method)
    {
        $annotations = array();

        // read parent annotations
        try {
            $prototype = $method->getPrototype();
            $annotations = array_merge($annotations, $this->readMethodAnnotations($className, $prototype));
        } catch (\ReflectionException $e) {}

        // read method annotations
        if ($docBlock = $method->getDocComment()) {
            $description = null;

            $docLines = explode("\n", $docBlock);
            $docLineCount = count($docLines);

            for ($i = 0; $i < $docLineCount; $i++) {
                $docLine = $docLines[$i];
                $docLine = preg_replace('/^\/\*\*\s*|^\s*\*\s*|\s*\*\/$|\s*$/', '', $docLine);

                $annotationStartPattern = '/^\@('.$this->availableAnnotations.')\s*(.*)$/i';
                $isAnnotationLine = preg_match($annotationStartPattern, $docLine, $matches);

                if ($isAnnotationLine) {
                    $annotationLineList = array();
                    $annotationLineList[] = $matches[2];

                    $annotationName = strtolower($matches[1]);

                    //  Find any additional lines that might contribute to this annotation
                    while ($i < $docLineCount - 1) {
                        $nextDocLine = $docLines[$i + 1];
                        $nextDocLine = preg_replace('/^\/\*\*|^\s*\*|\s*\*\/$|\s*$/', '', $nextDocLine);

                        $annotationContinuationPattern = "/^\\s{4,}/";

                        if (!preg_match($annotationContinuationPattern, $nextDocLine)) {
                            break;
                        }

                        $annotationLineList[] = trim($nextDocLine);
                        $i++;
                    }

                    $annotationContent = trim(implode(' ', $annotationLineList));

                    $class = $this->annotationClasses[$annotationName];
                    $callback = array($className, $method->getName());

                    if (!empty($annotationContent)) {
                        $annotation = new $class($callback, $annotationContent);
                    } else {
                        $annotation = new $class($callback);
                    }

                    if (null !== $description) {
                        $annotation->setDescription($description);
                    }

                    $annotations[] = $annotation;
                } elseif (null === $description && '' !== $docLine && false === strpos($docLine, '@')) {
                    $description = $docLine;
                }
            }
        }

        return $annotations;
    }
}
