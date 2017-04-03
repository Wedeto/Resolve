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
    protected $search_paths;

    /** The cache of templates, assets */
    protected $cache = null;

    /**
     * Set up the resolve cache. 
     */
    public function __construct(string $name)
    {
        self::getLogger();
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
     * Add a module to the search path of the Resolver.
     *
     * @param $name string The name of the module. Just for logging purposes.
     * @param $path string The path of the module.
     */
    public function addToSearchPath(string $name, string $path)
    {
        $this->search_path[$name] = $path;
    }

    /**
     * @return array A list of found modules
     */
    public function getSearchPath()
    {
        return $this->search_path;
    }

    /** 
     * Clear the search path
     * @return Resolver Provides fluent interface
     */
    public function clearSearchPath()
    {
        $this->search_path = array();
        if ($this->cache !== null && $this->cache->has('resolve', $this->name))
            $this->cache->set('resolve', $this->name, array());
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
        $cached = $this->cache !== null ? $this->cache->get('resolve', $this->name, $file) : null;
        if ($cached === false)
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
        $mods = $this->modules;

        foreach ($mods as $module => $location)
        {
            self::$logger->debug("Trying path: {0}/{1}/{2}", [$location, $type, $file]);
            $path = $location . '/' . $type . '/' . $file;

            if (file_exists($path) && is_readable($path))
            {
                $found_module = $module;
                break;
            }
        }

        if ($found_module !== null)
        {
            self::$logger->debug("Resolved {0} {1} to path {2} (module: {3})", [$type, $file, $path, $found_module]);
            if ($this->cache !== null)
            {
                $this->cache->put(
                    'resolve',
                    $this->name,
                    $file, 
                    array("module" => $found_module, "path" => $path)
                );
            }
            return $path;
        }
        elseif ($this->cache !== null)
        {
            $this->cache->put('resolve', $this->name, $file, false);
        }
    
        return null;
    }
}
