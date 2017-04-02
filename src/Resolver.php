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
 * Resolve templates, routes and assets from the core and modules.
 */
class Resolver
{
    use LoggerAwareStaticTrait;

    /** A list of installed modules */
    private $modules;

    /** The path of the core module */
    private $core_path;

    /** The cache of templates, assets, routes */
    private $cache = null;

    /** Set to true after a call to findModules */
    private $module_init = false;

    /**
     * Set up the resolve cache. 
     */
    public function __construct()
    {
        self::getLogger();
        $this->cache = new Cache('wedeto_resolve');
    }

    public function importComposerAutoloaderConfiguration(string $composer_autoloader_class)
    {
        // Find the Composer Autoloader class using its (generated) name
        $logger = self::getLogger();
        if ($cl === null)
        {
            $logger->error("Could not find Composer Autoloader class - could not deduce vendor path");
            return;
        }

        // Find the file the composer autoloader was defined in, and use that
        // to infer the vendor path and the base path.
        $ref = new ReflectionClass($cl);
        $fn = $ref->getFileName();
        $vendorDir = dirname(dirname($fn));
        $baseDir = dirname($vendorDir);
        $class_file = dirname($fn) . DIRECTORY_SEPARATOR . "autoload_psr4.php";

        // Check base directory to be a Wedeto module
        $modules = array();
        $base_name = basename($baseDir);
        if (is_dir($base_dir . '/template') || is_dir($base_dir . '/app') || is_dir($template . '/assets'))
            $modules[$base_name] = $base_dir;

        // Find all modules
        $paths = array();
        foreach (glob($vendorDir . "/*") as $vendor)
        {
            if (is_dir($vendor))
            {
                $new_modules = self::findModules($module, ucfirst(basename($vendor)));
                if (count($new_modules))
                    array_merge($modules, $new_modules);
            }
        }

        // Register the modules
        foreach ($modules as $name => $path)
            $this->registerModule($name, $path);

        return $modules;
    }

    /** 
     * Find installed modules in the module path
     * @param $module_path string Where to look for the modules
     */
    public static function findModules(string $module_path, $module_name_prefix = "")
    {
        $dirs = glob($module_path . '/*');

        $logger = self::getLogger();
        $modules = array();
        foreach ($dirs as $dir)
        {
            if (!is_dir($dir))
                continue;

            $has_template = is_dir($dir . '/template');
            $has_app = is_dir($dir . '/app');
            $has_assets = is_dir($dir . '/assets');

            if (!($has_template || $has_app || $has_assets))
            {
                $logger->info("Path {0} does not contain any usable elements", [$dir]);
                continue;
            }
            
            $mod_name = $module_name_prefix . ucfirst(basename($dir));
            $modules[$mod_name] = $dir;
            $logger->debug("Found module {0} in {1}", [$mod_name, $dir]);
        }

        return $modules;
    }

    /**
     * Add a module to the search path of the Resolver.
     *
     * @param $name string The name of the module. Just for logging purposes.
     * @param $path string The path of the module.
     */
    public function registerModule(string $name, string $path)
    {
        $this->modules[$name] = $path;
    }

    /**
     * Return the list of found modules
     */
    public function getModules()
    {
        return array_keys($this->modules);
    }

