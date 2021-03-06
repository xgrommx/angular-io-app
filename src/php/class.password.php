<?php


/**
 *
 * Password
 *
 * Used to generate and confirm password hashes. Also
 * includes additional checks to ensure password entered
 * is secure.
 *
 * Requires:
 * - php-scrypt (https://github.com/DomBlack/php-scrypt)
 *
 */

require_once 'class.db.php';

if(!defined("PASSWORD_RESET_LENGTH"))	define("PASSWORD_RESET_LENGTH", 3600);	// The time one has to reset their password in seconds (1 hour)

//-- Password Hashing --//
if(!defined("PASSWORD_HASH"))			define("PASSWORD_HASH",		"bcrypt");	// PBKDF2, bcrypt, scrypt (recommended)
if(!defined("PASSWORD_SALT"))			define("PASSWORD_SALT",		"");		// Added to password (Stored in Code - same for all)
//if(!defined("PASSWORD_PEPPER"))		define("PASSWORD_PEPPER",	FALSE);		// Added to password (Stored in other DB/cache)
//if(!defined("PASSWORD_CAYENNE"))		define("PASSWORD_CAYENNE",	FALSE);		// Added to password (Stored in File)
//if(!defined("PASSWORD_NONCE"))		define("PASSWORD_NONCE",	TRUE);		//

// PBKDF2 - Require NIST compliance [https://github.com/P54l0m5h1k/PBKDF2-implementation-PHP]
if(!defined("PBKDF2_SALT"))				define("PBKDF2_SALT",		"");
if(!defined("PBKDF2_BINARY"))			define("PBKDF2_BINARY",		TRUE);		// generate binary data, or base64 encoded string
if(!defined("PBKDF2_ITERATIONS"))		define("PBKDF2_ITERATIONS",	10000);		// how many iterations to perform 10,000+ (2012)
if(!defined("PBKDF2_KEY_LENGTH"))		define("PBKDF2_KEY_LENGTH",	32);		// key length
if(!defined("PBKDF2_ALGORITHM"))		define("PBKDF2_ALGORITHM",	"sha512");	// hashing algorithm (sha-256, sha-512)

// bcrypt - easy to implement [https://gist.github.com/1053158]
if(!defined("BCRYPT_WORK_FACTOR"))		define("BCRYPT_WORK_FACTOR",10);		// work_factor (4 - 31) [http://wildlyinaccurate.com/bcrypt-choosing-a-work-factor]

// scrypt - longest to break (2013-08), requires extra work server side to implement [https://github.com/DomBlack/php-scrypt]
if(!defined("SCRYPT_SALT"))				define("SCRYPT_SALT",		NULL);		// NULL to grenerate random
if(!defined("SCRYPT_PEPPER"))			define("SCRYPT_PEPPER",		"");		//
if(!defined("SCRYPT_SALT_LENGTH"))		define("SCRYPT_SALT_LENGTH",8);			// The length of the salt
if(!defined("SCRYPT_KEY_LENGTH"))		define("SCRYPT_KEY_LENGTH",	32);		// The key length
if(!defined("SCRYPT_CPU"))				define("SCRYPT_CPU",		16384);		// The CPU difficultly (must be a power of 2,  > 1) pow(2,14)
if(!defined("SCRYPT_MEMORY"))			define("SCRYPT_MEMORY",		8);			// The memory difficultly
if(!defined("SCRYPT_PARALLEL"))			define("SCRYPT_PARALLEL",	1);			// The parallel difficultly

class Password {
	private $db;
	private $errors = array();
	
	private $user_ID = 0;
	private $user_email = '';
	
	/**
	 * Constructs a Password object.
	 *
	 * @param array $config overwrite json settings
	 */
	function __construct($config = array()) {
		global $database;
		$this->db = $database;
		
		$this->config = sizeof($config) ? $config : json_decode(file_get_contents(dirname(__FILE__).'/../json/config.password.json'), true); // object
		
		// Build tables if not done already
		$this->db->query("CREATE  TABLE IF NOT EXISTS `angular_db`.`password_blacklist` (
		  `password` VARCHAR(256) NOT NULL ,
		  `length` INT NOT NULL DEFAULT 0 ,
		  `upper_count` INT NOT NULL DEFAULT 0 ,
		  `lower_count` INT NOT NULL DEFAULT 0 ,
		  `number_count` INT NOT NULL DEFAULT 0 ,
		  `special_count` INT NOT NULL DEFAULT 0 ,
		  `other_count` INT NOT NULL DEFAULT 0 ,
		  `identical_length` INT NOT NULL DEFAULT 0 ,
		  `use_count` INT NOT NULL DEFAULT 0 ,
		  PRIMARY KEY (`password`) ,
		  INDEX `length` (`length` ASC) )
		ENGINE = InnoDB");
		
		$this->db->query("CREATE  TABLE IF NOT EXISTS `angular_db`.`password_dictionary` (
		  `word` VARCHAR(32) NOT NULL ,
		  `lang` VARCHAR(5) NOT NULL DEFAULT 'en' ,
		  PRIMARY KEY (`word`) ,
		  INDEX `lang` (`lang` ASC) )
		 ENGINE = InnoDB");
	}
	
