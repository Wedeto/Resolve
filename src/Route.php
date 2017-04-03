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

use Serializable;

/**
 * Manage routes discovered by the router. Each app is stored
 * with it's content type, route and file path.
 */
class Route implements Serializable
{
    /** The route to this node */
    protected $route;

    /** The depth of this node */
    protected $depth;

    /** The app files linked to this node directly */
    protected $apps = array();
    
    /** The child nodes */
    protected $children = array();

    /**
     * Create a route node.
     * @param string $route The route to get here
     * @param int $depth The depth of this node.
     */
    public function __construct(string $route, int $depth)
    {
        $this->route = $route;
        $this->depth = $depth;
    }

    /**
     * Link an app to this route.
     * @param string $path The path to the app
     * @param string $ext The file extension for this app
     * @param string $module The module this app belongs to
     * @return Route Provides fluent interface
     */
    public function addApp(string $path, $ext, string $module)
    {
        $ext_key = empty($ext) ? '_' : $ext;
        if (!isset($this->apps[$ext_key]))
        {
            $this->apps[$ext_key] = array(
                'path' => $path,
                'module' => $module, 
                'route' => $this->route, 
                'ext' => $ext,
                'depth' => $this->depth
            );
        }
        return $this;
    }

    /**
     * Get a sub route for the specified route part. If a
     * child node does not exist yet, it will be created.
     * @param string $route_part The child route part
     * @return Route The child node
     */
    public function getSubRoute(string $route_part)
    {
        $sub_route = $this->route === '/' ? '/' . $route_part : $this->route . '/' . $route_part;

        if (!isset($this->children[$route_part]))
            $this->children[$route_part] = new Route($sub_route, $this->depth + 1);

        return $this->children[$route_part];
    }

    /**
     * Find a app for the specified route (or part of it). This method
     * recursively delegates to the child nodes, until an app is found.
     *
     * index.php will match all other route endings. If a child node for a
     * route part exists, this child becomes responsible, and if it does not
     * resolve, null is returned, which should be interpreted as a 404 - Not
     * Found.
     *
     * @param array $parts The route parts to resolve
     * @param string $ext The file extension that may be removed from the route
     * @return array The resolved route, or null if none was found.
     */
    public function resolve(array $parts, string $ext)
    {
        $part = array_shift($parts);

        $sub_part = $part;
        if (!empty($ext) && substr($part, -strlen($ext)) === $ext)
            $sub_part = substr($part, 0, -strlen($ext));

        // Ask child routes to resolve the route
        if (isset($this->children[$sub_part]))
        {
            // When a sub route exists, there's not falling back to this level anymore.
            return $this->children[$sub_part]->resolve($parts, $ext);
            //if (!empty($route))
            //    return $route;
        }

        // No sub-route, so put the part back in place
        if (!empty($part) && $sub_part !== "index")
            array_unshift($parts, $part);
        $route = null;

        // Check if a ext-specific app is available
        if (!empty($ext) && isset($this->apps[$ext]))
            $route = $this->apps[$ext];

        // Check if a generic app is available
        if (empty($route) && isset($this->apps['_']))
            $route = $this->apps['_'];

        if (empty($route) && empty($ext) && !empty($this->apps))
        {
            $route = reset($this->apps);
            $route['ext'] = null;
        }

        if (!empty($route) && !empty($ext))
            $route['ext'] = $ext;

        if (!empty($route) && !isset($route['remainder']))
            $route['remainder'] = $parts;

        // Return the route
        return $route;
    }

    /**
     * Serialize the route.
     * @return string data The serialized route
     */
    public function serialize()
    {
        return serialize([
            'route' => $this->route,
            'depth' => $this->depth,
            'apps' => $this->apps,
            'children' => $this->children
        ]);
    }

    /**
     * Unserialize the route from serialized PHP data
     * @param string $data The PHP Serialized data
     */
    public function unserialize($data)
    {
        $data = unserialize($data);
        $this->route = $data['route'];
        $this->depth = $data['depth'];
        $this->apps = $data['apps'];
        $this->children = $data['children'];
    }
}
