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

use PHPUnit\Framework\TestCase;

/**
 * @covers Wedeto\Resolve\Route
 */
final class RouteTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = new Route("/", 0);

        // Add some routes
        $this->root->addApp('/index.php', '', 'mod1');
        $this->root->addApp('/index.php', '', 'mod2');

        $sub = $this->root->getSubRoute('foo');
        $sub->addApp('/foo/index.php', '', 'mod1');

        $sub2 = $sub->getSubRoute('bar');
        $sub2->addApp('/foo/bar.json.php', '.json', 'mod1');
        $sub2->addApp('/foo/bar.php', '', 'mod1');

        $sub3 = $sub->getSubRoute('boo');
        $sub3->addApp('/foo/boo.json.php', '.json', 'mod2');
    }

    public function testResolveBasicRoute()
    {
        // Test resolving
        $route = $this->root->resolve(array(), '');
        $this->assertEquals([
            'path' => '/index.php',
            'module' => 'mod1',
            'route' => '/',
            'ext' => '',
            'depth' => 0,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array(), '.json');
        $this->assertEquals([
            'path' => '/index.php',
            'module' => 'mod1',
            'route' => '/',
            'ext' => '.json',
            'depth' => 0,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array(), '.json');
        $this->assertEquals([
            'path' => '/index.php',
            'module' => 'mod1',
            'route' => '/',
            'ext' => '.json',
            'depth' => 0,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array('foo'), '');
        $this->assertEquals([
            'path' => '/foo/index.php',
            'module' => 'mod1',
            'route' => '/foo',
            'ext' => '',
            'depth' => 1,
            'remainder' => []
        ], $route);
    }

    public function testResolveRouteWithJsonAndGenericApp()
    {
        $route = $this->root->resolve(array('foo', 'bar'), '');
        $this->assertEquals([
            'path' => '/foo/bar.php',
            'module' => 'mod1',
            'route' => '/foo/bar',
            'ext' => '',
            'depth' => 2,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array('foo', 'bar'), '.json');
        $this->assertEquals([
            'path' => '/foo/bar.json.php',
            'module' => 'mod1',
            'route' => '/foo/bar',
            'ext' => '.json',
            'depth' => 2,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array('foo', 'bar.json'), '.json');
        $this->assertEquals([
            'path' => '/foo/bar.json.php',
            'module' => 'mod1',
            'route' => '/foo/bar',
            'ext' => '.json',
            'depth' => 2,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array('foo', 'bar', 'baz'), '');
        $this->assertEquals([
            'path' => '/foo/bar.php',
            'module' => 'mod1',
            'route' => '/foo/bar',
            'ext' => '',
            'depth' => 2,
            'remainder' => ['baz']
        ], $route);

        $route = $this->root->resolve(array('foo', 'bar', 'baz.json'), '.json');
        $this->assertEquals([
            'path' => '/foo/bar.json.php',
            'module' => 'mod1',
            'route' => '/foo/bar',
            'ext' => '.json',
            'depth' => 2,
            'remainder' => ['baz']
        ], $route);
    }

    public function testResolveRouteWithOnlyJSONSpecificApp()
    {
        $route = $this->root->resolve(array('foo', 'boo.xml'), '.xml');
        $this->assertNull($route);

        $route = $this->root->resolve(array('foo', 'boo.json'), '.json');
        $this->assertEquals([
            'path' => '/foo/boo.json.php',
            'module' => 'mod2',
            'route' => '/foo/boo',
            'ext' => '.json',
            'depth' => 2,
            'remainder' => []
        ], $route);

        $route = $this->root->resolve(array('foo', 'boo', 'baa.json'), '.json');
        $this->assertEquals([
            'path' => '/foo/boo.json.php',
            'module' => 'mod2',
            'route' => '/foo/boo',
            'ext' => '.json',
            'depth' => 2,
            'remainder' => ['baa']
        ], $route);

        $route = $this->root->resolve(array('foo', 'boo'), '');
        $this->assertEquals([
            'path' => '/foo/boo.json.php',
            'module' => 'mod2',
            'route' => '/foo/boo',
            'ext' => null,
            'depth' => 2,
            'remainder' => []
        ], $route);
    }
    
    public function testSerialization()
    {
        // Test serialization
        $phps = serialize($this->root);
        $clone = unserialize($phps);
        $fail = new Route('/b', 0);
        $this->assertEquals($this->root, $clone);
        $this->assertNotEquals($this->root, $fail);
    }
}
