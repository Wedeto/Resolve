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

class Route implements Serializable
{
    protected $route;
    protected $depth;
    protected $apps = array();
    protected $children = array();

    public function __construct(string $route, $depth)
    {
        $this->route = $route;
        $this->depth = $depth;
    }

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
    }

    public function getSubRoute(string $route_part)
    {
        $sub_route = $this->route === '/' ? '/' . $route_part : $this->route . '/' . $route_part;

        if (!isset($this->children[$route_part]))
            $this->children[$route_part] = new Route($sub_route, $this->depth + 1);

        return $this->children[$route_part];
    }

    public function resolve(array $parts, string $ext)
    {
        $part = array_shift($parts);

        $sub_part = $part;
        if (!empty($ext) && substr($part, -strlen($ext)) === $ext)
            $sub_part = substr($part, 0, -strlen($ext));

        // Ask child routes to resolve the route
        if (isset($this->children[$sub_part]))
        {
            $route = $this->children[$sub_part]->resolve($parts, $ext);
            if (!empty($route))
                return $route;
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

    public function serialize()
    {
        return serialize([
            'route' => $this->route,
            'depth' => $this->depth,
            'apps' => $this->apps,
            'children' => $this->children
        ]);
    }

    public function unserialize($data)
    {
        $data = unserialize($data);
        $this->route = $data['route'];
        $this->depth = $data['depth'];
        $this->apps = $data['apps'];
        $this->children = $data['children'];
    }
}
