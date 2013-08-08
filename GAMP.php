<?php

/**
 * Google Analytics Measurement Protocol PHP Interface.
 *
 * This class provides a simple mechanism for submitting events and pageviews
 *  to Google (Universal) Analytics using the "Measurement Protocol." This
 *  is very useful for scenarios where the data you want to submit does not
 *  naturally occur in a client-side/browser context, and/or where the most
 *  straightforward place to capture an event occurrence is in a
 *  server-side script.
 * Belying its name somewhat, the Measurement Protocol is only used to submit
 *  data to GA, and has no retrieval methods.
 *
 * @see https://developers.google.com/analytics/devguides/collection/protocol/v1/devguide
 * @see https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters
 *
 * @author Nathanael Dewhurst
 * @version 1.0
 */

class GAMP {

  const MEASUREMENT_PROTOCOL_URL = 'http://www.google-analytics.com/collect';
  const MEASUREMENT_PROTOCOL_VERSION = '1';
  const MAX_GET_URL_LENGTH = 2000;
  const MAX_POST_BODY_LENGTH = 8192;

  private $tracking_id = NULL;
  private $client_id = NULL;
  private $http_method = NULL;
  private $request_parameters = NULL;
  private $use_cache_buster = NULL;
  private $error_message = NULL;

  /**
   * Instantiate a new gamp object. Invoker can provide a valid tracking ID
   *  and/or client ID. If not provided, we will try to obtain a tracking ID
   *  and/or client ID from other sources.
   *
   * @param string $tracking_id - Unique identifier of user's Google Analytics
   *  account.
   * @param string $client_id - A largely numeric string uniquely identifying
   *  the current website visitor.
   * @param string $http_method - The method to use when submitting data to GA
   *  ('GET' or 'POST').
   */
  public function __construct($tracking_id = NULL, $client_id = NULL, $http_method = 'POST', $anonymize_ip = FALSE, $use_cache_buster = FALSE) {
    // Set the tracking ID using the provided value or the value set via the GA
    //  module configuration form.
    if (empty($tracking_id)) {
      $tracking_id = variable_get('googleanalytics_account', FALSE);
    }
    if (!preg_match('/UA-\d+-\d/', $tracking_id)) {
      return FALSE;
    }
    $this->tracking_id = $tracking_id;

    // Set the client ID.
    $valid_client_id = $this->getValidClientID($client_id);
    $this->client_id = $valid_client_id;

    // Ensure that the given HTTP method is one that we're prepared to handle.
    if ($http_method != 'POST' && $http_method != 'GET') {
      $http_method = 'POST';
    }
    $this->http_method = $http_method;
    $this->use_cache_buster = $use_cache_buster;

    $this->request_parameters = array();

    if ($anonymize_ip) {
      $this->request_parameters['aip'] = 1;
    }
  }

  /**
   * Multipurpose method for obtaining/validating a client ID. If a client ID
   *  is provided, check it for validity. If no valid client ID is provided,
   *  try to pull one from the GA cookie. If that is impossible, generate a new
   *  client ID according to Google's preferred pattern (v4 UUID).
   *
   * @param string $client_id (optional)
   * @return string - A valid client ID string
   */
  protected function getValidClientID($client_id = '') {
    // If the given client ID matches the pattern used by GA javascript code
    //  (with or without the two leading components that correspond to cookie
    //  version and domain depth), then use it, after cleaning it as necessary.
    if (preg_match('/^.*(\d{9}\.\d{9})$/', $client_id, $matches)) {
      $client_id = $matches[1];
    }
    // Otherwise, see if the given client ID conforms to the UUID v4 standard.
    elseif (!preg_match('/^\d{8}-\d{4}-4\d{3}-[89abAB]\d{3}-\d{12}$/', $client_id)) {
      // If not, try to extract the client ID from an existing GA cookie.
      if (isset($_COOKIE['_ga'])) {
        $client_id = substr($_COOKIE['_ga'], 6);
      }
      // If all else fails, generate a new client ID according to the UUID v4
      //  standard.
      //  @see http://www.php.net/manual/en/function.uniqid.php#94959
      //  @see https://groups.google.com/d/msg/google-analytics-measurement-protocol/rE9otWYDFHw/XCSys_-2YeUJ
      //  @see http://tools.ietf.org/html/rfc4122#section-4.4
      //  @see http://en.wikipedia.org/wiki/Universally_unique_identifier#Version_4_.28random.29
      else {
        $client_id = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
          // 32 bits for "time_low."
          mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

          // 16 bits for "time_mid."
          mt_rand( 0, 0xffff ),

          // 16 bits for "time_hi_and_version." four most significant bits
          //  hold version number 4.
          mt_rand( 0, 0x0fff ) | 0x4000,

          // 16 bits: 8 bits for "clk_seq_hi_res", 8 bits for "clk_seq_low,"
          // two most significant bits hold zero, and one for variant DCE1.1.
          mt_rand( 0, 0x3fff ) | 0x8000,

          // 48 bits for "node."
          mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
      }
    }
    return $client_id;
  }