	/**
	 * Destructs a Password object.
	 *
	 * @return void
	 */
	function __destruct() {
		
	}
	
	/**
	 * make hash of email without dots
	 * in the local-part
	 *
	 * @param string $email
	 * @return string
	 */
	function hashEmail($email) {
		$email_parts = explode("@", $email);
		$email_parts[0] = str_replace(".", "", $email_parts[0]);
		$email = implode("@", $email_parts);
		return hash('sha512', $email);
	}
	
	/**
	 * Get User ID
	 *
	 * @return int
	 */
	public function getId() {
		return (USER_ID) ? USER_ID : $this->user_ID;
	}
	
	/**
	 * Set User ID
	 *
	 * @param int $user_ID
	 * @return void
	 */
	public function setId($user_ID) {
		$this->user_ID = $user_ID;
	}
	
	/**
	 * Get User Email
	 *
	 * @return string
	 */
	public function getEmail() {
		return (USER_EMAIL) ? USER_EMAIL : $this->user_email;
	}
	
	/**
	 * Set User Email
	 *
	 * @params string $user_email
	 * @return void
	 */
	public function setEmail($user_email) {
		$this->user_email = $user_email;
	}
	
	/**
	 * Has Errors
	 *
	 * @return int
	 */
	function hasErrors() {
  		// array_walk($words, create_function('&$str', '$str = "<p>$str</p>";'));
	  	return sizeof($this->errors);
  	}
  	
	/**
	 * Get Errors
	 *
	 * @return string
	 */
	function getErrors() {
  		// array_walk($words, create_function('&$str', '$str = "<p>$str</p>";'));
	  	return implode("\n", $this->errors);
  	}
	
	/**
	 * Update Password
	 * Add old password to history
	 *
	 * @param string $password New password
	 * @param string $email
	 * @return string
	 */
	function update($password, $email) {
		$password_hash = $this->hash($password, $email);
		$query = "UPDATE users SET"
				." password_hash = '{{password}}',"
				." password_timestamp = '{{password_timestamp}}',"
				." password_history = CONCAT(password_history, \",{{password}}\" ),"
				." timestamp_update = '{{timestamp_update}}'"
				." WHERE user_ID = '{{user_ID}}'";
		$this->db->query($query,
			array(
				'password_hash' => $password_hash,
				'password_timestamp' => $_SERVER['REQUEST_TIME'],
				'timestamp_update' => $_SERVER['REQUEST_TIME'],
				'user_ID' => $this->getId()
			)
		);
	}
	
	/**
	 * Runs validation checks a password
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function validate($password) {
		
		Timers::start('validate');
		if ($this->checkStrength($password)) {
			$this->checkDictionary($password);
			$this->checkBlacklist($password);
			
			if ($this->getId()) {
				$this->user_past_password($password);
				$this->user_input_data($password);
			}
		}
		Timers::stop('validate');
		Console::log($this->errors);
		return sizeof($this->errors) ? false : true;
	}
	
	/**
	 * Checks strength of password is long enough
	 *
	 * @param string $password Password
	 * @return bool
	 */
	 function checkStrength($password) {
		 return ($this->length($password) && $this->charset($password) && $this->entropy($password));
	}
	
