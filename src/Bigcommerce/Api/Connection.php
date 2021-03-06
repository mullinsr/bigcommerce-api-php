<?php

namespace Bigcommerce\Api;

/**
 * HTTP connection.
 */
class Connection
{

    /**
     * @var resource cURL resource
     */
    private $curl;

    /**
     * @var array Hash of HTTP request headers.
     */
    private $headers = array();

    /**
     * @var array Hash of headers from HTTP response
     */
    private $responseHeaders = array();

    /**
     * The status line of the response.
     * @var string
     */
    private $responseStatusLine;

    /**
     * @var string response body
     */
    private $responseBody;

    /**
     * @var boolean
     */
    private $failOnError = false;

    /**
     * Manually follow location redirects. Used if FOLLOWLOCATION
     * is unavailable due to open_basedir restriction.
     * @var boolean
     */
    private $followLocation = false;

    /**
     * Maximum number of redirects to try.
     * @var int
     */
    private $maxRedirects = 20;

    /**
     * Number of redirects followed in a loop.
     * @var int
     */
    private $redirectsFollowed = 0;

    /**
     * Deal with failed requests if failOnError is not set.
     * @var string | false
     */
    private $lastError = false;

    /**
     * Determines whether requests and responses should be treated
     * as XML. Defaults to false (using JSON).
     */
    private $useXml = false;
    
    /**
     * oAuth Client ID
     *
     * @var string
     */
    private $client_id;
    
    /**
     * oAuth Access Token
     *
     * @var string
     */
    private $oauth_token;
    
    /**
     * Hold the last request, so that we can retry if rate-limited.
     *
     * @var mixed
     */
    private $lastRequest;
    

