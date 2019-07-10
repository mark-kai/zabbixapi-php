<?php
/**
  * Zabbix PHP API Client (using the JSON-RPC Zabbix API)
  *
  * @version 2.4 
  * @author Wolfgang Alper <wolfgang.alper@intellitrend.de>
  * @copyright IntelliTrend GmbH, http://www.intellitrend.de
  * @license GNU Lesser General Public License v3.0
  * 
  * You can redistribute this library and/or modify it under the terms of
  * the GNU LGPL as published by the Free Software Foundation, 
  * either version 3 of the License, or any later version.
  * However you must not change author and copyright information.
  *
  * Implementation based on the offical Zabbix API docs.
  * Tested on Linux and Windows.
  *
  * Requires PHP 5.6+, JSON functions, CURL, Zabbix 3.0+
  * For usage see examples provided in 'examples/'
  * 
  * Errorhandling:
  * Errors are handled by exceptions. 
  * - In case of ZabbxiApi errors, the msg and code is passed to the exception
  * - In case of libary specfic errors, the code passed is defined by the constants: ZabbixApi::EXCEPTION_CLASS_CODE   
  */
class ZabbixApi {

	const VERSION = "2.4";

	const EXCEPTION_CLASS_CODE = 1000;
	const SESSION_PREFIX = 'zbx_';

	protected $zabUrl = '';
	protected $zabUser = '';
	protected $zabPassword = '';
	protected $authKey = '';
	protected $debug = false;
	protected $sessionDir = '';	// directory where to store the crypted session
	protected $sessionFileName = ''; // fileName of crypted session, depends on zabUrl/zabUser and SESSION_PREFIX
	protected $sessionFile = ''; // directory + / + fileName
	protected $sslCaFile = ''; // set external CA bundle. If not set use php.ini default settings. See https://curl.haxx.se/docs/caextract.html
	protected $sslVerifyPeer = 1; // verify cert
	protected $sslVerifyHost = 2;  // if cert is valid, check hostname
	protected $useGzip = true;
	protected $timeout = 30; //max. time in seconds to process request
	protected $connectTimeout = 10; //max. time in seconds to connect to server


	/**
	 * Constructor
	 */
	public function __construct() {		
	}


	/**
	 * Login - setup internal structure and validate sessionDir
	 * 
	 * @param string $zabUrl
	 * @param string $zabUser 
	 * @param string $zabPassword
	 * @param array $options - optional settings. Example: array('sessionDir' => '/tmp', 'sslVerifyPeer' => true, 'useGzip' => true, 'debug' => true);
	 * @return boolean $reusedSession - true if an existing session was reused
	 * @throws Exception $e
	 */
	public function login($zabUrl, $zabUser, $zabPassword, $options = array()) {

		$zabUrl = substr($zabUrl , -1) == '/' ? $zabUrl :  $zabUrl .= '/';
		$this->zabUrl = $zabUrl;

		$this->zabUser = $zabUser;
		$this->zabPassword = $zabPassword;

		$validOptions = array('debug', 'sessionDir', 'sslCaFile', 'sslVerifyHost', 'sslVerifyPeer', 'useGzip', 'timeout', 'connectTimeout');
		foreach ($options as $k => $v) {
			if (!in_array($k, $validOptions)) {
				throw new Exception("Invalid option used. option:$k", ZabbixApi::EXCEPTION_CLASS_CODE);
			}
		}


		if (array_key_exists('debug', $options)) {
			$this->debug = $options['debug'] && true;
		}

		if ($this->debug) {
			print "DBG login(). Using zabUser:$zabUser, zabUrl:$zabUrl\n";
		}

		if (array_key_exists('sslCaFile', $options)) {
			if (!is_file($options['sslCaFile'])) {
				throw new Exception("Error - sslCaFile:$sslCaFile is not a valid file", ZabbixApi::EXCEPTION_CLASS_CODE);
			} 
			$this->sslCaFile = $options['sslCaFile'];
		}

		if (array_key_exists('sslVerifyPeer', $options)) {
			$this->sslVerifyPeer = ($options['sslVerifyPeer']) ? 1 : 0;
		}

		if (array_key_exists('sslVerifyHost', $options)) {
			$this->sslVerifyHost = ($options['sslVerifyHost']) ? 2 : 0;
		}

		if (array_key_exists('useGzip', $options)) {
			$this->useGzip = ($options['useGzip']) ? true : false;
		}

		if (array_key_exists('timeout', $options)) {
			$this->timeout = (intval($options['timeout']) > 0)? $options['timeout'] : 30;
		}

		if (array_key_exists('connectTimeout', $options)) {
			$this->timeout = (intval($options['connectTimeout']) > 0)? $options['connectTimeout'] : 30;
		}

		if ($this->debug) {
			print "DBG login(). Using sslVerifyPeer:". $this->sslVerifyPeer . " sslVerifyHost:". $this->sslVerifyHost. " useGzip:". $this->useGzip. " timeout:". $this->timeout. " connectTimeout:". $this->connectTimeout. "\n";
		}
		
		// if sessionDir is passed as param check if a directory exists. otherwise ise the default temp directory
		if (array_key_exists('sessionDir', $options)) {
			$sessionDir = $options['sessionDir'];
			if (!is_dir($sessionDir)) {
				throw new Exception("Error - sessionDir:$sessionDir is not a valid directory", ZabbixApi::EXCEPTION_CLASS_CODE);
			} 
			if (!is_writable($sessionDir)) {
				throw new Exception("Error - sessionDir:$sessionDir is not a writeable directory", ZabbixApi::EXCEPTION_CLASS_CODE);
			}
			$this->sessionDir = $sessionDir;
		}
		else {
			$this->sessionDir = sys_get_temp_dir();
		}				
		
		$sessionFileName = ZabbixApi::SESSION_PREFIX. md5($this->zabUrl . $this->zabUser);
		$this->sessionFileName = $sessionFileName;
		$this->sessionFile = $this->sessionDir. '/'. $this->sessionFileName;

		if ($this->debug) {
			print "DBG login(). Using sessionDir:$sessionDir, sessionFileName:$sessionFileName\n";
		}

		$reusedSession = $this->loadSession();
		$this->call('user.get', array('output' => 'userid'));
		return $reusedSession;

	}