	/**
	 * Determine a passwords entropy
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function entropy($password) {
		// https://tech.dropbox.com/2012/04/zxcvbn-realistic-password-strength-estimation/
		if (1) {
			Console::log("Entropy PASSED");
		} else {
			$this->errors["entropy"] = "Password entropy too low.";
			Console::log("Entropy FAILED");
		}
		return true;
	}
	
	/**
	 * Checks if a password is long enough
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function length($password) {
		$length = strlen($password);
		
		if ($length < $this->config['min_length']) {
			$this->errors["min_length"] = "Password too short, must be ".$this->config['min_length']." or more";
			Console::log("Password Length FAILED");
			return false;
		}
		Console::log("Password Length PASSED");
		return true;
	}
	
	/**
	 * Checks if a password has charset diversity
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function charset($password) {
		$return = true;
		$password_chars = str_split($password);
		
		$subsets = array(
			"lower" => 0,
			"upper" => 0,
			"number" => 0,
			"special" => 0,
			"other" => 0,
		);
		
		// count each charset subset
		
		for ($i = 0, $l = count($password_chars); $i < $l; $i++) {
			$char = $password_chars[$i];
			
			if 		(strpos("abcdefghijklmnopqrstuvwxyz", $char) !== false)				{ ++$subsets['lower'];	}
			else if (strpos("ABCDEFGHIJKLMNOPQRSTUVWXYZ", $char) !== false)				{ ++$subsets['upper'];	}
			else if (strpos("0123456789", $char) !== false) 							{ ++$subsets['number'];	}
			else if (strpos("~!@#$%^&*()_+{}|:\"<>? `-=[]\;',./£", $char) !== false)	{ ++$subsets['special'];}
			else 																		{ ++$subsets['other'];	}
			
			// max_charset_identical check
			if ($i >= $this->config['max_identical']-1) {
				$charset_identical = true;
				for ($j = $i-1, $k = $i - $this->config['max_identical']; $j > $k; $j--) {
					if ($password_chars[$j] != $char) {
						$charset_identical = false;
					}
				}
				if ($charset_identical) {
					$this->errors["max_charset_identical"] = "Password cannot have ".$this->config['max_identical']." or more identical characters";
					Console::log("Password Charset Identical FAILED");
					$return = false;
				}
			}
		}
		
		// min_charset_subsets
		$subset_count = 0;
		foreach ($subsets as $subset) {
			if ($subset) $subset_count++;
		}
		
		if ($subset_count < $this->config['min_subset']) {
			$this->errors["min_charset_subset"] = "Password needs different types of characters";
			if ($subsets['lower'] < $this->config['min_lower']) {
				$this->errors["min_charset_lower"] = "Password needs at least one lower case letter";
				Console::log("Password Charset Uppercase Letter FAILED");
				$return = false;
			}
			if ($subsets['upper'] < $this->config['min_upper']) {
				$this->errors["min_charset_upper"] = "Password needs at least one upper case letter";
				Console::log("Password Charset Lowercase Letter FAILED");
				$return = false;
			}
			if ($subsets['number'] < $this->config['min_number']) {
				$this->errors["min_charset_number"] = "Password needs at least one number";
				Console::log("Password Charset Number FAILED");
				$return = false;
			}
			if ($subsets['special'] < $this->config['min_special']) {
				$this->errors["min_charset_special"] = "Password needs an special character (!\"£$%&...)";
				Console::log("Password Charset Special FAILED");
				$return = false;
			}
			if ($subsets['other'] < $this->config['min_other']) {
				$this->errors["min_charset_other"] = true;
				Console::log("Password Charset Other FAILED");
				$return = false;
			}
		}
		
		return $return;
	}
	
	/**
	 * Checks if a password overlaps with a dictionary word
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function checkDictionary($password) {
		$words = preg_split("/[^a-z]/", strtolower($password));
		$words = array_filter($words); // clean
		array_walk($words, create_function('&$str', '$str = "\'$str\'";')); // add quotes
		
		$query = "SELECT word FROM password_dictionary"
				." WHERE word IN (".implode(",", $words).") AND length > 2";
		$r = $this->db->query($query);
		if (!$r) {
			Console::log("Password Dictionary PASSED");
			return true;
		}
		$this->errors["dictionary"] = "Password is a dictionary word.";
		Console::log("Password Dictionary FAILED");
		return false;
	}
	
	/**
	 * Add word to dictionary
	 *
	 * @param string $password Password
	 * @param sting $lang ISO 2 or 5 char lang code. ie en, en-ca
	 * @return bool
	 */
	function AddToDictionary($password, $lang) {
		$this->db->insert_update("password_dictionary", array(
			"password" => $password,
			"lang" => $lang,
		));
	}
	/**
	 * Checks if a password is on the black list - a list of most popular passwords
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function checkBlacklist($password) {
		$r = $this->db->select("password_blacklist", array("password" => $password));
		if (!$r) {
			Console::log("Password Black List PASSED");
			return true;
		}
		$this->db->query("UPDATE password_blacklist SET use_count = use_count + 1 WHERE password = '{{password}}'", array("password" => $password));
		$this->errors["black_list"] = "This password is on our password Black List.";
		Console::log("Password Black List FAILED");
		return false;
	}
	
	/**
	 * Add password to blacklist with stats
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function AddToBlacklist($password) {
		$params = array(
			"password" => $password,
			"length" => sizeof($password),
			"upper_count" => 0,
			"lower_count" => 0,
			"number_count" => 0,
			"special_count" => 0,
			"other_count" => 0,
			"identical_length" => 0
		);
		
		$password_chars = str_split($password);
		
		// count each charset subset
		
		for ($i = 0, $l = count($password_chars); $i < $l; $i++) {
			$char = $password_chars[$i];
			
			if 		(strpos("abcdefghijklmnopqrstuvwxyz", $char) !== false)				{ ++$params['lower_count'];	}
			else if (strpos("ABCDEFGHIJKLMNOPQRSTUVWXYZ", $char) !== false) 			{ ++$params['upper_count'];	}
			else if (strpos("0123456789", $char) !== false) 							{ ++$params['number_count'];}
			else if (strpos("~!@#$%^&*()_+{}|:\"<>? `-=[]\;',./£", $char) !== false)	{ ++$params['special_count'];}
			else 																		{ ++$params['other_count'];	}
			
			// count indentical chars
			for ($j = $i; $j < $l; $j++) {
				if ($password_chars[$j] != $char) {
					$params['identical_length'] = max($j - $i, $params['identical_length']);
					break;
				}
			}
			
		}
		$this->db->insert_update("password_blacklist", $params);
		
	}
	
	/**
	 * Checks if a password has been used in the past for a user
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function user_past_password($password) {
		if (!$this->getId() || !$this->getEmail()) {
			Console::log("Password Used Already (No User ID or Email) FAILED");
			return false;
		}
		
		$r = $this->db->select("users", array("user_ID" => $this->getId()), array("password_history"));
		if (!$r) {
			Console::log("Password Used Already (No User) FAILED");
			return false;
		}
		
		$user = $this->db->fetch_assoc($r);
		$history = explode(",", $user['password_history']);
		
		foreach ($history as $hash) {
			if ($this->check($password, $hash, $this->getEmail())) {
				$this->errors['user_past_password'] = "You have already used this password.  Please choose a new unique password.";
				Console::log("Password Used Already FAILED");
				return false;
			}
		}
		Console::log("Password Used Already PASSED");
		return true;
	}
	
	/**
	 * Checks if a password overlaps with a user inputed data
	 *
	 * @param string $password Password
	 * @return bool
	 */
	function user_input_data($password) {
		if (!$this->getId()) {
			Console::log("Password Contain User Data (No User ID) FAILED");
			return false;
		}
		$return = true;
		$data = array("user_email", "user_username", "user_name_first", "user_name_last", "user_phone");
		
		$r = $this->db->select("users", array("user_ID" => $this->getId()), $data);
		if (!$r) {
			Console::log("Password Contain User Data (No User) FAILED");
			return false;
		}
		
		$user = $this->db->fetch_assoc($r);
		$words = array();
		foreach ($user as $key => $value) {
			if (!strlen($value)) continue;
			
			if (!$this->password_similarity($password, $value)) {
				$words[] = $value;
				$return = false;
			}
			
		}
		
		if ($return) {
			Console::log("Password Contain User Data PASSED");
		} else {
			$this->errors["user_input_data"] = "Too similar too ".implode(", ", $words).".";
			Console::log("Password Contain User Data FAILED");
		}
		
		return $return;
	}
    
