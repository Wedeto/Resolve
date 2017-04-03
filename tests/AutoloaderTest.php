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
use Wedeto\Util\Dictionary;
use Wedeto\Log\Logger;
use Wedeto\Log\MemLogger;

/**
 * @covers Wedeto\Resolve\Autoloader
 */
final class AutoloaderTest extends TestCase
{
    private $dir;

    public function setUp()
    {
        // Make the cache use a virtual test path
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('cachedir'));
        $this->dir = vfsStream::url('cachedir');
        Cache::setCachePath($this->dir);

        Autoloader::setLogger(Logger::getLogger(Autoloader::class));
    }

    public function testLoggers()
    {
        $log = Logger::getLogger("Foo.Bar");
        Autoloader::setLogger($log);

        $this->assertEquals($log, Autoloader::getLogger());

        $log2 = Logger::getLogger(Autoloader::class);
        Autoloader::setLogger($log2);

        $this->assertEquals($log2, Autoloader::getLogger());
    }

    public function testGetLoaders()
    {
        $root = $this->dir;
        mkdir($root . '/vendor1/src', 0777, true);
        mkdir($root . '/vendor2/lib', 0777, true);

        $loader = new Autoloader;
        $loader->registerNS('Foo\\Bar\\', $root . '/vendor1/src');
        $loader->registerNS('Foo\\', $root . '/vendor2/lib');

        $paths = $loader->getClassLoaders('Foo\\Bar\\Baz');

        $this->assertEquals(2, count($paths));
        $ns = array();
        foreach ($paths as $p)
            $ns[] = $p['ns'];

        $this->assertContains('Foo\\', $ns);
        $this->assertContains('Foo\\Bar\\', $ns);
    }

    public function testBuildCache()
    {
        $root = $this->dir;

        mkdir($root . '/vendor1/src/Foo/Bar', 0777, true);
        mkdir($root . '/vendor2/lib/Foo/Baz', 0777, true);
        mkdir($root . '/vendor3/classes/Sub', 0777, true);

        touch($root . '/vendor1/src/Foo/Bar/TestClass11.php');
        touch($root . '/vendor1/src/Foo/Bar/TestClass12.php');
        touch($root . '/vendor1/src/Foo/Bar/TestClass13.php');

        touch($root . '/vendor2/lib/Foo/Baz/TestClass21.php');
        touch($root . '/vendor2/lib/Foo/Baz/TestClass22.php');
        touch($root . '/vendor2/lib/Foo/Baz/TestClass23.php');

        touch($root . '/vendor3/classes/TestClass31.php');
        touch($root . '/vendor3/classes/TestClass32.php');
        touch($root . '/vendor3/classes/TestClass33.php');

        touch($root . '/vendor3/classes/Sub/TestClass41.php');
        touch($root . '/vendor3/classes/Sub/TestClass42.php');
        touch($root . '/vendor3/classes/Sub/TestClass43.php');
        
        $loader = new Autoloader;
        $loader->registerNS('Foo\\Bar\\', $root . '/vendor1/src/Foo/Bar');
        $loader->registerNS('Foo\\Baz\\', $root . '/vendor2/lib/Foo/Baz');
        $loader->registerNS('Foobar\\', $root . '/vendor3/classes');

        $cache = new Cache('resolve');
        $loader->setCache($cache);
        $this->assertEquals($cache, $loader->getCache());

        $loader->buildCache();

        $paths = $cache->get('classpaths');
        $this->assertTrue($paths->has('Foo\\Bar\\TestClass11', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foo\\Bar\\TestClass12', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foo\\Bar\\TestClass13', Dictionary::TYPE_STRING));

        $this->assertTrue($paths->has('Foo\\Baz\\TestClass21', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foo\\Baz\\TestClass22', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foo\\Baz\\TestClass23', Dictionary::TYPE_STRING));

        $this->assertTrue($paths->has('Foobar\\TestClass31', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foobar\\TestClass32', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foobar\\TestClass33', Dictionary::TYPE_STRING));

        $this->assertTrue($paths->has('Foobar\\Sub\\TestClass41', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foobar\\Sub\\TestClass42', Dictionary::TYPE_STRING));
        $this->assertTrue($paths->has('Foobar\\Sub\\TestClass43', Dictionary::TYPE_STRING));
    }

    public function testBuildCacheDuplicateClasses()
    {
        $root = $this->dir;

        mkdir($root . '/vendor1/src', 0777, true);
        mkdir($root . '/vendor2/src/Bar', 0777, true);

        touch($root . '/vendor1/src/TestClass1.php');
        touch($root . '/vendor2/src/Bar/TestClass1.php');
        
        $loader = new Autoloader;
        $loader->registerNS('Foo\\Bar\\', $root . '/vendor1/src');
        $loader->registerNS('Foo\\', $root . '/vendor2/src');

        $cache = new Cache('resolve');
        $loader->setCache($cache);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate class definition");
        $loader->buildCache();
    }

    public function testBuildCacheWithPSR0Namespace()
    {
        $root = $this->dir;

        mkdir($root . '/vendor1/src', 0777, true);
        mkdir($root . '/vendor2/src/Bar', 0777, true);

        touch($root . '/vendor1/src/TestClass1.php');
        touch($root . '/vendor2/src/Bar/TestClass1.php');
        
        $loader = new Autoloader;
        $loader->registerNS('Foo\\Bar\\', $root . '/vendor1/src');
        $loader->registerNS('Foo\\', $root . '/vendor2/src', Autoloader::PSR0);

        $cache = new Cache('resolve');
        $loader->setCache($cache);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("pure PSR4-based namespaces");
        $loader->buildCache();
    }

    public function testBuildCacheWithoutCache()
    {
        $root = $this->dir;

        $loader = new Autoloader;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Cannot build cache without a cache instance");
        $loader->buildCache();
    }


    public function testAutoloaderFind()
    {
        $loader = new Autoloader;
        $loader_class = Autoloader::findComposerAutoloader();
        $this->assertEquals("ComposerAutoloader", substr($loader_class, 0, 18));
    }

    public function testAutoloaderImport()
    {
        $root = $this->dir;
        mkdir($root . '/vendor/composer', 0777, true);

        $autoload_name = 'ComposerAutoloader123456789foobar';
        $autoload_path = $root . '/vendor/composer/autoload_real.php';
        $code = <<<CODE
<?php
class ComposerAutoloader123456789foobar
{}
?>
CODE;
        file_put_contents($autoload_path, $code);

        $autoload_psr0_file = $root . '/vendor/composer/autoload_psr0.php';
        $autoload_psr4_file = $root . '/vendor/composer/autoload_psr4.php';

        $p0dir1 = $root . '/vendor/foo/src';
        $p0dir2 = $root . '/vendor/bar/src';

        $p4dir1 = $root . '/vendor/foo4/src';
        $p4dir2 = $root . '/vendor/bar4/src';
        $code_psr0 = <<<CODE
<?php
return array(
    'Foo\\\\' => ['$p0dir1'],
    'Bar\\\\' => ['$p0dir2']
);
CODE;

        file_put_contents($autoload_psr0_file, $code_psr0);

        $code_psr4 = <<<CODE
<?php
return array(
    'Foo\\\\' => ['$p4dir1'],
    'Bar\\\\' => ['$p4dir2']
);
CODE;

        file_put_contents($autoload_psr4_file, $code_psr4);

        mkdir($p0dir1, 0777, true);
        mkdir($p0dir2, 0777, true);
        mkdir($p4dir1, 0777, true);
        mkdir($p4dir2, 0777, true);

        require_once $autoload_path;
        $this->assertTrue(class_exists($autoload_name));

        $a = new Autoloader;
        $a->importComposerAutoloaderConfiguration($autoload_name);

        $loaders = $a->getClassLoaders('Foo\\MyClass');

        foreach ($loaders as $loader)
        {
            $match_psr0 = $loader['path'] = $p0dir1 && $loader['std'] == Autoloader::PSR0 && $loader['ns'] === "Foo\\";
            $match_psr4 = $loader['path'] = $p4dir1 && $loader['std'] == Autoloader::PSR4 && $loader['ns'] === "Foo\\";

            $this->assertTrue($match_psr0 xor $match_psr4);
        }
    }

    public function testInvalidPaths()
    {
        $root = $this->dir;

        $loader = new Autoloader;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("is not readable");
        $loader->registerNS('Foo\\Bar\\', $root . '/non/existing');
    }

    public function testInvalidLoaderType()
    {
        $root = $this->dir;

        $loader = new Autoloader;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid standard: foo");
        $loader->registerNS('Foo\\Bar\\', $root, 'foo');
    }

    public function testLoaderWithEmptyNS()
    {
        $root = $this->dir;

        $expected = $root . '/vendor1/src/Foo/Bar/Baz.php';
        mkdir($root . '/vendor1/src/Foo/Bar', 0777, true);
        touch($expected);

        $loader = new Autoloader;
        $loader->registerNS('', $root . '/vendor1/src', Autoloader::PSR4);

        $path = Autoloader::findPSR4('', 'Foo\\Bar\\Baz', $root . '/vendor1/src');
        $this->assertEquals($expected, $path);

        $path = Autoloader::findPSR4('', 'Foo\\Bar\\Boo', $root . '/vendor1/src');
        $this->assertNull($path);
    }

    public function testPSR4Loader()
    {
        $root = $this->dir;

        $expected = $root . '/vendor1/src/Foo/Bar/Baz.php';
        mkdir($root . '/vendor1/src/Foo/Bar', 0777, true);
        touch($expected);

        $path = Autoloader::findPSR4('Foo\\Bar\\', 'Foo\\Bar\\Baz', $root . '/vendor1/src/Foo/Bar');
        $this->assertEquals($expected, $path);

        $path = Autoloader::findPSR4('Foo\\Bar\\', 'Foo\\Bar\\Boo', $root . '/vendor1/src/Foo/Bar');
        $this->assertNull($path);
    }

    public function testPSR0Loader()
    {
        $root = $this->dir;

        $expected = $root . '/vendor1/src/Foo/Bar/Baz.php';
        mkdir($root . '/vendor1/src/Foo/Bar', 0777, true);
        touch($expected);

        $path = Autoloader::findPSR0('Foo\\', 'Foo\\Bar_Baz', $root . '/vendor1/src/Foo');
        $this->assertEquals($expected, $path);

        $path = Autoloader::findPSR0('Foo\\', 'Foo\\Bar\\Baz', $root . '/vendor1/src/Foo');
        $this->assertEquals($expected, $path);

        $path = Autoloader::findPSR0('Foo\\', 'Foo\\Bar\\Boo', $root . '/vendor1/src/Foo');
        $this->assertNull($path);
    }

    private function writeClass($file, $class, $tp = 'class')
    {
        $parts = explode('\\', $class);
        $cln = array_pop($parts);
        $ns = implode('\\', $parts);

        $code = <<<CODE
<?php
namespace $ns;
$tp $cln
{}

CODE;

        if ($tp === "interface")
            $code .= <<<CODE
class {$cln}_impl implements {$cln}
{}

CODE;
        file_put_contents($file, $code);
    }

    /**
     * @covers Wedeto\Resolve\Autoloader::autoload
     */
    public function testAutoloadWithoutCache()
    {
        $root = $this->dir;

        mkdir($root . '/vendor/src', 0777, true);
        mkdir($root . '/vendor/src/Sub', 0777, true);
        mkdir($root . '/vendor2/src/NS2', 0777, true);

        $this->writeClass($root . '/vendor/src/Foo.php', 'My\\NS\\Foo');
        $this->writeClass($root . '/vendor/src/Sub/Bar.php', 'My\\NS\\Sub\\Bar');
        $this->writeClass($root . '/vendor2/src/NS2/Baz.php', 'My\\NS2_Baz');

        $a = new Autoloader;
        $a->registerNS('My\\NS\\', $root . '/vendor/src');
        $a->registerNS('My', $root . '/vendor2/src', Autoloader::PSR0);

        $this->assertFalse(class_exists('My\\NS\\Foo', false));
        $a->autoload('My\\NS\\Foo');
        $this->assertTrue(class_exists('My\\NS\\Foo', false));

        $this->assertFalse(class_exists('My\\NS\\Sub\\Bar', false));
        $a->autoload('My\\NS\\Sub\\Bar');
        $this->assertTrue(class_exists('My\\NS\\Sub\\Bar', false));

        $this->assertFalse(class_exists('My\\NS2_Baz', false));
        $a->autoload('My\\NS2_Baz');
        $this->assertTrue(class_exists('My\\NS2_Baz', false));

        $this->writeClass($root . '/vendor/src/FooIFace.php', 'My\\NS\\FooIFace', 'interface');
        $this->assertFalse(class_exists('My\\NS\\FooIFace', false));
        $a->autoload('My\\NS\\FooIFace');
        $this->assertTrue(interface_exists('My\\NS\\FooIFace'));
    }

    public function testAutoloadWithCache()
    {
        $cache = new Cache('Autoloader-test'); 
        $root = $this->dir;

        mkdir($root . '/vendor3/src', 0777, true);
        mkdir($root . '/vendor3/src/Sub', 0777, true);
        mkdir($root . '/vendor4/src/NS2', 0777, true);

        $this->writeClass($root . '/vendor3/src/Foo.php', 'My2\\NS\\Foo');
        $this->writeClass($root . '/vendor3/src/Sub/Bar.php', 'My2\\NS\\Sub\\Bar');
        $this->writeClass($root . '/vendor4/src/NS2/Baz.php', 'My2\\NS2_Baz');

        $a = new Autoloader;
        $a->setCache($cache);
        $a->registerNS('My2\\NS\\', $root . '/vendor3/src');
        $a->registerNS('My2', $root . '/vendor4/src', Autoloader::PSR0);

        $this->assertFalse(class_exists('My2\\NS\\Foo', false));
        $a->autoload('My2\\NS\\Foo');
        $this->assertTrue(class_exists('My2\\NS\\Foo', false));

        $this->assertFalse(class_exists('My2\\NS\\Sub\\Bar', false));
        $a->autoload('My2\\NS\\Sub\\Bar');
        $this->assertTrue(class_exists('My2\\NS\\Sub\\Bar', false));

        $this->assertFalse(class_exists('My2\\NS2_Baz', false));
        $a->autoload('My2\\NS2_Baz');
        $this->assertTrue(class_exists('My2\\NS2_Baz', false));

        // Attempt to load non-existing class
        $this->assertFalse(interface_exists('My2\\NS\\FooIFace', false));
        $a->autoload('My2\\NS\\FooIFace');
        $this->assertFalse(interface_exists('My2\\NS\\FooIFace', false));

        // Authorative mode is disabled, so after creating the class, it should be loaded
        $this->writeClass($root . '/vendor3/src/FooIFace.php', 'My2\\NS\\FooIFace', 'interface');
        $a->autoload('My2\\NS\\FooIFace');
        $this->assertTrue(interface_exists('My2\\NS\\FooIFace'));
    }

    public function testAutoloadWithAuthorativeEnabled()
    {
        $cache = new Cache('Autoloader-test'); 
        $root = $this->dir;

        mkdir($root . '/vendor5/src', 0777, true);
        mkdir($root . '/vendor5/src/Sub', 0777, true);

        $this->writeClass($root . '/vendor5/src/Foo.php', 'My3\\NS\\Foo');
        $this->writeClass($root . '/vendor5/src/Sub/Bar.php', 'My3\\NS\\Sub\\Bar');

        $a = new Autoloader;
        $a->setCache($cache);
        $this->assertFalse($a->getAuthorative());
        $a->setAuthorative(true);
        $this->assertTrue($a->getAuthorative());
        $a->registerNS('My3\\NS\\', $root . '/vendor5/src');

        $this->assertFalse(class_exists('My3\\NS\\Foo', false));
        $a->autoload('My3\\NS\\Foo');
        $this->assertTrue(class_exists('My3\\NS\\Foo', false));

        $this->assertFalse(class_exists('My3\\NS\\Sub\\Bar', false));
        $a->autoload('My3\\NS\\Sub\\Bar');
        $this->assertTrue(class_exists('My3\\NS\\Sub\\Bar', false));

        // Attempt to load non-existing interface
        $this->assertFalse(class_exists('My3\\NS\\FooIFace', false));
        $a->autoload('My3\\NS\\FooIFace');
        $this->assertFalse(interface_exists('My3\\NS\\FooIFace'));

        // Failure should be cached now, so creating the class will not work
        $this->writeClass($root . '/vendor5/src/FooIFace.php', 'My3\\NS\\FooIFace', 'interface');
        $a->autoload('My3\\NS\\FooIFace');
        $this->assertFalse(interface_exists('My3\\NS\\FooIFace'));
    }

    public function testAutoloadWithAuthorativeEnabledAfterBuildCache()
    {
        $cache = new Cache('Autoloader-test'); 
        $root = $this->dir;

        mkdir($root . '/vendor6/src', 0777, true);
        mkdir($root . '/vendor6/src/Sub', 0777, true);

        $this->writeClass($root . '/vendor6/src/Foo.php', 'My4\\NS\\Foo');
        $this->writeClass($root . '/vendor6/src/Sub/Bar.php', 'My4\\NS\\Sub\\Bar');

        $a = new Autoloader;
        $a->setCache($cache);
        $this->assertFalse($a->getAuthorative());
        $a->setAuthorative(true);
        $this->assertTrue($a->getAuthorative());
        $a->registerNS('My4\\NS\\', $root . '/vendor6/src');

        // Build the cache
        $a->buildCache();

        // Now create another interface. Will not be present in cache
        $this->writeClass($root . '/vendor6/src/FooIFace.php', 'My4\\NS\\FooIFace', 'interface');

        // Test classes existing when the cache was built
        $this->assertFalse(class_exists('My4\\NS\\Foo', false));
        $a->autoload('My4\\NS\\Foo');
        $this->assertTrue(class_exists('My4\\NS\\Foo', false));

        $this->assertFalse(class_exists('My4\\NS\\Sub\\Bar', false));
        $a->autoload('My4\\NS\\Sub\\Bar');
        $this->assertTrue(class_exists('My4\\NS\\Sub\\Bar', false));

        // The interface was created after the cache, so it should not be loaded
        $this->assertFalse(class_exists('My4\\NS\\FooIFace', false));
        $a->autoload('My4\\NS\\FooIFace');
        $this->assertFalse(interface_exists('My4\\NS\\FooIFace'));

        // A rebuild of the cache should help there
        $a->buildCache();
        $a->autoload('My4\\NS\\FooIFace');
        $this->assertTrue(interface_exists('My4\\NS\\FooIFace'));
    }

    public function testAutoloadWithExistingClass()
    {
        $a = new Autoloader;
        $a->autoload(static::class);
        $this->assertTrue(class_exists(static::class));
    }

    public function testAutoloadWithTraitInterfaceAndClass()
    {
        $root = $this->dir;

        mkdir($root . '/vendor7/src', 0777, true);
        mkdir($root . '/vendor7/src/Sub', 0777, true);

        $this->writeClass($root . '/vendor7/src/Foo.php', 'My5\\NS\\Foo', "class");
        $this->writeClass($root . '/vendor7/src/Bar.php', 'My5\\NS\\Bar', "trait");
        $this->writeClass($root . '/vendor7/src/Baz.php', 'My5\\NS\\Baz', "interface");

        // Incorrectly names class
        $this->writeClass($root . '/vendor7/src/Boobaz.php', 'My5\\NS\\BarBaz', "class");

        $a = new Autoloader;
        $a->registerNS('My5\\NS\\', $root . '/vendor7/src');

        // Test classes existing when the cache was built
        $this->assertFalse(class_exists('My5\\NS\\Foo', false));
        $a->autoload('My5\\NS\\Foo');
        $this->assertTrue(class_exists('My5\\NS\\Foo', false));

        $this->assertFalse(trait_exists('My5\\NS\\Bar', false));
        $a->autoload('My5\\NS\\Bar');
        $this->assertTrue(trait_exists('My5\\NS\\Bar', false));

        $this->assertFalse(interface_exists('My5\\NS\\Baz', false));
        $a->autoload('My5\\NS\\Baz');
        $this->assertTrue(interface_exists('My5\\NS\\Baz', false));
        
        // Check loading incorrectly named file
        $this->assertFalse(class_exists('My5\\NS\\Boobaz', false));
        $a->autoload('My5\\NS\\Boobaz');
        $this->assertFalse(class_exists('My5\\NS\\Boobaz', false));
    }

    public function testAutoloadWithCustomAutoloader()
    {
        $root = $this->dir;

        mkdir($root . '/vendor8/src', 0777, true);
        $fb_path = $root . '/vendor8/src/FooBar.php';
        $this->writeClass($fb_path, 'My6\\NS\\BarBaz', "class");

        $a = new Autoloader;
        $a->registerNS('My6\\NS\\', $root . '/vendor8/src', function ($cl) use ($fb_path) {
            if ($cl === "My6\\NS\\BarBaz")
                require_once $fb_path;
        });

        // Test classes existing when the cache was built
        $this->assertFalse(class_exists('My6\\NS\\BarBaz', false));
        $a->autoload('My6\\NS\\BarBaz');
        $this->assertTrue(class_exists('My6\\NS\\BarBaz', false));

        // Test with non-existing class
        $this->assertFalse(class_exists('My6\\NS\\BooBaz', false));
        $a->autoload('My6\\NS\\BooBaz');
        $this->assertFalse(class_exists('My6\\NS\\BooBaz', false));
    }

    public function testAutoloadWithCustomAutoloaderThrowingExceptions()
    {
        $root = $this->dir;

        mkdir($root . '/vendor9/src', 0777, true);
        $a = new Autoloader;
        $a->registerNS('My7\\NS\\', $root . '/vendor9/src', function ($cl) {
            if ($cl === "My7\\NS\\BarBaz")
                throw new \RuntimeException("Foobarred");
        });

        // Test classes existing when the cache was built
        $this->assertFalse(class_exists('My7\\NS\\BarBaz', false));
        $a->autoload('My7\\NS\\BarBaz');
        $this->assertFalse(class_exists('My7\\NS\\BarBaz', false));
    }
}
