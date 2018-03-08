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

use Wedeto\Util\DI\DI;
use Wedeto\Util\Cache\Cache;
use Wedeto\Util\Cache\Manager as CacheManager;

/**
 * @covers Wedeto\Resolve\Resolver
 */
final class ResolverTest extends TestCase
{
    private $dir;
    private $cache;

    public function setUp()
    {
        // Make the cache use a virtual test path
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('cachedir'));
        $this->dir = vfsStream::url('cachedir');
        DI::startNewContext('test');
        $this->cmgr = DI::getInjector()->getInstance(CacheManager::class);
        $this->cmgr->setCachePath($this->dir);
        $this->cache = $this->cmgr->getCache('resolve');
    }

    public function tearDown()
    {
        DI::destroyContext('test');
    }

    public function testResolver()
    {
        $mgr = new Resolver($this->cache);
        
        $res = $mgr->getResolvers();
        $this->assertEmpty($res);

        $mgr->addResolverType("assets", "assets");
        $rlv = $mgr->getResolver('assets');
        $this->assertInstanceOf(SubResolver::class, $rlv);

        $thrown = false;
        try
        {
            $mgr->addResolverType("assets", "assets");
        }
        catch (\LogicException $e)
        {
            $this->assertEquals("Duplicate resolver type: assets", $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $res = $mgr->getResolvers();
        $this->assertEquals(1, count($res));
        $this->assertEquals($rlv, $res['assets']);
    }

    public function testInvalidName()
    {
        $mgr = new Resolver($this->cache);

        $thrown = false;
        try
        {
            $t = $mgr->getResolver("foo");
        }
        catch (\InvalidArgumentException $e)
        {
            $this->assertEquals("Unknown resolver type: foo", $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $thrown = false;
        $res = new SubResolver('foo');
        try
        {
            $t = $mgr->setResolver("foo", $res);
        }
        catch (\InvalidArgumentException $e)
        {
            $this->assertEquals("Unknown resolver type: foo", $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $thrown = false;
        try
        {
            $t = $mgr->resolve('foo', 'bar');
        }
        catch (\InvalidArgumentException $e)
        {
            $this->assertEquals("Unknown resolver type: foo", $e->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);
    }

    public function testAuthorative()
    {
        $mgr = new Resolver($this->cache);

        $mgr->addResolverType('router', 'router');
        $mgr->setResolver('router', new Router);
        $mgr->addResolverType('assets', 'assets');
        $mgr->addResolverType('template', 'template');

        $resolvers = $mgr->getResolvers();
        $this->assertEquals(3, count($resolvers));
        foreach ($resolvers as $res)
            $this->assertFalse($res->getAuthorative());

        $this->assertInstanceOf(Resolver::class, $mgr->setAuthorative(true));
        $this->assertTrue($mgr->getAuthorative());
        $resolvers = $mgr->getResolvers();
        $this->assertEquals(3, count($resolvers));
        foreach ($resolvers as $res)
            $this->assertTrue($res->getAuthorative());

        $this->assertInstanceOf(Resolver::class, $mgr->setAuthorative(false));
        $this->assertFalse($mgr->getAuthorative());
        $resolvers = $mgr->getResolvers();
        $this->assertEquals(3, count($resolvers));
        foreach ($resolvers as $res)
            $this->assertFalse($res->getAuthorative());
    }

    public function testSetPrecedence()
    {
        $root = $this->dir;

        mkdir($root . '/module1/app', 0777, true);
        mkdir($root . '/module1/assets', 0777, true);
        mkdir($root . '/module1/template', 0777, true);

        mkdir($root . '/module2/app', 0777, true);
        mkdir($root . '/module2/assets', 0777, true);
        mkdir($root . '/module2/template', 0777, true);

        mkdir($root . '/module3/app', 0777, true);

        touch($root . '/module1/app/app.php');
        touch($root . '/module1/assets/test.css');
        touch($root . '/module1/template/tpl.php');
        touch($root . '/module2/app/app.php');
        touch($root . '/module2/template/tpl.php');
        touch($root . '/module3/app/app.php');

        $mgr = new Resolver($this->cache);
        $mgr->addResolverType('router', 'app');
        $mgr->setResolver('router', new Router);
        $mgr->addResolverType('assets', 'assets');
        $mgr->addResolverType('template', 'template');
        $mgr->registerModule('mod1', $root . '/module1', 1);
        $mgr->registerModule('mod2', $root . '/module2', 2);
        $mgr->registerModule('mod3', $root . '/module3', 3);

        $this->assertEquals($root . '/module1/assets/test.css', $mgr->resolve('assets', 'test.css'));
        $this->assertEquals($root . '/module1/template/tpl.php', $mgr->resolve('template', 'tpl.php'));

        $route = $mgr->resolve('router', '/app');
        $this->assertEquals($root . '/module1/app/app.php', $route['path']);

        $mods = $mgr->getModules();
        $this->assertTrue([
            'mod1' => $root . '/module1',
            'mod2' => $root . '/module2',
            'mod3' => $root . '/module3'
        ] === $mods);

        $this->assertInstanceOf(Resolver::class, $mgr->setPrecedence('mod2', 0));
        $this->assertInstanceOf(Resolver::class, $mgr->setPrecedence('mod3', 5));
        $this->assertEquals($root . '/module2/template/tpl.php', $mgr->resolve('template', 'tpl.php'));

        $route = $mgr->resolve('router', '/app');
        $this->assertEquals($root . '/module2/app/app.php', $route['path']);

        $mods = $mgr->getModules();
        $this->assertTrue([
            'mod2' => $root . '/module2',
            'mod1' => $root . '/module1',
            'mod3' => $root . '/module3'
        ] === $mods);

        // Set to equal precedence - sorting should be on path name now
        $this->assertInstanceOf(Resolver::class, $mgr->setPrecedence('mod3', 0));
        $mods = $mgr->getModules();
        $this->assertTrue([
            'mod2' => $root . '/module2',
            'mod3' => $root . '/module3',
            'mod1' => $root . '/module1'
        ] === $mods);
    }

    public function testWithExtension()
    {
        $root = $this->dir;

        mkdir($root . '/module1/template', 0777, true);
        touch($root . '/module1/template/tpl.php');

        $mgr = new Resolver($this->cache);
        $mgr->addResolverType('template', 'template', '.php');
        $mgr->registerModule('mod1', $root . '/module1', 1);

        $this->assertEquals($root . '/module1/template/tpl.php', $mgr->resolve('template', 'tpl.php'));
        $this->assertEquals($root . '/module1/template/tpl.php', $mgr->resolve('template', 'tpl'));
    }

    public function testEmptyModule()
    {
        $root = $this->dir;

        mkdir($root . '/module10', 0777, true);
        $mgr = new Resolver($this->cache);
        $mgr->addResolverType('template', 'template', '.php');
        $mgr->registerModule('mod10', $root . '/module10', 1);

        $resolver = $mgr->getResolver('template');
        $search_path = $resolver->getSearchPath();
        $this->assertEmpty($search_path);
    }

    public function testAutoconfigFromComposer()
    {
        $root = $this->dir;
        $vendor_dir = $root . "/vendor";
        
        mkdir($vendor_dir . '/wedeto/mod1/app', 0777, true);

        mkdir($vendor_dir . '/wedeto/mod2/template', 0777, true);
        mkdir($vendor_dir . '/wedeto/mod2/assets', 0777, true);

        mkdir($vendor_dir . '/wedeto/mod3/assets', 0777, true);

        mkdir($vendor_dir . '/wedeto/mod4/app', 0777, true);
        mkdir($vendor_dir . '/wedeto/mod4/assets', 0777, true);
        mkdir($vendor_dir . '/wedeto/mod4/template', 0777, true);

        // Make sure that random files don't trigger anything weird
        touch($vendor_dir . '/wedeto/foo.bar');

        $mgr = new Resolver;
        $mgr->addResolverType('template', 'template');
        $mgr->addResolverType('assets', 'assets');
        $mgr->addResolverType('router', 'app');
        $mgr->setResolver('router', new Router);
        $mgr->autoConfigureFromComposer($vendor_dir);

        $router = $mgr->getResolver('router');
        $search_path = $router->getSearchPath();
        $expected = [
            'wedeto.mod1' => $vendor_dir . '/wedeto/mod1/app',
            'wedeto.mod4' => $vendor_dir . '/wedeto/mod4/app'
        ];

        $this->assertEquals($expected, $search_path);

        $assets = $mgr->getResolver('assets');
        $search_path = $assets->getSearchPath();
        $expected = [
            'wedeto.mod2' => $vendor_dir . '/wedeto/mod2/assets',
            'wedeto.mod3' => $vendor_dir . '/wedeto/mod3/assets',
            'wedeto.mod4' => $vendor_dir . '/wedeto/mod4/assets'
        ];
        $this->assertEquals($expected, $search_path);

        $template = $mgr->getResolver('template');
        $search_path = $template->getSearchPath();
        $expected = [
            'wedeto.mod2' => $vendor_dir . '/wedeto/mod2/template',
            'wedeto.mod4' => $vendor_dir . '/wedeto/mod4/template'
        ];
        $this->assertEquals($expected, $search_path);
    }

    public function testAutoconfigWithInvalidPath()
    {
        $root = $this->dir;
        $vendor_dir = $root . "/vendor";

        $mgr = new Resolver;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Not a path");
        $mgr->autoConfigureFromComposer($vendor_dir);

    }
}

