<?php
/**
 * Plugin Name: AWS S3 Multi-Instance Sync & Serve
 * Description: Uploads Media and CSS to S3 and rewrites URLs to serve directly from the bucket.
 * Version: 3.0
 * Author: Alain Puron
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Try to load the SDK
if ( file_exists( __DIR__ . '/aws-autoloader.php' ) ) {
    require_once __DIR__ . '/aws-autoloader.php';
}

// 2. Failsafe: If the SDK failed to load, stop the plugin from crashing the site
if ( ! class_exists( 'Aws\S3\S3Client' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>AWS S3 Sync:</strong> The AWS SDK files are missing or incomplete. Please ensure the Aws folder and aws-autoloader.php are present.</p></div>';
    });
    return; // Stop running the rest of the plugin
}

use Aws\S3\S3Client;
use Aws\Exception\AwsException;



class AWS_S3_Multi_Instance {

    private $options;
    private $upload_dir;

    public function __construct() {
        $this->options = get_option( 'aws_s3_sync_settings' );
        $this->upload_dir = wp_upload_dir();

        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );

        // 1. Sync Hooks (Upload to S3)
        if ( ! empty( $this->options['sync_media'] ) ) {
            add_filter( 'wp_generate_attachment_metadata', array( $this, 'sync_media_to_s3' ), 10, 2 );
        }

        if ( ! empty( $this->options['sync_css'] ) ) {
            add_action( 'save_post', array( $this, 'sync_builder_css_to_s3' ), 99, 1 );
            add_action( 'elementor/core/files/clear_cache', array( $this, 'sync_elementor_global_css' ) );
        }

        // 2. URL Rewrite Hooks (Serve from S3)
        if ( ! empty( $this->options['s3_base_url'] ) ) {
            // Rewrite Media URLs
            add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_url_to_s3' ) );
            add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_srcset_to_s3' ), 10, 5 );
            
            // Rewrite CSS Enqueue URLs (Catches Elementor and custom uploads)
            add_filter( 'style_loader_src', array( $this, 'rewrite_url_to_s3' ) );
        }
    }

    /**
     * Initializes the S3 Client
     */
    private function get_s3_client() {
        if ( empty( $this->options['aws_key'] ) || empty( $this->options['aws_secret'] ) || empty( $this->options['aws_region'] ) ) return false;

        return new S3Client([
            'version'     => 'latest',
            'region'      => $this->options['aws_region'],
            'credentials' => [
                'key'    => $this->options['aws_key'],
                'secret' => $this->options['aws_secret'],
            ],
        ]);
    }

    /**
     * Uploads a file to S3 mirroring the local uploads directory structure
     */
    private function upload_to_s3( $file_path ) {
        $s3 = $this->get_s3_client();
        if ( ! $s3 || empty( $this->options['bucket_name'] ) || ! file_exists( $file_path ) ) return false;

        // Strip the local base directory to get the relative path (e.g., "2026/06/image.jpg" or "elementor/css/global.css")
        $relative_path = ltrim( str_replace( $this->upload_dir['basedir'], '', $file_path ), '/' );

        try {
            $s3->putObject([
                'Bucket'      => $this->options['bucket_name'],
                'Key'         => $relative_path,
                'SourceFile'  => $file_path,
                'ACL'         => 'public-read',
                'ContentType' => mime_content_type( $file_path ) ?: 'application/octet-stream'
            ]);
            return true;
        } catch ( AwsException $e ) {
            error_log( 'AWS S3 Upload Error: ' . $e->getMessage() );
            return false;
        }
    }

    /* --- SYNC METHODS --- */

    public function sync_media_to_s3( $metadata, $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( $file_path ) {
            $this->upload_to_s3( $file_path );
            
            // Sync generated thumbnails
            if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                $base_dir = dirname( $file_path ) . '/';
                foreach ( $metadata['sizes'] as $size => $size_info ) {
                    $this->upload_to_s3( $base_dir . $size_info['file'] );
                }
            }
        }
        return $metadata;
    }

    public function sync_builder_css_to_s3( $post_id ) {
        if ( wp_is_post_revision( $post_id ) ) return;
        $elementor_path = $this->upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
        if ( file_exists( $elementor_path ) ) {
            $this->upload_to_s3( $elementor_path );
        }
    }

    public function sync_elementor_global_css() {
        $global_css_path = $this->upload_dir['basedir'] . '/elementor/css/global.css';
        if ( file_exists( $global_css_path ) ) {
            $this->upload_to_s3( $global_css_path );
        }
    }

    /* --- URL REWRITE METHODS --- */

    /**
     * Swaps the local uploads URL for the S3 URL
     */
    public function rewrite_url_to_s3( $url ) {
        if ( empty( $url ) ) return $url;

        $local_base_url = $this->upload_dir['baseurl'];
        $s3_base_url = rtrim( $this->options['s3_base_url'], '/' );

        // If the URL contains the local uploads directory path, swap it
        if ( strpos( $url, $local_base_url ) !== false ) {
            $url = str_replace( $local_base_url, $s3_base_url, $url );
        }

        return $url;
    }

    /**
     * Swaps URLs inside the responsive image srcset attributes
     */
    public function rewrite_srcset_to_s3( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        foreach ( $sources as $width => $source ) {
            $sources[$width]['url'] = $this->rewrite_url_to_s3( $source['url'] );
        }
        return $sources;
    }

    /* --- ADMIN MENU SETUP --- */

    public function add_plugin_page() {
        add_options_page( 'AWS S3 Sync Settings', 'AWS S3 Sync', 'manage_options', 'aws-s3-sync', array( $this, 'create_admin_page' ) );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>AWS S3 Multi-Instance Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'aws_s3_sync_group' );
                do_settings_sections( 'aws-s3-sync-admin' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function page_init() {
        register_setting( 'aws_s3_sync_group', 'aws_s3_sync_settings' );
        add_settings_section( 'setting_section_id', 'AWS Credentials & URL Settings', null, 'aws-s3-sync-admin' );

        add_settings_field( 'aws_key', 'AWS Access Key', array( $this, 'aws_key_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'aws_secret', 'AWS Secret Key', array( $this, 'aws_secret_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'aws_region', 'AWS Region', array( $this, 'aws_region_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'bucket_name', 'Bucket Name', array( $this, 'bucket_name_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 's3_base_url', 'S3 Delivery URL', array( $this, 's3_base_url_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'sync_options', 'Sync Options', array( $this, 'sync_options_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
    }

    public function aws_key_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[aws_key]" value="%s" style="width: 300px;" />', isset( $this->options['aws_key'] ) ? esc_attr( $this->options['aws_key'] ) : '' );
    }
    public function aws_secret_callback() {
        printf( '<input type="password" name="aws_s3_sync_settings[aws_secret]" value="%s" style="width: 300px;" />', isset( $this->options['aws_secret'] ) ? esc_attr( $this->options['aws_secret'] ) : '' );
    }
    public function aws_region_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[aws_region]" value="%s" placeholder="us-east-1" />', isset( $this->options['aws_region'] ) ? esc_attr( $this->options['aws_region'] ) : '' );
    }
    public function bucket_name_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[bucket_name]" value="%s" />', isset( $this->options['bucket_name'] ) ? esc_attr( $this->options['bucket_name'] ) : '' );
    }
    public function s3_base_url_callback() {
        printf( '<input type="url" name="aws_s3_sync_settings[s3_base_url]" value="%s" style="width: 300px;" placeholder="https://your-bucket.s3.amazonaws.com" /><br><small>The URL to serve files from. Put your CloudFront domain here if you use one.</small>', isset( $this->options['s3_base_url'] ) ? esc_attr( $this->options['s3_base_url'] ) : '' );
    }
    public function sync_options_callback() {
        $media = isset( $this->options['sync_media'] ) ? 'checked' : '';
        $css = isset( $this->options['sync_css'] ) ? 'checked' : '';
        echo "<label><input type='checkbox' name='aws_s3_sync_settings[sync_media]' value='1' $media> Sync & Rewrite Media Library</label><br>";
        echo "<label><input type='checkbox' name='aws_s3_sync_settings[sync_css]' value='1' $css> Sync & Rewrite Elementor CSS</label>";
    }
}

if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
    new AWS_S3_Multi_Instance();
} else {
    // Only load the rewrite rules on the frontend to keep the admin loading local files safely
    new AWS_S3_Multi_Instance();
}