	/**
	 * Checks the similarity of a string to the password
	 *
	 * @param string $password Password
	 * @return bool
	 */
	public function password_similarity($password, $text) {
		$return = true;
		$str_array = preg_split("/[^a-z]/", strtolower($text));
		
		// string in password
		foreach ($str_array as $str) {
			if (strlen($str) > 2 && stripos($password, $str) !== false) {
				$return = false;
			}
		}
		
		/*
		similar_text() — Calculate the similarity between two strings
		levenshtein() - Calculate Levenshtein distance between two strings
		soundex() - Calculate the soundex key of a string
		metaphone() - Similar to soundex, and possibly more effective for you. It's more accurate than soundex() as it knows the basic rules of English pronunciation. The metaphone generated keys are of variable length.
		*/
		
		/*$percent = 0;
			
		// levenshtein
		$max=max(strlen($password), strlen($value));
		
		$lev = levenshtein($password, $value);
		$percent = -100*$lev/$max+100;
		
		if ($percent > 20) {
			
		}*/
		return $return;
	}
	
	/**
	 * Salting a password
	 *
	 * @param string $password Password
	 * @param string $email
	 * @return string
	 */
	public function salt($password, $email = '') {
		return $password.$this->hashEmail($email).PASSWORD_SALT;
	}
	
