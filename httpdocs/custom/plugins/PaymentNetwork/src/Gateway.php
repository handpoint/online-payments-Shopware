<?php

namespace P3\PaymentNetwork;

use \RuntimeException;
use \InvalidArgumentException;

/**
 * Class to communicate with Payment Gateway
 */
class Gateway
{
    /**
     * @var string	Gateway Hosted API Endpoint
     */
    protected $hostedUrl = 'https://commerce-api.handpoint.com/hosted/';

    /**
     * @var string	Gateway Direct API Endpoint
     */
    protected $directUrl = 'https://commerce-api.handpoint.com/direct/';

    /**
     * @var string	Merchant Account Id or Alias
     */
    protected $merchantID = '155928';

    /**
     * @var string	Secret for above Merchant Account
     */
    protected $merchantSecret = 'm3rch4nts1gn4tur3k3y';

    /**
     * Useful response codes
     */
    const RC_SUCCESS						= 0;	// Transaction successful
    const RC_DO_NOT_HONOR					= 5;	// Transaction declined
    const RC_NO_REASON_TO_DECLINE			= 85;	// Verification successful

    const RC_3DS_AUTHENTICATION_REQUIRED	= 0x1010A;

    /**
     * @var boolean	Enable debugging
     */
    static public $debug = false;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * The constructor accepts the following options:
     *  + 'hostedUrl'		- Gateway Hosted API Endpoint
     *  + 'directUrl'		- Gateway Direct API Endpoint
     *  + 'merchantID'		- Merchant Account Id or Alias
     *  + 'merchantSecret'	- Merchant Account Secret
     *  + 'client'	        - Client for sending HTTP Requests
     *
     * Gateway constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        if (array_key_exists('merchantID', $options)) {
            $this->merchantID = $options['merchantID'];
        }

        if (array_key_exists('merchantSecret', $options)) {
            $this->merchantSecret = $options['merchantSecret'];
        }

        if (array_key_exists('directUrl', $options)) {
            $this->directUrl = $options['directUrl'];
        }

        if (array_key_exists('hostedUrl', $options)) {
            $this->hostedUrl = $options['hostedUrl'];
        }

