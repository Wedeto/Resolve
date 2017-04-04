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

class Router extends Resolver 
{
    use LoggerAwareStaticTrait;

    /** The root route node */
    protected $root = null;

    public function __construct(string $name = "router")
    {
        parent::__construct($name);
    }

    /**
     * Resolve an controller / route.
     * @param string $request string The incoming request
     *
     * @return array An array containing:
     *               'path' => The file that best matches the route
     *               'route' => The part of the request that matches
     *               'module' => The source module for the controller
     *               'remainder' => Arguments object with the unmatched part of the request
     */
    public function resolve(string $request)
    {
        $parts = array_filter(explode("/", $request));

        // Determine the file extension
        $last_part = end($parts);
        $dpos = strrpos($last_part, '.');
        if (!empty($dpos)) // If a dot exists after the first character
            $ext = substr($last_part, $dpos);
        else
            $ext = "";

        $root = $this->getRoutes();

        $route = $root->resolve($parts, $ext);

        if (!empty($route) && !file_exists($route['path']) && !$this->authorative)
        {
            // A non-existing file was found in the cache - flush the cache and
            // try again.
            $this->clearCache();
            $root = $this->getRoutes();
            $route = $root->resolve($parts, $ext);
        }

        if ($route === null)
            self::$logger->info("Failed to resolve route for request to {0}", [$request]);
        else
            self::$logger->debug("Resolved route for {route} to {path} (module: {module})", $route);

        return $route;
    }
    
    /**
     * Find files and directories in a directory. The contents are filtered on
     * .php files and files.
     *
     * @param $dir string The directory to list
     * @param $recursive boolean Whether to also scan subdirectories
     * @return array The contents of the directory.
     */
    public static function listDir(string $dir, bool $recursive = true)
    {
        $contents = array();
        $subdirs = array();
        $reader = dir($dir);
        while ($entry = $reader->read())
        {
            if ($entry === "." || $entry === "..")
                continue;

            $entry = $dir . DIRECTORY_SEPARATOR . $entry;
            if (substr($entry, -4) === ".php")
            {
                $contents[] = $entry;
            }
            elseif (is_dir($entry) && $recursive)
            {
                $subdirs = array_merge($subdirs, self::listDir($entry));
            }
        }

        // Sort the direct contents of the directory so that index.php comes first
        usort($contents, function ($a, $b) {
            $sla = strlen($a);
            $slb = strlen($b);
            
            // index files come first
            $a_idx = substr($a, -10) === "/index.php";
            $b_idx = substr($b, -10) === "/index.php";
            if ($a_idx !== $b_idx)
                return $a_idx ? -1 : 1;

            // sort the rest alphabetically
            return strcasecmp($a, $b);
        });

        // Add the contents of subdirectories to the direct contents
        return array_merge($contents, $subdirs);
    }

    /**
     * Get all routes available from all modules. If the routes have already been
     * determined, they will be used. Clear the cache to redo this.
     *
     * @return array The available routes and the associated controller
     */
    public function getRoutes()
    {
        if (!$this->sorted)
            $this->sortModules();

        $cache = $this->getCachedData();

        if ($cache !== null && $this->root === null)
        {
            $root = $cache->get('data');
            if ($root instanceof Route)
                $this->root = $root;
        }

        if ($this->root !== null)
            return $this->root;
        
        $this->root = new Route('/', 0);
        foreach ($this->search_path as $module => $info)
        {
            $app_path = $info['path'];
            $files = self::listDir($app_path);
            foreach ($files as $path)
            {
                $file = str_replace($app_path, "", $path);
                $parts = array_filter(explode("/", $file));
                $ptr = $this->root;

                $cnt = 0;
                $l = count($parts);
                foreach ($parts as $part)
                {
                    $last = $cnt === $l - 1;
                    if ($last)
                    {
                        if ($part === "index.php")
                        {
                            // Only store if empty - 
                            $ptr->addApp($path, '', $module);
                        }
                        else
                        {
                            $app_name = substr($part, 0, -4);

                            // Strip file extension
                            $ext = "";
                            $ext_pos = strrpos($app_name, '.');
                            if (!empty($ext_pos))
                            {
                                $ext = substr($app_name, $ext_pos);
                                $app_name = substr($app_name, 0, $ext_pos);
                            }

                            $app = $ptr->getSubRoute($app_name);
                            $app->addApp($path, $ext, $module);
                        }
                        break;
                    }
                
                    // Move the pointer deeper
                    $ptr = $ptr->getSubRoute($part);
                    ++$cnt;
                }
            }
        }

        if ($cache !== null)
            $cache->set('data', $this->root);

        return $this->root;
    }

    /**
     * Clear the cached resolved files
     * @return Resolver Provides fluent interface
     */
    public function clearCache()
    {
        parent::clearCache();
        $this->root = null;
    }
}
