<?php


namespace Tsugi\Util;

use \Tsugi\Util\U;
use \Firebase\JWT\JWT;

/**
 * This is a general purpose LTI class with no Tsugi-specific dependencies.
 */
class LTI13 extends LTI {

    const VERSION_CLAIM =       'https://purl.imsglobal.org/spec/lti/claim/version';
    const MESSAGE_TYPE_CLAIM =  'https://purl.imsglobal.org/spec/lti/claim/message_type';
    const MESSAGE_TYPE_RESOURCE = 'LtiResourceLinkRequest';
    const MESSAGE_TYPE_DEEPLINK = 'LtiDeepLinkingRequest';
    const RESOURCE_LINK_CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/resource_link';
    const DEPLOYMENT_ID =       'https://purl.imsglobal.org/spec/lti/claim/deployment_id';
    const ROLES_CLAIM =         'https://purl.imsglobal.org/spec/lti/claim/roles';
    const PRESENTATION_CLAIM =  'https://purl.imsglobal.org/spec/lti/claim/launch_presentation';

    const NAMESANDROLES_CLAIM = 'https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice';
    const ENDPOINT_CLAIM =      'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';
    const DEEPLINK_CLAIM =      'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings';

    const MEDIA_TYPE_MEMBERSHIPS = 'application/vnd.ims.lti-nrps.v2.membershipcontainer+json';
    const MEDIA_TYPE_LINEITEM = 'application/vnd.ims.lis.v2.lineitem+json';
    const MEDIA_TYPE_LINEITEMS = 'application/vnd.ims.lis.v2.lineitemcontainer+json';
    const SCORE_TYPE = 'application/vnd.ims.lis.v1.score+json';
    const RESULTS_TYPE = 'application/vnd.ims.lis.v2.resultcontainer+json';

    public static function extract_consumer_key($jwt) {
        return 'lti13_' . $jwt->body->iss;
    }

    public static function raw_jwt($request_data=false) {
        if ( $request_data === false ) $request_data = $_REQUEST;
        $raw_jwt = U::get($request_data, 'id_token');
        if ( ! $raw_jwt ) return false;
        return $raw_jwt;
    }

    public static function parse_jwt($raw_jwt, $required_fields=true) {
        if ( $raw_jwt === false ) return false;
        if ( ! is_string($raw_jwt)) return 'parse_jwt first parameter must be a string';
        $jwt_parts = explode('.', $raw_jwt);
        if ( count($jwt_parts) < 2 ) return "jwt must have at least two parts";
        $jwt_header = json_decode(JWT::urlsafeB64Decode($jwt_parts[0]));
        if ( ! $jwt_header ) return "Could not decode jwt header";
        if ( ! isset($jwt_header->alg) ) return "Missing alg from jwt header";
        $jwt_body = json_decode(JWT::urlsafeB64Decode($jwt_parts[1]));
        if ( ! $jwt_body ) return "Could not decode jwt body";
        if ( $required_fields && ! isset($jwt_body->iss) ) return "Missing iss from jwt body";
        if ( $required_fields && ! isset($jwt_body->aud) ) return "Missing aud from jwt body";
        if ( $required_fields && ! isset($jwt_body->exp) ) return "Missing exp from jwt body";
        $jwt = new \stdClass();
        $jwt->header = $jwt_header;
        $jwt->body = $jwt_body;
        if ( count($jwt_parts) > 2 ) {
            $jwt_extra = json_decode(JWT::urlsafeB64Decode($jwt_parts[1]), true);
            if ( $jwt_body ) $jwt->extra = $jwt_extra;
        }
        return $jwt;
    }

    // Returns true if this is a Basic LTI message
    // with minimum values to meet the protocol
    // Returns true, false , or a string
    public static function isRequestDetail($request_data=false) {
        $raw_jwt = self::raw_jwt($request_data);
        if ( ! $raw_jwt ) return false;
        $jwt = self::parse_jwt($raw_jwt);
        if ( is_string($jwt) ) {
            return $jwt;
        }
        return is_object($jwt);
    }

    // Returns true if this is a Basic LTI message
    // with minimum values to meet the protocol
    // Returns true or  false
    public static function isRequest($request_data=false) {
        $retval = self::isRequestDetail($request_data);
        if ( is_string($retval) ) {
            error_log("Bad launch ".$retval);
            return false;
        }
        return is_object($retval);
    }