  /**
   * Send data to GA via HTTP POST/GET.
   *
   * @param $data - Array containing key-value pairs to be submitted as part of
   *  the POST/GET request.
   * @return object
   */
  protected function submitHTTPRequest($data) {
    // Add a declaration to the data set indicating the current MP version.
    $data['v'] = gamp::MEASUREMENT_PROTOCOL_VERSION;

    // Add the tracking ID and client ID (which were established at the time of
    //  instantiation).
    $data['tid'] = $this->tracking_id;
    $data['cid'] = $this->client_id;

    // Add any other parameters that have been set (custom dimensions and
    //  metrics, traffic source info, etc.)
    $data = array_merge($data, $this->request_parameters);

    // Reset the dimension and metric lists (nothing with a "hit" scope should
    //  persist for future hits; anything with a "session" or "user" scope only
    //  needs to be submitted once per gamp instance) (NRD 2013-08-05).
    //    $this->dimensions = array();
    //    $this->metrics = array();
    // @todo: does this apply to all other data?
    $this->request_parameters = array();

    //  Note that if you do not provide "&" as an argument separator,
    //  http_build_query will use the URI-encoded form - "&amp;" - which seems
    //  to cause issues (request
    //  gets a 200 response but event never appears in GA) (NRD 2013-08-02).
    $params = http_build_query($data, NULL, '&');
    // This strange valueless key (or keyless value) seems to be unnecessary,
    //  although it is shown in the MP documentation (NRD 2013-08-02).
    //    $content = 'payload_data&' . $content;
    // The payload must be UTF-8 encoded.
    $encoded_params = utf8_encode($params);

    $url = gamp::MEASUREMENT_PROTOCOL_URL;
    $method = $this->http_method;
    $url_request_options = array(
      'method' => $method,
    );

    // Pass an accurate user agent string to Google (as opposed to the default
    //  Drupal user agent).
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
      $url_request_options['headers'] = array(
        'User-Agent' => $_SERVER['HTTP_USER_AGENT'],
      );
    }

    if ($method == 'POST') {
      if (strlen($encoded_params) > gamp::MAX_POST_BODY_LENGTH) {
        $this->error_message = t('The body of a POST request must be no '
          . 'greater than ' . gamp::MAX_POST_BODY_LENGTH . ' bytes. The body '
          . 'of this request would be ')
          . strlen($encoded_params) . t(' bytes long.');
        return FALSE;
      }
      $url_request_options['data'] = $encoded_params;
    }
    else {
      $url .= '?' . $encoded_params;
      // If specified, add a 14-digit random number to the end of the request,
      //  to prevent browsers or proxies from caching hits.
      if ($this->use_cache_buster) {
        $random_num = str_pad(rand(0, 100000000000000), 14, '0', STR_PAD_LEFT);
        $url .= '&z=' . $random_num;
      }
      if (strlen($url) > gamp::MAX_GET_URL_LENGTH) {
        $this->error_message = t('The final, encoded URL for a GET request '
          . 'must be no greater than ' . gamp::MAX_GET_URL_LENGTH
          . ' bytes. The URL for this request would be ')
          . strlen($url) . t(' bytes long.');
        return FALSE;
      }
    }