	/**
	 * Convenient function to get remote API version
	 * 
	 * @return string $apiVersion
	 */
	public function getApiVersion() {
		return $this->call('apiinfo.version');
	}


	/**
	 * Get version of this library
	 * 
	 * @return string $version
	 */
	public function getVersion() {
		return Zabbixapi::VERSION;
	}


	/**
	 * Get authKey used for API communication
	 * 
	 * @return string $authKey
	 */
	public function getAuthKey() {
		return $this->authKey;
	}


	/**
	 * Enable / Disable debug
	 * 
	 * @param boolean $status. True = enable
	 */
	public function setDebug($status) {
		$this->debug = $status && 1;
	}


	/**
	 * Logout from Zabbix Server and delete the authKey from filesystem
	 * 
	 * Only use this method if its really needed, because you cannot reuse the session later on.
	 */
	public function logout() {
		if ($this->debug) {
			print "DBG logout(). Delete sessionFile and logout from Zabbix\n";
		}
		$response = $this->callZabbixApi('user.logout');
		// remove session locally - ignore if session no longer exists
		$ret = unlink($this->getSessionFile());
	}

	
	/**
	 * Get session directory
	 * 
	 * @return string $sessionDir
	 */
	public function getSessionDir() {
		return $this->sessionDir;
	}

	/**
	 * Get session FileName without path
	 * 
	 * @return string $sessionFileName
	 */
	public function getSessionFileName() {
		return $this->sessionFileName;
	}


	/**
	 * Get full FileName with path
	 * 
	 * @return string $sessionFile
	 */
	public function getSessionFile() {
		return $this->sessionFile;
	}


	/**
	 * High level Zabbix Api call. Will automatically re-login and retry if call failed using the current authKey.
	 * 
	 * Note: Can only be called after login() was called once before at any time.
	 * 
	 * @param string $method. Zabbix API method i.e. 'host.get'
	 * @param mixed $params. Params as defined in the Zabbix API for that particular method
	 * @return mixed $response. Decoded Json response or scalar
	 * @throws Exception 
	 */
	public function call($method, $params = array()) {
		if (!$this->zabUrl) {
			throw new Exception("Not logged in. Call login() first.", ZabbixApi::EXCEPTION_CLASS_CODE);
		}
		//try to call API with existing auth. on Error re-login and try again
		try {
			$response = $this->callZabbixApi($method, $params);
		}
		catch (exception $e) {
			$this->__login();
			$response = $this->callZabbixApi($method, $params);
		}
		return $response;
	}


	/*************** Protected / Private  functions ***************/


