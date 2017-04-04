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

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use org\bovigo\vfs\vfsStreamDirectory;

use Wedeto\Util\Cache;

/**
 * @covers Wedeto\Resolve\Router
 */
final class RouterTest extends TestCase
{
    private $dir;

    public function setUp()
    {
        // Make the cache use a virtual test path
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('router'));
        $this->dir = vfsStream::url('router');
        Cache::setCachePath($this->dir);
    }

    public function testRouterGetAndSetModules()
    {
        $root = $this->dir;
        mkdir($root . '/mod1/app', 0777, true);
        mkdir($root . '/mod2/app', 0777, true);

        $r = new Router;
        $actual = $r->getSearchPath();
        $this->assertEmpty($actual);

        $r->addToSearchPath('mod1', $root . '/mod1/app', 0);
        $r->addToSearchPath('mod2', $root . '/mod2/app', 0);
        $actual = $r->getSearchPath();
        $expected = ['mod1' => $root . '/mod1/app', 'mod2' => $root . '/mod2/app'];
        $this->assertEquals($expected, $actual);

        $r->clearSearchPath();
        $r->addToSearchPath('mod1', $root . '/mod1/app', 0);
        $actual = $r->getSearchPath();
        $expected = ['mod1' => $root . '/mod1/app'];
        $this->assertEquals($expected, $actual);

        $r->addToSearchPath('mod1', $root . '/mod1/app', 0);
        $actual = $r->getSearchPath();
        $this->assertEquals($expected, $actual);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Path does not exist");
        $r->addToSearchPath('mod3', $root . '/mod3/app', 0);
    }

    public function testRouterListDir()
    {
        $root = $this->dir;
        mkdir($root . '/mod1/app/sub', 0777, true);

        $mod = $root . '/mod1/app';
        touch($mod . '/app1.php');
        touch($mod . '/index.php');
        touch($mod . '/qwerty.php');
        touch($mod . '/sub/foo.php');
        touch($mod . '/sub/bar.php');

        $actual = Router::listDir($mod, true);
        $expected = [
            $mod . '/index.php',
            $mod . '/app1.php',
            $mod . '/qwerty.php',
            $mod . '/sub/bar.php',
            $mod . '/sub/foo.php'
        ];

        $this->assertEquals($expected, $actual);
    }

    public function testRouteWithExtension()
    {
        $root = $this->dir;
        mkdir($root . '/mod1/app/sub', 0777, true);

        $mod = $root . '/mod1/app';
        touch($mod . '/app1.php');
        touch($mod . '/index.php');
        touch($mod . '/qwerty.json.php');
        touch($mod . '/qwerty.xml.php');
        touch($mod . '/qwerty.php');
        touch($mod . '/sub/foo.php');
        touch($mod . '/sub/bar.php');

        $r = new Router;
        $r->addToSearchPath('mod1', $mod, 0);

        $route = $r->resolve('/qwerty.json');
        $this->assertEquals($mod . '/qwerty.json.php', $route['path']);
        $this->assertEmpty($route['remainder']);
        $this->assertEquals(".json", $route['ext']);

        $route = $r->resolve('/qwerty.xml');
        $this->assertEquals($mod . '/qwerty.xml.php', $route['path']);
        $this->assertEmpty($route['remainder']);
        $this->assertEquals(".xml", $route['ext']);

        $route = $r->resolve('/qwerty.csv');
        $this->assertEquals($mod . '/qwerty.php', $route['path']);
        $this->assertEmpty($route['remainder']);
        $this->assertEquals(".csv", $route['ext']);

        $route = $r->resolve('/qwerty');
        $this->assertEquals($mod . '/qwerty.php', $route['path']);
        $this->assertEmpty($route['remainder']);
        $this->assertEmpty($route['ext']);

        $route = $r->resolve('/app1');
        $this->assertEquals($mod . '/app1.php', $route['path']);
        $this->assertEmpty($route['remainder']);
        $this->assertEmpty($route['ext']);

        unlink($mod. '/app1.php');
        $route = $r->resolve('/app1');
        $this->assertEquals($mod . '/index.php', $route['path']);
        $this->assertEquals(['app1'], $route['remainder']);
        $this->assertEmpty($route['ext']);

        $route = $r->resolve('/sub/meep');
        $this->assertNull($route);

        $routes = $r->getRoutes();
        $this->assertInstanceOf(Route::class, $routes);
    }

    public function testRouterWithCache()
    {
        $cache = new Cache('resolve');

        $root = $this->dir;
        mkdir($root . '/mod1/app', 0777, true);
        $mod = $root . '/mod1/app';
        touch($mod . '/app1.php');
        touch($mod . '/app2.php');

        $router = new Router;
        $router->setCache($cache);
        $router->addToSearchPath('mod1', $mod, 0);

        $route = $router->resolve('/app1');
        $this->assertEquals($mod . '/app1.php', $route['path']);

        $route = $router->resolve('/app2');
        $this->assertEquals($mod . '/app2.php', $route['path']);

        // Repeat with same cache
        $router = new Router;
        $router->setCache($cache);
        $router->addToSearchPath('mod1', $mod, 0);

        $route = $router->resolve('/app1');
        $this->assertEquals($mod . '/app1.php', $route['path']);

        $route = $router->resolve('/app2');
        $this->assertEquals($mod . '/app2.php', $route['path']);
    }
}
