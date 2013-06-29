<?php

require_once 'ShopifyApiException.php';
require_once 'ShopifyCurlException.php';

/**
 * Shopify Client
 * @author Ferid Mövsümov
 */
class ShopifyClient {
	
	/**
	 * Domain name of the shop
	 * @var string
	 */
	public $shopDomain;
	
	/**
	 * Token
	 * @var string
	 */
	private $_token;
	
	/**
	 * api key
	 * @var string
	 */
	private $_apiKey;
	
	/**
	 * secret
	 * @var string
	 */
	private $secret;
	
	/**
	 * Last response headers
	 * @var string
	 */
	private $last_response_headers = null;

	/**
	 * Constructor method
	 * @param string $shopDomain
	 * @param string $token
	 * @param string $apiKey
	 * @param string $secret
	 */
	public function __construct($shopDomain, $token, $apiKey, $secret) {
		$this->name = "ShopifyClient";
		$this->shopDomain = $shopDomain;
		$this->_token = $token;
		$this->_apiKey = $apiKey;
		$this->secret = $secret;
	}
	
	/**
	 * Get the URL required to request authorization
	 * @param string $scope
	 * @param string $redirectUrl
	 * @return string
	 */
	public function getAuthorizeUrl($scope, $redirectUrl='') {
		$url = "http://{$this->shopDomain}/admin/oauth/authorize?client_id={$this->_apiKey}&scope=" . urlencode($scope);
		if ($redirectUrl != '')
		{
			$url .= "&redirect_uri=" . urlencode($redirectUrl);
		}
		return $url;
	}

	/**
	 * Once the User has authorized the app, call this with the code to get the access token
	 * @param string $code
	 * @return string
	 */
	public function getAccessToken($code) {
		// POST to  POST https://SHOP_NAME.myshopify.com/admin/oauth/access_token
		$url = "https://{$this->shopDomain}/admin/oauth/access_token";
		$payload = "client_id={$this->_apiKey}&client_secret={$this->secret}&code=$code";
		$response = $this->curlHttpApiRequest('POST', $url, '', $payload, array());
		$response = json_decode($response, true);
		if (isset($response['access_token']))
			return $response['access_token'];
		return '';
	}

	/**
	 * Returns number of calls
	 * @return number
	 */
	public function callsMade()
	{
		return $this->shopApiCallLimitParam(0);
	}

	/**
	 * Return call limit
	 * @return int
	 */
	public function callLimit()
	{
		return $this->shopApiCallLimitParam(1);
	}

	/**
	 * Returns left call number
	 * @param string $responseHeaders
	 * @return number
	 */
	public function callsLeft($responseHeaders)
	{
		return $this->callLimit() - $this->callsMade();
	}

	/**
	 * call method
	 * @param string $method
	 * @param string $path
	 * @param string[] $params
	 * @throws ShopifyApiException
	 * @return mixed
	 */
	public function call($method, $path, $params=array())
	{
		$baseurl = "https://{$this->shopDomain}/";
	
		$url = $baseurl.ltrim($path, '/');
		$query = in_array($method, array('GET','DELETE')) ? $params : array();
		$payload = in_array($method, array('POST','PUT')) ? stripslashes(json_encode($params)) : array();
		$request_headers = in_array($method, array('POST','PUT')) ? array("Content-Type: application/json; charset=utf-8", 'Expect:') : array();

		// add auth headers
		$request_headers[] = 'X-Shopify-Access-Token: ' . $this->_token;

		$response = $this->curlHttpApiRequest($method, $url, $query, $payload, $request_headers);
		$response = json_decode($response, true);

		if (isset($response['errors']) or ($this->last_response_headers['http_status_code'] >= 400))
			throw new ShopifyApiException($method, $path, $params, $this->last_response_headers, $response);

		return (is_array($response) and (count($response) > 0)) ? array_shift($response) : $response;
	}

	/**
	 * makes http api request
	 * @param string $method
	 * @param string $url
	 * @param string $query
	 * @param string $payload
	 * @param string[] $requestHeaders
	 * @throws ShopifyCurlException
	 * @return string
	 */
	private function curlHttpApiRequest($method, $url, $query='', $payload='', $requestHeaders=array())
	{
		$url = $this->curlAppendQuery($url, $query);
		$ch = curl_init($url);
		$this->curlSetopts($ch, $method, $payload, $requestHeaders);
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($errno) throw new ShopifyCurlException($error, $errno);
		list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
		$this->last_response_headers = $this->curlParseHeaders($message_headers);

		return $message_body;
	}

	/**
	 * @param string $url
	 * @param string $query
	 * @return string
	 */
	private function curlAppendQuery($url, $query)
	{
		if (empty($query)) return $url;
		if (is_array($query)) return "$url?".http_build_query($query);
		else return "$url?$query";
	}

	/**
	 * @param string $ch
	 * @param string $method
	 * @param string $payload
	 * @param string $requestHeaders
	 */
	private function curlSetopts($ch, $method, $payload, $requestHeaders)
	{
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_USERAGENT, 'HAC');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, $method);
		if (!empty($requestHeaders)) curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
		
		if ($method != 'GET' && !empty($payload))
		{
			if (is_array($payload)) $payload = http_build_query($payload);
			curl_setopt ($ch, CURLOPT_POSTFIELDS, $payload);
		}
	}

	/**
	 * parse headers
	 * @param string $messageHeaders
	 * @return string
	 */
	private function curlParseHeaders($messageHeaders)
	{
		$header_lines = preg_split("/\r\n|\n|\r/", $messageHeaders);
		$headers = array();
		list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim(array_shift($header_lines)), 3);
		foreach ($header_lines as $header_line)
		{
			list($name, $value) = explode(':', $header_line, 2);
			$name = strtolower($name);
			$headers[$name] = trim($value);
		}

		return $headers;
	}
	
	/**
	 * @param int $index
	 * @throws Exception
	 * @return number
	 */
	private function shopApiCallLimitParam($index)
	{
		if ($this->last_response_headers == null)
		{
			throw new Exception('Cannot be called before an API call.');
		}
		$params = explode('/', $this->last_response_headers['http_x_shopify_shop_api_call_limit']);
		return (int) $params[$index];
	}	
}