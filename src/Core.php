<?php
/**
 * Created by PhpStorm.
 * User: egorov
 * Date: 22.10.2015
 * Time: 16:20
 */
namespace samsonphp\router;
use samsonphp\router\exception\NoMatchFound;

/**
 * Main routing logic
 * @package samsonphp\router
 */
class Core
{
    /** @var array Collection of all application routes */
    protected $routes = array();

    /**
     * Create router logic function.
     * This method is recursive
     * @param array|string $dataPointer Collection of routes or route identifier
     * @param string $path Current route tree path
     * @param string $code Final result
     * @param int $level Recursion level
     * @return string Router logic function
     */
    protected function generateIf(&$dataPointer, $path, &$code = '', $level = 1)
    {
        /** @var bool $conditionStarted Flag for creating conditions */
        $conditionStarted = false;

        // Count left spacing to make code looks better
        $tabs = implode('', array_fill(0, $level, ' '));
        foreach ($dataPointer as $placeholder => $data) {
            // Concatenate path
            $newPath = $path . '/' . $placeholder;

            // Add route description as a comment
            $code .= $tabs . '// ' . $newPath . "\n";

            // Count indexes
            $stIndex = strlen($path) + 1;
            $length = strlen($placeholder);

            // Check if placeholder is a route variable
            if (preg_match('/{(?<name>[^}:]+)(\t*:\t*(?<filter>[^}]+))?}/i', $placeholder, $matches)) {
                // Define parameter filter or use generic
                $filter = isset($matches['filter']) ? $matches['filter'] : '[0-9a-z_]+';

                // Generate parameter route parsing, logic is that parameter can have any length so we
                // limit it either by closest brace(}) to the right or to the end of the string
                $code .= $tabs . 'if (preg_match("/(?<' . $matches['name'] . '>'.$filter.'+)/i", substr($path, ' . $stIndex . ',  strpos($path, "/", ' . $stIndex . ') ? strlen($path) - strpos($path, "/", ' . $stIndex . ') : 0), $matches)) {' . "\n";

                // When we have route parameter we do not split logic tree as different parameters can match
                $conditionStarted = false;
            } else { // Generate route placeholder comparison
                $code .= $tabs . ($conditionStarted ? 'else ' : '') . 'if (substr($path, ' . $stIndex . ', ' . $length . ') === "' . $placeholder . '" ) {' . "\n";

                // Flag that condition group has been started
                $conditionStarted = true;
            }

            // This is route end - call handler
            if (is_string($data)) {
                $code .= $tabs . '     return $routes["' . $data . '"]->callback;' . "\n";
            } else { // Go deeper in recursion
                $this->generateIf($data, $newPath, $code, $level + 5);
            }

            // Close current route condition group
            $code .= $tabs . '}' . "\n";
        }

        return $code;
    }

    /**
     * Load all web-application routes
     * @param string $prefix URL path prefix for loaded routes
     * @return array Collection of web-application routes
     */
    protected function loadRoutes($modules, $prefix = '')
    {
        $routes = new RouteCollection();
        foreach ($modules as $moduleID => $module) {
            //if (in_array('samsonphp\router\RouteInterface', class_implements($module, false))) {
            if(is_subclass_of($module, 'samsonphp\router\RouteInterface')) {
                // Try to get module routes using interface method
                $moduleRoutes = $module->routes();
                // There are no routes defined
                if (!sizeof($moduleRoutes)) {
                    // Generate generic routes
                    $moduleRoutes = $this->createGenericRoutes($module);
                }

                $routes = $routes->merge($moduleRoutes);
            }
        }

        // Build multi-dimentional array-tree
        $routeTree = array();
        foreach ($routes as $route) {
            $map = array();
            foreach (explode('/', $route->pattern) as $routePart) {
                // Remove empty parts
                if (isset($routePart{0})) {
                    $map[] = '["' . $routePart . '"]';
                }
            }
            eval('$routeTree'.implode('', $map).'= $route->identifier;');
        }
        $routeTree = array_filter($routeTree);

        $routerCode = 'function __router($path, array & $routes) {'."\n";
        $routerCode.= $this->generateIf($routeTree, '')."\n".'}';
        trace($routerCode);

        //trace($routeTree);
        die('');

        return $routes;
    }

    /**
     * Convert class method signature into pattern with parameters
     * @param object $object Object
     * @param string $method Method name
     * @return string Pattern string with parameters placeholders
     */
    protected function buildMethodParameters($object, $method)
    {
        $pattern = array();

        // Analyze callback arguments
        $reflectionMethod = new \ReflectionMethod($object, $method);
        foreach ($reflectionMethod->getParameters() as $parameter) {
            // Build pattern markers
            $pattern[] = '{'.$parameter->getName().'}';
            //trace($parameter->getDefaultValue(),1);
        }

        return implode('/', $pattern);
    }


