<?php
namespace axenox\WebDAV\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\Facades\AbstractHttpFacade\NotFoundHandler;
use exface\Core\Facades\AbstractHttpFacade\HttpRequestHandler;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\FilePathDataType;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use Sabre\DAV;

/**
 * Facade to allow access to server folders via WebDAV.
 * 
 * To make a folder accessible via WebDAV, add a file called `axenox.WebDAV.config.json` 
 * to your `config` folder:
 * 
 * ```
 *  {
 *	 "FOLDERS": [
 *		{
 *			"URL": "test",
 *			"PATH": "vendor/exface/core",
 *			"SHOW_IN_BROWSER": true
 *		}
 *	 ]
 *  }
 * 
 * ```
 * 
 * Replace the values as needed:
 * 
 * - `URL` is the name of the WebDAV share, that will be used in the API URL - e.g. 
 * `test` will be accessible via `api/webdav/test`. The `URL` MUST not contain slashes!
 * - `PATH` is the path to the desired folder in the file system of the server: either
 * absolute or relative to the workbench installation folder
 * - `SHOW_IN_BROWSER` will make the WebDAV URL accessible via browser and not only via
 * WebDAV client. It will basically render a web page representation if a browser is
 * detected.
 * 
 * @author Andrej Kabachnik
 *
 */
class WebDavFacade extends AbstractHttpFacade
{        
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/webdav';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Psr\Http\Server\RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new HttpRequestHandler(new NotFoundHandler());
        
        // Authenticate users
        $handler->add(new AuthenticationMiddleware($this));
        
        $uri = $request->getUri();
        $path = ltrim(StringDataType::substringAfter($uri->getPath(), $this->getUrlRouteDefault()), "/");
        $urlFolder = StringDataType::substringBefore($path, '/', $path);
        $addPluginBrowser = false;
        
        $fsFolder = '';
        foreach ($this->getConfig()->getOption('FOLDERS') as $uxon) {
            if ($uxon->getProperty('URL') === $urlFolder) {
                $fsFolder = $uxon->getProperty('PATH');
                $addPluginBrowser = $uxon->getProperty('SHOW_IN_BROWSER') ?? $addPluginBrowser;
            }
        }
        if ($fsFolder === '') {
            return new Response(404);
        }
        $fsFolderAbs = FilePathDataType::normalize($this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $fsFolder);
        
        // Initialize the WebDAV server
        $rootDirectory = new DAV\FS\Directory($fsFolderAbs);
        $server = new DAV\Server($rootDirectory);
        $workbenchUri = new Uri($this->getWorkbench()->getUrl());
        $server->setBaseUri(rtrim($workbenchUri->getPath(), '/') . '/' . $this->buildUrlToFacade(true) . '/' . $urlFolder);
        
        // The lock manager is reponsible for making sure users don't overwrite
        // each others changes.
        /*$lockBackend = new DAV\Locks\Backend\File('data/locks');
        $lockPlugin = new DAV\Locks\Plugin($lockBackend);
        $server->addPlugin($lockPlugin);*/
        
        // This ensures that we get a pretty index in the browser, but it is
        // optional.
        if ($addPluginBrowser) {
            $server->addPlugin(new DAV\Browser\Plugin());
        }
        
        // All we need to do now, is to fire up the server
        $server->start();
        
        $response = new Response(200);
        return $response;
    }
}