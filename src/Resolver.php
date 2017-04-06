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

use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\Cache;

/**
 * Resolve templates assets from registered modules / locations
 */
class Resolver
{
    use LoggerAwareStaticTrait;

    /** The name to identify the type of files resolved. Also used in the cache as identifier key */
    protected $name;

    /** The paths to search when resolving */
    protected $search_path = array();

    /** If the list has been sorted. Initally empty, so initially sorted */
    protected $sorted = true;

    /** The cache of templates, assets */
    protected $cache = null;

    /** The authorativeness of the cache */
    protected $authorative = false;

    /**
     * Set up the resolve cache. 
     *
     * @param string $name The name of the resolver (Eg type of files resolved)
     */
    public function __construct(string $name)
    {
        self::getLogger();
        $this->name = $name;
    }

    /**
     * @return string the name of the resolver
     */
    public function getName()
    {
        return $this->name;
    }

    /** 
     * Set a cache used to store located files
     */
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get the cache instance in use
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * Set the authorative state. When authorative is enabled, and a file
     * failed to resolve according to the cache, no attempt is made to resolve
     * it. This increases performance in production, but can be inconvenient
     * during development.
     *
     * @param bool $authorative The authorative state
     * @return Resolver Provides fluent interface
     */
    public function setAuthorative(bool $authorative)
    {
        $this->authorative = $authorative;
        return $this;
    }

    /**
     * @return bool The Authorative state.
     * @seealso Resolver::setAuthorative
     */
    public function getAuthorative()
    {
        return $this->authorative;
    }

    /**
     * Add a module to the search path of the Resolver.
     *
     * @param string $name The name of the module. Just for logging purposes.
     * @param string $path The path of the module.
     * @param int $precedence The order in which the modules are searched.
     *                        Lower means searched earlier.
     * @return Resolver Provides fluent interface
     */
    public function addToSearchPath(string $name, string $path, int $precedence)
    {
        if (!is_dir($path))
            throw new \InvalidArgumentException("Path does not exist: " . $path);

        $this->search_path[$name] = array('path' => $path, 'precedence' => $precedence);
        $this->sorted = false;
        
        return $this;
    }

    protected function sortModules()
    {
        // Sort the paths so that the highest priority comes first
        uasort($this->search_path, function ($l, $r) {
            if ($l['precedence'] !== $r['precedence'])
                return $l['precedence'] - $r['precedence'];
            return strcmp($l['path'], $r['path']);
        });

        $this->sorted = true;
    }

    /** 
     * Set the precedence value of a module
     * @param string $name The name of the module
     * @param int $precedence The precedence value to set
     * @return Resolver Provides fluent interface
     */
    public function setPrecedence(string $name, int $precedence)
    {
        if (!isset($this->search_path[$name]))
            throw new \InvalidArgumentException("Unknown module: " . $name);

        if ($this->search_path[$name]['precedence'] !== $precedence)
        {
            $this->search_path[$name]['precedence'] = $precedence;
            $this->sorted = false;
        }
        return $this;
    }

    /**
     * Get the precedence value of a module
     * @param string $name The name of the module
     * @return int The precedence value
     */
    public function getPrecedence(string $name)
    {
        if (!isset($this->search_path[$name]))
            throw new \InvalidArgumentException("Unknown module: " . $name);

        return $this->search_path[$name]['precedence'];
    }

    /**
     * @return array A list of found modules
     */
    public function getSearchPath()
    {
        if (!$this->sorted)
            $this->sortModules();

        $sp = array();
        foreach ($this->search_path as $name => $info)
            $sp[$name] = $info['path'];
        return $sp;
    }

    /** 
     * Clear the search path
     * @return Resolver Provides fluent interface
     */
    public function clearSearchPath()
    {
        $this->search_path = array();
        $this->sorted = false;
    }

    /**
     * Clear the cached resolved files
     * @return Resolver Provides fluent interface
     */
    public function clearCache()
    {
        if ($this->cache !== null)
            $this->cache->set('resolve', $this->name, array('data' => [], 'search_path' => $this->search_path));
        return $this;
    }

    /**
     * Obtain the cached data. The cache is cleared when the sort order does not match
     * the cache.
     *
     * @return The cached data
     */
    protected function getCachedData()
    {
        if ($this->cache === null)
            return null;

        $sp = $this->cache->get('resolve', $this->name, 'search_path');
        if ($sp !== null)
            $sp = $sp->toArray();

        if ($this->search_path !== $sp)
            $this->clearCache();

        return $this->cache->get('resolve', $this->name);
    }

    /**
     * Helper method that searches the core and modules for a specific type of file. 
     * The files are evaluated in alphabetical order, and core always comes first.
     *
     * @param $type string The type to find, template or asset
     * @param $file string The file to locate
     * @return string A matching file. Null is returned if nothing was found.
     */
    public function resolve(string $file)
    {
        if (!$this->sorted)
            $this->sortModules();

        $cache = $this->getCachedData();
        $cached = $cache !== null ? $cache->get('data', $file) : null;

        if ($cached === false && $this->authorative)
            return null;

        if (!empty($cached))
        {
            if (file_exists($cached['path']) && is_readable($cached['path']))
            {
                self::$logger->debug(
                    "Resolved {0} {1} to path {2} (module: {3}) (cached)", 
                    [$this->name, $file, $cached['path'], $cached['module']]
                );
                return $cached['path'];
            }
            else
            {
                self::$logger->error(
                    "Cached path for {0} {1} from module {2} cannot be read: {3}", 
                    [$this->name, $file, $cached['module'], $cached['path']]
                );
            }
        }

        $path = null;
        $found_module = null;
        $mods = $this->search_path;

        foreach ($mods as $module => $info)
        {
            $location = $info['path'];
            self::$logger->debug("Trying {0} path: {1}/{2}", [$location, $this->name, $file]);
            $path = $location . '/' . $file;

            if (file_exists($path) && is_readable($path))
            {
                $found_module = $module;
                break;
            }
        }

        if ($found_module !== null)
        {
            self::$logger->debug("Resolved {0} {1} to path {2} (module: {3})", [$this->name, $file, $path, $found_module]);
            if ($cache !== null)
            {
                $cache->set(
                    'data',
                    $file, 
                    array("module" => $found_module, "path" => $path)
                );
            }
            return $path;
        }
        elseif ($cache !== null)
        {
            $cache->set('data', $file, false);
        }
    
        return null;
    }
}
