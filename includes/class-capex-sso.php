<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capex_SSO {

    private $base_url;
    private $client_id;
    private $client_secret;
    private $redirect_handler;
    private $scope_id;
    private $ssokey;

    public function __construct() {
        $this->client_id        = get_option( 'capex_client_id' );
        $this->client_secret    = get_option( 'capex_client_secret' );
        $this->redirect_handler = get_option( 'capex_redirect_handler' );
        $this->scope_id         = get_option( 'capex_scope_id', '' );
        $this->ssokey           = get_option( 'capex_ssokey', '' );

        // TEST or LIVE environment
        $env = get_option( 'capex_environment', 'test' );
        $this->base_url = ( 'live' === $env )
            ? 'https://sso.mycreditinfo.ge'
            : 'https://sso-test.mycreditinfo.ge';
    }

    /**
     * 1. Build authorization URL
     * @param string $return_url - page to redirect user back to after SSO
     */
    public function get_auth_url( $return_url ) {
        if ( empty( $this->client_id ) || empty( $this->redirect_handler ) || empty( $this->scope_id ) ) {
            return false;
        }

        $nonce = wp_create_nonce( 'capex_sso_nonce' );

        $state_data    = $nonce . '|' . $return_url;
        $state_encoded = base64_encode( $state_data );

        set_transient( 'capex_sso_' . $nonce, $return_url, 10 * MINUTE_IN_SECONDS );

        // Save return URL in transient as fallback (MyCreditinfo may not return state param)
        set_transient( 'capex_sso_return_url', $return_url, 10 * MINUTE_IN_SECONDS );

        // Per MyCreditinfo spec: client_id, redirect_uri, scope only
        $params = array(
            'client_id'    => $this->client_id,
            'redirect_uri' => $this->redirect_handler,
            'scope'        => $this->scope_id,
            'state'        => $state_encoded,
        );

        return $this->base_url . '/authorize?' . http_build_query( $params );
    }

    /**
     * 2. Validate state and extract return URL
     */
    public function validate_state_and_get_url( $state_encoded ) {
        $state_decoded = base64_decode( $state_encoded, true );
        if ( false === $state_decoded ) {
            return false;
        }

        $parts = explode( '|', $state_decoded, 2 );

        if ( count( $parts ) < 2 ) {
            return false;
        }

        $nonce      = $parts[0];
        $return_url = $parts[1];

        $saved_url = get_transient( 'capex_sso_' . $nonce );

        if ( $saved_url && $saved_url === $return_url ) {
            delete_transient( 'capex_sso_' . $nonce );
            // Validate return URL is same-host
            if ( wp_validate_redirect( $return_url, home_url( '/' ) ) !== $return_url ) {
                return home_url( '/' );
            }
            return $return_url;
        }

        return false;
    }

    /**
     * 3. Exchange authorization code for access token
     * Requires ssokey header per MyCreditinfo API gateway
     */
    public function exchange_code_for_token( $code ) {
        $headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
        if ( ! empty( $this->ssokey ) ) {
            $headers['ssokey'] = $this->ssokey;
        }

        $body = array(
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirect_handler,
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
        );

        $token_url = $this->base_url . '/token';

        $response = wp_remote_post( $token_url, array(
            'body'    => $body,
            'headers' => $headers,
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[CAPEX SSO] Token request failed: ' . $response->get_error_message() );
            return false;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['access_token'] ) ) {
            return $data['access_token'];
        }

        error_log( '[CAPEX SSO] Token exchange failed — HTTP ' . $http_code );
        return false;
    }

    /**
     * 4. Fetch user info with access token
     * Requires ssokey header per MyCreditinfo API gateway
     */
    public function get_user_info( $access_token ) {
        $headers = array( 'Authorization' => 'Bearer ' . $access_token );
        if ( ! empty( $this->ssokey ) ) {
            $headers['ssokey'] = $this->ssokey;
        }

        $response = wp_remote_get( $this->base_url . '/userinfo', array(
            'headers' => $headers,
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
     * Map CreditInfo API response to internal field keys.
     *
     * PERSON response:
     * {
     *   "customer_type": "PERSON",
     *   "sub": "731757e7-...",
     *   "country": "GE",
     *   "birthdate": "2001-02-03",
     *   "address": "თბილისი",
     *   "last_name": "კვიჭიძე",
     *   "mobile_number": "599509333",
     *   "first_name": "კოსტა",
     *   "email": "mail@creditinfo.ge",
     *   "username": "01130052216"
     * }
     *
     * COMPANY response:
     * {
     *   "customer_type": "COMPANY",
     *   "sub": "98863ef2-...",
     *   "country": "GE",
     *   "address": "გორი 1",
     *   "mobile_number": "599509333",
     *   "first_name": "შპს ჭიჭიკო ინტერგალაქტიკი",
     *   "email": "mail@creditinfo.ge",
     *   "establishment_date": "2023-08-01",
     *   "username": "123456788"
     * }
     */
    private function map_user_data( $api_data ) {
        return array(
            'customer_type'      => $api_data['customer_type']      ?? '',
            'name'               => $api_data['first_name']         ?? '',
            'surname'            => $api_data['last_name']          ?? '',
            'pid'                => $api_data['username']            ?? '',
            'dob'                => $api_data['birthdate']           ?? '',
            'establishment_date' => $api_data['establishment_date'] ?? '',
            'email'              => $api_data['email']               ?? '',
            'phone'              => $api_data['mobile_number']       ?? '',
            'address'            => $api_data['address']             ?? '',
            'country'            => $api_data['country']             ?? '',
        );
    }
}
