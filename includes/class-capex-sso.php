<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_SSO {

    // Creditinfo Endpoints
    private $auth_endpoint      = 'https://sso.mycreditinfo.ge/authorize';
    private $token_endpoint     = 'https://sso.mycreditinfo.ge/token';
    private $userinfo_endpoint  = 'https://sso.mycreditinfo.ge/userinfo';

    private $client_id;
    private $client_secret;
    private $redirect_handler;
    private $scope_id;

    public function __construct() {
        $this->client_id        = get_option( 'capex_client_id' );
        $this->client_secret    = get_option( 'capex_client_secret' );
        $this->redirect_handler = get_option( 'capex_redirect_handler' );
        $this->scope_id         = get_option( 'capex_scope_id', '' );
    }

    /**
     * 1. ავტორიზაციის URL-ის გენერირება
     * @param string $return_url - გვერდი, სადაც მომხმარებელი უნდა დაბრუნდეს (მაგ: /auto-loan)
     */
    public function get_auth_url( $return_url ) {
        if ( empty( $this->client_id ) || empty( $this->redirect_handler ) || empty( $this->scope_id ) ) {
            return false;
        }

        $nonce = wp_create_nonce( 'capex_sso_nonce' );

        // State: NONCE|return_url, encoded in base64
        $state_data    = $nonce . '|' . $return_url;
        $state_encoded = base64_encode( $state_data );

        // Store nonce → return_url for 10 min (replay protection)
        set_transient( 'capex_sso_' . $nonce, $return_url, 10 * MINUTE_IN_SECONDS );

        $params = array(
            'client_id'    => $this->client_id,
            'redirect_uri' => $this->redirect_handler,
            'scope'        => $this->scope_id,           // Scope ID from MyCreditinfo (e.g. "scope1")
            'state'        => $state_encoded,
        );

        return $this->auth_endpoint . '?' . http_build_query( $params );
    }

    /**
     * 2. State-ის გაშიფრვა და ვალიდაცია
     */
    public function validate_state_and_get_url( $state_encoded ) {
        $state_decoded = base64_decode( $state_encoded );
        $parts = explode( '|', $state_decoded, 2 );

        if ( count( $parts ) < 2 ) {
            return false;
        }

        $nonce      = $parts[0];
        $return_url = $parts[1];

        $saved_url = get_transient( 'capex_sso_' . $nonce );

        if ( $saved_url && $saved_url === $return_url ) {
            delete_transient( 'capex_sso_' . $nonce );
            return $return_url;
        }

        return false;
    }

    /**
     * 3. ტოკენის მიღება (Code Exchange)
     */
    public function exchange_code_for_token( $code ) {
        $body = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect_handler,
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
        );

        $response = wp_remote_post( $this->token_endpoint, array(
            'body'    => $body,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['access_token'] ) ) {
            return $data['access_token'];
        }

        return false;
    }

    /**
     * 4. მომხმარებლის ინფოს წამოღება
     */
    public function get_user_info( $access_token ) {
        $response = wp_remote_get( $this->userinfo_endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $user_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $user_data ) && ! isset( $user_data['error'] ) ) {
            return $this->map_user_data( $user_data );
        }

        return false;
    }

    /**
     * Map CreditInfo API response to our internal field keys.
     *
     * API response example (per docs):
     * {
     *   "sub": "51dc84a3-...",          // UUID — session/user identifier
     *   "firstName": "name",
     *   "lastName": "lastName",
     *   "country": "GE",
     *   "birthdate": "2003-09-20",
     *   "address": "address",
     *   "email": "name@gmail.com",
     *   "username": "00000000000"        // Personal ID number / Tax number
     * }
     */
    private function map_user_data( $api_data ) {
        return array(
            'name'     => $api_data['firstName'] ?? '',
            'surname'  => $api_data['lastName']  ?? '',
            'pid'      => $api_data['username']  ?? '',   // Personal ID = username field
            'dob'      => $api_data['birthdate'] ?? '',
            'email'    => $api_data['email']     ?? '',
            'phone'    => $api_data['phone']     ?? '',
            'address'  => $api_data['address']   ?? '',
            'country'  => $api_data['country']   ?? '',
        );
    }
}