    /**
     *
     * @param $module
     * @return array
     */
    protected function createGenericRoutes($module)
    {
        /** @var RouteCollection $routes */
        $routes = new RouteCollection();

        /** @var Route $universalRoute */
        $universalRoute = null;
        /** @var Rotue $baseRoute */
        $baseRoute = null;

        // Iterate class methods
        foreach (get_class_methods($module) as $method) {
            $prefix = '/' . $module->id;
            // Try to find standard controllers
            switch (strtolower($method)) {
                case GenericInterface::CTR_UNI: // Add generic controller action
                    $universalRoute = new Route($prefix . '/*', array($module, $method), $module->id . GenericInterface::CTR_UNI);
                    //trace($this->buildMethodParameters($module, $method), 1);
                    break;
                case GenericInterface::CTR_BASE: // Add base controller action
                    $baseRoute = new Route($prefix . '/?$', array($module, $method), $module->id . GenericInterface::CTR_BASE);
                    break;
                case GenericInterface::CTR_POST:// not implemented
                case GenericInterface::CTR_PUT:// not implemented
                case GenericInterface::CTR_DELETE:// not implemented
                    break;

                // Ignore magic methods
                case '__call':
                case '__wakeup':
                case '__sleep':
                case '__construct':
                case '__destruct':
                case '__set':
                case '__get':
                    break;

                // This is not special controller action
                default:
                    // Match controller action OOP pattern
                    if (preg_match('/^' . GenericInterface::OBJ_PREFIX . '(?<async_>async_)?(?<cache_>cache_)?(?<action>.+)/i', $method, $matches)) {
                        // Build controller action pattern
                        $pattern = $prefix . '/' . $matches['action'].'/'.$this->buildMethodParameters($module, $method);

                        //trace($pattern, 1);

                        // Add route for this controller action
                        $routes->add(
                            new Route(
                                $pattern,
                                array($module, $method), // Route callback
                                $module->id . '_' . $method, // Route identifier
                                Route::METHOD_ANY,
                                $matches[GenericInterface::ASYNC_PREFIX] == GenericInterface::ASYNC_PREFIX ? true : false,
                                $matches[GenericInterface::CACHE_PREFIX] == GenericInterface::CACHE_PREFIX ? true : false
                            )
                        );
                    }
            }
        }

        // Add universal controller action
        if (isset($universalRoute)) {
            $routes->add($universalRoute);
        }

        // Add base controller action
        if (isset($baseRoute)) {
            $routes->add($baseRoute);
            // If we have not found base controller action but we have universal action
        } else if (isset($universalRoute)){
           // Bind its pattern to universal controller callback
            $routes->add(
                new Route(
                    $prefix . '/?$',
                    $universalRoute->callback,
                    $module->id . GenericInterface::CTR_BASE
                )
            );
        }

        return $routes;
    }

    /**
     * Old generic "main_page" route callback searcher to match old logic
     * @param \samson\core\Core $core
     * @param callable|string $callback
     * @deprecated Will be removed in next major version
     * @return array
     *
     */
    public function findGenericDefaultAction(\samson\core\Core & $core, $callback)
    {
        if (is_callable($callback)) {
            return $callback;
        } else if (isset($core->module_stack[$callback])) {
            return array($core->module_stack[$callback], GenericInterface::CTR_UNI);
        }
    }


    /**
     * SamsonPHP core.routing event handler
     *
     * @param \samson\core\Core $core       Pointer to core object
     * @param mixed             $result     Return value as routing result
     * @param string            $default    Default route path
     */
    public function router(\samson\core\Core & $core, & $result, & $path, $default, $async = false)
    {
        // Load core module routes
        $routes = $this->loadRoutes($core->module_stack);

        try {
            /** @var Route $route Match route in routes collection or use default main_page route */
            $route = $path === '/' ? new Route('/', $this->findGenericDefaultAction($core, $default), 'main_page') : $routes->match($path);
        } catch(NoMatchFound $e) { // No matching routes was found
            return $result = A_FAILED;
        }

        if ($route !== false) {

            // Get object from callback & set it as current active core module
            $core->active($route->callback[0]);

            //trace($route->pattern, 1);

            // Route parameters
            $parameters = array();

            // Check if request has special asynchronous markers
            if ($_SERVER['HTTP_ACCEPT'] == '*/*' || isset($_SERVER['HTTP_SJSASYNC']) || isset($_POST['SJSASYNC'])) {
                // If this route is asynchronous
                if ($route->async) {

                    // Perform controller action
                    $result = is_callable($route->callback) ? call_user_func_array($route->callback, $parameters) : A_FAILED;

                    // Anyway convert event result to array
                    if (!is_array($result)) $result = array($result);

                    // If event successfully completed
                    if (!isset($result['status']) || !$result['status']) {
                        // Handle event chain fail
                        $result['message'] = "\n" . 'Event failed: ' . $route->identifier;

                        // Add event result array to results collection
                        //$result = array_merge($event_result, $_event_result);

                        // Stop event-chain execution
                        //break;
                    } // Add event result array to results collection
                    //else $result = array_merge($result, $_event_result);

                    // If at least one event has been executed
                    if (sizeof($result)) {
                        // Set async response
                        $core->async(true);

                        // Send success status
                        header("HTTP/1.0 200 Ok");

                        // Encode event result as json object
                        echo json_encode($result, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

                        return A_SUCCESS;
                    }
                }

            } else { // Synchronous controller
                // Perform controller action
                $result = is_callable($route->callback) ? call_user_func_array($route->callback, $parameters) : A_FAILED;

            }

            // If this route needs caching
            if ($route->cache) {
                $core->cached();
            }

            // Stop candidate search
            $result = !isset($result) ? A_SUCCESS : $result;
        }
    }
}