    /**
     * Resolve an controller / route.
     * @param $request string The incoming request
     * @return array An array containing:
     *               'path' => The file that best matches the route
     *               'route' => The part of the request that matches
     *               'module' => The source module for the controller
     *               'remainder' => Arguments object with the unmatched part of the request
     */
    public function app(string $request, bool $retry = true)
    {
        $parts = array_filter(explode("/", $request));

        // Determine the file extension
        $last_part = end($parts);
        $dpos = strrpos($last_part, '.');
        if (!empty($dpos)) // If a dot exists after the first character
            $ext = substr($last_part, $dpos);
        else
            $ext = "";

        $routes = $this->getRoutes();
        $route = $routes->resolve($parts, $ext);

        if (!empty($route) && $retry && !file_exists($route['path']))
        {
            // A non-existing file was found in the cache - flush the cache,
            // but do this only once.
            $this->cache->clear();
            return $this->app($request, false);
        }

        if ($route === null)
            self::$logger->info("Failed to resolve route for request to {0}", [$request]);

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
    private static function listDir(string $dir, bool $recursive = true)
    {
        $contents = array();
        $subdirs = array();
        foreach (glob($dir . "/*") as $entry)
        {
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
     * Get all routes available from all modules
     * @return array The available routes and the associated controller
     */
    public function getRoutes()
    {
        $routes = $this->cache->get('routes');
        if (!empty($routes))
            return $routes;
        
        $routes = new Route('/', 0);
        foreach ($this->modules as $module => $location)
        {
            $app_path = $location . '/app';
            
            $files = self::listDir($app_path);
            foreach ($files as $path)
            {
                $file = str_replace($app_path, "", $path);
                $parts = array_filter(explode("/", $file));
                $ptr = $routes;

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

        // Update the cache
        $this->cache->put('routes', $routes);
        return $routes;
    }

    /**
     * Resolve a template file. This method will traverse the installed
     * modules in reversed order. The files are ordered alphabetically, and
     * core always comes first.  By reversing the order, it becomes
     * possible to override templates by modules coming later.
     *
     * @param $template string The template identifier. 
     * @return string The location of a matching template.
     */
    public function template(string $template)
    {
        if (substr($template, -4) != ".php")
            $template .= ".php";

        return $this->resolve('template', $template, true);
    }

    /**
     * Resolve a asset file. This method will traverse the installed
     * modules in reversed order. The files are ordered alphabetically, and
     * core always comes first.  By reversing the order, it becomes
     * possible to override assets by modules coming later.
     *
     * @param $asset string The name of the asset file
     * @return string The location of a matching asset
     */
    public function asset(string $asset)
    {
        return $this->resolve('assets', $asset, true);
    }

    /**
     * Helper method that searches the core and modules for a specific type of file. 
     * The files are evaluated in alphabetical order, and core always comes first.
     *
     * @param $type string The type to find, template or asset
     * @param $file string The file to locate
     * @param $reverse boolean Whether to return the first matching or the last matching.
     * @param $case_insensitive boolean When this is true, all files will be compared lowercased
     * @return string A matching file. Null is returned if nothing was found.
     */
    private function resolve(string $type, string $file, bool $reverse = false, bool $case_insensitive = false)
    {
        if ($case_insensitive)
            $file = strtolower($file);

        $cached = $this->cache->get($type, $file);
        if ($cached === false)
            return null;

        if (!empty($cached))
        {
            if (file_exists($cached['path']) && is_readable($cached['path']))
            {
                self::$logger->debug("Resolved {0} {1} to path {2} (module: {3}) (cached)", [$type, $file, $cached['path'], $cached['module']]);
                return $cached['path'];
            }
            else
                self::$logger->error("Cached path for {0} {1} from module {2} cannot be read: {3}", [$type, $file, $cached['module'], $cached['path']]);
        }

        $path = null;
        $found_module = null;
        $mods = $reverse ? array_reverse($this->modules) : $this->modules;

        // A glob pattern is composed to implement a case insensitive file search
        if ($case_insensitive)
        {
            $glob_pattern = "";
            // Create a character class [Aa] for each character in the string
            for ($i = 0; $i < strlen($file); ++$i)
            {
                $ch = substr($file, $i, 1); // lower case character, as strtlower was called above
                if ($ch !== '/')
                    $ch = '[' . strtoupper($ch) . $ch . ']';
                $glob_pattern .= $ch;
            }
        }

        foreach ($mods as $module => $location)
        {
            if ($case_insensitive)
            {
                $files = glob($location . '/' . $type . '/' . $glob_pattern);
                if (count($files) === 0)
                    continue;
                $path = reset($files);
            }
            else
            {
                self::$logger->debug("Trying path: {0}/{1}/{2}", [$location, $type, $file]);
                $path = $location . '/' . $type . '/' . $file;
            }

            if (file_exists($path) && is_readable($path))
            {
                $found_module = $module;
                break;
            }
        }

        if ($found_module !== null)
        {
            self::$logger->debug("Resolved {0} {1} to path {2} (module: {3})", [$type, $file, $path, $found_module]);
            $this->cache->put($type, $file, array("module" => $found_module, "path" => $path));
            return $path;
        }
        else
            $this->cache->put($type, $file, false);
    
        return null;
    }
}
