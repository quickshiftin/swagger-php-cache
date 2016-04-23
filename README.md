Caching for Swagger-Codgen PHP client libraries
=====

## Add caching support to your generated Swagger php client libraries to boost performance!
[Swagger](http://swagger.io/getting-started/) is to define a standard, language-agnostic interface to REST APIs which allows both humans and computers to discover and understand the capabilities of the service without access to source code, documentation, or through network traffic inspection.

[Swagger Codegen](http://swagger.io/swagger-codegen/) is used in part to generate client client libraries and server stubs from a [Swagger](http://swagger.io/getting-started/) definition.

This project aims to add pluggable caching to the clients.

## Overview

First you'll have to have a service that provides a Swagger definition. [Magento2](http://devdocs.magento.com/swagger/index.html)
is a great example, and what got me started on this project.

### Installation
1. Generate a Swagger PHP client library using [Swagger-Codegen](http://swagger.io/swagger-codegen/)
2. Publish your generated client in version control such that it's accessible via composer
3. `composer require` both you generated Swagger client and `quickshiftin/swagger-php-cache`

### Usage

#### Instantiate the client
```
// use clause at the top of your code
use Quickshiftin\Swagger\Registry as SwaggerClient;

// Configure authentication details
$oSwaggerConfig = new Swagger\Client\Configuration()
$oSwaggerConfig->setHost('http://magev2.local/rest/default');
$oSwaggerConfig->addDefaultHeader('Authorization', 'Bearer 2fx2vvisghugut3xwwgummbnpqq001ny');

// Instantiate the cache
$oCache = phpFastCache\CacheManager::getInstance($sCacheBackend, [
    'cache_method' => $sCacheMethod,
    'allow_search' => true,  // @note Requires pdo_sqlite
    'path'         => '/tmp'
]); 

// Instantiate the SwaggerClient, which acts as a registry for service objects
$oMageClient = new SwaggerClient($oSwaggerConfig, $oCache);


```

#### Make a GET request which will cache the result
```
// Invoke API methods using classes and methods generated by Swagger
// Here we call list the Magento catalog category tree then cache the results based on URI
$oRootCategory =
    $oMage2Client
        // Service object becomes name of method, this object is now cached by $oMage2Client (registry pattern)
        // First argument is the name of the service method, any additional arguments are passed to the API
        ->CatalogCategoryManagementVApi('catalogCategoryManagementV1GetTreeGet');

// Subsequent calls are cached
$oRootCategory = // I'm loading from the cache now!!
    $oMage2Client
        ->CatalogCategoryManagementVApi('catalogCategoryManagementV1GetTreeGet');
```

#### Automatic cache busting
```
// Automatic cache-busting (albeit conservative implementation)
// EG Deleting something with the same base URI as a cached item will purge related cached items
// This will bust the cache we created in the prior example
$oMage2Client
    ->CatalogCategoryRepositoryVApi(
        'catalogCategoryRepositoryV1DeleteByIdentifierDelete',
        $oRoot->getId());
```

## External dependencies

### Userspace
* Caching library will be pluggable in the future, currently has hard dependency on phpFastCache/phpFastCache

### Extensions
* Requires pdo-sqlite (For searching to work in phpFastCache)

## Future ideas / TODO
* Unit test...
* Maybe will go for server side library too, early focus is client-side caching
* Caching logic hidden inside the code for now, but will allow for extension in future release
