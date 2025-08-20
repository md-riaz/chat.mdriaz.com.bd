<?php

class Framework
{

    private static $defaultController = 'Home';
    private static $defaultMethod = 'index';
    private static $currentController;
    private static $currentMethod;
    private static $currentParams = [];

    private static $registered = false;

    public static function run()
    {

        self::init();

        self::autoload();

        self::dispatch();
    }

    private static function init()
    {
        // Detect CLI mode
        $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

        if (!$isCli) {
            header('Access-Control-Allow-Origin: *');
            header("Access-Control-Allow-Headers: authorization, content-type");
            header("Access-Control-Max-Age: 600");
            header("Content-type: application/json; charset=utf-8");

            // Handle OPTIONS preflight request
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                header("HTTP/1.1 200 OK");
                exit();
            }
        }

        // Define path constants

        define("DS", DIRECTORY_SEPARATOR);

        define("ROOT", getcwd() . DS);

        define("APP_PATH", ROOT . 'application' . DS);

        define("FRAMEWORK_PATH", ROOT . "framework" . DS);


        //Require config file
        require ROOT . 'configuration/config.php';

        // Load Composer autoloader if present
        if (file_exists(ROOT . 'vendor/autoload.php')) {
            require ROOT . 'vendor/autoload.php';
        }

        if (APP_MODE === "Debug") {

            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        } else {

            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            error_reporting(0);
        }


        //Set TimeZone
        date_default_timezone_set(TIMEZONE);