	/**
	 * Try loading a previous Session
	 * 
	 * @return boolean $reusedSession. True - session is reused
	 */
	protected function loadSession() {
		//get authKey from session file
		$authKey = $this->getSession();
		if (!($authKey)) {
			$this->authKey = $authKey;
			if ($this->debug) {
				print "DBG loadSession(). Loaded session from sessionFile\n";
			}
			return true;
		}

		if ($this->debug) {
				print "DBG loadSession(). No valid authKey found in sessionFile or sessionfile does not exist.\n";
		}
		return false;
	}


	/**
	 * Internal login function to perform the login. Saves authKey to sessionFile on success.
	 * 
	 * @return boolean $success
	 */
	protected function __login() {
		// Try to login to our API
		if ($this->debug) {
			print "DBG __login(). Called\n";
		}
		$response = $this->callZabbixApi('user.login', array( 'password' => $this->zabPassword, 'user' => $this->zabUser ));

		if (isset($response) && strlen($response) == 32) {
			$this->authKey = $response;
			//on successful login save authKey in sessionDir
			$this->setSession();
			return true;
		}
		
		// login failed
		$this->authKey = '';
		return false;
	}


	/**
	 * Internal call to Zabbix API via RPC/API call
	 * 
	 * @param string $method
	 * @param mixed $params
	 * @return mixed $response. Json decoded response
	 * @throws Exception
	 */
	protected function callZabbixApi($method, $params = array()) {
		
		if (!$this->authKey && $method != 'user.login' && $method != 'apiinfo.version') {
			// will be handled by call(), by executing a login - not visible to user
			throw new Exception("Not logged in and no authKey", ZabbixApi::EXCEPTION_CLASS_CODE);
		}
		
		$request = $this->buildRequest($method, $params);
		$rawResponse = $this->executeRequest($this->zabUrl.'api_jsonrpc.php', $request);

		if ($this->debug) {
			print "DBG callZabbixApi(). Raw response from API: $rawResponse\n";
		}
		$response = json_decode($rawResponse, true);

		if ( isset($response['id']) && $response['id'] == 1 ) {
			return $response['result'];
		}

		$msg = "Error without further information.";		
		if (is_array($response) && array_key_exists('error', $response)) {
			$code = $response['error']['code'];
			$message = $response['error']['message'];
			$data = $response['error']['data'];
			$msg = "$message [$data]";
			throw new Exception($msg, $code);
		}
		
		throw new Exception($msg);

	}


	/**
	 * Build the Zabbix JSON-RPC request
	 * 
	 * @param string $method
	 * @param mixed $params
	 * @return string $request. Json encoded request object
	 */
	protected function buildRequest($method, $params = array()) {	
		$request = array(
			'auth' => $this->authKey,
			'method' => $method,
			'id' => 1,  // since we do not work in parallel, always using the same id should work
			'params' => ( is_array($params) ? $params : array() ),
			'jsonrpc' => "2.0"
		);

		if ($method == 'user.login') {
			unset($request['auth']);
		}
		
		if ($method == 'apiinfo.version') {
			unset($request['auth']);
		}
		
		return json_encode($request);
	}