    /**
     * Verify the Public Key for this request
     *
     * @return mixed This returns true if the request verified.  If the request did not verify,
     * this returns the exception that was generated.
     */
    public static function verifyPublicKey($raw_jwt, $public_key, $algs) {
        try {
            // $decoded = JWT::decode($raw_jwt, $public_key, array('RS256'));
            $decoded = JWT::decode($raw_jwt, $public_key, $algs);
            // $decoded_array = json_decode(json_encode($decoded), true);
            return true;
        } catch(\Exception $e) {
            return $e;
        }
    }

    // Returns true if the lti_message_type is valid
    public static function isValidMessageType($lti_message_type=false) {
        return ($lti_message_type == "basic-lti-launch-request" ||
            $lti_message_type == 'LtiResourceLinkRequest' ||
            $lti_message_type == "ToolProxyReregistrationRequest" ||
            $lti_message_type == "ContentItemSelectionRequest");
    }

    // Returns true if the lti_version is valid
    public static function isValidVersion($lti_version=false) {
        return ($lti_version == "LTI-1p0" || $lti_version == "LTI-2p0");
    }

    /**
     * Apply Jon Postel's Law as appropriate
     *
     * Postel's Law - https://en.wikipedia.org/wiki/Robustness_principle
     *
     * "TCP implementations should follow a general principle of robustness:
     * be conservative in what you do, be liberal in what you accept from others."
     *
     * By default, Jon Postel mode is off and we are stricter than we need to be.
     * This works well because it reduces the arguments with the certification
     * folks.   But if you add:
     *
     *      $CFG->jon_postel = true;
     *
     * Tsugi will follow Jon Postel's law.
     */
    public static function jonPostel($body, &$failures) {
        if ( isset($CFG->jon_postel) ) return; // We are on Jon Postel mode

        // Sanity checks
        $version = false;
        if ( isset($body->{self::VERSION_CLAIM}) ) $version = $body->{self::VERSION_CLAIM};
        if ( strpos($version, '1.3') !== 0 ) $failures[] = "Bad LTI version: ".$version;

        $message_type = false;
        if ( isset($body->{self::MESSAGE_TYPE_CLAIM}) ) $message_type = $body->{self::MESSAGE_TYPE_CLAIM};
        if ( ! $message_type ) {
            $failures[] = "Missing message type";
        } else if ( $message_type == self::MESSAGE_TYPE_RESOURCE ) {
            // Required
            if ( ! isset($body->{self::RESOURCE_LINK_CLAIM}) ) $failures[] = "Missing required resource_link claim";
            if ( ! isset($body->{self::RESOURCE_LINK_CLAIM}->id) ) $failures[] = "Missing required resource_link id";
        } else if ( $message_type == self::MESSAGE_TYPE_DEEPLINK ) {
            // OK
        } else {
            $failures[] = "Bad message type: ".$message_type;
        }

        if ( ! isset($body->{self::ROLES_CLAIM}) ) $failures[] = "Missing required role claim";
        if ( ! isset($body->{self::DEPLOYMENT_ID}) ) $failures[] = "Missing required deployment_id claim";
    }

    public static function getGradeToken($issuer, $subject, $lti13_token_url, $lti13_privkey, &$debug_log=false) {

        return self::get_access_token([
            "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
            "https://purl.imsglobal.org/spec/lti-ags/scope/score",
            "https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly"
        ], $issuer, $subject, $lti13_token_url, $lti13_privkey, $debug_log);
    }

    public static function getRosterToken($issuer, $subject, $lti13_token_url, $lti13_privkey, &$debug_log=false) {

        return self::get_access_token([
            "https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly"
        ], $issuer, $subject, $lti13_token_url, $lti13_privkey, $debug_log);
    }

    public static function getRosterWithSourceDidsToken($issuer, $subject, $lti13_token_url, $lti13_privkey, &$debug_log=false) {

        return self::get_access_token([
            // "https://purl.imsglobal.org/spec/lti-ags/scope/basicoutcome",
            "https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly"
        ], $issuer, $subject, $lti13_token_url, $lti13_privkey, $debug_log);
    }

    public static function getLineItemsToken($issuer, $subject, $lti13_token_url, $lti13_privkey, &$debug_log=false) {

        return self::get_access_token([
            "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
        ], $issuer, $subject, $lti13_token_url, $lti13_privkey, $debug_log);
    }