        if (array_key_exists('client', $options)) {
            $this->client = $options['client'];
        } else {
            $this->client = new Client($this->directUrl);
        }
    }

    /**
     * Send request to Gateway using HTTP Hosted API.
     *
     * The method will send a request to the Gateway using the HTTP Hosted API.
     *
     * The method will {@link sign() sign} the request.
     *
     * The method returns the HTML fragment that needs including in order to
     * send the request.
     *
     * The method does not attempt to validate any request fields.
     *
     * If the request doesn't contain a 'redirectURL' element then one will be
     * added which redirects the response to the current script.
     *
     * Any response can be {@link verifyResponse() verified} using the following
     * PHP code;
     * <code>
     * try {
     *     \P3\SDK\Gateway::verifyResponse($_POST);
     * } catch(\Exception $e) {
     *     die($e->getMessage());
     * }
     * </code>
     *
     * @param	array	$request	request data
     * @return	string				request HTML form.
     *
     * @throws	InvalidArgumentException	invalid request data
     */
    public function hostedRequest(array $request) {

        static::debug(__METHOD__ . '() - args=', func_get_args());

        if (!isset($request['redirectURL'])) {
            $request['redirectURL'] = ($_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $request['merchantID'] = $this->merchantID;
        $request['signature'] = self::sign($request, $this->merchantSecret);

        $htmlForm = self::silentPost($this->hostedUrl, $request);

        static::debug(__METHOD__ . '() - ret=', $htmlForm);

        return $htmlForm;
    }

    /**
     * Send request to Gateway using HTTP Direct API.
     *
     * The method will send a request to the Gateway using the HTTP Direct API.
     *
     * The method will {@link sign() sign} the request and also {@link
     * verifySignature() check the signature} on any response.
     *
     * The method will throw an exception if it is unable to send the request
     * or receive the response.
     *
     * The method does not attempt to validate any request fields.
     *
     * @param array $request request data
     * @return array request response
     */
    public function directRequest(array $request) {

        static::debug(__METHOD__ . '() - args=', func_get_args());

        $request['signature'] = static::sign($request, $this->merchantSecret);

        $response = $this->client->post($request, $this->directUrl);

        static::debug(__METHOD__ . '() - ret=', $response);

        return $response;
    }

    /**
     * Verify the any response.
     *
     * This method will verify that the response is present, contains a response
     * code and is correctly signed.
     *
     * If the response is invalid then an exception will be thrown.
     *
     * Any signature is removed from the passed response.
     *
     * @param array $response reference to the response to verify
     * @param callable $onThreeDSRequired
     * @param callable $onSuccess
     * @return boolean                true if signature verifies
     */
     public function verifyResponse(array &$response, callable $onThreeDSRequired, callable $onSuccess) {

        if (!$response || !isset($response['responseCode'])) {
            throw new RuntimeException('Invalid response from Payment Gateway');
        }

        $fields = null;
        $signature = null;
        if (isset($response['signature'])) {
            $signature = $response['signature'];
            unset($response['signature']);
            if ($this->merchantSecret && $signature && strpos($signature, '|') !== false) {
                list($signature, $fields) = explode('|', $signature);
            }
        }

        // We display three suitable different exception messages to help show
        // secret mismatches between ourselves and the Gateway without giving
        // too much away if the messages are displayed to the Cardholder.
        if ($this->merchantSecret && !$signature) {
            // Signature missing when one expected (We have a secret but the Gateway doesn't)
            throw new RuntimeException('Incorrectly signed response from Payment Gateway');
        } else if ($this->merchantSecret && static::sign($response, $this->merchantSecret, $fields) !== $signature) {
            // Signature mismatch
            throw new RuntimeException('Incorrectly signed response from Payment Gateway (2)');
        }

        settype($response['responseCode'], 'integer');

        // Check the response code
        if ($response['responseCode'] === Gateway::RC_3DS_AUTHENTICATION_REQUIRED) {

            // Send request to the ACS server
            $threeDSVersion = (int) str_replace('.', '', $response['threeDSVersion']);

            return $onThreeDSRequired($threeDSVersion, $response);
        } else if ($response['responseCode'] === Gateway::RC_SUCCESS) {
            return $onSuccess($response);
        } else {
            // Signature mismatch
            throw new RuntimeException("Failed to take payment: " . htmlentities($response['responseMessage']));
        }
    }

    // Render HTML to silently POST data to URL in target browser window
    static public function silentPost($url = '?', array $post = null, $target = '_self') {

        $url = htmlentities($url);
        $target = htmlentities($target);
        $fields = '';

        if ($post) {
            foreach ($post as $name => $value) {
                $fields .= self::fieldToHtml($name, $value);
            }
        }

        return <<<HTML
<form id="silentPost" action="{$url}" method="post" target="{$target}">
    {$fields}
    <noscript><input type="submit" value="Continue"></noscript>
</form>
<script>
    window.setTimeout('document.forms.silentPost.submit()', 0);
</script>
HTML;
    }

    /**
     * Return the field name and value as HTML input tags.
     *
     * The method will return a string containing one or more HTML <input
     * type="hidden"> tags which can be used to store the name and value.
     *
     * @param string $name field name
     * @param mixed $value field value
     * @return    string                    HTML containing <INPUT> tags
     */
    static protected function fieldToHtml(string $name, $value) {
        $ret = '';
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $ret .= static::fieldToHtml($name . '[' . $n . ']', $v);
            }
        } else {
            // Convert all applicable characters or none printable characters to HTML entities
            $value = preg_replace_callback('/[\x00-\x1f]/', function($matches) { return '&#' . ord($matches[0]) . ';'; }, htmlentities($value, ENT_COMPAT, 'UTF-8', true));
            $ret = "<input type=\"hidden\" name=\"{$name}\" value=\"{$value}\" />";
        }

        return $ret;
    }

    /**
     * Sign the given array of data.
     *
     * This method will return the correct signature for the data array.
     *
     * If the secret is not provided then any {@link static::$merchantSecret
     * default secret} is used.
     *
     * The partial parameter is used to indicate that the signature should
     * be marked as 'partial' and can take three possible value types as
     * follows;
     *   + boolean    - sign with all $data fields
     *   + string    - comma separated list of $data field names to sign
     *   + array    - array of $data field names to sign
     *
     * @param array $data data to sign
     * @param string $secret secret to use in signing
     * @param mixed $partial partial signing
     * @return    string                signature
     */
    static public function sign(array $data, string $secret, $partial = false) {

        // Support signing only a subset of the data fields
        if ($partial) {
            if (is_string($partial)) {
                $partial = explode(',', $partial);
            }
            if (is_array($partial)) {
                $data = array_intersect_key($data, array_flip($partial));
            }
            $partial = join(',', array_keys($data));
        }

        // Sort the data in ascending ascii key order
        ksort($data);

        // Convert to a URL encoded string
        $ret = http_build_query($data, '', '&');

        // Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
        $ret = preg_replace('/%0D%0A|%0A%0D|%0D/i', '%0A', $ret);

        // Hash the string and secret together
        $ret = hash('SHA512', $ret . $secret);

        // Mark as partially signed if required
        if ($partial) {
            $ret . '|' . $partial;
        }

        return $ret;
    }

    /**
     * Display debug message into PHP error log.
     *
     * The method will write the arguments into the PHP error log if
     * the {@link $debug} property is true. Any none string arguments
     * will be {@link \var_export() formatted}.
     *
     * @param	mixed		...			value to debug
     * @return	void
     */
    static protected function debug() {
        if (static::$debug) {
            $msg = '';
            foreach (func_get_args() as $arg) {
                $msg .= (is_string($arg) ? $arg : var_export($arg, true)) . ' ';
            }
            error_log($msg);
        }
    }
}
