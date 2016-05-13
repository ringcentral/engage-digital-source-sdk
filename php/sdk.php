<?php

/**
 * Copyright 2012 Dimelo, SA
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 * 
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

if (!function_exists('json_encode')) {
    throw new Exception('SMCC SDK needs the JSON extension');
}

class SmccSdk {

    /**
     * Key used for signing communication.
     */
    protected $secret_key = null;

    /**
     * Request body in original form.
     */
    protected $request_body = null;

    /**
     * JSON decoded body of the request.
     */
    protected $decoded_request_body = null;

    /**
     * Create an SDK object with the secret key.
     *
     * @param string $key Secret key used for communication signing.
     */
    public function __construct($key) {
        $this->secret_key = $key;
    }

    /**
     * Takes a string (in any format supported by strtotime) and returns a
     * string in the format expected by the SDK.
     *
     * Please make sure to call `date_default_timezone_set()` before calling
     * time functions.
     *
     * @param string $timestr Time string in any format supported by `strtotime`.
     *
     * @return string Date in the ISO8601 format.
     */
    public static function to_sdk_time($timestr) {
        return date(DATE_ISO8601, strtotime($timestr));
    }

    /**
     * Takes a SDK time string and returns a string in the expected `$format`.
     * The default format is `Y-m-d H:i:s`.
     *
     * Please make sure to call `date_default_timezone_set()` before calling
     * time functions.
     *
     * @param string $timestr Time string in the ISO8601 format.
     * @param string $format  Format to convert the date to. Default is `Y-m-d H:i:s`.
     *
     * @return string Date in the specified format.
     */
    public static function from_sdk_time($timestr, $format = 'Y-m-d H:i:s') {
        return date($format, strtotime($timestr));
    }

    /**
     * Returns the action specified by the current request.
     *
     * @return string SDK action.
     */
    public function get_action() {
        $body = $this->get_body();
        return $body['action'];
    }

    /**
     * Returns the parameters passed for the SDK action.
     *
     * @return array SDK action's parameters.
     */
    public function get_params() {
        $body = $this->get_body();
        return array_key_exists('params', $body) ? $body['params'] : array();
    }

    /**
     * Checks if the signature matches the request body.
     *
     * @return bool
     */
    public function is_valid_request() {
        $signature = $this->signature($this->get_raw_body());
        return $signature === @$_SERVER['HTTP_X_SMCCSDK_SIGNATURE'] || $signature === @$_REQUEST['SMCCSDK_SIGNATURE'];
    }

    /**
     * JSON encodes the `$object` and outputs it. It also computes the
     * signature for the response and sends it as a header.
     * Note: this function will call `exit`.
     */
    public function respond($object) {
        $body = json_encode($object);
        header('Content-type: application/json');
        header('X-SMCCSDK-SIGNATURE: ' . $this->signature($body));
        echo $body;
        exit;
    }

    /**
     * Sends a 400 header and outputs the given `$message`.
     * Note: this function will call `exit`.
     *
     * @param string $message Error message.
     */
    public function error($message) {
        header('Status: 400 Bad Request');
        echo $message;
        exit;
    }

    /**
     * Computes the SHA512 signature of the given `$text` using the
     * secret key.
     *
     * @param string $text String to be signed.
     *
     * @return string Signature string.
     */
    public function signature($text) {
        return hash_hmac('sha512', $text, $this->secret_key, $raw = false);
    }

    /**
     * Returns the body of a POST request.
     *
     * @return string POST body.
     */
    protected function get_raw_body() {
        if ($this->request_body === null) {
            $this->request_body = file_get_contents('php://input');
        }
        return $this->request_body;
    }

    /**
     * Returns the JSON decoded representation of the request body.
     *
     * @return object JSON decode POST body.
     */
    protected function get_body() {
        if ($this->decoded_request_body === null) {
            $this->decoded_request_body = json_decode($this->get_raw_body(), true);
        }
        return $this->decoded_request_body;
    }

}

