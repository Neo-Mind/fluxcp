<?php
require_once 'Flux/Config.php';
require_once 'Flux/Error.php';
require_once 'Flux/Connection.php';
require_once 'Flux/LoginServer.php';
require_once 'Flux/CharServer.php';
require_once 'Flux/MapServer.php';
require_once 'Flux/Athena.php';
require_once 'Flux/LoginAthenaGroup.php';

/**
 * The Flux class contains methods related to the application on the larger
 * scale. For the most part, it handles application initialization such as
 * parsing the configuration files and whatnot.
 */
class Flux {
	/**
	 * Application-specific configuration object.
	 *
	 * @access public
	 * @var Flux_Config
	 */
	public static $appConfig;
	
	/**
	 * Servers configuration object.
	 *
	 * @access public
	 * @var Flux_Config
	 */
	public static $serversConfig;
	
	/**
	 * Messages configuration object.
	 *
	 * @access public
	 * @var Flux_Config
	 */
	public static $messagesConfig;
	
	/**
	 * Collection of Flux_Athena objects.
	 *
	 * @access public
	 * @var array
	 */
	public static $servers = array();
	
	/**
	 * Registry where Flux_LoginAthenaGroup instances are kept for easy
	 * searching.
	 *
	 * @access public
	 * @var array
	 */
	public static $loginAthenaGroupRegistry = array();
	
	/**
	 * Registry where Flux_Athena instances are kept for easy searching.
	 *
	 * @access public
	 * @var array
	 */
	public static $athenaServerRegistry = array();
	
	/**
	 * Object containing all of Flux's session data.
	 *
	 * @access public
	 * @var Flux_SessionData
	 */
	public static $sessionData;
	
	/**
	 * Initialize Flux application. This will handle configuration parsing and
	 * instanciating of objects crucial to the control panel.
	 *
	 * @param array $options Options to pass to initializer.
	 * @throws Flux_Error Raised when missing required options.
	 * @access public
	 */
	public static function initialize($options = array())
	{
		$required = array('appConfigFile', 'serversConfigFile', 'messagesConfigFile');
		foreach ($required as $option) {
			if (!array_key_exists($option, $options)) {
				self::raise("Missing required option `$option' in Flux::initialize()");
			}
		}
		
		// Parse application and server configuration files, this will also
		// handle configuration file normalization. See the source for the
		// below methods for more details on what's being done.
		self::$appConfig      = self::parseAppConfigFile($options['appConfigFile']);
		self::$serversConfig  = self::parseServersConfigFile($options['serversConfigFile']);
		self::$messagesConfig = self::parseMessagesConfigFile($options['messagesConfigFile']);
		
		// Initialize server objects.
		self::initializeServerObjects();
	}
	
	/**
	 * Initialize each Login/Char/Map server object and contain them in their
	 * own collective Athena object.
	 *
	 * This is also part of the Flux initialization phase.
	 *
	 * @access public
	 */
	public static function initializeServerObjects()
	{
		foreach (self::$serversConfig->getChildrenConfigs() as $key => $config) {
			$connection  = new Flux_Connection($config->getDbConfig(), $config->getLogsDbConfig());
			$loginServer = new Flux_LoginServer($config->getLoginServer());
			
			// LoginAthenaGroup maintains the grouping of a central login
			// server and its underlying Athena objects.
			self::$servers[$key] = new Flux_LoginAthenaGroup($config->getServerName(), $connection, $loginServer);
			
			// Add into registry.
			self::registerServerGroup($config->getServerName(), self::$servers[$key]);
			
			foreach ($config->getCharMapServers()->getChildrenConfigs() as $charMapServer) {
				$charServer = new Flux_CharServer($charMapServer->getCharServer());
				$mapServer  = new Flux_MapServer($charMapServer->getMapServer());
				
				// Create the collective server object, Flux_Athena.
				$athena = new Flux_Athena($charMapServer, $loginServer, $charServer, $mapServer);
				self::$servers[$key]->addAthenaServer($athena);
				
				// Add into registry.
				self::registerAthenaServer($config->getServerName(), $charMapServer->getServerName(), $athena);
			}
		}
	}
	
	/**
	 * Wrapper method for setting and getting values from the appConfig.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param arary $options
	 * @access public
	 */
	public static function config($key, $value = null, $options = array())
	{
		if (!is_null($value)) {
			return self::$appConfig->set($key, $value, $options);
		}
		else {
			return self::$appConfig->get($key);
		}
	}
	