        define("TIMESTAMP", date("Y-m-d H:i:s"));
    }

    private static function autoload()
    {
        if (self::$registered) return;
        self::$registered = true;

        spl_autoload_register(function ($class) {
            // Prevent overriding built-in or already-loaded classes
            if (class_exists($class, false)) return;

            $file = self::resolveClassFile($class);

            if ($file && file_exists($file)) {
                require $file;
            } else {
                throw new Exception("Class '$class' not found in file '$file'. Please check the class name and file path.");
            }
        });
    }

    private static function resolveClassFile(string $class): ?string
    {
        $baseDirs = [
            'App\\' => APP_PATH,
            'Framework\\Core\\' => FRAMEWORK_PATH . 'core/',
            'Framework\\Queue\\' => FRAMEWORK_PATH . 'queue/',
        ];

        static $composerPackages = null;
        static $dynamicLibraries = [];

        // Auto-discover Composer packages once
        if ($composerPackages === null) {
            $composerPackages = self::discoverComposerPackages();
        }

        // Check discovered Composer packages first (highest priority)
        foreach ($composerPackages as $namespace => $paths) {
            if (strpos($class, $namespace) === 0) {
                $relativeClass = substr($class, strlen($namespace));
                $relativePath = str_replace('\\', '/', $relativeClass) . '.php';
                
                // Try each path for this namespace (some packages have multiple source directories)
                foreach ($paths as $basePath) {
                    $filePath = $basePath . $relativePath;
                    if (file_exists($filePath)) {
                        return $filePath;
                    }
                }
            }
        }

        // Original framework base directories
        foreach ($baseDirs as $prefix => $baseDir) {
            $prefixLength = strlen($prefix);
            if (strncmp($prefix, $class, $prefixLength) === 0) {
                $relativeClass = substr($class, $prefixLength);
                return $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            }
        }

        // Legacy dynamic library detection (for simple structures without composer.json)
        $librariesDir = FRAMEWORK_PATH . 'libraries/';
        $namespaceParts = explode('\\', $class);
        
        if (count($namespaceParts) > 1) {
            $firstNamespace = array_shift($namespaceParts);

            if (!isset($dynamicLibraries[$firstNamespace])) {
                $libraryDir = $librariesDir . $firstNamespace . '/src/';
                $dynamicLibraries[$firstNamespace] = is_dir($libraryDir) ? $libraryDir : false;
            }

            if ($dynamicLibraries[$firstNamespace]) {
                $relativeClass = implode('/', $namespaceParts);
                return $dynamicLibraries[$firstNamespace] . $relativeClass . '.php';
            }
        }

        // Fallback: non-namespaced legacy path
        return FRAMEWORK_PATH . 'libraries/' . str_replace('\\', '/', $class) . '.php';
    }

    /**
     * Auto-discover Composer packages by scanning composer.json files
     * @return array Array of namespace => paths mappings
     */
    private static function discoverComposerPackages(): array
    {
        $packages = [];
        $librariesDir = FRAMEWORK_PATH . 'libraries/';
        
        if (!is_dir($librariesDir)) {
            return $packages;
        }

        // Scan for vendor/package structure (e.g., cboden/ratchet, react/socket)
        $vendors = glob($librariesDir . '*', GLOB_ONLYDIR);
        
        foreach ($vendors as $vendorDir) {
            if (!is_dir($vendorDir)) continue;
            
            $vendorName = basename($vendorDir);
            $packageDirs = glob($vendorDir . '/*', GLOB_ONLYDIR);
            
            foreach ($packageDirs as $packageDir) {
                if (!is_dir($packageDir)) continue;
                
                $composerJsonPath = $packageDir . '/composer.json';
                
                if (file_exists($composerJsonPath)) {
                    $packageData = self::parseComposerAutoload($composerJsonPath, $packageDir);
                    $packages = array_merge($packages, $packageData);
                }
            }
        }

        // Also scan for direct packages in libraries root (e.g., PHPMailer, FCM)
        $directPackages = glob($librariesDir . '*', GLOB_ONLYDIR);
        foreach ($directPackages as $packageDir) {
            if (!is_dir($packageDir)) continue;
            
            $composerJsonPath = $packageDir . '/composer.json';
            
            if (file_exists($composerJsonPath)) {
                $packageData = self::parseComposerAutoload($composerJsonPath, $packageDir);
                $packages = array_merge($packages, $packageData);
            }
        }

        return $packages;
    }

    /**
     * Parse composer.json autoload configuration
     * @param string $composerJsonPath Path to composer.json file
     * @param string $packageDir Package base directory
     * @return array Namespace mappings
     */
    private static function parseComposerAutoload(string $composerJsonPath, string $packageDir): array
    {
        $mappings = [];
        
        try {
            $composerData = json_decode(file_get_contents($composerJsonPath), true);
            
            if (!$composerData || !is_array($composerData)) {
                return $mappings;
            }

            // Handle PSR-4 autoloading
            if (isset($composerData['autoload']['psr-4']) && is_array($composerData['autoload']['psr-4'])) {
                foreach ($composerData['autoload']['psr-4'] as $namespace => $srcPaths) {
                    $srcPaths = is_array($srcPaths) ? $srcPaths : [$srcPaths];
                    
                    foreach ($srcPaths as $srcPath) {
                        $fullPath = $packageDir . '/' . ltrim(rtrim($srcPath, '/'), '/');
                        if ($srcPath === '') {
                            $fullPath = $packageDir; // Root directory mapping
                        }
                        $fullPath = rtrim($fullPath, '/') . '/';
                        
                        if (is_dir($fullPath)) {
                            if (!isset($mappings[$namespace])) {
                                $mappings[$namespace] = [];
                            }
                            $mappings[$namespace][] = $fullPath;
                        }
                    }
                }
            }

            // Handle PSR-0 autoloading (legacy)
            if (isset($composerData['autoload']['psr-0']) && is_array($composerData['autoload']['psr-0'])) {
                foreach ($composerData['autoload']['psr-0'] as $namespace => $srcPaths) {
                    $srcPaths = is_array($srcPaths) ? $srcPaths : [$srcPaths];
                    
                    foreach ($srcPaths as $srcPath) {
                        $fullPath = $packageDir . '/' . ltrim(rtrim($srcPath, '/'), '/') . '/';
                        
                        if (is_dir($fullPath)) {
                            // For PSR-0, we need to append the namespace path
                            $namespacePath = str_replace('\\', '/', trim($namespace, '\\'));
                            $finalPath = $fullPath . $namespacePath . '/';
                            
                            if (is_dir($finalPath)) {
                                if (!isset($mappings[$namespace])) {
                                    $mappings[$namespace] = [];
                                }
                                $mappings[$namespace][] = $finalPath;
                            }
                        }
                    }
                }
            }

            // Handle classmap autoloading
            if (isset($composerData['autoload']['classmap']) && is_array($composerData['autoload']['classmap'])) {
                foreach ($composerData['autoload']['classmap'] as $path) {
                    $fullPath = $packageDir . '/' . ltrim($path, '/');
                    
                    if (is_dir($fullPath)) {
                        // For classmap directories, we'll add them as fallback paths
                        // This is less precise but better than nothing
                        $mappings[''] = $mappings[''] ?? [];
                        $mappings[''][] = rtrim($fullPath, '/') . '/';
                    }
                }
            }

            // Handle files autoloading (functions, etc.)
            if (isset($composerData['autoload']['files']) && is_array($composerData['autoload']['files'])) {
                foreach ($composerData['autoload']['files'] as $filePath) {
                    $fullFilePath = $packageDir . '/' . ltrim($filePath, '/');
                    
                    if (file_exists($fullFilePath)) {
                        // Auto-require the file if it hasn't been included yet
                        $realPath = realpath($fullFilePath);
                        if ($realPath && !in_array($realPath, get_included_files())) {
                            require_once $realPath;
                        }
                    }
                }
            }

        } catch (Exception $e) {
            // Silently ignore JSON parsing errors and continue
            error_log("Framework: Error parsing composer.json at {$composerJsonPath}: " . $e->getMessage());
        }

        return $mappings;
    }


    private static function dispatch()
    {

        // Detect CLI mode and skip dispatch if CLI
        $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
        if ($isCli) {
            echo "Running in CLI mode: no HTTP dispatch.\n";
            return;
        }

        // Safely retrieve REQUEST_URI
        $REQUEST_PATH = explode('?', $_SERVER['REQUEST_URI']);
        $URL = ltrim($REQUEST_PATH[0], "index.php");
        $URL = explode('/', rtrim(ltrim($URL, "/"), "/"));


        //Define Section
        $app_selection = json_decode(file_get_contents(ROOT . "configuration/section.json"), true);

        if (in_array($URL[0], $app_selection)) {

            define("APP_SECTION", ucfirst($URL[0]) . DS);

            unset($URL[0]);

            $actual_url = implode("/", $URL);

            $URL = explode('/', $actual_url);
        } else {

            define("APP_SECTION", "");
        }


        // Define Controller
        if (!empty($URL[0])) {
            $controllerFile = APP_PATH . APP_SECTION . ucfirst($URL[0]) . '.php';
            if (file_exists($controllerFile)) {
                self::$currentController = ucfirst($URL[0]);
                unset($URL[0]);
            }
        } else {
            self::$currentController = self::$defaultController;
            $controllerFile = APP_PATH . APP_SECTION . self::$currentController . '.php';
        }

        // Prepare the controller class name
        $controllerClass = APP_SECTION ? 'App\\' . (str_replace(DS, '\\', APP_SECTION)) . self::$currentController : 'App\\' . self::$currentController;
        $controllerClassPath = APP_PATH . APP_SECTION . self::$currentController . '.php';
        $controllerClassPath = str_replace('\\', DS, $controllerClassPath);

        // Check if the controller class exists
        if (!file_exists($controllerClassPath) || !class_exists($controllerClass)) {
            echo json_encode(['error' => 404, 'msg' => 'Controller not found.']);
            exit();
        }

        self::$currentController = new $controllerClass();

        // Define Method
        if (isset($URL[1]) && method_exists(self::$currentController, $URL[1])) {
            self::$currentMethod = $URL[1];
            unset($URL[1]);
        } else {
            self::$currentMethod = self::$defaultMethod;
        }

        // Check if the method exists in the controller
        if (!method_exists(self::$currentController, self::$currentMethod)) {
            echo json_encode(['error' => 404, 'msg' => 'Method not found.']);
            exit();
        }

        $controller_parts = explode("\\", get_class(self::$currentController));
        $current_controller_name = end($controller_parts);

        // Check Permission
        if (!Framework\Core\Auth::checkPermission(APP_SECTION, $current_controller_name, self::$currentMethod)) {
            echo json_encode(['error' => 403, 'msg' => 'Unauthorized access']);
            exit();
        }

        //defune Current URL Path for active menu
        // define("CUR_REQUEST_PATH", APP_SECTION . $current_controller_name . '/' . self::$currentMethod);
        define("CUR_REQUEST_PATH", APP_SECTION . $current_controller_name . '/' . self::$currentMethod);

        //Define Parameters
        self::$currentParams = $URL ? array_values($URL) : [];


        call_user_func_array([self::$currentController, self::$currentMethod], self::$currentParams);
    }
}
