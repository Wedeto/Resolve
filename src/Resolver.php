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
use Wedeto\Util\Cache\Cache;

class Resolver
{
    use LoggerAwareStaticTrait;

    /** The cache to store all resolve data */
    protected $cache;

    /** If the modules are sorted on precedence */
    protected $sorted = true;

    /** The modules in use in the manager */
    protected $modules = array();

    /** The resolvers */
    protected $resolvers = array();

    /** The authorative mode */
    protected $authoritive = false;

    /** The name of the main module */
    protected $main_module = "main";
    
    /** 
     * Set up the manager.
     * @param Cache $cache The cache to use for the resolvers.
     */
    public function __construct(Cache $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * Add a resolver type
     * @param string $type The resolver type to add
     * @param string $path The sub-path this resolver looks in in each module
     * @param string $extension The required extension on resolved elements. If absent from a reference,
     *                          it will be appended
     * @param SubResolver $instance A way to pass in a SubResolver instance. When null, a new one will be
     *                           instantiated. Cannot be used in combination with $extension
     * @return Resolver Provides fluent interface
     */
    public function addResolverType(string $type, string $path, string $extension = "", SubResolver $instance = null)
    {
        // Check argument validity
        if (isset($this->resolvers[$type]))
            throw new \LogicException("Duplicate resolver type: " . $type);

        if ($instance !== null && $extension !== "")
            throw new \LogicException("Cannot set a file extension for a provided instance");

        // Instantiate a new resolver when none has been passed
        if ($instance === null)
            $instance = new SubResolver($type, $extension);

        $this->resolvers[$type] = [
            'path' => $path,
            'resolver' => $instance
        ];

        if ($this->cache)
            $this->resolvers[$type]['resolver']->setCache($this->cache);

        // Register found modules with the new resolver
        foreach ($this->modules as $name => $module_path)
            if (is_dir($module_path . DIRECTORY_SEPARATOR . $path))
                $instance->addToSearchPath($name, $path);

        return $this;
    }

    /**
     * Return a resolver instance
     * @param string $type The resolver type to return
     * @return Resolver The resolver instance
     * @throws InvalidArgumentException When the resolver type is unknown
     */
    public function getResolver(string $type)
    {
        if (!isset($this->resolvers[$type]))
            throw new \InvalidArgumentException("Unknown resolver type: " . $type);
        return $this->resolvers[$type]['resolver'];
    }

    /**
     * Replace a resolver with a custom instance
     * @param string $type The resolver to replace
     * @param SubResolver $resolver The SubResolver instance
     * @return Resolver Provides fluent interface
     */
    public function setResolver(string $type, SubResolver $resolver)
    {
        if (!isset($this->resolvers[$type]))
            throw new \InvalidArgumentException("Unknown resolver type: " . $type);

        if ($this->cache !== null)
            $resolver->setCache($this->cache);

        $this->resolvers[$type]['resolver'] = $resolver;
        return $this;
    }

    /**
     * @return array The list of configured resolvers, with their names as key
     */
    public function getResolvers()
    {
        $res = [];
        foreach ($this->resolvers as $name => $resolver)
            $res[$name] = $resolver['resolver'];

        return $res;
    }

    /**
     * Resolve a reference of a specific type
     * @param string $type The reference type
     * @param string $reference What to resolve
     * @return string The resolved element 
     */
    public function resolve(string $type, string $reference)
    {
        if (!isset($this->resolvers[$type]))
            throw new \InvalidArgumentException("Unknown resolver type: " . $type);

        return $this->resolvers[$type]['resolver']->resolve($reference);
    }

    /**
     * Set the authorativeness of the resolvers
     * @param bool $authorative Whether the resolvers are authorative or not: whether
     *                          they trust cached resolve failures or not.
     *                          This improves performance in production but can be inconvenient
     *                          in development.
     */
    public function setAuthorative(bool $authorative)
    {
        foreach ($this->resolvers as $resolver)
            $resolver['resolver']->setAuthorative($authorative);

        $this->authorative = $authorative;
        return $this;
    }

    /**
     * @return bool The authorativeness of the resolvers
     */
    public function getAuthorative()
    {
        return $this->authorative;
    }

    /**
     * Register a module
     *
     * @param string $module The name of the module
     * @param string $path The path where the module stores its data
     * @param int $precedence Determines the order in which the paths are
     *                        searched. Used for templates and assets, to
     *                        make it possible to reliably override others.
     * @return Resolver Provides fluent interface
     */
    public function registerModule(string $module, string $path, int $precedence)
    {
        // Normalize the module name: lowercased, with dots
        $module = strtolower(preg_replace('/([\.\\/\\\\])/', '.', $module));

        $found_elements = array();
        foreach ($this->resolvers as $type => $resolver)
        {
            $type_path = $path . DIRECTORY_SEPARATOR . $resolver['path'];
            if (is_dir($type_path))
            {
                $resolver['resolver']->addToSearchPath($module, $type_path, $precedence);
                $found_elements[] = $type;
            }
        }

        if (!empty($found_elements))
            $this->modules[$module] = ['path' => $path, 'precedence' => $precedence];
        else
            self::getLogger()->debug("No resolvable items found in module: " . $module);

        // No longer sorted, probably
        $this->sorted = false;

        return $this;
    }

    /**
     * Sort the modules on precedence
     */
    protected function sortModules()
    {
        uasort($this->modules, function ($l, $r) {
            if ($l['precedence'] !== $r['precedence'])
                return $l['precedence'] - $r['precedence'];
            return strcmp($l['path'], $r['path']);
        });
        $this->sorted = true;
    }

    /**
     * @return array Associative array of (module_name, path) pairs
     */
    public function getModules()
    {
        if (!$this->sorted)
            $this->sortModules();

        $mods = [];
        foreach ($this->modules as $name => $mod)
            $mods[$name] = $mod['path'];

        return $mods;
    }

    /**
     * Set the precedence for a module on all resolvers.
     * @param string $module The name of the module.
     * @param int $precedence The precedence value, used to order the modules.
     *                        Lower values come first.
     * @return Resolver Provides fluent interface
     */
    public function setPrecedence(string $module, int $precedence)
    {
        foreach ($this->resolvers as $type => $resolver)
        {
            try
            {
                $resolver['resolver']->setPrecedence($module, $precedence);
            }
            catch (\InvalidArgumentException $e)
            {} // Not all resolvers may contain this module
        }

        $this->modules[$module]['precedence'] = $precedence;

        // No longer sorted, probably
        $this->sorted = false;
        
        return $this;
    }

    /**
     * Discover the module configuration based on the Composer configuration
     *
     * @param string $vendor_dir The Composer vendor path - e.g. MyProject/vendor
     * @return Resolver Provides fluent interface
     */
    public function autoConfigureFromComposer(string $vendor_dir)
    {
        $main_dir = dirname($vendor_dir);

        // Add the main module
        $this->main_module = "main";
        $package = $main_dir . DIRECTORY_SEPARATOR . "composer.json";

        if (file_exists($package))
        {
            // A composer.json file should exist for a composer project,
            // so read the module name from that.
            $json = @file_get_contents($package);
            if ($json)
            {
                $js = @json_decode($json, true);
                if (isset($js['name']))
                    $this->main_module = $js['name'];
            }
        }

        // Register the main module with the highest precedence
        $this->registerModule($this->main_module, $main_dir, 0);

        $modules = $this->findModules($vendor_dir, "", 1);
        ksort($modules);

        $count = 0;
        foreach ($modules as $name => $path)
            $this->registerModule($name, $path, ++$count);

        return $this;
    }

    /** 
     * Find modules in the specified path
     *
     * @param string $path string Where to look for the modules
     * @param string $module_name_prefix The prefix for the generated module names
     * @param int $depth The depth at which to look for modules
     */
    public static function findModules(string $path, string $module_name_prefix, int $depth)
    {
        if (!is_dir($path))
            throw new \InvalidArgumentException("Not a path: $path");

        $dirs = dir($path);

        $modules = array();
        while ($dir = $dirs->read())
        {
            if ($dir === ".." || $dir === ".")
                continue;

            $mod_path = $path . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($mod_path))
                continue;

            $dirname = ucfirst(strtolower($dir));
            $module_name = $module_name_prefix . $dirname;
            if ($depth > 0)
            {
                $sub_modules = self::findModules($mod_path, $module_name . '.', $depth - 1);
                $modules = array_merge($modules, $sub_modules);
            }
            else
            {
                $modules[$module_name] = $mod_path;
            }
        }

        return $modules;
    }
}
