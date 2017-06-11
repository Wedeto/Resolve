<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/
namespace Wedeto\Resolve;

use ReflectionClass;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Wedeto\Util\Cache;
use Wedeto\Util\DefVal;

/**
 * Autoloader that implements PSR-0 and PSR-4 standards. By default it will use
 * PSR-4, but registerNS can be instructed to treat a namespace as PSR-0
 * compatible. Alternatively, you can even provide a custom function to use as
 * a loader for a specific namespace.
 *
 * The class loader supports authorative mode - in this case it will trust the cache.
 * A cache instance is required for this mode. Each resolved class will be 
 */
final class Autoloader
{
    const PSR0 = "PSR-0";
    const PSR4 = "PSR-4";

    protected static $logger = null;
    private $cache = null;
    private $authorative = false;
    private $registered_namespaces = [];
    private $root_namespace = ['sub_ns' => [], 'loaders' => []];

    public static function setLogger(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }

    /**
     * @codeCoverageIgnore The NullLogger can only be set once, automatically. Unpredictable in testing
     */
    public static function getLogger()
    {
        if (self::$logger === null)
            self::$logger = new NullLogger;
        return self::$logger;
    }
    
    /**
     * Find all .php source files in the specified path or any subdirectory,
     * and return them in an array.
     *
     * @return array The list of .php files.
     */
    public static function findSourceFiles(string $path)
    {
        $result = array();
        $reader = dir($path);

        while ($file = $reader->read())
        {
            if ($file === '.' || $file === '..')
                continue;

            $file = $path . '/' . $file;
            if (substr($file, -4) === ".php")
            {
                $result[] = $file;
            }
            elseif (is_dir($file))
            {
                $sub = self::findSourceFiles($file);
                foreach ($sub as $f)
                    $result[] = $f;
            }
        }

        return $result;
    }
    
    /**
     * Look up file according to PSR0 standard:
     * http://www.php-fig.org/psr/psr-0/
     *
     * @param $ns string The namespace of the class
     * @param $class_name string The class to locate
     * @param $path string The path where the namespace classes are located
     * @return string The path to the class file. Null when not found
     */
    public static function findPSR0($ns, $class_name, $path)
    {
        $class = str_replace($ns, "", $class_name);
        $last_ns = strrpos($class, '\\');
        if ($last_ns !== false)
        {
            $prefix = substr($class, 0, $last_ns + 1);
            $class = substr($class, $last_ns + 1);
        }
        else
        {
            $prefix = "";
        }

        $prefix = str_replace('\\', DIRECTORY_SEPARATOR, $prefix);
        $class_file = str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
        $class_path = $path . DIRECTORY_SEPARATOR . $prefix . $class_file;

        if (file_exists($class_path))
            return $class_path;

        return null;
    }

    /**
     * Look up file according to PSR4 standard:
     * http://www.php-fig.org/psr/psr-4/
     *
     * @param $ns string The namespace of the class
     * @param $class_name string The class to locatae
     * @param $path string The path where the namespace classes are located
     * @return string The path to the class file. Null when not found
     */
    public static function findPSR4($ns, $class_name, $path)
    {
        $class = str_replace($ns, "", $class_name);
        $class_file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';

        $class_path = $path . DIRECTORY_SEPARATOR . $class_file;
        if (file_exists($class_path))
            return $class_path;

        return null;
    }

