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
 * @covers Wedeto\Resolve\SubResolver
 */
final class SubResolverTest extends TestCase
{
    private $dir;

    public function setUp()
    {
        // Make the cache use a virtual test path
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('cachedir'));
        $this->dir = vfsStream::url('cachedir');
        $this->cmgr = DI::getInjector()->getInstance(CacheManager::class);
        $this->cmgr->setCachePath($this->dir);
    }

    public function testSubResolver()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);
        mkdir($root . '/module1/files/bar', 0770, true);

        mkdir($root . '/module2/files/foo', 0770, true);
        mkdir($root . '/module2/files/bar', 0770, true);

        mkdir($root . '/module3/files/foo', 0770, true);

        touch($root . '/module1/files/foo/test1.css');
        touch($root . '/module1/files/foo/test2.css');
        touch($root . '/module1/files/bar/test3.css');

        touch($root . '/module2/files/foo/test2.css');
        touch($root . '/module2/files/bar/test3.css');
        touch($root . '/module2/files/bar/test4.css');

        touch($root . '/module3/files/foo/test2.css');

        $resolver = new SubResolver('css');
        $this->assertEquals('css', $resolver->getName());
        $cache = $this->cmgr->getCache('wedeto-resolve');
        $resolver->setCache($cache);
        $this->assertEquals($cache, $resolver->getCache());

        $resolver->addToSearchPath('mod1', $root . '/module1', 1);
        $resolver->addToSearchPath('mod2', $root . '/module2', 2);
        $resolver->addToSearchPath('mod3', $root . '/module3', 0);

        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module3/files/foo/test2.css', $result);

        $sp = $resolver->getSearchPath();
        $this->assertEquals([
            'mod1' => $root . '/module1',
            'mod2' => $root . '/module2',
            'mod3' => $root . '/module3'
        ], $sp);

        $resolver->clearSearchPath();
        $sp = $resolver->getSearchPath();
        $this->assertEmpty($sp);
    }

    public function testResolveWithoutCache()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);

        touch($root . '/module1/files/foo/test1.css');
        touch($root . '/module1/files/foo/test2.css');

        $resolver = new SubResolver('css');

        // Add the module path
        $resolver->addToSearchPath('mod1', $root . '/module1', 1);

        // Test resolving files not in cache
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module1/files/foo/test2.css', $result);

        // Resolve them again
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module1/files/foo/test2.css', $result);
    }

    public function testResolveCacheHits()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);

        touch($root . '/module1/files/foo/test1.css');
        touch($root . '/module1/files/foo/test2.css');

        $resolver = new SubResolver('css');
        $cache = $this->cmgr->getCache('wedeto-resolve');
        $resolver->setCache($cache);
        $this->assertEquals($cache, $resolver->getCache());

        // Add the module path
        $resolver->addToSearchPath('mod1', $root . '/module1', 1);

        // Test resolving files not in cache
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module1/files/foo/test2.css', $result);

        // Remove a cached file
        unlink($root . '/module1/files/foo/test2.css');

        // Test resolving cached file that still exists
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        // Test resolving cached file that does not exist anymore
        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertNull($result);

        // Re-create it, it should be found again
        touch($root . '/module1/files/foo/test2.css');
        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module1/files/foo/test2.css', $result);
    }

    public function testResolveWithAuthorativeCache()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);

        touch($root . '/module1/files/foo/test1.css');
        touch($root . '/module1/files/foo/test2.css');

        $resolver = new SubResolver('css');
        $cache = $this->cmgr->getCache('wedeto-resolve');
        $resolver->setCache($cache);
        $this->assertEquals($cache, $resolver->getCache());

        $this->assertFalse($resolver->getAuthorative());
        $this->assertSame($resolver, $resolver->setAuthorative(true));
        $this->assertTrue($resolver->getAuthorative());

        // Add the module path
        $resolver->addToSearchPath('mod1', $root . '/module1', 1);

        // Test resolving files not in cache
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertEquals($root . '/module1/files/foo/test2.css', $result);

        // Remove a cached file
        unlink($root . '/module1/files/foo/test2.css');

        // Test resolving cached file that still exists
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        // Test resolving cached file that does not exist anymore
        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertNull($result);

        // Re-create it, it should not be found again
        touch($root . '/module1/files/foo/test2.css');
        $result = $resolver->resolve('files/foo/test2.css');
        $this->assertNull($result);

        // Create a file that was not cached so far, it should be picked up
        touch($root . '/module1/files/foo/test3.css');
        $result = $resolver->resolve('files/foo/test3.css');
        $this->assertEquals($root . '/module1/files/foo/test3.css', $result);

        // Adding a module to the search path should invalidate the cache
        mkdir($root . '/module2/files/foo', 0770, true);
        touch($root . '/module2/files/foo/test1.css');

        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module1/files/foo/test1.css', $result);

        $resolver->addToSearchPath('mod2', $root . '/module2', -1);
        $result = $resolver->resolve('files/foo/test1.css');
        $this->assertEquals($root . '/module2/files/foo/test1.css', $result);
    }

    public function testSetPrecedence()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);
        mkdir($root . '/module2/files/foo', 0770, true);

        touch($root . '/module1/files/foo/test1.css');
        touch($root . '/module2/files/foo/test1.css');

        $resolver = new SubResolver('css');
        $cache = $this->cmgr->getCache('wedeto-resolve');
        $resolver->setCache($cache);

        // Add the module path
        $resolver->addToSearchPath('mod1', $root . '/module1', 1);
        $resolver->addToSearchPath('mod2', $root . '/module2', 2);

        $this->assertEquals(1, $resolver->getPrecedence('mod1'));
        $this->assertEquals(2, $resolver->getPrecedence('mod2'));
        $this->assertEquals($root . '/module1/files/foo/test1.css', $resolver->resolve('files/foo/test1.css'));

        $this->assertInstanceOf(SubResolver::class, $resolver->setPrecedence('mod1', 2));
        $this->assertInstanceOf(SubResolver::class, $resolver->setPrecedence('mod2', 1));
        $resolver->clearCache();

        $this->assertEquals(2, $resolver->getPrecedence('mod1'));
        $this->assertEquals(1, $resolver->getPrecedence('mod2'));
        $this->assertEquals($root . '/module2/files/foo/test1.css', $resolver->resolve('files/foo/test1.css'));
    }

    public function testSetPrecedenceInvalidModule()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);

        $resolver = new SubResolver('css');
        $resolver->addToSearchPath('mod1', $root . '/module1', 1);

        $resolver->setPrecedence('mod1', 5);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessagE("Unknown module");
        $resolver->setPrecedence('foo', 1);
    }

    public function testGetPrecedenceInvalidModule()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);

        $resolver = new SubResolver('css');
        $resolver->addToSearchPath('mod1', $root . '/module1', 5);

        $this->assertEquals(5, $resolver->getPrecedence('mod1'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown module");
        $resolver->getPrecedence('foo');
    }

    public function testAddNonExistingSearchPath()
    {
        $root = $this->dir;
        $resolver = new SubResolver('test');
        $this->assertEquals('test', $resolver->getName());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Path does not exist");
        $resolver->addToSearchPath('foo', $root . '/bar', 0);
    }

    public function testSortModules()
    {
        $root = $this->dir;
        mkdir($root . '/module1/files/foo', 0770, true);
        mkdir($root . '/module2/files/foo', 0770, true);

        $resolver = new SubResolver('css');
        $resolver->addToSearchPath('mod2', $root . '/module2', 0);
        $resolver->addToSearchPath('mod1', $root . '/module1', 0);

        $path = $resolver->getSearchPath();
        $this->assertEquals([
            'mod1' => $root . '/module1',
            'mod2' => $root . '/module2'
        ], $path);
        
    }

    public function testRouterWillResolveAfterModificationOfSearchPath()
    {
        $root = $this->dir;
        mkdir($root . '/mod1/files', 0777, true);
        $mod = $root . '/mod1/files';
        touch($mod . '/foo.css');

        mkdir($root . '/mod2/files', 0777, true);
        $mod2 = $root . '/mod2/files';
        touch($mod2 . '/bar.css');

        $resolver = new SubResolver('css');
        $resolver->addToSearchPath('mod1', $mod, 0);

        $resolved = $resolver->resolve('/foo.css');
        $this->assertEquals($mod . '/foo.css', $resolved);

        $resolved = $resolver->resolve('/bar.css');
        $this->assertNull($resolved);

        $resolver->addToSearchPath('mod2', $mod2, 0);
        $resolved = $resolver->resolve('/bar.css');
        $this->assertEquals($mod2 . '/bar.css', $resolved);
    }
}
