<?php
namespace Quickshiftin\Swagger;

use phpFastCache\Core\DriverAbstract;
use phpFastCache\CacheManager;

// XXX Is this reliable or can it vary from client to client based on spec?
use Swagger\Client\ApiClient;
use Swagger\Client\Configuration;

/**
 * A generic caching class that sits on top of Swagger\Client\ApiClient
 * using phpFastCache\Core\DriverAbstract that accelrates GET calls to your Magento REST API.
 */
class Client
{
    const DEFAULT_CACHE_TTL = 600;

    private
        $_oCache,
        $_oApiClient,
        $_iDefaultCacheTtl,
        $_iTmpCacheTtl,
        $_bTmpTtl=false;

    /**
     * Constructor of the class
     * @param Configuration $config config for this ApiClient
     * @param CacheManager $cacheManager - optional customized caching object
     */
    public function __construct(
        Configuration $oApiConfig,
        DriverAbstract $oCacheDriver=null,
        $iDefaultCacheTtl=self::DEFAULT_CACHE_TTL
    ) {
        // Allow client to fully define cache manager, but create a default one otherwise
        if($oCacheDriver) {
            // @todo Need to verify allow_search is enabled in this case
            $this->_oCache = $oCacheDriver;
        } else {
            $this->_oCache =
                CacheManager::getInstance($sCacheBackend, [
                    'cache_method' => $sCacheMethod,
                    'allow_search' => true,  // @note Requires pdo_sqlite
                    'path'         => '/tmp'
                ]);
        }

        $this->_oApiClient       = new ApiClient($oApiConfig);
        $this->_iDefaultCacheTtl = $iDefaultCacheTtl;
    }

    /**
     * Sets the ttl for a single invocation. Call this before calling
     * a service method to change the cache TTL for that URI.
     *
     * @todo Would be nice to be able to map TTL to Services/Methods
     */
    public function ttl($iTtl)
    {
        $this->_iTmpCacheTtl = $iTtl;
        $this->_bTmpTtl      = false;
    }

    /**
     * Get the config
     * @return Configuration
     */
    public function getConfig()
    {
        return $this->_oApiClient->getConfig();
    }

    /**
     * Get the serializer
     * @return ObjectSerializer
     */
    public function getSerializer()
    {
        return $this->_oApiClient->getSerializer();
    }

    /**
     * Get API key (with prefix if set)
     * @param  string $apiKeyIdentifier name of apikey
     * @return string API key with the prefix
     */
    public function getApiKeyWithPrefix($apiKeyIdentifier)
    {
        return $this->_oApiClient->getApiKeyWithPrefix($apiKeyIdentifier);
    }

    /**
     * Return the header 'Accept' based on an array of Accept provided
     *
     * @param string[] $accept Array of header
     *
     * @return string Accept (e.g. application/json)
     */
    public function selectHeaderAccept($accept)
    {
        return $this->_oApiClient->selectHeaderAccept($accept);
    }

    /**
     * Return the content type based on an array of content-type provided
     *
     * @param string[] $content_type Array fo content-type
     *
     * @return string Content-Type (e.g. application/json)
     */
    public function selectHeaderContentType($content_type)
    {
        return $this->_oApiClient->selectHeaderContentType($content_type);
    }

    /** 
     * Make the HTTP call (Sync)
     *
     * This is where the magic happens. We use the cache to supercharge GET calls!
     *
     * @param string $resourcePath path to method endpoint
     * @param string $method       method to call
     * @param array  $queryParams  parameters to be place in query URL
     * @param array  $postData     parameters to be placed in POST body
     * @param array  $headerParams parameters to be place in request header
     * @param string $responseType expected response type of the endpoint
     * @throws \Swagger\Client\ApiException on a non 2xx response
     * @return mixed
     */
    public function callApi(
        $resourcePath,
        $method,
        $queryParams=[],
        $postData=[],
        $headerParams=[],
        $responseType=null
    ) {
        // phpFastCache treats *any* / characters in the cache key as an indication to use regular expressions
        // when calling the search function... To circumvent this we'll cache the resource path with + characters instead
        $sCacheResourcePath = str_replace('/', '+', $resourcePath);

        // Convert array parameters to string representations
        $sQp = 'QP';
        if(!empty($queryParams)) {
            $sQp .= ':' . md5(serialize($queryParams));
        }

        $sPd = 'PD';
        if(!empty($postData)) {
            $sPd .= ':' . md5(serialize($postData));
        }

        $sHp = 'HP';
        if(!empty($headerParams)) {
            $sHp .= ':' . md5(serialize($headerParams));
        }

        $sRt = 'RT';
        if(!empty($responseType)) {
            $sRt .= ':' . $responseType;
        }

        $sCacheKey = implode('-', [$method, $sCacheResourcePath, $sQp, $sPd, $sHp, $sRt]);

        // The whole point of this thing is to cache GET method calls
        if($method == 'GET') {
            $mResult = $this->_oCache->get($sCacheKey);

            // Return cached result!
            if($mResult !== null) {
                return $mResult;
            }

            // Otherwise, run the underlying call to Magento
            $mResult = $this->_oApiClient->callApi(
                $resourcePath, $method, $queryParams, $postData, $headerParams, $responseType);

            // Cache the result
            $iCacheTtl = $this->_bTmpTtl ? $this->_iTmpCacheTtl : $this->_iDefaultCacheTtl;
            $this->_oCache->set($sCacheKey, $mResult, $iCacheTtl);

            // Flip back the temp TTL
            if($this->_bTmpTtl) {
                $this->_bTmpTtl = false;
            }

            // Return the result (which is now cached)
            return $mResult;
        }

        // Otherwise we need to potentially bust some cached GET items before running the underly API call
        // @note This section could be a lot better, this is just a first stab...
        $aResourcePath = explode('+', $sCacheResourcePath);
        do {
            // Build a search key consisting of the current resource path and the GET HTTP method
            $sSearchKey = 'GET-' . implode('+', $aResourcePath);

            // Search the cache for any matches, and delete them
            $aMatches = $this->_oCache->search($sSearchKey, false);
            foreach($aMatches as $sMatchKey => $iTimestamp) {
                $bDeleted = $this->_oCache->delete($sMatchKey);
                /* XXX Seems we're getting into the deletion code when we shouldn't for some reason
                       the match count is wrong sometimes it seems ??
                if(!$bDeleted) {
                    trigger_error('Failed to delete phpFastCache entry: ' . $sMatchKey, E_USER_WARNING);
                }
                */
            }

            // Reduce the search path and try again, unless we've reached the end
            array_pop($aResourcePath);
        } while(count($aResourcePath) > 2);

        // Now run the actual method
        return $this->_oApiClient->callApi(
                $resourcePath, $method, $queryParams, $postData, $headerParams, $responseType);
    }
}
