<?php
/**
 * Parameter validation shared by generation and publishing workflows.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPAIP_9IR4 {
    private static $config = array(
        'batch_generate' => array(
            'post_status' => array(
                'values'  => array( 'draft', 'publish', 'future' ),
                'default' => 'draft',
            ),
        ),
        'seo_push' => array(
            'platforms' => array(
                'values'  => array( 'baidu', 'bing', '360', 'google' ),
                'default' => null,
            ),
        ),
    );

    public static function get_values( $feature, $param, $unused = null ) {
        unset( $unused );
        return self::$config[ $feature ][ $param ]['values'] ?? array();
    }

    public static function is_allowed( $feature, $param, $value, $unused = null ) {
        unset( $unused );
        $allowed = self::get_values( $feature, $param );

        if ( is_array( $value ) ) {
            return empty( array_diff( $value, $allowed ) );
        }

        return in_array( $value, $allowed, true );
    }

    public static function sanitize( $feature, $param, $value, $unused = null ) {
        unset( $unused );
        if ( self::is_allowed( $feature, $param, $value ) ) {
            return $value;
        }

        $config = self::$config[ $feature ][ $param ] ?? array();
        return $config['default'] ?? null;
    }

    public static function get_client_config() {
        $result = array();
        foreach ( self::$config as $feature => $params ) {
            foreach ( $params as $param => $config ) {
                $result[ $feature ][ $param ] = array(
                    'full'    => $config['values'],
                    'default' => $config['default'],
                );
            }
        }
        return $result;
    }

    public static function get_feature_params( $feature ) {
        return self::$config[ $feature ] ?? array();
    }
}