    /**
     * Set the cache object caching resolved classes
     * @param Cache $cache The cache to use
     * @return Autoloader Provides fluent interface
     */
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
        return $this;
    }

    /** 
     * @return Cache the cache object in use
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Set the authorative state
     * @param bool $authorative When true, classes without a cache entry are considered
     *                          non-existent. On the first call to autoload, the cache
     *                          is built if this wasn't done before. Without a cache,
     *                          this flag is useless and ignored.
     * @return Autoloader Provides fluent interface
     */
    public function setAuthorative(bool $authorative = true)
    {
        $this->authorative = $authorative;
        return $this;
    }

    /**
     * @return bool The authorative state. When true, the autoloader will not attempt to
     *              load classes where no cache entry exists for.
     */
    public function getAuthorative()
    {
        return $this->authorative;
    }

    /**
     * Register a namespace with the autoloader.
     * 
     * @param $ns string The namespace to register
     * @param $path string The path where to find classes in this namespace
     * @param $standard mixed Autoloader::PSR4 or Autoloader::PSR0. PSR4 is
     *                        used by default. You may also provide a callback
     *                        function here that will be used as the loader for
     *                        this namespace, rather than the provided PSR-0
     *                        and PSR-4 loaders.
     */
    public function registerNS(string $ns, string $path, $standard = Autoloader::PSR4)
    {
        if (!file_exists($path) || !is_dir($path) || !is_readable($path))
            throw new \InvalidArgumentException("Path $path is not readable");

        if ($standard !== Autoloader::PSR0 && $standard !== Autoloader::PSR4 && !is_callable($standard))
            throw new \InvalidArgumentException("Invalid standard: $standard");

        // Strip a leading namespace separator
        $ns = trim($ns, '\\');

        // Get the namespace parts
        $parts = explode('\\', $ns);

        $this->registered_namespaces[$ns] = true;

        // Make sure there is a trailing namespace separator
        if ($ns !== "" && substr($ns, -1, 1) !== '\\')
            $ns .= '\\';

        $ref = &$this->root_namespace;
        foreach ($parts as $part)
        {
            // Registering empty namespace
            if ($part === "")
                break;

            if (!isset($ref['sub_ns'][$part]))
                $ref['sub_ns'][$part] = ['loaders' => [], 'sub_ns' => []];

            $ref = &$ref['sub_ns'][$part];
        }

        $ref['loaders'][] = [
            'ns' => $ns,
            'path' => $path,
            'std' => $standard
        ];
    }
    

    /**
     * Build or rebuild the class cache. This will scan all defined PSR4 paths for .php files, 
     * deduce the resulting classname from the path and add the path to the cache.
     *
     * This works only when solely PSR4 namespaces are defined. There is
     * ambiguity in the file path -> class name for PSR0-based namespaces as
     * directory separators can be converted to either namespace separators or
     * underscores. To avoid this ambiguity, a exception is thrown when PSR0 namespaces
     * have been registered.
     *
     * If any of the paths contain files that are not PSR4 compatible, errors may occur,
     * because the files are not actually loaded to check if they contain the correct class.
     * When two or more files map to the same class name, a LogicException is thrown.
     *
     * When authorative mode is enabled, after running buildCache the class loader will only
     * load classes that were found by buildCache.
     */
    public function buildCache()
    {
        if ($this->cache === null)
            throw new \LogicException("Cannot build cache without a cache instance");
        
        $this->cache->set('cache_built', false);
        $this->cache->set('classpaths', array());
        
        $stack = array($this->root_namespace);
        
        while (!empty($stack))
        {
            $ref = array_pop($stack);
            foreach ($ref['loaders'] as $loader)
            {
                if ($loader['std'] !== Autoloader::PSR4)
                    throw new \LogicException("Cache builder only works with pure PSR4-based namespaces");

                $class_count = 0;
                $root = $loader['path'] . DIRECTORY_SEPARATOR;
                $ns = $loader['ns'];
                $php_files = self::findSourceFiles($loader['path']);
                foreach ($php_files as $file)
                {
                    $rel_file = substr($file, strlen($root));

                    // Store absolute resolved paths when not using stream wrappers
                    if (strpos($file, '://') === false) $file = realpath($file);
                    $parts = explode(DIRECTORY_SEPARATOR, $rel_file);
                    $class_name = substr($ns . implode('\\', $parts), 0, -4);
                    if ($this->cache->has('classpaths', $class_name))
                    {
                        $conflict = $this->cache->get('classpaths', $class_name);
                        throw new \LogicException(sprintf(
                            "Duplicate class definition in file %s and %s (conflicting namespace: %s)", 
                            $file,
                            $conflict,
                            $ns
                        ));
                    }

                    $this->cache->set('classpaths', $class_name, $file);
                    ++$class_count;
                }
                self::getLogger()->info('Found {0} classes in namespace {1}', [$class_count, $ns]);
            }

            foreach ($ref['sub_ns'] as $sub_ns)
                array_push($stack, $sub_ns);
        }

        $this->cache->set('cache_built', true);
        self::getLogger()->info('Class cache built');
    }


    /**
     * The spl_autoloader that loads classes registered namespaces
     * @param string $class_name The class to load
     */
    public function autoload($class_name)
    {
        // Basic check
        if (class_exists($class_name, false))
            return;

        // Use cache when available
        if ($this->cache !== null)
        {
            $cache_built = $this->cache->dget('cache_built', false);

            $path = $this->cache->get('classpaths', $class_name);
            if ($this->authorative && ($path === false || ($cache_built && $path === null)))
                return; // Authorative, no match found, so no loading

            if (!empty($path))
            {
                require_once $path;
                return;
            }

            // No match found, not authorative. Keep looking
        }

        $loaders = $this->getClassLoaders($class_name);
        foreach ($loaders as $loader)
        {
            $path = null;
            if ($loader['std'] === Autoloader::PSR0)
            {
                $path = self::findPSR0($loader['ns'], $class_name, $loader['path']);
            }
            elseif ($loader['std'] === Autoloader::PSR4)
            {
                $path = self::findPSR4($loader['ns'], $class_name, $loader['path']);
            }
            elseif (is_callable($loader['std']))
            {
                try
                {
                    $path = $loader['std']($class_name);
                }
                catch (\Throwable $e)
                {
                    self::getLogger()->critical(
                        'Class loader for {ns} threw exception with ' .
                        'attempting to load class {class}: {exception}', 
                        ['ns' => $loader['ns'], 'exception' => $e, 'class' => $class_name]
                    );
                } // Don't throw any errors
            }

            if (empty($path))
                continue;

            require_once $path;
            
            $exists = class_exists($class_name) || trait_exists($class_name) || interface_exists($class_name);
            if (self::$logger)
            {
                if (trait_exists($class_name))
                    self::$logger->debug("Loaded trait {0} from path {1}", [$class_name, $path]);
                elseif (interface_exists($class_name))
                    self::$logger->debug("Loaded interface {0} from path {1}", [$class_name, $path]);
                elseif (class_exists($class_name, false))
                    self::$logger->debug("Loaded class {0} from path {1}", [$class_name, $path]);
                else
                    self::$logger->error("File {0} does not contain class {1}", [$path, $class_name]);
            }

            // When a class has been loaded, don't loop any further
            if ($exists)
                break;
        }

        // Cache resolved path
        if ($this->cache !== null)
        {
            if (!empty($path))
                $this->cache->set('classpaths', $class_name, $path);
            else // Cache failure
                $this->cache->set('classpaths', $class_name, false);
        }
    }

    /**
     * Find the class name of the Composer autoloader. The name starts
     * with ComposerAutoloader and has a random suffix. Therefore, the list
     * of class names is obtained and all class names are compared with this pattern.
     */
    public static function findComposerAutoloader()
    {
        // Find the Composer Autoloader class using its (generated) name
        $list = get_declared_classes();
        foreach ($list as $cl)
            if (substr($cl, 0, 18) === "ComposerAutoloader")
                return $cl;

        // @codeCoverageIgnoreStart
        // Tests run using composer autoloader
        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get the Composer vendor directory, using the Composer autoloader class
     * name provided.
     *
     * @param string $composer_loader_class The fully qualified class name of the Composer autoloader
     * @return string The vendor dir used by composer
     */
    public static function findComposerAutoloaderVendorDir(string $composer_loader_class)
    {
        $ref = new \ReflectionClass($composer_loader_class);

        // Composer is located at MyProject/vendor/composer
        $path = dirname($ref->getFileName());
        return dirname($path);
    }

    /**
     * Import the configuration from the Composer autoloader, by specifying the
     * class name of the Composer Autoloader. This is used to locate the
     * composer folder, which contains files autoload_psr0.ph and/or
     * autoload_psr4.php which return an array of namespace -> path mappings,
     * which are then registered in the Wedeto Autoloader.
     *
     * @param string $composer_loader_class The fully qualified class name of
     * the Composer class loader.
     */
    public function importComposerAutoloaderConfiguration(string $composer_vendor_dir)
    {
        $cdir = $composer_vendor_dir . DIRECTORY_SEPARATOR . 'composer';
        $psr0 = $cdir . DIRECTORY_SEPARATOR . 'autoload_psr0.php';
        if (file_exists($psr0))
        {
            $namespaces = include($psr0);
            foreach ($namespaces as $ns => $paths)
                foreach ($paths as $path)
                    $this->registerNS($ns, $path, Autoloader::PSR0);
        }

        $psr4 = $cdir . DIRECTORY_SEPARATOR . 'autoload_psr4.php';
        if (file_exists($psr4))
        {
            $namespaces = include($psr4);
            foreach ($namespaces as $ns => $paths)
                foreach ($paths as $path)
                    $this->registerNS($ns, $path, Autoloader::PSR4);
        }
    }

    /**
     * Get a list of class loaders that may be able to load the specified
     * class or namespace. The resulting list is ordered so that the most
     * specific class loader comes first and the most generic comes last.
     *
     * @param string $class The fully qualified class (or namespace) to be loaded
     * @return array A list of loaders, each containing keys: 
     *               - 'path' where the classes are stored
     *               - 'std' either Autoloader::PSR0, Autoloader::PSR4 or a callable to be used
     *                  delegate the autoload task to
     *               - 'ns' the namespace this loader provides
     */
    public function getClassLoaders(string $class)
    {
        $parts = explode("\\", $class);
        $result = array();
        $ref = &$this->root_namespace;
        $sub_ns = "";
        foreach ($parts as $part)
        {
            foreach ($ref['loaders'] as $loader)
                $result[] = $loader;

            if (!isset($ref['sub_ns'][$part]))
                break;

            $ref = &$ref['sub_ns'][$part];
        }

        // Reverse the result, because the last one is the most specific
        // namespace, which would be the one to try first.
        return array_reverse($result);
    }

    /** 
     * @return array The list of registered namespace
     */
    public function getRegisteredNamespaces()
    {
        return array_keys($this->registered_namespaces);
    }
}