	/**
	 * Wrapper method for setting and getting values from the messagesConfig.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param arary $options
	 * @access public
	 */
	public static function message($key, $value = null, $options = array())
	{
		if (!is_null($value)) {
			return self::$messagesConfig->set($key, $value, $options);
		}
		else {
			return self::$messagesConfig->get($key);
		}
	}
	
	/**
	 * Convenience method for raising Flux_Error exceptions.
	 *
	 * @param string $message Message to pass to constructor.
	 * @throws Flux_Error
	 * @access public
	 */
	public static function raise($message)
	{
		throw new Flux_Error($message);
	}

	/**
	 * Parse PHP array into Flux_Config instance.
	 *
	 * @param array $configArr
	 * @access public
	 */
	public static function parseConfig(array $configArr)
	{
		return new Flux_Config($configArr);
	}
	
	/**
	 * Parse a PHP array returned as the result of an included file into a
	 * Flux_Config configuration object.
	 *
	 * @param string $filename
	 * @access public
	 */
	public static function parseConfigFile($filename)
	{
		// Uses require, thus assumes the file returns an array.
		return self::parseConfig(require($filename));
	}
	
	/**
	 * Parse a file in an application-config specific manner.
	 *
	 * @param string $filename
	 * @access public
	 */
	public static function parseAppConfigFile($filename)
	{
		$config = self::parseConfigFile($filename);
		
		if (!$config->getThemeName()) {
			self::raise('ThemeName is required in application configuration.');
		}
		elseif (!self::themeExists($themeName=$config->getThemeName())) {
			self::raise("The selected theme '$themeName' does not exist.");
		}
		elseif (!($config->getPayPalReceiverEmails() instanceOf Flux_Config)) {
			self::raise("PayPalReceiverEmails must be an array.");
		}
		
		return $config;
	}
	
	/**
	 * Parse a file in a servers-config specific manner. This method gets a bit
	 * nasty so beware of ugly code ;)
	 *
	 * @param string $filename
	 * @access public
	 */
	public static function parseServersConfigFile($filename)
	{
		$config            = self::parseConfigFile($filename);
		$options           = array('overwrite' => false, 'force' => true); // Config::set() options.
		$serverNames       = array();
		$athenaServerNames = array();
		
		foreach ($config->getChildrenConfigs() as $topConfig) {
			//
			// Top-level normalization.
			//
			
			if (!($serverName = $topConfig->getServerName())) {
				self::raise('ServerName is required for each top-level server configuration, check your servers configuration file.');
			}
			elseif (in_array($serverName, $serverNames)) {
				self::raise("The server name '$serverName' has already been configured. Please use another name.");
			}
			
			$serverNames[] = $serverName;
			$athenaServerNames[$serverName] = array();
			
			$topConfig->setDbConfig(array(), $options);
			$topConfig->setLogsDbConfig(array(), $options);
			$topConfig->setLoginServer(array(), $options);
			$topConfig->setCharMapServers(array(), $options);
			
			$dbConfig     = $topConfig->getDbConfig();
			$logsDbConfig = $topConfig->getLogsDbConfig();
			$loginServer  = $topConfig->getLoginServer();
			
			foreach (array($dbConfig, $logsDbConfig) as $_dbConfig) {
				$_dbConfig->setHostname('localhost', $options);
				$_dbConfig->setUsername('ragnarok', $options);
				$_dbConfig->setPassword('ragnarok', $options);
				$_dbConfig->setPersistent(true, $options);
			}
			
			$loginServer->setDatabase($dbConfig->getDatabase(), $options);
			$loginServer->setUseMD5(true, $options);
			
			// Raise error if missing essential configuration directives.
			if (!$loginServer->getAddress()) {
				self::raise('Address is required for each LoginServer section in your servers configuration.');
			}
			elseif (!$loginServer->getPort()) {
				self::raise('Port is required for each LoginServer section in your servers configuration.');
			}
			
			foreach ($topConfig->getCharMapServers()->getChildrenConfigs() as $charMapServer) {
				//
				// Char/Map normalization.
				//
				
				$charMapServer->setBaseExpRates(1, $options);
				$charMapServer->setJobExpRates(1, $options);
				$charMapServer->setDropRates(1, $options);
				$charMapServer->setCharServer(array(), $options);
				$charMapServer->setMapServer(array(), $options);
				$charMapServer->setDatabase($dbConfig->getDatabase(), $options);				
				
				if (!($athenaServerName = $charMapServer->getServerName())) {
					self::raise('ServerName is required for each CharMapServers pair in your servers configuration.');
				}
				elseif (in_array($athenaServerName, $athenaServerNames[$serverName])) {
					self::raise("The server name '$athenaServerName' under '$serverName' has already been configured. Please use another name.");
				}
				
				$athenaServerNames[$serverName][] = $athenaServerName;
				$charServer = $charMapServer->getCharServer();
				
				if (!$charServer->getAddress()) {
					self::raise('Address is required for each CharServer section in your servers configuration.');
				}
				elseif (!$charServer->getPort()) {
					self::raise('Port is required for each CharServer section in your servers configuration.');
				}
				
				$mapServer = $charMapServer->getMapServer();
				if (!$mapServer->getAddress()) {
					self::raise('Address is required for each MapServer section in your servers configuration.');
				}
				elseif (!$mapServer->getPort()) {
					self::raise('Port is required for each MapServer section in your servers configuration.');
				}
			}
		}
		
		return $config;
	}
	
