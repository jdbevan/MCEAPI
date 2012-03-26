<?php

	$inc_path = dirname(__FILE__);
	include_once $inc_path . '/MCAPI.class.php';
	include_once $inc_path . '/config.inc.php';

	/**
	 * Wee helper classes for slightly nicer code when creating segment filters
	 * No error checking.
	 */
	class Conditions {
		protected $conditions;
		
		function Conditions($field, $op, $value) {
			$this->conditions = array();
			$this->addCondition($field, $op, $value);
		}
		
		function addCondition($field, $op, $value, $extra = null) {
			if ($this->conditions === null) {
				$this->conditions = array();
			}
			
			$cond = array("field" => $field,
							"op" => $op,
							"value" => $value);
			 
			if ($extra !== null) {
				$cond["extra"] = $extra;
			}
			
			$this->conditions[] = $cond;
		}
		
		function getConditions(){
			return $this->conditions;
		}
	}
	class Segment extends Conditions {
		
		private $match;
		
		function Segment($match, $field=null, $op=null, $value=null) {
			// Set as any by default
			if (!in_array($match, array("all", "any"))) {
				$match = "any";
			}
			
			$this->match = $match;
			if ($field !== null and $op !== null and $value !== null) {
				parent::__construct($field, $op, $value);
			}
		}
		
		function asArray() {
			return array("match" => $this->match,
						"conditions" => $this->getConditions());
		}
		
		function setConditions($conds) {
			$this->conditions = $conds;
		}
	}
	
	/**
	 * Incomplete implementation of the Export API.
	 *
	 * Only downloads a list of subscriber data. Does not download list of activity:
	 * http://apidocs.mailchimp.com/export/1.0/
	 */
	class MCEAPI extends MCAPI {
		
		public $listID;

		/**
		 * Create wrapper for the Export API
		 * 
		 * @param string $apikey Your API key
		 * @param boolean $secure Use SSL connection
		 */
		function MCEAPI($apikey, $secure=false) {
			parent::__construct($apikey, $secure);
			
			// $this->apiUrl = parse_url("http://api.mailchimp.com/" . $this->version . "/?output=php");
			$this->version = '1.0';
   			$this->exportApiUrl = parse_url("http://api.mailchimp.com/export/" . $this->version);
		}
		
		/**
		 * Obtain a JSON list of subscribers based on $segment data
		 * Docs: http://apidocs.mailchimp.com/export/1.0/list.func.php
		 * 
		 * @param array $segment List of stuff to segment by
		 * @param string $status One of: subscribed (default), unsubscribed, cleaned
		 * @param string $since Only check members who's data has changed since YYY-MM-DD HH:mm:ss
		 * @return mixed False on no matches for segment, array of JSON strings on success
		 */
		function getSubscribers($segment, $status = "subscribed", $since = null) {
			// Check segment options
			$number_of_matches = $this->campaignSegmentTest($this->listID, $segment);
			if ($number_of_matches > 0 and isset($this->listID)) {
				
				$params = array("id" => $this->listID,
								"status" => $status,
								"segment" => $segment);
				
				if ($since !== null) {
					$params['since'] = $since;
				}
				
				return $this->callExportServer("list", $params);
			} else {
				$this->errorMessage = "No matches for segment";
				$this->errorCode = -99;
			}
			return false;
		}
		
		/**
		 * Actually connect to the server and call the requested methods, parsing and returning the result
		 * You should never have to call this function manually
		 * Stolen from the Normal API and modified for explicit and exclusive use by this class. The campaignSegmentText()
		 * call in getSubscribers() requires the original callServer function to be unmodified.
		 *
		 * @param string $method The action to perform on the API
		 * @param array $params Array containing an associative array of data to send to the API including a list ID, status, and segment
		 * @return mixed False on failure, array of JSON strings on success
		 */
		function callExportServer($method, $params) {
			$dc = "us1";
			if (strstr($this->api_key,"-")){
				list($key, $dc) = explode("-",$this->api_key,2);
				if (!$dc) $dc = "us1";
			}
			$host = $dc.".".$this->exportApiUrl["host"];
			$params["apikey"] = $this->api_key;

			$this->errorMessage = "";
			$this->errorCode = "";
			$sep_changed = false;
			//sigh, apparently some distribs change this to &amp; by default
			if (ini_get("arg_separator.output")!="&"){
				$sep_changed = true;
				$orig_sep = ini_get("arg_separator.output");
				ini_set("arg_separator.output", "&");
			}
			$post_vars = http_build_query($params);
			if ($sep_changed) {
				ini_set("arg_separator.output", $orig_sep);
			}

			$payload = "POST " . $this->exportApiUrl["path"] . "/" . $method . "/ HTTP/1.0\r\n";
			$payload .= "Host: " . $host . "\r\n";
			$payload .= "User-Agent: MCAPI/" . $this->version ."\r\n";
			$payload .= "Content-type: application/x-www-form-urlencoded\r\n";
			$payload .= "Content-length: " . strlen($post_vars) . "\r\n";
			$payload .= "Connection: close \r\n\r\n";
			$payload .= $post_vars;

			ob_start();
			if ($this->secure){
				$sock = fsockopen("ssl://".$host, 443, $errno, $errstr, 30);
			} else {
				$sock = fsockopen($host, 80, $errno, $errstr, 30);
			}
			if(!$sock) {
				$this->errorMessage = "Could not connect (ERR $errno: $errstr)";
				$this->errorCode = "-99";
				ob_end_clean();
				return false;
			}

			$response = "";
			fwrite($sock, $payload);
			stream_set_timeout($sock, $this->timeout);
			$info = stream_get_meta_data($sock);
			while ((!feof($sock)) && (!$info["timed_out"])) {
				$response .= fread($sock, $this->chunkSize);
				$info = stream_get_meta_data($sock);
			}
			fclose($sock);
			ob_end_clean();
			if ($info["timed_out"]) {
				$this->errorMessage = "Could not read response (timed out)";
				$this->errorCode = -98;
				return false;
			}

			list($headers, $response) = explode("\r\n\r\n", $response, 2);
			$headers = explode("\r\n", $headers);
			$errored = false;
			foreach($headers as $h){
				if (substr($h,0,26)==="X-MailChimp-API-Error-Code"){
					$errored = true;
					$error_code = trim(substr($h,27));
					break;
				}
			}

			if(ini_get("magic_quotes_runtime")) $response = stripslashes($response);

			$serial = @unserialize($response);
			$json = null;
			if ($serial === false) {
				
				/*
				 * Originally parsed the returned JSON into one great big JSON object
				 * that was returned by the MCEAPI object.
				 * Worked fine on a development machine where PHP had loads of memory
				 * to play with, but needed changing to return an array of JSON objects
				 * that can be parsed later on using json_decode() to avoid exceeding
				 * the memory limits
				 */
				
				$json = explode("\n", $response);
				//$json = array();
				//foreach ($json_lines as $line) {
				//	$json[] = json_decode("[" . $line . "]");
				//}
				
				//$json = json_decode("[" . substr( str_replace( array("\r\n", "\r", "\n"), ",", $response), 0, -1) . "]", true);
				
				/*switch (json_last_error()) {
					case JSON_ERROR_NONE:
						break;
					case JSON_ERROR_DEPTH:
						$this->errorMessage = "JSON Maximum Stack Depth Exceeded";
						$this->errorCode = -999;
						break;
					case JSON_ERROR_STATE_MISMATCH:
						$this->errorMessage = "JSON Underflow Or The Modes Mismatch";
						$this->errorCode = -999;
						break;
					case JSON_ERROR_CTRL_CHAR:
						$this->errorMessage = "JSON Unexpected Control Character Found";
						$this->errorCode = -999;
						break;
					case JSON_ERROR_SYNTAX:
						$this->errorMessage = "JSON Syntax Error, Malformed JSON";
						$this->errorCode = -999;
						break;
					case JSON_ERROR_UTF8:
						$this->errorMessage = "JSON Malformed UTF-8 Characters, Possibly Incorrectly Encoded";
						$this->errorCode = -999;
						break;
					default:
						$this->errorMessage = "JSON Unknown Error";
						$this->errorCode = -999;
						break;
				}*/
			}
			
			if($response && $serial === false && $json === null) {
				$response = array("error" => "Bad Response: " . $this->errorMessage, "code" => $this->errorCode);
			} else if ($json !== null) {
				$response = $json;
			} else {
				$response = $serial;
			}
			
			if($errored && is_array($response) && isset($response["error"])) {
				$this->errorMessage = $response["error"];
				$this->errorCode = $response["code"];
				return false;
			} elseif($errored) {
				$this->errorMessage = "No error message was found";
				$this->errorCode = $error_code;
				return false;
			}

			return $response;
		}
	}
	
	
	/*
	 * Sample usage:
	 */
	$mceapi = new MCEAPI($apikey);
	$mceapi->listID = '01ab34cd56';
	
	$segment = new Segment("all");
	$segment->addCondition("EMAIL", "like", "gmail.com");

	$subscribers = $mceapi->getSubscribers($segment->asArray());
	
	// Parse response
	foreach ($subscibers as $index=>$json_string) {
		if ($index == 0) {
			// Skip header row
			continue;
		}
		
		$subscriber_data = json_decode($json_string);
		//...
	}
?>