    // Call lineitem
    public static function sendLineItem($user_id, $grade, $comment, $lineitem_url,
        $access_token, &$debug_log=false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();

        $grade = $grade * 100.0;
        $grade = (int) $grade;

        // user_id comes from the "sub" in the JWT launch
        $grade_call = [
            // "timestamp" => "2017-04-16T18:54:36.736+00:00",
            "timestamp" => U::iso8601(),
            "scoreGiven" => $grade,
            "scoreMaximum" => 100,
            "comment" => $comment,
            "activityProgress" => "Completed",
            "gradingProgress" => "Completed",
            "userId" => $user_id,
        ];

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Content-Type: '.self::SCORE_TYPE,
            'Accept: '.self::SCORE_TYPE
        ];

        // echo("\n---\n$lineitem_url\n-----\n");
        $actual_url = $lineitem_url."/scores";
        curl_setopt($ch, CURLOPT_URL, $actual_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($grade_call));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ( is_array($debug_log) ) $debug_log[] = "Scores Url: ".$actual_url;
        if ( is_array($debug_log) ) $debug_log[] = $headers;

        $line_item = curl_exec($ch);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close ($ch);

        // echo $line_item;
        if ( is_array($debug_log) ) $debug_log[] = "Sent line item, received status=$httpcode\n".$line_item;

        if ( $httpcode != 200 ) {
            $json = json_decode($line_item, true);
            $status = U::get($json, "error", "Unable to send lineitem");
            if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
            return $status;
        }