	/**
	 * Parses a messages configuration file.
	 *
	 * @param string $filename
	 * @access public
	 */
	public static function parseMessagesConfigFile($filename)
	{
		$config = self::parseConfigFile($filename);
		// Nothing yet.
		return $config;
	}
	
	/**
	 * Check whether or not a theme exists.
	 *
	 * @return bool
	 * @access public
	 */
	public static function themeExists($themeName)
	{
		return is_dir(FLUX_THEME_DIR."/$themeName");
	}
	
	/**
	 * Register the server group into the registry.
	 *
	 * @param string $serverName Server group's name.
	 * @param Flux_LoginAthenaGroup Server group object.
	 * @return Flux_LoginAthenaGroup
	 * @access private
	 */
	private function registerServerGroup($serverName, Flux_LoginAthenaGroup $serverGroup)
	{
		self::$loginAthenaGroupRegistry[$serverName] = $serverGroup;
		return $serverGroup;
	}
	
	/**
	 * Register the Athena server into the registry.
	 *
	 * @param string $serverName Server group's name.
	 * @param string $athenaServerName Athena server's name.
	 * @param Flux_Athena $athenaServer Athena server object.
	 * @return Flux_Athena
	 * @access private
	 */
	private function registerAthenaServer($serverName, $athenaServerName, Flux_Athena $athenaServer)
	{
		if (!array_key_exists($serverName, self::$athenaServerRegistry) || !is_array(self::$athenaServerRegistry[$serverName])) {
			self::$athenaServerRegistry[$serverName] = array();
		}
		
		self::$athenaServerRegistry[$serverName][$athenaServerName] = $athenaServer;
		return $athenaServer;
	}
	
	/**
	 * Get Flux_LoginAthenaGroup server object by its ServerName.
	 *
	 * @param string
	 * @return mixed Returns Flux_LoginAthenaGroup instance or false on failure.
	 * @access public
	 */
	public static function getServerGroupByName($serverName)
	{
		$registry = &self::$loginAthenaGroupRegistry;
		
		if (array_key_exists($serverName, $registry) && $registry[$serverName] instanceOf Flux_LoginAthenaGroup) {
			return $registry[$serverName];
		}
		else {
			return false;
		}
	}
	
	/**
	 * Get Flux_Athena instance by its group/server names.
	 *
	 * @param string $serverName Server group name.
	 * @param string $athenaServerName Athena server name.
	 * @return mixed Returns Flux_Athena instance or false on failure.
	 * @access public
	 */
	public static function getAthenaServerByName($serverName, $athenaServerName)
	{
		$registry = &self::$athenaServerRegistry;
		if (array_key_exists($serverName, $registry) && array_key_exists($athenaServerName, $registry[$serverName]) &&
			$registry[$serverName][$athenaServerName] instanceOf Flux_Athena) {
		
			return $registry[$serverName][$athenaServerName];
		}
		else {
			return false;
		}
	}
	
	/**
	 * Get the job class name from a job ID.
	 *
	 * @param int $id
	 * @return mixed Job class or false.
	 * @access public
	 */
	public static function getJobClass($id)
	{
		$key   = "JobClasses.$id";
		$class = self::config($key);
		
		if ($class) {
			return $class;
		}
		else {
			return false;
		}
	}
	
	/**
	 * Get the job ID from a job class name.
	 *
	 * @param string $class
	 * @return mixed Job ID or false.
	 * @access public
	 */
	public static function getJobID($class)
	{
		$index = self::config('JobClassIndex')->toArray();
		if (array_key_exists($class, $index)) {
			return $index[$class];
		}
		else {
			return false;
		}
	}
}
?>