    /**
     * Initializes the connection object.
     */
    public function __construct()
    {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, array($this, 'parseHeader'));
        curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, array($this, 'parseBody'));

        if (!ini_get("open_basedir")) {
            curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
        } else {
            $this->followLocation = true;
        }

        $this->setTimeout(60);
    }

    /**
     * Controls whether requests and responses should be treated
     * as XML. Defaults to false (using JSON).
     */
    public function useXml($option = true)
    {
        $this->useXml = $option;
    }

    /**
     * Throw an exception if the request encounters an HTTP error condition.
     *
     * <p>An error condition is considered to be:</p>
     *
     * <ul>
     *    <li>400-499 - Client error</li>
     *    <li>500-599 - Server error</li>
     * </ul>
     *
     * <p><em>Note that this doesn't use the builtin CURL_FAILONERROR option,
     * as this fails fast, making the HTTP body and headers inaccessible.</em></p>
     */
    public function failOnError($option = true)
    {
        $this->failOnError = $option;
    }

    /**
     * Sets the HTTP basic authentication.
     */
    public function authenticateBasic($username, $password)
    {
        curl_setopt($this->curl, CURLOPT_USERPWD, "$username:$password");
    }
    
    /**
     * Sets the HTTP oAuth autentication.
     */
    public function authenticateOauth($client_id, $oauth_token)
    {
    		$this->client_id   = $client_id;
    		$this->oauth_token = $oauth_token;
    	}
    		
    /**
     * Set a default timeout for the request. The client will error if the
     * request takes longer than this to respond.
     *
     * @param int $timeout number of seconds to wait on a response
     */
    public function setTimeout($timeout)
    {
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $timeout);
    }

    /**
     * Set a proxy server for outgoing requests to tunnel through.
     */
    public function useProxy($server, $port = false)
    {
        curl_setopt($this->curl, CURLOPT_PROXY, $server);

        if ($port) {
            curl_setopt($this->curl, CURLOPT_PROXYPORT, $port);
        }
    }

    /**
     * @todo may need to handle CURLOPT_SSL_VERIFYHOST and CURLOPT_CAINFO as well
     * @param boolean
     */
    public function verifyPeer($option = false)
    {
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, $option);
    }

    /**
     * Add a custom header to the request.
     */
    public function addHeader($header, $value)
    {
        $this->headers[$header] = "$header: $value";
    }

    /**
     * Get the MIME type that should be used for this request.
     */
    private function getContentType()
    {
        return ($this->useXml) ? 'application/xml' : 'application/json';
    }

    /**
     * Clear previously cached request data and prepare for
     * making a fresh request.
     */
    private function initializeRequest()
    {
        $this->isComplete = false;
        $this->responseBody = '';
        $this->responseHeaders = array();
        $this->lastError = false;
        $this->addHeader('Accept', $this->getContentType());
        
        if (isset($this->client_id) && isset($this->oauth_token)) {
        		$this->addHeader('X-Auth-Client', $this->client_id);
        		$this->addHeader('X-Auth-Token',  $this->oauth_token);
        	}

        curl_setopt($this->curl, CURLOPT_POST, false);
        curl_setopt($this->curl, CURLOPT_PUT, false);
        curl_setopt($this->curl, CURLOPT_HTTPGET, false);

        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->headers);
    }

    /**
     * Check the response for possible errors and handle the response body returned.
     *
     * If failOnError is true, a client or server error is raised, otherwise returns false
     * on error.
     */
    private function handleResponse()
    {
    		if ($this->getHeader("X-Retry-After") !== null) { 		//If the request failed due to being rate limited, sleep and retry
    			sleep($this->getHeader("X-Retry-After") + 1);		//Sleep
    			$method = $this->lastRequest[0];
    			if ($method != 'delete') { //get,post,put
    				$url = $this->lastRequest[1];
    				$query = $this->lastRequest[2];
    				return $this->$method($url,$query);  //retry
    			} else {	 //delete
    				$url = $this->lastRequest[1];
    				return $this->$method($url);	  //retry
    			}
    		} else {
		    if (curl_errno($this->curl)) {
		        throw new NetworkError(curl_error($this->curl), curl_errno($this->curl));
		    }

		    $body = ($this->useXml) ? $this->getBody() : json_decode($this->getBody());

		    $status = $this->getStatus();

		    if ($status >= 400 && $status <= 499) {
		        if ($this->failOnError) {
		            throw new ClientError($body, $status);
		        } else {
		            $this->lastError = $body;
		            return false;
		        }
		    } else if ($status >= 500 && $status <= 599) {
		        if ($this->failOnError) {
		            throw new ServerError($body, $status);
		        } else {
		            $this->lastError = $body;
		            return false;
		        }
		    }

		    if ($this->followLocation) {
		        $this->followRedirectPath();
		    }

		    return $body;
		}
    }

    /**
     * Return an representation of an error returned by the last request, or false
     * if the last request was not an error.
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Recursively follow redirect until an OK response is recieved or
     * the maximum redirects limit is reached.
     *
     * Only 301 and 302 redirects are handled. Redirects from POST and PUT requests will
     * be converted into GET requests, as per the HTTP spec.
     */
    private function followRedirectPath()
    {
        $this->redirectsFollowed++;

        if ($this->getStatus() == 301 || $this->getStatus() == 302) {

            if ($this->redirectsFollowed < $this->maxRedirects) {

                $location = $this->getHeader('Location');
                $forwardTo = parse_url($location);

                if (isset($forwardTo['scheme']) && isset($forwardTo['host'])) {
                    $url = $location;
                } else {
                    $forwardFrom = parse_url(curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL));
                    $url = $forwardFrom['scheme'] . '://' . $forwardFrom['host'] . $location;
                }

                $this->get($url);

            } else {
                $errorString = "Too many redirects when trying to follow location.";
                throw new NetworkError($errorString, CURLE_TOO_MANY_REDIRECTS);
            }
        } else {
            $this->redirectsFollowed = 0;
        }
    }

    /**
     * Make an HTTP GET request to the specified endpoint.
     */
    public function get($url, $query = false)
    {
    		$this->lastRequest = array( //Save the resquest so we can retry if rate limited
			0 => 'get',
			1 => $url,
			2 => $query
		);
			
        $this->initializeRequest();

        if (is_array($query)) {
            $url .= '?' . http_build_query($query);
        }

        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        curl_exec($this->curl);
		
        return $this->handleResponse();
    }

    /**
     * Make an HTTP POST request to the specified endpoint.
     */
    public function post($url, $body)
    {
    		$this->lastRequest = array( //Save the resquest so we can retry if rate limited
			0 => 'post',
			1 => $url,
			2 => $body
		);
    
        $this->addHeader('Content-Type', $this->getContentType());

        if (!is_string($body)) {
            $body = json_encode($body);
        }

        $this->initializeRequest();

        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $body);
        curl_exec($this->curl);
        		
        return $this->handleResponse();
    }

    /**
     * Make an HTTP HEAD request to the specified endpoint.
     *
     * @param $url
     * @return bool|mixed|string
     */
    public function head($url)
    {
        $this->initializeRequest();

        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_NOBODY, true);
        curl_exec($this->curl);

        return $this->handleResponse();
    }

    /**
     * Make an HTTP PUT request to the specified endpoint.
     *
     * Requires a tmpfile() handle to be opened on the system, as the cURL
     * API requires it to send data.
     *
     * @param $url
     * @param $body
     * @return bool|mixed|string
     */
    public function put($url, $body)
    {
    		$this->lastRequest = array( //Save the resquest so we can retry if rate limited
			0 => 'put',
			1 => $url,
			2 => $body
		);
		
        $this->addHeader('Content-Type', $this->getContentType());

        if (!is_string($body)) {
            $body = json_encode($body);
        }

        $this->initializeRequest();

        $handle = tmpfile();
        fwrite($handle, $body);
        fseek($handle, 0);
        curl_setopt($this->curl, CURLOPT_INFILE, $handle);
        curl_setopt($this->curl, CURLOPT_INFILESIZE, strlen($body));

        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_PUT, true);
        curl_exec($this->curl);

        fclose($handle);
        curl_setopt($this->curl, CURLOPT_INFILE, STDIN);
		
        return $this->handleResponse();
    }

    /**
     * Make an HTTP DELETE request to the specified endpoint.
     *
     * @param $url
     * @return bool|mixed|string
     */
    public function delete($url)
    {
        $this->initializeRequest();

        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_exec($this->curl);

		$this->lastRequest = array( //Save the resquest so we can retry if rate limited
			0 => 'delete',
			1 => $url
		);
			
        return $this->handleResponse();
    }

    /**
     * Method that appears unused, but is in fact called by curl
     *
     * @param $curl
     * @param $body
     * @return int
     */
    private function parseBody($curl, $body)
    {
        $this->responseBody .= $body;
        return strlen($body);
    }

    /**
     * Method that appears unused, but is in fact called by curl
     *
     * @param $curl
     * @param $headers
     * @return int
     */
    private function parseHeader($curl, $headers)
    {
        if (!$this->responseStatusLine && strpos($headers, 'HTTP/') === 0) {
            $this->responseStatusLine = $headers;
        } else {
            $parts = explode(': ', $headers);
            if (isset($parts[1])) {
                $this->responseHeaders[$parts[0]] = trim($parts[1]);
            }
        }
        return strlen($headers);
    }

    /**
     * Access the status code of the response.
     */
    public function getStatus()
    {
        return curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
    }

    /**
     * Access the message string from the status line of the response.
     */
    public function getStatusMessage()
    {
        return $this->responseStatusLine;
    }

    /**
     * Access the content body of the response
     */
    public function getBody()
    {
        return $this->responseBody;
    }

    /**
     * Access given header from the response.
     */
    public function getHeader($header)
    {
        if (array_key_exists($header, $this->responseHeaders)) {
            return $this->responseHeaders[$header];
        }
    }

    /**
     * Return the full list of response headers
     */
    public function getHeaders()
    {
        return $this->responseHeaders;
    }

    /**
     * Close the cURL resource when the instance is garbage collected
     */
    public function __destruct()
    {
        curl_close($this->curl);
    }
}
