<?php
/**
 * Go! AOP framework
 *
 * @copyright Copyright 2012, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\Instrument\Transformer;

use Go\Aop\Features;
use Go\Core\AspectKernel;
use Go\Instrument\PathResolver;
use Go\Instrument\ClassLoading\CachePathResolver;

/**
 * Transformer that injects source filter for "require" and "include" operations
 */
class FilterInjectorTransformer implements SourceTransformer
{

    /**
     * Php filter definition
     */
    const PHP_FILTER_READ = 'php://filter/read=';

    /**
     * Name of the filter to inject
     *
     * @var string
     */
    protected static $filterName;

    /**
     * Kernel options
     *
     * @var array
     */
    protected static $options = array();

    /**
     * @var AspectKernel|null
     */
    protected static $kernel;

    /**
     * @var CachePathResolver|null
     */
    protected static $cachePathResolver;

    /**
     * Class constructor
     *
     * @param AspectKernel $kernel Kernel to take configuration from
     * @param string $filterName Name of the filter to inject
     */
    public function __construct(AspectKernel $kernel, $filterName)
    {
        self::configure($kernel, $filterName);
    }

    /**
     * Static configurator for filter
     *
     * @param AspectKernel $kernel Kernel to use for configuration
     * @param string $filterName Name of the filter to inject
     *
     * @throws \RuntimeException if filter was configured early
     */
    protected static function configure(AspectKernel $kernel, $filterName)
    {
        if (self::$kernel) {
            throw new \RuntimeException("Filter injector can be configured only once.");
        }
        self::$kernel            = $kernel;
        self::$options           = $kernel->getOptions();
        self::$filterName        = $filterName;
        self::$cachePathResolver = $kernel->getContainer()->get('aspect.cache.path.resolver');
    }

    /**
     * Replace source path with correct one
     *
     * This operation can check for cache, can rewrite paths, add additional filters and much more
     *
     * @param string $originalResource Initial resource to include
     * @param string $originalDir Path to the directory from where include was called for resolving relative resources
     *
     * @return string Transformed path to the resource
     */
    public static function rewrite($originalResource, $originalDir = '')
    {
        static $appDir, $cacheDir, $debug, $usePrebuiltCache;
        if (!$appDir) {
            extract(self::$options, EXTR_IF_EXISTS);
            $usePrebuiltCache = self::$options['features'] & Features::PREBUILT_CACHE;
        }

        $resource = (string) $originalResource;
        if ($resource['0'] !== '/') {
            $shouldCheckExistence = true;
            $resource
                =  PathResolver::realpath($resource, $shouldCheckExistence)
                ?: PathResolver::realpath("{$originalDir}/{$resource}", $shouldCheckExistence)
                ?: $originalResource;
        }
        // If the cache is disabled, then use on-fly method
        if (!$cacheDir || $debug) {
            return self::PHP_FILTER_READ . self::$filterName . "/resource=" . $resource;
        }

        $newResource = self::$cachePathResolver->getCachePathForResource($resource);

        // Trigger creation of cache, this will create a cache file with $newResource name
        if (!$usePrebuiltCache && !file_exists($newResource)) {
            // Workaround for https://github.com/facebook/hhvm/issues/2485
            $file = fopen($resource, 'r');
            stream_filter_append($file, self::$filterName);
            stream_get_contents($file);
            fclose($file);
        }

        return $newResource;
    }

    /**
     * Wrap all includes into rewrite filter
     *
     * @param StreamMetaData $metadata Metadata for source
     * @return void
     */
    public function transform(StreamMetaData $metadata)
    {
        if ((strpos($metadata->source, 'include')===false) && (strpos($metadata->source, 'require')===false)) {
            return;
        }
        static $lookFor = array(
            T_INCLUDE      => true,
            T_INCLUDE_ONCE => true,
            T_REQUIRE      => true,
            T_REQUIRE_ONCE => true
        );
        $tokenStream       = token_get_all($metadata->source);

        $transformedSource = '';
        $isWaitingEnd      = false;

        $insideBracesCount = 0;
        $isBracesFinished  = false;
        $isTernaryOperator = false;
        foreach ($tokenStream as $token) {
            if ($isWaitingEnd && $token === '(') {
                if ($isWaitingEnd) {
                    $insideBracesCount++;
                }
            } elseif ($isWaitingEnd && $token === ')') {
                if ($insideBracesCount > 0) {
                    $insideBracesCount--;
                } else {
                    $isBracesFinished = true;
                }
            }

            $lastBrace = ($isBracesFinished && $token === ')');

            if ($isWaitingEnd && $token === '?') {
                $isTernaryOperator = true;
            }

            if ($isTernaryOperator && ($token === ';' || $lastBrace)) {
                $isTernaryOperator = false;
            }

            if ($isWaitingEnd && !$isTernaryOperator && $insideBracesCount == 0
                && ($token === ';' || $token === ',' || $token === ':' || $lastBrace)
            ) {
                $isWaitingEnd = false;
                $transformedSource .= ', __DIR__)';
            }
            list ($token, $value) = (array) $token + array(1 => $token);
            $transformedSource .= $value;
            if (!$isWaitingEnd && isset($lookFor[$token])) {
                $isWaitingEnd = true;
                $isBracesFinished = $isTernaryOperator = false;
                $transformedSource  .= ' \\' . __CLASS__ . '::rewrite(';
            }
        }
        $metadata->source = $transformedSource;
    }
}
