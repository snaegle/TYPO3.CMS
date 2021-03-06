<?php
namespace TYPO3\CMS\Backend\Http;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\Dispatcher;
use TYPO3\CMS\Core\Http\DispatcherInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Dispatcher which resolves a route to call a controller and method (but also a callable)
 */
class RouteDispatcher extends Dispatcher implements DispatcherInterface
{
    /**
     * Main method to resolve the route and checks the target of the route, and tries to call it.
     *
     * @param ServerRequestInterface $request the current server request
     * @param ResponseInterface $response the prepared response
     * @return ResponseInterface the filled response by the callable / controller/action
     * @throws InvalidRequestTokenException if the route was not found
     * @throws \InvalidArgumentException if the defined target for the route is invalid
     */
    public function dispatch(ServerRequestInterface $request, ResponseInterface $response)
    {
        /** @var Router $router */
        $router = GeneralUtility::makeInstance(Router::class);
        /** @var Route $route */
        $route = $router->matchRequest($request);
        $request = $request->withAttribute('route', $route);
        $request = $request->withAttribute('target', $route->getOption('target'));
        if (!$this->isValidRequest($request)) {
            throw new InvalidRequestTokenException('Invalid request for route "' . $route->getPath() . '"', 1425389455);
        }

        if ($route->getOption('module')) {
            return $this->dispatchModule($request, $response);
        }
        $targetIdentifier = $route->getOption('target');
        $target = $this->getCallableFromTarget($targetIdentifier);
        return call_user_func_array($target, [$request, $response]);
    }

    /**
     * Wrapper method for static form protection utility
     *
     * @return \TYPO3\CMS\Core\FormProtection\AbstractFormProtection
     */
    protected function getFormProtection()
    {
        return FormProtectionFactory::get();
    }

    /**
     * Checks if the request token is valid. This is checked to see if the route is really
     * created by the same instance. Should be called for all routes in the backend except
     * for the ones that don't require a login.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return bool
     * @see \TYPO3\CMS\Backend\Routing\UriBuilder where the token is generated.
     */
    protected function isValidRequest($request)
    {
        $route = $request->getAttribute('route');
        if ($route->getOption('access') === 'public') {
            return true;
        }
        $token = (string)(isset($request->getParsedBody()['token']) ? $request->getParsedBody()['token'] : $request->getQueryParams()['token']);
        return $this->getFormProtection()->validateToken($token, 'route', $route->getOption('_identifier'));
    }

    /**
     * Executes the modules configured via Extbase
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface A PSR-7 response object
     * @throws \RuntimeException
     */
    protected function dispatchModule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $route = $request->getAttribute('route');
        $moduleName = $route->getOption('moduleName');
        $moduleConfiguration = $this->getModuleConfiguration($moduleName);

        $backendUserAuthentication = $GLOBALS['BE_USER'];

        // Check permissions and exit if the user has no permission for entry
        // @todo please do not use "true" here, what a bad coding paradigm
        $backendUserAuthentication->modAccess($moduleConfiguration, true);
        $id = $request->getQueryParams()['id'] ?? $request->getParsedBody()['id'];
        if (MathUtility::canBeInterpretedAsInteger($id) && $id > 0) {
            $permClause = $backendUserAuthentication->getPagePermsClause(Permission::PAGE_SHOW);
            // Check page access
            if (!is_array(BackendUtility::readPageAccess($id, $permClause))) {
                // Check if page has been deleted
                $deleteField = $GLOBALS['TCA']['pages']['ctrl']['delete'];
                $pageInfo = BackendUtility::getRecord('pages', $id, $deleteField, $permClause ? ' AND ' . $permClause : '', false);
                if (!$pageInfo[$deleteField]) {
                    throw new \RuntimeException('You don\'t have access to this page', 1289917924);
                }
            }
        }

        // Use regular Dispatching
        // @todo: unify with the code above
        $targetIdentifier = $route->getOption('target');
        if (!empty($targetIdentifier)) {
            // @internal routeParameters are a helper construct for the install tool only.
            // @todo: remove this, after sub-actions in install tool can be addressed directly
            if (!empty($moduleConfiguration['routeParameters'])) {
                $request = $request->withQueryParams(array_merge_recursive(
                    $request->getQueryParams(),
                    $moduleConfiguration['routeParameters']
                ));
            }
            return parent::dispatch($request, $response);
        }
        // extbase module
        $configuration = [
                'extensionName' => $moduleConfiguration['extensionName'],
                'pluginName' => $moduleName
            ];
        if (isset($moduleConfiguration['vendorName'])) {
            $configuration['vendorName'] = $moduleConfiguration['vendorName'];
        }

        // Run Extbase
        $bootstrap = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Core\Bootstrap::class);
        $content = $bootstrap->run('', $configuration);

        $response->getBody()->write($content);

        return $response;
    }

    /**
     * Returns the module configuration which is provided during module registration
     *
     * @param string $moduleName
     * @return array
     * @throws \RuntimeException
     */
    protected function getModuleConfiguration($moduleName)
    {
        if (!isset($GLOBALS['TBE_MODULES']['_configuration'][$moduleName])) {
            throw new \RuntimeException('Module ' . $moduleName . ' is not configured.', 1289918325);
        }
        return $GLOBALS['TBE_MODULES']['_configuration'][$moduleName];
    }
}
