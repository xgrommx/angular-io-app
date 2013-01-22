<?php


require_once 'class.db.php';

class Company {
	private $table = 'companies';

	function __construct() {
		global $database, $session, $filter;
		$this->db = $database;
		$this->filter = $filter;
		$this->session = $session;
		
		// Dev
		$this->log = FirePHP::getInstance(true);
		$this->timer = new Timers;
	}

	function __destruct() {
		
	}
	
	private function __log($var_dump) {
		$this->log->fb($var_dump, FirePHP::INFO);
	}

	/**
	 *	get a list of users for a company
	 *	session company only (privacy)
	 *
	 */
	function get_user($user_ID=NULL) {
		$return = array();

		$db_where = array('company_ID' => COMPANY_ID);
		if (!is_null($user_ID)) {
			$db_where['user_ID'] = $user_ID;
		}/* else {
			$db_where['user_ID'] =$this->session->cookie["user_ID"];
		}*/

		$results = $this->db->select('users',
			$db_where,
			array("user_ID", "user_name", "user_name_first", "user_name_last", "user_email", "user_phone", "user_details")
		);
		if ($results) {
			while($user = $this->db->fetch_assoc($results, array("user_phone"))) {
				$user['user_ID'] = $user['user_ID'];
				$return[] = $user;
			}
			if (!is_null($user_ID)) {
				$return = $return[0];
			}
		}

		return $return;
	}

	// new user
	function post_user($request_data=NULL) {
		$return = array();
		$params = array(
			"user_ID",
			"user_name",
			"user_email",
			"user_cell",
			"user_phone",
			"user_fax",
			"user_function",
		);

		foreach ($params as $key) {
			$request_data[$key] = isset($request_data[$key]) ? $request_data[$key] : NULL;
		}

		$this->filter->set_request_data($request_data);
		$this->filter->set_group_rules('users');
		if(!$this->filter->run()) {
			$return["alerts"] = $this->filter->get_errors();
			return $return;
		}
		$request_data = $this->filter->get_request_data();

		$user = array(
			'company_ID' => COMPANY_ID,
			'user_name' => $request_data['user_name'],
			'user_email' => $request_data['user_email'],
			'user_phone' => $request_data['user_phone'],
			'timestamp_create' => $_SERVER['REQUEST_TIME'],
			'timestamp_update' => $_SERVER['REQUEST_TIME'],
		);

		//print_r($user);
		$user_ID = $this->db->insert('users', $user);

		// SEND MESSAGE TO create password.
		/*if ($user_ID != $request_data['user_ID']) {
			mail($request_data['user_email'], "Account Created",
				"An RFQs.ca account was created for you.\n\n"
				." To set your password visit https://rfqs.ca/app and click reset.\n\n"
				."Kind Regards,\n\n"
				."RFQs.ca Customer Support.");
		}*/
		
		return $user_ID;
	}

	/*
	 *	get a company details
	 *
	 */
	function get($company_ID=NULL) {
		$return = array();
		$company_ID = is_null($company_ID) ? COMPANY_ID: $company_ID;

		$results = $this->db->select('companies',
			array('company_ID' => $company_ID),
			array('company_ID','company_name','company_url','company_phone','company_details','user_default_ID','location_default_ID')
		);
		if ($results) {
			$company = $this->db->fetch_assoc($results, array("company_phone"));
			
			$return = $company;
			
			// primary user
			$results = $this->db->select('users',
				array('company_ID' => COMPANY_ID, 'user_ID' => $company['user_default_ID']),
				array("user_ID", "user_name", "user_name_first", "user_name_last", "user_email", "user_phone", "user_details")
			);
			while ($results && $user = $this->db->fetch_assoc($results, array("user_phone"))) {
				$user['user_ID'] = $user['user_ID'];
				$return['user'] = $user;
			}
			// get users
			/*$results = $this->db->select('users',
				array('company_ID' => COMPANY_ID),
				array("user_ID", "user_name", "user_name_first", "user_name_last", "user_email", "user_details")
			);
			while ($results && $user = $this->db->fetch_assoc($results)) {
				$return['users'][$user['user_ID']] = $user;
			}*/
			
			// primary location
			$results = $this->db->select('locations',
				array('company_ID' => COMPANY_ID, 'location_ID' => $company['location_default_ID']),
				array('location_ID', 'company_ID', 'location_name', 'address_1', 'address_2', 'city', 'region_code', 'country_code', 'mail_code', 'latitude', 'longitude', 'location_phone', 'location_fax')
			);
			while ($results && $location = $this->db->fetch_assoc($results)) {
				$location['company_ID'] =  $location['company_ID'];
				$location['location_ID'] =  $location['location_ID'];
				$location['latitude'] = (double) $location['latitude'];
				$location['longitude'] = (double) $location['longitude'];
				$return['location'] = $location;
			}
			// get locations
			/*$results = $this->db->select('locations', array('company_ID' => COMPANY_ID));
			while ($results && $location = $this->db->fetch_assoc($results)) {
				$return['locations'][$location['location_ID']] = $location;
			}*/

		}
		//print_r($return);
		return $return;
	}

	/*
	create company detials for signup
	session company only (privacy)
	*/
	function post($request_data=NULL) {
		$alerts = array();
		$params = array(
			// company
			"company_name",
			"company_url",
			"company_phone",

		);

		foreach ($params as $key) {
			$request_data[$key] = isset($request_data[$key]) ? $request_data[$key] : NULL;
		}

		// validate and sanitize
		/*$this->filter->set_request_data($request_data);
		$this->filter->set_group_rules('companies,locations,users');
		$this->filter->set_key_rules(array('company_name', 'company_type', 'address_1', 'city', 'region_code', 'country_code', 'mail_code', 'user_name', 'user_email', 'password'), 'required');
		$this->filter->set_all_rules('trim|sanitize_string', true);
		if(!$this->filter->run()) {
			$return["errors"] = $this->filter->get_errors();
			return $return;
		}
		$request_data = $this->filter->get_request_data();*/

		// company //
		$company = array(
			"company_name"			=> $request_data["company_name"],
			"company_url"			=> $request_data["company_url"],
			"company_phone"			=> $request_data["company_phone"],
			"user_default_ID"		=> USER_ID,
			'timestamp_create' 		=> $_SERVER['REQUEST_TIME'],
			'timestamp_update' 		=> $_SERVER['REQUEST_TIME'],
		);
		$company_ID = $this->db->insert('companies', $company);

		// add to user
		$this->db->update('users', array('company_ID' => $company_ID), array('user_ID' => USER_ID));
		
		$this->session->update();	// add company_ID into session
		
		return $company_ID;
	}

	function put($request_data=NULL) {
		$alerts = array();
		$params = array(
			// company
			"company_name",
			"company_url",
			"company_details",
			"company_phone",
		);

		foreach ($params as $key) {
			$request_data[$key] = isset($request_data[$key]) ? $request_data[$key] : NULL;
		}

		// company //
		$company = array(
			"company_ID"			=> COMPANY_ID,
			"company_name"			=> $request_data["company_name"],
			"company_url"			=> $request_data["company_url"],
			"company_phone"			=> $request_data["company_phone"],
			"company_details"		=> $request_data["company_details"],
			//'timestamp_create' 		=> $_SERVER['REQUEST_TIME'],
			'timestamp_update' 		=> $_SERVER['REQUEST_TIME'],
		);



		$this->db->insert_update('companies', $company, $company);

		return;
	}

}

?>