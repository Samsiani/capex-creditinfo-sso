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
    private $redirect_handler; // ეს არის ტექნიკური URL, რომელიც Creditinfo-შია გაწერილი

    public function __construct() {
        $this->client_id        = get_option( 'capex_client_id' );
        $this->client_secret    = get_option( 'capex_client_secret' );
        $this->redirect_handler = get_option( 'capex_redirect_handler' ); // განახლებული სეთინგი
    }

    /**
     * 1. ავტორიზაციის URL-ის გენერირება
     * @param string $return_url - გვერდი, სადაც მომხმარებელი უნდა დაბრუნდეს (მაგ: /auto-loan)
     */
    public function get_auth_url( $return_url ) {
        if ( empty( $this->client_id ) || empty( $this->redirect_handler ) ) {
            return false;
        }

        // ვქმნით უნიკალურ Nonce-ს უსაფრთხოებისთვის
        $nonce = wp_create_nonce( 'capex_sso_nonce' );

        // State-ში ვწერთ: NONCE + გამყოფი + დასაბრუნებელი მისამართი
        // მაგ: "a1b2c3d4|https://capexcredit.ge/auto-loan/"
        // ამას ვაკოდირებთ Base64-ში, რომ URL-ში ლამაზად ჩაჯდეს
        $state_data = $nonce . '|' . $return_url;
        $state_encoded = base64_encode( $state_data );

        // ვინახავთ დროებით მეხსიერებაში (Transient) 10 წუთით
        set_transient( 'capex_sso_' . $nonce, $return_url, 10 * MINUTE_IN_SECONDS );

        $scope = 'openid profile email phone address'; 

        $params = array(
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_handler, // ეს მუდმივია (რაც Creditinfo-მ იცის)
            'response_type' => 'code',
            'scope'         => $scope,
            'state'         => $state_encoded // აქ არის ჩამალული რეალური მარშრუტი
        );

        return $this->auth_endpoint . '?' . http_build_query( $params );
    }

    /**
     * 2. State-ის გაშიფრვა და ვალიდაცია
     * ეს მეთოდი გვეუბნება, სად უნდა გადავამისამართოთ მომხმარებელი საბოლოოდ
     */
    public function validate_state_and_get_url( $state_encoded ) {
        $state_decoded = base64_decode( $state_encoded );
        $parts = explode( '|', $state_decoded );

        if ( count( $parts ) < 2 ) {
            return false;
        }

        $nonce = $parts[0];
        $return_url = $parts[1];

        // ვამოწმებთ, არსებობს თუ არა ეს Nonce ჩვენს ბაზაში (Valid & Not Expired)
        $saved_url = get_transient( 'capex_sso_' . $nonce );

        if ( $saved_url && $saved_url === $return_url ) {
            // გამოყენების შემდეგ ვშლით (Replay Attack Protection)
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
            'redirect_uri'  => $this->redirect_handler, // აქაც იგივე ტექნიკური URL უნდა იყოს
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
     * მონაცემების გადათარგმნა ჩვენს ფორმატში
     */
    private function map_user_data( $api_data ) {
        return array(
            'name'      => isset($api_data['firstName']) ? $api_data['firstName'] : '',
            'surname'   => isset($api_data['lastName']) ? $api_data['lastName'] : '',
            'pid'       => isset($api_data['sub']) ? $api_data['sub'] : '', 
            'dob'       => isset($api_data['birthdate']) ? $api_data['birthdate'] : '',
            'email'     => isset($api_data['email']) ? $api_data['email'] : '',
            'phone'     => isset($api_data['phone']) ? $api_data['phone'] : '',
            'address'   => isset($api_data['address']) ? $api_data['address'] : '',
        );
    }
}