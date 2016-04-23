<?php
namespace Quickshiftin\Swagger;

use Swagger\Client\Configuration;
use phpFastCache\Core\DriverAbstract;

/**
 * APIs are divided up into modules, such that Swagger provides service classes
 * and methods on those classes which correspond to API endpoints.
 *
 * To prevent calls to new and you having to maintain local variables for every
 * service object you need to interact with, this class provides a registry for them.
 * 
 * It also acts as a proxy to Swagger specified APIs, by caching API responses for GET requests
 * based on the request URI and request payload.
 *
 * Support for the caching behavior is provided, but you must supply an instantiated cache backend
 * and configuration for your Swagger generated client library.
 */
class Registry
{
    protected $_bSupressExceptions = false;

    private
        $_sUrl,                     // API base URL
        $_oApiClient,               // Wrapped Swagger\ApiClient
        $_aServices = [],           // Cached service objects
        $_sSwaggerClientNamespace;  // Namespace of your generated Swagger client library - EG "\\Swagger\\Client\\Api\\";

    /** 
     * Configure the base REST URL and access token for connectivity to Magento.
     *
     * @param string $sSwaggerClientNamespace Namespace basepath to your generated swagger client library
     * @param string $sUrl Base URL to the API defined by Swagger
     * @param string $sAccessToken Hardcoded support for access token based security
     */
    public function __construct(
        $sSwaggerClientNamespace,
        Configuration $oSwaggerConfig,
        DriverAbstract $oCache,
        $iDefaultTtl=Client::DEFAULT_CACHE_TTL
    ) {
        // Set the base URL of the Magento installation
        $this->_sUrl = $oSwaggerConfig->getHost();

        // Setup the cached API client
        $this->_oApiClient = new Client($oSwaggerConfig, $oCache);
    }

    /**
     * Public interface to easily call methods on cached service client objects.
     *
     * The convention is $oProxy->ServiceName('methodName', 'addtional', 'args', ...);
     *
     * _createService is used to instantiate a service object based on ServiceName and
     * _callService is used to invoke Method name with $aParams
     */
    public function __call($sService, array $aParams=[])
    {
        // $sMethod is always the first param
        $sMethod = array_shift($aParams);

        // Validation
        if($iFirstUnderscore === false || empty($sService) || empty($sMethod)) {
            throw new \InvalidArgumentException('Service & Method incorrectly specified');
        }

        // Invocation
        return $this->_callRemote($sService, $sMethod, $aParams);
    }

    /** 
     * Allows you to define custom service class for provided service key.
     * Otherwise there's a standard mapping defined by _createService.
     */
    protected function _customServiceClass($sService) { return null; }

    /**
     * Instantiate a class for a given endpoint designated by $sService.
     */
    protected function _createService($sService)
    {
        // Allow subclass to define alternate naming convention for given $sService
        $sServiceClass = $this->_customServiceClass($sService);

        // Otherwise use a standard convention
        if(!$sServiceClass)
            $sServiceClass = $this->_sSwaggerClientNamespace . $sService;

        return new $sServiceClass($this->_oApiClient);
    }

    /**
     * Provide a more convenient syntax for creating service objects and 
     * invoking methods on them in one high level function.
     *
     * Sometimes we expect a command might throw an exception and we'd just like to roll through it anyway.
     * In that case you may pass true for the last parameter.
     */
    protected function _callRemote($sService, $sMethod, array $aParams=[], $bSupressException=false)
    {
        if(!isset($this->_aServices[$sService])) {
            $this->_aServices[$sService] = $this->_createService($sService);
        }

        $oService = $this->_aServices[$sService];

        try {
            return call_user_func_array([$oService, $sMethod], $aParams);
        } catch(\Exception $e) {
            if(!$bSupressException && !$this->_bSupressExceptions) {
                throw $e;
            }
        }
    }
}