    // @todo: Remove Drupal dependency.
    $response = drupal_http_request($url, $url_request_options);

    return $response;
  }

  /**
   * Submit an event via the Measurement Protocol.
   *
   * @param $category
   * @param $action
   * @param $label (optional)
   * @param $value (optional)
   * @return object
   */
  public function sendEvent($category, $action, $label = NULL, $value = NULL) {
    $request_variables = array(
      't' => 'event',
      'ec' => $category,
      'ea' => $action,
    );
    if (!empty($label)) {
      $request_variables['el'] = $label;
    }
    if (!empty($value)) {
      $request_variables['ev'] = $value;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit a pageview via the Measurement Protocol.
   *
   * @param $doc_path (optional)
   * @param $doc_title (optional)
   * @param $doc_host (optional)
   * @param $doc_location (optional)
   * @param $content_description (optional)
   * @return object
   */
  public function sendPageview($doc_path = NULL, $doc_title = NULL, $doc_host = NULL, $doc_location = NULL, $content_description = NULL) {
    $request_variables = array(
      't' => 'pageview',
    );
    if (!empty($doc_path)) {
      $request_variables['dp'] = $doc_path;
    }
    if (!empty($doc_title)) {
      $request_variables['dt'] = $doc_title;
    }
    if (!empty($doc_host)) {
      $request_variables['dh'] = $doc_host;
    }
    if (!empty($doc_location)) {
      $request_variables['dl'] = $doc_location;
    }
    if (!empty($content_description)) {
      $request_variables['cd'] = $content_description;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit an e-commerce transaction via the Measurement Protocol.
   *
   * @param $transaction_id
   * @param $affiliation (optional)
   * @param $revenue (optional)
   * @param $shipping_cost (optional)
   * @param $tax (optional)
   * @param $currency_code (optional)
   * @return object
   */
  public function sendTransaction($transaction_id, $affiliation = NULL, $revenue = NULL, $shipping_cost = NULL, $tax = NULL, $currency_code = NULL) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $request_variables = array(
      't' => 'transaction',
      'ti' => $transaction_id,
    );
    if (!empty($affiliation)) {
      $request_variables['ta'] = $affiliation;
    }
    if (!empty($revenue)) {
      $request_variables['tr'] = $revenue;
    }
    if (!empty($shipping_cost)) {
      $request_variables['ts'] = $shipping_cost;
    }
    if (!empty($tax)) {
      $request_variables['tt'] = $tax;
    }
    if (!empty($currency_code)) {
      $request_variables['cu'] = $currency_code;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit an e-commerce item via the Measurement Protocol.
   *
   * @param $transaction_id
   * @param $affiliation (optional)
   * @param $revenue (optional)
   * @param $shipping_cost (optional)
   * @param $tax (optional)
   * @param $currency_code (optional)
   * @return object
   */
  public function sendItem($transaction_id, $item_name, $price = NULL, $quantity = NULL, $code = NULL, $category = NULL, $currency_code = NULL) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $request_variables = array(
      't' => 'item',
      'ti' => $transaction_id,
      'in' => $item_name,
    );
    if (!empty($price)) {
      $request_variables['ip'] = $price;
    }
    if (!empty($quantity)) {
      $request_variables['iq'] = $quantity;
    }
    if (!empty($code)) {
      $request_variables['ic'] = $code;
    }
    if (!empty($category)) {
      $request_variables['iv'] = $category;
    }
    if (!empty($currency_code)) {
      $request_variables['cu'] = $currency_code;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit a social interaction via the Measurement Protocol.
   *
   * @param String $social_network - e.g. "facebook"
   * @param String $action - e.g. "like"
   * @param String $target - e.g. "http://www.mysite.com/likable-page"
   * @return object
   */
  public function sendSocialAction($social_network, $action, $target) {
    // @todo: validate param lengths (e.g. 50-byte limit), type, etc.
    $request_variables = array(
      't' => 'social',
      'sn' => $social_network,
      'sa' => $action,
      'st' => $target,
    );

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit timing data via the Measurement Protocol.
   * For user-related timing data, use the "sendUserTimingData" method.
   *
   * @param null $page_load_time
   * @param null $dns_time
   * @param null $page_download_time
   * @param null $redirect_response_time
   * @param null $tcp_connect_time
   * @param null $server_response_time
   * @return object
   */
  public function sendTimingData($page_load_time = NULL, $dns_time = NULL, $page_download_time = NULL, $redirect_response_time = NULL, $tcp_connect_time = NULL, $server_response_time = NULL) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $request_variables = array(
      't' => 'timing',
    );
    if (!empty($page_load_time)) {
      $request_variables['plt'] = $page_load_time;
    }
    if (!empty($dns_time)) {
      $request_variables['dns'] = $dns_time;
    }
    if (!empty($page_download_time)) {
      $request_variables['pdt'] = $page_download_time;
    }
    if (!empty($redirect_response_time)) {
      $request_variables['rrt'] = $redirect_response_time;
    }
    if (!empty($tcp_connect_time)) {
      $request_variables['tcp'] = $tcp_connect_time;
    }
    if (!empty($server_response_time)) {
      $request_variables['srt'] = $server_response_time;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit user-related timing data via the Measurement Protocol.
   *
   * @param null $category
   * @param null $var_name
   * @param null $time
   * @param null $label
   * @return object
   */
  public function sendUserTimingData($category = NULL, $var_name = NULL, $time = NULL, $label = NULL) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $request_variables = array(
      't' => 'timing',
    );
    if (!empty($category)) {
      $request_variables['utc'] = $category;
    }
    if (!empty($var_name)) {
      $request_variables['utv'] = $var_name;
    }
    if (!empty($time)) {
      $request_variables['utt'] = $time;
    }
    if (!empty($label)) {
      $request_variables['utl'] = $label;
    }

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Submit an exception via the Measurement Protocol.
   *
   * @param string $description - A useful description of the exception that
   *  occurred.
   * @param bool $is_fatal - Specifies whether the exception was fatal.
   * @return object
   */
  public function sendException($description = NULL, $is_fatal = TRUE) {
    $request_variables = array(
      't' => 'exception',
    );
    if (!empty($description)) {
      $request_variables['exd'] = $description;
    }
    $request_variables['exf'] = $is_fatal ? 1 : 0;

    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Set values for one or more custom dimensions, to be submitted with the next
   *  hit.
   *
   * @param $dimensions - An array of string values whose keys are pre-coded
   *  indices of custom dimensions that have been defined in Google Analytics.
   * Example usage:
   * @code
   *  // Custom dimension 3 corresponds to "Gender." 
   *  $dimensions = array('cd3' => 'Male');
   *  $gamp = new gamp();
   *  $gamp->setDimensions($dimensions);
   * @endcode
   */
  public function setDimensions($dimensions) {
    foreach ($dimensions as $dimension_index => $dimension_value) {
      if (preg_match('/^cd[1-9][0-9]*$/', $dimension_index)) {
        $this->request_parameters[$dimension_index] = $dimension_value;
      }
    }
  }

  /**
   * Set values for one or more custom metrics, to be submitted with the next
   *  hit.
   *
   * @param $metrics - An array of integer values whose keys are pre-coded
   *  indices of custom metrics that have been defined in Google Analytics.
   * Example usage:
   * @code
   *  // Custom metric 1 corresponds to "Reward Points."
   *  $metrics = array('cm1' => 450);
   *  $gamp = new gamp();
   *  $gamp->setMetrics($metrics);
   * @endcode
   */
  public function setMetrics($metrics) {
    foreach ($metrics as $metric_index => $metric_value) {
      if (preg_match('/^cm[1-9][0-9]*$/', $metric_index)) {
        $this->request_parameters[$metric_index] = $metric_value;
      }
    }
  }

  /**
   * Retrieve a string describing the most recently set error arising from the
   *  use of this class/object.
   *
   * @return String | NULL
   */
  public function getErrorMessage() {
    return $this->error_message;
  }
}