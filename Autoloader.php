<?php

/*
This is part of WASP, the Web Application Software Platform.
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
namespace WASP\Resolve;

use WASP\Debu;
use Psr\Log\LoggerInterface;

/**
 * Autoloader that implements PSR-0 and PSR-4 standards. By default it will use
 * PSR-4, but registerNS can be instructed to treat a namespace as PSR-0
 * compatible. Alternatively, you can even provide a custom function to use as
 * a loader for a specific namespace.
 */
final class Autoloader
{
    const PSR0 = 0;
    const PSR4 = 4;

    protected static $logger = null;
    private static $loaders = array();

    public static function setLogger(LoggerInterface $logger)
    {
        self::$logger = $logger;
    }
    
    /**
     * Register a namespace with the autoloader.
     * 
     * @param $ns string The namespace to register
     * @param $path string The path where to find classes in this namespace
     * @param $standard mixed Autoloader::PSR4 or Autoloader::PSR0. PSR4 is
     *                        used by default. You may also provide a callback
     *                        function here that will be used as the loader for
     *                        this namespace, rather than the provided PSR-0
     *                        and PSR-4 loaders.
     */
    public function registerNS(string $ns, string $path, $standard = Autoloader::PSR4)
    {
        if (!file_exists($path) || !is_dir($path) || !is_readable($path))
            throw new \RuntimeException("Path $path is not readable");

        if ($standard !== Autoloader::PSR0 && $standard !== Autoloader::PSR4 && !is_callable($standard))
            throw new \RuntimeException("Invalid standard: $standard");

        // Strip a leading namespace separator
        if (substr($ns, 0, 1) === '\\')
            $ns = substr($ns, 1);

        // Make sure there is a trailing namespace separator
        if (substr($ns, -1, 1) !== '\\')
            $ns .= '\\';

        $parts = array_filter(explode('\\', $ns));

        $ref = &self::$loaders;
        $ns_path = "";
        foreach ($parts as $part)
        {
            $ns_path .= $part . '\\';
            if (isset($ref[$part]['loader']))
                throw new \RuntimeException("Cannot override namespace $ns_path");

            if (!isset($ref[$part]))
                $ref[$part] = array();

            $ref = &$ref[$part];
        }

        if (!empty($ref))
            throw new \RuntimeException("Cannot override namespace $ns_path");

        // Register the namespace
        $ref['loader'] = true;
        $ref['std'] = $standard;
        $ref['path'] = $path;
        $ref['ns'] = $ns;
    }
    
    /**
     * Look up file according to PSR0 standard:
     * http://www.php-fig.org/psr/psr-0/
     *
     * @param $ns string The namespace of the class
     * @param $class_name string The class to locatae
     * @param $path string The path where the namespace classes are located
     * @return string The path to the class file. Null when not found
     */
    public static function findPSR0($ns, $class_name, $path)
    {
        $class = str_replace($ns, "", $class_name);
        $class_file = str_replace('\\', DIRECTORY_SEPARATOR, $class);
        $class_file = str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';

        $class_path = $path . DIRECTORY_SEPARATOR . $class_file;
        if (file_exists($class_path))
            return $class_path;
        return null;
    }

    /**
     * Look up file according to PSR4 standard:
     * http://www.php-fig.org/psr/psr-4/
     *
     * @param $ns string The namespace of the class
     * @param $class_name string The class to locatae
     * @param $path string The path where the namespace classes are located
     * @return string The path to the class file. Null when not found
     */
    public static function findPSR4($ns, $class_name, $path)
    {
        $class = str_replace($ns, "", $class_name);
        $class_file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';

        $class_path = $path . DIRECTORY_SEPARATOR . $class_file;
        if (file_exists($class_path))
            return $class_path;
        return null;
    }

    /**
     * The spl_autoloader that loads classes registered namespaces
     */
    public static function autoload($class_name)
    {
        $parts = explode('\\', $class_name);
        $ref = self::$loaders;
        foreach ($parts as $part)
        {
            if (!isset($ref[$part]))
                return;

            $ref = $ref[$part];
            if (isset($ref['loader']))
                break;
        }

        // We have a loader
        $path = "";
        if ($ref['std'] === self::PSR0)
        {
            $path = self::findPSR0($ref['ns'], $class_name, $ref['path']);
        }
        elseif (is_callable($ref['std']))
        {
            try
            { // Make sure we don't throw any exceptions
                $path = $ref['std']($class_name);
            }
            catch (\Throwable $e)
            {}
        }
        else
        {
            // Default to PSR-4
            $path = self::findPSR4($ref['ns'], $class_name, $ref['path']);
        }

        if (!$path)
            return;

        require_once $path;
            
        // Perform some logging when the logger is available
        if (self::$logger)
        {
            if (trait_exists($class_name))
                self::$logger->debug("Loaded trait {0} from path {1}", [$class_name, $path]);
            elseif (interface_exists($class_name))
                self::$logger->debug("Loaded interface {0} from path {1}", [$class_name, $path]);
            elseif (class_exists($class_name))
                self::$logger->debug("Loaded class {0} from path {1}", [$class_name, $path]);
            else
                self::$logger->error("File {0} does not contain class {1}", [$path, $class_name]);
        }
    }
}

// Set up the autoloader
spl_autoload_register(array(Autoloader::class, 'autoload'));