	/**
	 * Low level execute the request
	 * 
	 * @param string $zabUrl. Url pointing to API endpoint
	 * @param mixed $data. 
	 * @return string $response. Json encoded response from API
	 */
	protected function executeRequest($zabUrl, $data = '') {
		$c = curl_init($zabUrl);
		// These are required for submitting JSON-RPC requests

		$headers = array();
		$headers[]  = 'Content-Type: application/json-rpc';
		$headers[]  = 'User-Agent: IntelliTrend/ZabbixApi;Version:'. Zabbixapi::VERSION;
		
		$opts = array(
			// allow to return a curl handle
			CURLOPT_RETURNTRANSFER => true,
			// max number of seconds to allow curl to process the request
			CURLOPT_TIMEOUT => $this->timeout,
			// max number of seconds to establish a connection
			CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,			
			// ensure the certificate itself is valid (signed by a trusted CA, the certificate chain is complete, etc.)
			CURLOPT_SSL_VERIFYPEER => $this->sslVerifyPeer,
			// 0 or 2. Ensure the host connecting to is the host named in the certificate.
			CURLOPT_SSL_VERIFYHOST => $this->sslVerifyHost,
			// follow if url has changed
			CURLOPT_FOLLOWLOCATION => true,
			// no cached connection or responses
			CURLOPT_FRESH_CONNECT => true
		);


		$opts[CURLOPT_HTTPHEADER] = $headers;

		$opts[CURLOPT_CUSTOMREQUEST] = "POST";
		$opts[CURLOPT_POSTFIELDS] = ( is_array($data) ? http_build_query($data) : $data );

		// use compression
		$opts[CURLOPT_ENCODING] = 'gzip';

		if ($this->debug) {
			print "DBG executeRequest(). CURL Params: ". $opts[CURLOPT_POSTFIELDS]. "\n";
		}

		curl_setopt_array($c, $opts);
		// pass CAs if set
		if ($this->sslCaFile != '') {
			curl_setopt($c, CURLOPT_CAINFO, $this->sslCaFile);
		}

		$response = @curl_exec($c);
		$info = curl_getinfo($c);
		$sslErrorMsg = curl_error($c);		  

		$httpCode = $info['http_code'];
		$sslVerifyResult = $info['ssl_verify_result'];

		if ( $httpCode == 0 || $httpCode >= 400) {
			throw new Exception("Request failed with HTTP-Code:$httpCode, sslVerifyResult:$sslVerifyResult. $sslErrorMsg", $httpCode);
		}

		
		if ( $sslVerifyResult != 0 && $this->sslVerifyPeer == 1) {
			$error = curl_error($c);
			throw new Exception("Request failed with SSL-Verify-Result:$sslVerifyResult. $sslErrorMsg", $sslVerifyResult);
		}

		curl_close($c);
		return $response;
	}
   
	
	/**
	 * Read authKey from session-file
	 * 
	 * @return string authKey. NULL if key was not found.
	 */
	protected function getSession() {
		$sessionFile = $this->getSessionFile();
		
		// if no session exist simply return
		$fh = @fopen($sessionFile, "r");
		if ($fh == false) {
			if ($this->debug) {
				print "DBG getSession(). sessionFile not found. sessionFile:$sessionFile\n";
			}
			return NULL;
		}
		
		$encryptedKey = fread($fh, filesize($sessionFile));
		if (!$encryptedKey) {
			return NULL;
		}
		
		fclose($fh);
		
		$authKey = $this->decryptAuthKey($encryptedKey);

		if (!$authKey) {
			if ($this->debug) {
				print "DBG getSession(). Decrypting authKey from sessionFile failed. sessionFile:$sessionFile\n";
			}
			return NULL;
		}
		

		if ($this->debug) {
			print "DBG getSession(). Read authKey:$authKey from sessionFile:$sessionFile\n";
		}
		
		return $authKey;
	}

	
	/**
	 * Write encryptedKey into session-file
	 * 
	 * @return true
	 * @throws exeception 
	 */
	protected function setSession() {
		//write content
		$sessionFile = $this->getSessionFile();

		$fh = fopen($sessionFile, "w");
		if ($fh == false) {
			throw new Exception("Cannot open sessionFile. sessionFile:$sessionFile", ZabbixApi::EXCEPTION_CLASS_CODE);
		}

		$encryptedKey = $this->encryptAuthKey();

		if (fwrite($fh, $encryptedKey) == false) {
			throw new Exception("Cannot write key to sessionFile. sessionFile:$sessionFile", ZabbixApi::EXCEPTION_CLASS_CODE);
		}
		
		fclose($fh);
		
		if ($this->debug) {
			print "DBG setSession(). Saved encrypted authKey:$encryptedKey to sessionFile:$sessionFile\n";
		}

		return true;
	}

	
	/**
	 * Encrypt authKey
	 * 
	 * @return string $encryptedKey
	 */
	protected function encryptAuthKey() {
		$encryptedKey = base64_encode(openssl_encrypt(
			$this->authKey,
			"aes-128-cbc",
			hash("SHA256", $this->zabUser. $this->zabPassword, true),
			OPENSSL_RAW_DATA,
			"1356647968472110"
		));

		return $encryptedKey;
	}


	/**
	 * Decrypt authKey
	 * 
	 * @param string $encryptedKey
	 * @return string $decryptedKey. If decryption fails key is empty ""
	 */
	protected function decryptAuthKey($encryptedKey) {
		$decryptedKey = openssl_decrypt(base64_decode($encryptedKey),
			"aes-128-cbc",
			hash("SHA256", $this->zabUser. $this->zabPassword, true),
			OPENSSL_RAW_DATA,
			"1356647968472110"
		);

		return $decryptedKey;	
	}

}