        return true;
    }

    // Call memberships and roles
    public static function loadRoster($membership_url, $access_token, &$debug_log=false) {

        $ch = curl_init();

        $membership_url = trim($membership_url);

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Accept: '.self::MEDIA_TYPE_MEMBERSHIPS,
            'Content-Type: '.self::MEDIA_TYPE_MEMBERSHIPS // TODO: Remove when certification is fixed
        ];

        curl_setopt($ch, CURLOPT_URL, $membership_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ( is_array($debug_log) ) $debug_log[] = $membership_url;
        if ( is_array($debug_log) ) $debug_log[] = $headers;

        $membership = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close ($ch);
        if ( is_array($debug_log) ) $debug_log[] = "Sent roster request, received status=$httpcode (".strlen($membership)." characters)";

        if ( strlen($membership) < 1 ) {
            return "No data retrieved status=" . $httpcode;
        }

        $json = json_decode($membership, false);   // Top level object
        if ( $json === null ) {
            $retval = "Unable to parse returned roster JSON:". json_last_error_msg();
            if ( is_array($debug_log) ) {
                if (is_array($debug_log) ) $debug_log[] = $retval;
                if (is_array($debug_log) ) $debug_log[] = substr($membership, 0, 3000);
            }
            return $retval;
        }

        if ( $httpcode == 200 && isset($json->members) ) {
            if ( is_array($debug_log) ) $debug_log[] = "Loaded ".count($json->members)." roster entries";
            return $json;
        }

        $status = isset($json->error) ? $json->error : "Unable to load results";
        if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
        return $status;
    }

    // Load LineItems
    public static function loadLineItems($lineitems_url, $access_token, &$debug_log=false) {

        $lineitems_url = trim($lineitems_url);

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Accept: '.self::MEDIA_TYPE_LINEITEMS,
            // 'Content-Type: '.self::MEDIA_TYPE_LINEITEMS // TODO: Remove when certification is fixed
        ];
        curl_setopt($ch, CURLOPT_URL, $lineitems_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (is_array($debug_log) ) $debug_log[] = 'Line Items URL: '.$lineitems_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $lineitems = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close ($ch);
        if ( is_array($debug_log) ) $debug_log[] = "Sent lineitems request, received status=$httpcode (".strlen($lineitems)." characters)";

        $json = json_decode($lineitems, false);
        if ( $json === null ) {
            $retval = "Unable to parse returned lineitems JSON:". json_last_error_msg();
            if ( is_array($debug_log) ) {
                if (is_array($debug_log) ) $debug_log[] = $retval;
                if (is_array($debug_log) ) $debug_log[] = substr($lineitems, 0, 1000);
            }
            return $retval;
        }
        if ( $httpcode == 200 && is_array($json) ) {
            if ( is_array($debug_log) ) $debug_log[] = "Loaded ".count($json)." lineitems entries";
            return $json;
        }

        $status = isset($json->error) ? $json->error : "Unable to load results";
        if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
        return $status;
    }

    // Load A LineItem
    public static function loadLineItem($lineitem_url, $access_token, &$debug_log=false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();
        $headers = [
            'Authorization: Bearer '. $access_token,
            'Accept: '.self::MEDIA_TYPE_LINEITEM,
        ];

        curl_setopt($ch, CURLOPT_URL, $lineitem_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (is_array($debug_log) ) $debug_log[] = 'Line Items URL: '.$lineitem_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $lineitem = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close ($ch);
        if ( is_array($debug_log) ) $debug_log[] = "Sent lineitem request, received status=$httpcode (".strlen($lineitem)." characters)";

        $json = json_decode($lineitem, false);
        if ( $json === null ) {
            $retval = "Unable to parse returned lineitem JSON:". json_last_error_msg();
            if ( is_array($debug_log) ) {
                if (is_array($debug_log) ) $debug_log[] = $retval;
                if (is_array($debug_log) ) $debug_log[] = substr($lineitem, 0, 1000);
            }
            return $retval;
        }

        if ( $httpcode == 200 && is_object($json) ) {
            if ( is_array($debug_log) ) $debug_log[] = "Loaded lineitem";
            return $json;
        }

        $status = isset($json->error) ? $json->error : "Unable to load results";
        if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
        return $status;
    }

    // Load results for a LineItem
    public static function loadResults($lineitem_url, $access_token, &$debug_log=false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Content-Type: '.self::RESULTS_TYPE,   //  TODO: Convince Claude this is wrong
            'Accept: '.self::RESULTS_TYPE
        ];

        $actual_url = $lineitem_url."/results";
        curl_setopt($ch, CURLOPT_URL, $actual_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (is_array($debug_log) ) $debug_log[] = 'Line Items URL: '.$actual_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $results = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close ($ch);
        if ( is_array($debug_log) ) $debug_log[] = "Sent results request, received status=$httpcode (".strlen($results)." characters)";
        if ( is_array($debug_log)) $debug_log[] = substr($results, 0, 3000);

        $json = json_decode($results, false);
        if ( $json === null ) {
            $retval = "Unable to parse returned results JSON:". json_last_error_msg();
            if ( is_array($debug_log) ) $debug_log[] = $retval;
            return $retval;
        }

        if ( $httpcode == 200 && is_array($json) ) {
            if ( is_array($debug_log) ) $debug_log[] = "Loaded results";
            return $json;
        }

        $status = isset($json->error) ? $json->error : "Unable to load results";
        if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
        return $status;
    }

    // Delete A LineItem
    public static function deleteLineItem($lineitem_url, $access_token, &$debug_log=false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '. $access_token
        ];

        curl_setopt($ch, CURLOPT_URL, $lineitem_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

        if (is_array($debug_log) ) $debug_log[] = 'Line Item URL: '.$lineitem_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close ($ch);
        if ( is_array($debug_log) ) $debug_log[] = "Sent lineitem delete, received status=$httpcode (".strlen($response)." characters)";

        if ( $httpcode == 200 ) {
            if ( is_array($debug_log) ) $debug_log[] = "Deleted lineitem";
            return true;
        }

        if ( strlen($response) < 1 ) {
            return "Failed with no response body and code=".$httpcode;
        }

        $json = json_decode($response, false);
        if ( $json === null ) {
            $retval = "Unable to parse returned lineitem JSON:". json_last_error_msg();
            if ( is_array($debug_log) ) {
                if (is_array($debug_log) ) $debug_log[] = $retval;
                if (is_array($debug_log) ) $debug_log[] = substr($lineitem, 0, 1000);
            }
            return $retval;
        }

        $status = U::get($json, "error", "Unable to delete lineitem");
        if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
        return $status;
    }

    public static function createLineItem($lineitem_url, $access_token, $lineitem, &$debug_log = false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Content-Type: ' . self::MEDIA_TYPE_LINEITEM
        ];

        curl_setopt($ch, CURLOPT_URL, $lineitem_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($lineitem));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (is_array($debug_log) ) $debug_log[] = 'Line Item URL: '.$lineitem_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $line_item = curl_exec($ch);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close ($ch);

        if ( is_array($debug_log) ) $debug_log[] = "Created line item, received status=$httpcode\n".$line_item;

        if ( $httpcode != 200 ) {
            $json = json_decode($line_item, true);
            $status = U::get($json, "error", "Unable to create lineitem");
            if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
            return $status;
        }

        return true;
    }

    public static function updateLineItem($lineitem_url, $access_token, $lineitem, &$debug_log = false) {

        $lineitem_url = trim($lineitem_url);

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer '. $access_token,
            'Content-Type: ' . self::MEDIA_TYPE_LINEITEM
        ];

        curl_setopt($ch, CURLOPT_URL, $lineitem_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($lineitem));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (is_array($debug_log) ) $debug_log[] = 'Line Item URL: '.$lineitem_url;
        if (is_array($debug_log) ) $debug_log[] = $headers;

        $line_item = curl_exec($ch);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close ($ch);

        if ( is_array($debug_log) ) $debug_log[] = "Updated line item, received status=$httpcode\n".$line_item;

        if ( $httpcode != 200 ) {
            $json = json_decode($line_item, true);
            $status = U::get($json, "error", "Unable to update lineitem");
            if ( is_array($debug_log) ) $debug_log[] = "Error status: $status";
            return $status;
        }

        return true;
    }

    public static function get_access_token($scope, $issuer, $subject, $lti13_token_url, $lti13_privkey, &$debug_log=false) {

        $lti13_token_url = trim($lti13_token_url);
        $issuer = trim($issuer);
        $subject = trim($subject);

        if ( ! is_string($scope) ) {
            $scope = implode(' ',$scope);
        }

        $jwt_claim = self::base_jwt($issuer, $subject, $debug_log);
        $jwt_claim["aud"] = $lti13_token_url;

        $jwt = self::encode_jwt($jwt_claim, $lti13_privkey);

        $auth_request = [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt,
            'scope' => $scope
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,$lti13_token_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ( is_array($debug_log) ) $debug_log[] = "Token Url: ".$lti13_token_url;
        if ( is_array($debug_log) ) $debug_log[] = $auth_request;

        $token_str = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ( is_array($debug_log) ) $debug_log[] = "Returned token data $httpcode\n".$token_str;
        $token_data = json_decode($token_str, true);

        curl_close ($ch);

        return $token_data;
    }

    public static function base_jwt($issuer, $subject, &$debug_log=false) {

        $jwt_claim = [
            "iss" => $issuer,
            "sub" => $subject,
            "iat" => time(),
            "exp" => time()+60,
            "jti" => uniqid($issuer)
        ];
        return $jwt_claim;
    }

    public static function encode_jwt($jwt_claim, $lti13_privkey) {
        $jws = JWT::encode($jwt_claim, self::cleanup_PKCS8($lti13_privkey), 'RS256');
        return $jws;
    }

    public static function build_jwt_html($launch_url, $jws, $dodebug=true) {
        $html = "<form action=\"" . $launch_url . "\" method=\"POST\">\n"
                . "    <input type=\"hidden\" name=\"JWT\" value=\"" . htmlspecialchars($jws) . "\" />\n"
                . "    <input type=\"submit\" value=\"Go!\" />\n"
                . "</form>\n";

        if ($dodebug) {
            $jwt = self::parse_jwt($jws, false);
            $html .=   "<p>\n--- Encoded JWT:<br/>"
                    . htmlspecialchars($jws)
                    . "</p>\n"
                    . "<p>\n--- JWT:<br/><pre>"
                    . htmlspecialchars(json_encode($jwt->body, JSON_PRETTY_PRINT))
                    . "</pre></p>\n";
        }
        return $html;
    }

    public static function cleanup_PKCS8($private_key)
    {
        $parts = preg_split('/\s+/', $private_key);
        $better = "";
        $indashes = false;
        foreach($parts as $part) {
            if ( strpos($part,'-----') === 0 ) {
                if ( strlen($better) > 0 ) $better .= "\n";
                $better .= $part;
                $indashes = true;
                continue;
            }
            if ( U::endsWith($part,'-----') > 0 ) {
                $better .= ' ' . $part;
                $indashes = false;
                continue;
            }
            $better .= $indashes ? ' ' : "\n";
            $better .= $part;
        }
        return $better;
    }

}
