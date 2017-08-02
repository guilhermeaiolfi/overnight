<?php

namespace ON\Db;

use ON\Db\DatabseInterface;
use ON\Db\Manager as DbManager;

class Doctrine2Database implements DatabaseInterface {
  protected $connection = null;
  protected $container;
  protected $parameters;
  protected $name;

  public function __construct ($name, $parameters, $container) {
    $this->container = $container;
    $this->name = $name;
    $this->parameters = $parameters;
    $connection_config = $parameters["connection"];

    $connection = null;
    if(is_string($connection_config)) {
      try {
        $database = $container->get(DbManager::class)->getDatabase($connection_config);
      } catch(\Exception $e) {
        echo $e->getMessage();exit;
        throw new \Exception(sprintf('Connection "%s" configured for use in the Doctrine Entity Manager could not be found.', $connection_config, 0, $e));
      }
      try {
        $connection = $database->getConnection();
      } catch(\Exception $e) {
        throw new \Exception(sprintf("Connection '%s' configured for use in Doctrine Entity Manager could not be initialized:\n\n%s", $connection_config, $e->getMessage()), 0, $e);
      }
    } elseif(!is_array($connection_config)) {
      throw new \Exception('It expects configuration parameter "connection" to be an array containing connection details or a string with the name of an Doctrine Entity Manager to use.');
    } else {
      $connection = $connection_config;
    }

    // make new configuration
    $cc = isset($parameters['configuration_class'])? $parameters['configuration_class'] : '\Doctrine\ORM\Configuration';
    $config = new $cc();
    $this->prepareConfiguration($config);

    // make new event manager or take the one on the given named connection
    if($connection instanceof \Doctrine\DBAL\Connection) {
      $eventManager = $connection->getEventManager();
    } else {
      $ec = isset($parameters['event_manager_class'])? $parameters['event_manager_class'] : '\Doctrine\Common\EventManager';
      $eventManager = new $ec();
    }
    $this->prepareEventManager($eventManager);

    try {
      $this->connection = \Doctrine\ORM\EntityManager::create($connection, $config, $eventManager);
    } catch(Exception $e) {
      throw new AgaviDatabaseException(sprintf("Failed to create Doctrine\ORM\EntityManager for connection '%s':\n\n%s", $name, $e->getMessage()), 0, $e);
    }
  }

  protected function prepareEventManager($eventManager) {

  }

  protected function prepareConfiguration(\Doctrine\DBAL\Configuration $config)
  {
    $cfg = $this->container->get('config');
    // auto-generate proxy classes in debug mode by default
    $auto_generate = isset($this->parameters['configuration']) && isset($this->parameters['configuration']['auto_generate_proxy_classes'])? $this->parameters['configuration']['autolo_generate_proxy_classes'] : $cfg["debug"];

    $config->setAutoGenerateProxyClasses($auto_generate);

    $mda = $this->parameters['configuration']['metadata_driver_impl_argument'];
    // check if a metadata driver class is configured (explicitly check with getParameter() to allow "deletion" of the parameter by using null)

    $md = isset($this->parameters['configuration']['metadata_driver_impl_class'])? $this->parameters['configuration']['metadata_driver_impl_class'] : false;

    if(isset($this->parameters['configuration']['metadata_driver_impl_class']) && $mb) {
      // yes, so we construct the class with the configured arguments
      // construct the given class and pass the path as the argument
      // in many cases, the argument may be a string with a path or an array of paths, which means that we cannot use reflection and newInstanceArgs()
      // for more elaborate cases with multiple ctor arguments or where the ctor expects a non-scalar value, people need to use prepareConfiguration() in a subclass
      $md = new $md($mda);
    } else {
      // no, that means we use the default annotation driver and the configured argument as the path
      $md = $config->newDefaultAnnotationDriver($mda);
    }
    $config->setMetadataDriverImpl($md);

    // set proxy namespace and dir
    // defaults to something including the connection name, or the app cache dir, respectively
    $proxy_namespace = isset($this->parameters['configuration']['proxy_namespace'])? $this->parameters['configuration']['proxy_namespace'] : 'Doctrine2Database_Proxy_' . preg_replace('#\W#', '_', $this->name);
    $config->setProxyNamespace($proxy_namespace);

    $proxy_dir = isset($this->paramerters['configuration']['proxy_dir'])? $this->paramerters['configuration']['proxy_dir'] : $cfg["paths"]["cache"];
    $config->setProxyDir($proxy_dir);

    // unless configured differently, use ArrayCache in debug mode and APC (if available) otherwise
    if($cfg["debug"] || !extension_loaded('apc')) {
      $defaultCache = '\Doctrine\Common\Cache\ArrayCache';
    } else {
      $defaultCache = '\Doctrine\Common\Cache\ApcCache';
    }
    $metadataCache = isset($this->parameters['configuration']['metadata_cache_impl_class'])? $this->parameters['configuration']['metadata_cache_impl_class'] : $defaultCache;
    $config->setMetadataCacheImpl(new $metadataCache);

    $queryCache = isset($this->parameters['configuration']['query_cache_impl_class'])? $this->parameters['configuration']['query_cache_impl_class'] : $defaultCache;
    $config->setQueryCacheImpl(new $queryCache);

    $resultCache = isset($this->parameters['configuration']['result_cache_impl_class'])? $this->parameters['configuration']['result_cache_impl_class'] : $defaultCache;
    $config->setResultCacheImpl(new $resultCache);
  }

  public function getConnection() {
    return $this->connection;
  }

  public function getResource() {
    return $this->connection->getConnection();
  }

}