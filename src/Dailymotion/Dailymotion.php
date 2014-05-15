<?php

namespace Dailymotion;

use Dailymotion\Exception\AuthException;
use Dailymotion\Exception\UploadException;

class Dailymotion
{
    const ROOT_ENDPOINT                     = 'https://api.dailymotion.com';
    const AUTH_ENDPOINT                     = 'https://api.dailymotion.com/oauth/authorize';
    const ACCESS_TOKEN_ENDPOINT             = '/oauth/token';
    const CLIENT_CREDENTIALS_TOKEN_ENDPOINT = '/oauth/authorize/client';
    const USER_AGENT                        = 'trappar/dailymotion-php-sdk';

    private $clientId;
    private $clientSecret;
    private $accessToken;
    private $redirectUri;
    private $scopes = 'public'; //Default value do not set here

    /**
     * @param [type] $client_id     [description]
     * @param [type] $client_secret [description]
     * @param [type] $access_token  [description]
     */
    public function __construct($clientId = null, $clientSecret = null, $accessToken = null)
    {
        $this->setClientId($clientId);
        $this->setClientSecret($clientSecret);
        $this->setAccessToken($accessToken);
    }

    /**
     * @return string|null
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @param string $clientId
     */
    public function setClientId($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * @return string|null
     */
    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    /**
     * @param string $clientSecret
     */
    public function setClientSecret($clientSecret)
    {
        $this->clientSecret = $clientSecret;
    }

    /**
     * @return string|null
     */
    public function getRedirectUri()
    {
        return $this->redirectUri;
    }

    /**
     * @param string $redirectUri
     */
    public function setRedirectUri($redirectUri)
    {
        $this->redirectUri = $redirectUri;
    }

    /**
     * @return string
     */
    public function getScopes()
    {
        return $this->scopes;
    }

    /**
     * Set the requested scopes for authorization
     * @param string|array $scopes - String input should be space-separated
     */
    public function setScopes($scopes)
    {
        if (is_array($scopes)) {
            $scopes = implode(' ', $scopes);
        }

        $this->scopes = $scopes;
    }

    /**
     * @return string|null
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @param string $access_token
     */
    public function setAccessToken($access_token)
    {
        $this->accessToken = $access_token;
    }

    /**
     * @param  string $method
     * @param  string $url
     * @param  array  $params
     * @return mixed
     */
    public function request($method, $url, $params = array())
    {
        $headers[] = 'User-Agent: ' . self::USER_AGENT;

        if ($this->getAccessToken()) {
            $headers[] = 'Authorization: Bearer ' . $this->getAccessToken();
        } else {
            $headers[] = 'Authorization: Basic ' . $this->authHeader();
        }

        $curl_url  = "";
        $curl_opts = array();
        switch (strtoupper($method)) {
            case 'GET' :
                $curl_url = self::ROOT_ENDPOINT . $url . '?' . http_build_query($params, '', '&');
                break;

            case 'POST' :
            case 'PATCH' :
            case 'PUT' :
            case 'DELETE' :
                $curl_url  = self::ROOT_ENDPOINT . $url;
                $curl_opts = array(
                    CURLOPT_POST          => true,
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_POSTFIELDS    => http_build_query($params, '', '&')
                );
                break;
        }

        //  Set the headers
        $curl_opts[CURLOPT_HTTPHEADER] = $headers;

        $response = $this->curlRequest($curl_url, $curl_opts);

        $response = $this->decodeResponseBody($response);

        return $response;
    }

    /**
     * Get authorization header for retrieving tokens/credentials.
     * @return string
     */
    private function authHeader()
    {
        return base64_encode($this->clientId . ':' . $this->clientSecret);
    }

    /**
     * Internal function to handle requests, both authenticated and by the upload function.
     * @param string $url       full url for this request
     * @param array  $curl_opts array of curl options
     * @return array response with body, status, and headers
     */
    private function curlRequest($url, $curl_opts = array())
    {
        //  Apply the defaults to the curl opts.
        $curl_opt_defaults = array(
            CURLOPT_HEADER         => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30);

        //  Can't use array_merge since it would reset the numbering to 0 and lose the CURLOPT constant values.
        //  Insetad we find the overwritten ones and manually merge.
        $overwritten_keys = array_intersect(array_keys($curl_opts), array_keys($curl_opt_defaults));
        foreach ($curl_opt_defaults as $setting => $value) {
            if (in_array($setting, $overwritten_keys)) {
                break;
            }
            $curl_opts[$setting] = $value;
        }

        // Call the API
        $curl = curl_init($url);
        curl_setopt_array($curl, $curl_opts);
        $response  = curl_exec($curl);
        $curl_info = curl_getinfo($curl);
        curl_close($curl);

        //  Retrieve the info
        $header_size = $curl_info['header_size'];
        $headers     = substr($response, 0, $header_size);
        $body        = substr($response, $header_size);

        //  Return it raw.
        return array(
            'body'    => $body,
            'status'  => $curl_info['http_code'],
            'headers' => self::parseHeaders($headers)
        );
    }

    /**
     * Parse headers into an array
     * @param  array $headers
     * @return array
     */
    protected static function parseHeaders($headers)
    {
        $final_headers = array();
        $list          = explode("\n", trim($headers));

        $http = array_shift($list);

        foreach ($list as $header) {
            $parts                          = explode(':', $header);
            $final_headers[trim($parts[0])] = isset($parts[1]) ? trim($parts[1]) : '';
        }

        return $final_headers;
    }

    /**
     * Build an authorization url to send the user to
     * @param string|null $state
     * @return string
     */
    public function buildAuthorizationEndpoint($state = null)
    {
        $this->verifyAuthInfoPresent('Can not build authorization endpoint without Client ID, Client Secret, and Redirect URI');

        $query = array(
            "response_type" => 'code',
            "client_id"     => $this->getClientId(),
            "redirect_uri"  => $this->getRedirectUri()
        );

        $query['scope'] = $this->getScopes();

        if (!empty($state)) {
            $query['state'] = $state;
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($query);
    }

    /**
     * Exchange an authorization code for an access token.
     * @param  string $authorizationCode
     * @return string
     * @throws AuthException
     */
    public function authorize($authorizationCode)
    {
        $this->verifyAuthInfoPresent('Can not get access token without Client ID, Client Secret, and Redirect URI');

        $response = $this->request("POST", self::ACCESS_TOKEN_ENDPOINT, array(
            'grant_type'    => 'authorization_code',
            'code'          => $authorizationCode,
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri'  => $this->getRedirectUri()
        ));

        if (isset($response['body']['access_token'])) {
            $this->setAccessToken($response['body']['access_token']);
        }

        return $response;
    }

    /**
     * Shortcut method for posting a new video to the granted user account
     * @param string $filePath relative/absolute path to local file
     * @param array  $params
     * @return mixed
     */
    public function postVideo($filePath, $params = array())
    {
        $params['url'] = $this->upload($filePath);

        return $this->request('POST', '/me/videos', $params);
    }

    /**
     * Upload a file to be used in a subsequent /me/videos request
     * @param string $filePath Path to the video file to upload.
     * @return string uploaded url
     * @throws UploadException
     */
    public function upload($filePath)
    {
        //  Validate that our file is real.
        if (!file_exists($filePath)) {
            throw new UploadException('Unable to locate file to upload: ' . $filePath);
        }

        //  Begin the upload request by getting an upload url
        $response = $this->request('GET', '/file/upload');
        if ($response['status'] != 200) {
            throw new UploadException('Unable to get an upload ticket.');
        }

        $url = $response['body']['upload_url'];

        $curl_opts = array(
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => array(
                'file' => new \CURLFile(realpath($filePath))
            ),
            CURLOPT_TIMEOUT    => null
        );

        $response = $this->curlRequest($url, $curl_opts);
        $response = $this->decodeResponseBody($response);

        return $response['body']['url'];
    }

    /**
     * @param array $response from a request/curlRequest
     * @return array
     */
    private function decodeResponseBody($response)
    {
        $response['body'] = json_decode($response['body'], true);

        return $response;
    }

    private function verifyAuthInfoPresent($message)
    {
        if (!$this->getClientId() || !$this->getClientSecret() || !$this->getRedirectUri()) {
            throw new AuthException($message);
        }
    }
}