	/**
	 * hashing a password
	 *
	 * @param string $password Password
	 * @param string $email
	 * @return string
	 */
	function hash($password, $email = '') {
		Timers::start('hash');

		$password = $this->salt($password,$email);

		if (PASSWORD_HASH == 'PBKDF2') {
			$return = $this->pbkdf2_hash($password, PBKDF2_SALT, PBKDF2_BINARY, PBKDF2_ITERATIONS, PBKDF2_KEY_LENGTH, PBKDF2_ALGORITHM);
		} else if (PASSWORD_HASH == 'bcrypt') {
			$return = $this->bcrypt_hash($password, BCRYPT_WORK_FACTOR);
		} else if (PASSWORD_HASH == 'scrypt') {
			$return = $this->scrypt_hash($password, SCRYPT_SALT, SCRYPT_PEPPER, SCRYPT_CPU, SCRYPT_MEMORY, SCRYPT_PARALLEL, SCRYPT_KEY_LENGTH);
		}

		Timers::stop('hash');
		return $return;
	}
	
	/**
	 * checking a password against a hash
	 *
	 * @param string $password Password
	 * @param string $hash Hash of stored password
	 * @param string $email
	 * @return bool
	 */
	function check($password, $hash, $email = '') {
		Timers::start('hash_check');

		$password = $this->salt($password,$email);

		if (PASSWORD_HASH == 'PBKDF2') {
			$return = $this->pbkdf2_check($password, $hash, PBKDF2_SALT, PBKDF2_BINARY, PBKDF2_ITERATIONS, PBKDF2_KEY_LENGTH, PBKDF2_ALGORITHM);
		} else if (PASSWORD_HASH == 'bcrypt') {
			$return = $this->bcrypt_check($password, $hash);
		} else if (PASSWORD_HASH == 'scrypt') {
			$return = $this->scrypt_check($password, $hash, SCRYPT_PEPPER, SCRYPT_KEY_LENGTH);
		}

		Timers::stop('hash_check');
		return $return;
	}

	/**
	 * generate pbkdf2 hash
	 * @src https://github.com/P54l0m5h1k/PBKDF2-implementation-PHP/blob/master/crypt.php
	 * @static
	 * @param string $password   -		defined password
	 * @param string $salt	   -		defined salt
	 * @param bool   $binary	 - generate binary data, or base64 encoded string
	 * @param int	$iterations - how many iterations to perform
	 * @param int	$keylength  - key length
	 * @param string $algorithm  - hashing algorithm
	 * @return bool|string
	 */
	private static function pbkdf2_hash($password, $salt, $binary = true, $iterations = 10000, $keylength = 32, $algorithm = 'sha256') {
		if (!in_array($algorithm, hash_algos()))
			return false;
		$derivedkey = '';
		for ($block = 1; $block <= ceil($keylength / strlen(hash($algorithm, null, true))); $block++):
			$ib = $b = hash_hmac($algorithm, $salt.pack('N', $block), $password, true);
			for ($i = 1; $i < $iterations; $i++)
				$ib ^= ($b = hash_hmac($algorithm, $b, $password, true));
			$derivedkey .= $ib;
		endfor;
		return $binary ? substr($derivedkey, 0, $keylength) : base64_encode(substr($derivedkey, 0, $keylength));
	}

	private static function pbkdf2_check($password, $hash, $salt, $binary = true, $iterations = 10000, $keylength = 32, $algorithm = 'sha256') {
		$return = $this->pbkdf2_hash($password, $salt, $binary, $iterations, $keylength, $algorithm) == $hash;
	}

	private static function bcrypt_hash($password, $work_factor = 8) {
		if (version_compare(PHP_VERSION, '5.3') < 0) throw new Exception('Bcrypt requires PHP 5.3 or above');

		if (! function_exists('openssl_random_pseudo_bytes')) {
			throw new Exception('Bcrypt requires openssl PHP extension');
		}

		if ($work_factor < 4 || $work_factor > 31) $work_factor = 8;
		$salt =
			'$2a$' . str_pad($work_factor, 2, '0', STR_PAD_LEFT) . '$' .
			substr(
				strtr(base64_encode(openssl_random_pseudo_bytes(16)), '+', '.'),
				0, 22
			);
		return crypt($password, $salt);
	}

