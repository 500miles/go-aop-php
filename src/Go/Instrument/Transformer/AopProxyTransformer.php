<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2011, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Go\Instrument\Transformer;

use TokenReflection\Broker;
use TokenReflection\ReflectionClass;
use TokenReflection\ReflectionMethod;
use TokenReflection\ReflectionFileNamespace;

/**
 * @package go
 */
class AopProxyTransformer implements SourceTransformer
{

    /**
     * Suffix, that will be added to all proxied class names
     */
    const AOP_PROXIED_SUFFIX = '__AopProxied';

    /**
     * Reflection broker instance
     *
     * @var Broker
     */
    protected $broker;

    public function __construct(Broker $broker, \Go\Aop\PointcutAdvisor $advisor)
    {
        $this->broker = $broker;
        $this->advisor = $advisor;
    }

    /**
     * This method may transform the supplied source and return a new replacement for it
     *
     * @param string $source Source for class
     * @param StreamMetaData $metadata Metadata for source
     *
     * @return string Transformed source
     */
    public function transform($source, StreamMetaData $metadata = null)
    {
        $parsedSource = $this->broker->processString($source, $metadata->getResourceUri(), true);

        // TODO: this code only for debug, will be refactored
        $classFilter = $this->advisor->getPointcut()->getClassFilter();

        /** @var $namespaces ReflectionFileNamespace[] */
        $namespaces = $parsedSource->getNamespaces();
        foreach ($namespaces as $namespace) {

            /** @var $classes ReflectionClass[] */
            $classes = $namespace->getClasses();
            foreach ($classes as $class) {
                if ($classFilter->matches($class)) {
                    echo "Matching class ", $class->getName(), "<br>\n";
                    $methodMatcher = $this->advisor->getPointcut()->getPointFilter();

                    /** @var $methods ReflectionMethod[] */
                    $methods = $class->getMethods();
                    foreach ($methods as $method) {
                        if ($methodMatcher->matches($method)) {
                            echo "Matching method ", $method->getName(), "<br>\n";
                        }
                    }
                }
            }
        }
        return $source;
    }

}