	private static function bcrypt_check($password, $hash) {
		if (version_compare(PHP_VERSION, '5.3') < 0) throw new Exception('Bcrypt requires PHP 5.3 or above');

		return crypt($password, $hash) == $hash;
	}

	/**
	 * Create a password hash
	 *
	 * @src https://github.com/DomBlack/php-scrypt/blob/master/scrypt.php
	 * @param string $password The clear text password
	 * @param string $salt     The salt to use, or null to generate a random one
	 * @param int	$N         The CPU difficultly (must be a power of 2,  > 1)
	 * @param int	$r         The memory difficultly
	 * @param int	$p         The parallel difficultly
	 *
	 * @return string The hashed password
	 */
	private static function scrypt_hash($password, $salt = false, $pepper = '', $N = 16384, $r = 8, $p = 1, $key_length = 32, $salt_length = 8) {
		if ($salt === false) {
			$salt = '';
				$possibleChars = '0123456789abcdefghijklmnopqrstuvwxyz';
				$noOfChars = strlen($possibleChars) - 1;

				for ($i = 0; $i < $salt_length; $i++) {
					$salt .= $possibleChars[mt_rand(0, $noOfChars)];
				}
		} else {
			//Remove dollar signs from the salt, as we use that as a separator.
			$salt = str_replace('$', '', $salt);
		}

		$hash = scrypt($password, $pepper.$salt, $N, $r, $p, $key_length);

		return $N.'$'.$r.'$'.$p.'$'.$salt.'$'.$hash;
	}

	/**
	 * Check a clear text password against a hash
	 *
	 * @src https://github.com/DomBlack/php-scrypt/blob/master/scrypt.php
	 * @param string $password   The clear text password
	 * @param string $hash	   The hashed password
	 * @param string $pepper	 A little extra to be added to the salt
	 * @param string $key_length Length of the scrypt key
	 *
	 * @return boolean If the clear text matches
	 */
	private static function scrypt_check($password, $hash, $pepper = '', $key_length = 32) {
		list($N, $r, $p, $salt, $hash) = explode('$', $hash);

		return scrypt(
			$password, $pepper.$salt,
			$N, $r, $p,
			self::$key_length
		) == $hash;
	}

	/**
	 * encryption
	 * @src https://github.com/P54l0m5h1k/PBKDF2-implementation-PHP/blob/master/crypt.php
	 * @param mixed  $msg	- message/data
	 * @param string $k	  - encryption key
	 * @param bool   $binary - base64 encode result
	 * @return bool|string   - iv+ciphertext+mac
	 */
	private static function encrypt($msg, $k, $binary = true)
	{
		if (!$td = mcrypt_module_open('rijndael-256', '', 'ctr', ''))
			return false;
		if (mcrypt_generic_init($td, $k, $iv = mcrypt_create_iv(32, MCRYPT_RAND)) !== 0)
			return false;
		$msg .= self::pbkdf2($msg = $iv.mcrypt_generic($td, serialize($msg)), $k, 1000, 32);
		mcrypt_generic_deinit($td);
		mcrypt_module_close($td);
		return $binary ? $msg : base64_encode($msg);
	}

	/**
	 * decryption
	 * @src https://github.com/P54l0m5h1k/PBKDF2-implementation-PHP/blob/master/crypt.php
	 * @param string $msg	- output from encrypt()
	 * @param string $k	  - encryption key
	 * @param bool   $binary - base64 decode msg
	 * @return bool|string   - original data
	 */
	public static function decrypt($msg, $k, $binary = true)
	{
		if (!$binary)
			$msg = base64_decode($msg);
		if (!$td = mcrypt_module_open('rijndael-256', '', 'ctr', ''))
			return false;
		$iv = substr($msg, 0, 32);
		$mo = strlen($msg) - 32;
		$em = substr($msg, $mo);
		$msg = substr($msg, 32, strlen($msg) - 64);
		$mac = self::pbkdf2($iv.$msg, $k, 1000, 32);
		if ($em !== $mac)
			return false;
		if (mcrypt_generic_init($td, $k, $iv) !== 0)
			return false;
		$msg = unserialize(mdecrypt_generic($td, $msg));
		mcrypt_generic_deinit($td);
		mcrypt_module_close($td);
		return $msg;
	}

}

function passowrdImportBlacklist() {
	
}

function passwordImportDictionary() {
	
}


?>