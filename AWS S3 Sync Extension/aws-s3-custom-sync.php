<?php
/**
 * Plugin Name: AWS S3 Multi-Instance Sync & Serve
 * Description: Uploads Media and CSS to S3 and rewrites URLs to serve directly from the bucket. Includes built-in setup instructions and local historical library processing.
 * Version: 3.5
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

        // Bulk sync AJAX hook
        add_action( 'wp_ajax_aws_s3_bulk_sync', array( $this, 'handle_bulk_sync_ajax' ) );

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
     * Initializes the S3 Client with safety trim adjustments
     */
    private function get_s3_client() {
        $aws_key    = isset( $this->options['aws_key'] ) ? trim( $this->options['aws_key'] ) : '';
        $aws_secret = isset( $this->options['aws_secret'] ) ? trim( $this->options['aws_secret'] ) : '';
        $aws_region = isset( $this->options['aws_region'] ) ? trim( $this->options['aws_region'] ) : '';

        if ( empty( $aws_key ) || empty( $aws_secret ) || empty( $aws_region ) ) return false;

        return new S3Client([
            'version'     => 'latest',
            'region'      => $aws_region,
            'credentials' => [
                'key'    => $aws_key,
                'secret' => $aws_secret,
            ],
        ]);
    }

    /**
     * Uploads a file to S3 mirroring the local uploads directory structure
     */
    private function upload_to_s3( $file_path ) {
        $s3          = $this->get_s3_client();
        $bucket_name = isset( $this->options['bucket_name'] ) ? trim( $this->options['bucket_name'] ) : '';

        if ( ! $s3 || empty( $bucket_name ) || ! file_exists( $file_path ) ) return false;

        // Strip the local base directory to get the relative path (e.g., "2026/06/image.jpg")
        $relative_path = ltrim( str_replace( $this->upload_dir['basedir'], '', $file_path ), '/' );

        try {
            $s3->putObject([
                'Bucket'      => $bucket_name,
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

    /* --- BACKGROUND BULK SYNC LOGIC --- */

    public function handle_bulk_sync_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized user.' ) );
        }

        // Count total matching attachments
        $total_images = wp_count_posts( 'attachment' )->inherit;
        
        $offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;

        $attachments = get_posts( array(
            'post_type'      => 'attachment',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'post_status'    => 'inherit',
        ) );

        if ( empty( $attachments ) ) {
            wp_send_json_success( array( 'total' => $total_images ) );
        }

        foreach ( $attachments as $attachment ) {
            $file_path = get_attached_file( $attachment->ID );
            
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                continue; 
            }

            // 1. Upload Main File
            $this->upload_to_s3( $file_path );

            // 2. Scan and Upload Thumbnails
            $metadata = wp_get_attachment_metadata( $attachment->ID );
            if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                $base_dir = dirname( $file_path ) . '/';
                foreach ( $metadata['sizes'] as $size_info ) {
                    $thumb_path = $base_dir . $size_info['file'];
                    if ( file_exists( $thumb_path ) ) {
                        $this->upload_to_s3( $thumb_path );
                    }
                }
            }
        }

        wp_send_json_success( array( 'total' => intval( $total_images ) ) );
    }

    /* --- URL REWRITE METHODS --- */

    public function rewrite_url_to_s3( $url ) {
        if ( empty( $url ) ) return $url;

        $local_base_url = $this->upload_dir['baseurl'];
        $s3_base_url    = rtrim( trim( $this->options['s3_base_url'] ), '/' );

        if ( strpos( $url, $local_base_url ) !== false ) {
            $url = str_replace( $local_base_url, $s3_base_url, $url );
        }

        return $url;
    }

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
            <h1>AWS S3 Multi-Instance Control Panel</h1>
            <p>Configure seamless asset synchronization for highly available, multi-instance web servers.</p>
            
            <div style="display: flex; gap: 25px; flex-wrap: wrap; margin-top: 20px;">
                
                <div style="flex: 2; min-width: 450px;">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields( 'aws_s3_sync_group' );
                        do_settings_sections( 'aws-s3-sync-admin' );
                        submit_button();
                        ?>
                    </form>

                    <div class="card" style="margin-top: 25px; max-width: 100%; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px;">
                        <h2 style="margin-top:0;">Bulk Process Existing Media</h2>
                        <p>If this website already has images inside its library, click below to migrate them to your Amazon S3 cluster automatically.</p>
                        
                        <button type="button" id="start-s3-sync" class="button button-secondary" style="height:34px; padding:0 16px;">Start Bulk Upload Worker</button>
                        
                        <div id="sync-progress-container" style="display:none; margin-top: 20px;">
                            <div style="background: #eee; width: 100%; height: 22px; border-radius: 4px; overflow: hidden; border: 1px solid #ccc;">
                                <div id="sync-progress-bar" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s;"></div>
                            </div>
                            <p id="sync-status-text" style="font-weight: bold; margin-top: 8px; color:#333;">Initializing background streams...</p>
                        </div>
                    </div>
                </div>

                <div style="flex: 1; min-width: 300px; max-width: 450px;">
                    <div class="card" style="padding: 20px; background: #f6f7f7; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.02); border-radius: 4px;">
                        <h2 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #ccd0d4; color:#23282d;">📋 Step-by-Step Setup Instructions</h2>
                        
                        <h3 style="color:#2271b1; margin-bottom:5px;">Step 1: Get AWS Keys</h3>
                        <p style="margin-top:0; font-size:13px; line-height:1.5;">Log into your AWS Console, open <strong>IAM</strong>, and create an infrastructure user with <strong>Programmatic Access</strong>. Attach the <code>AmazonS3FullAccess</code> permission policy to provide the secure API keys required on the left.</p>

                        <h3 style="color:#2271b1; margin-bottom:5px;">Step 2: Configure Your S3 Bucket</h3>
                        <p style="margin-top:0; font-size:13px; line-height:1.5;">Open your Amazon S3 bucket permissions console and configure these two vital requirements:
                        <ul style="list-style-type: disc; padding-left: 20px; margin-top:5px; font-size:13px;">
                            <li><strong>Block Public Access:</strong> Uncheck the "Block all public access" checkbox.</li>
                            <li><strong>Object Ownership:</strong> Click edit, switch it to <strong>ACLs Enabled</strong>, and acknowledge the security prompt.</li>
                        </ul>
                        </p>

                        <h3 style="color:#2271b1; margin-bottom:5px;">Step 3: Define Your Delivery URL</h3>
                        <p style="margin-top:0; font-size:13px; line-height:1.5;">By default, input your standard bucket address: <br><code>https://your-bucket-name.s3.your-region.amazonaws.com</code><br><br>
                        <strong>🚀 Pro-Tip:</strong> For high-traffic networks, create an <strong>AWS CloudFront Distribution</strong> pointing to your S3 bucket. Paste your CloudFront domain name or custom branded CNAME (e.g., <code>https://cdn.yourdomain.com</code>) into the field to deliver media files worldwide via edge-caching.</p>

                        <h3 style="color:#2271b1; margin-bottom:5px;">Step 4: Enable Sync Options</h3>
                        <p style="margin-top:0; font-size:13px; line-height:1.5;">Check the sync boxes to intercept file workflows. The plugin safely strips trailing white spaces behind the scenes to avoid communication bugs.</p>
                    </div>
                </div>

            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let offset = 0;
            const batchSize = 5; 

            $('#start-s3-sync').on('click', function() {
                $(this).attr('disabled', true).text('Syncing...');
                $('#sync-progress-container').show();
                runSyncBatch();
            });

            function runSyncBatch() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aws_s3_bulk_sync',
                        offset: offset,
                        batch_size: batchSize
                    },
                    success: function(response) {
                        if (response.success) {
                            offset += batchSize;
                            let total = parseInt(response.data.total);
                            
                            if (total === 0) {
                                $('#sync-progress-bar').css('width', '100%');
                                $('#sync-status-text').text('No media library items found to upload.');
                                $('#start-s3-sync').text('Sync Finished');
                                return;
                            }

                            let percent = Math.min(100, Math.round((offset / total) * 100));
                            
                                $('#sync-progress-bar').css('width', percent + '%');
                                $('#sync-status-text').text('Synced ' + Math.min(offset, total) + ' of ' + total + ' files.');

                            if (offset < total) {
                                runSyncBatch(); 
                            } else {
                                $('#sync-progress-bar').css('background', '#46b450');
                                $('#sync-status-text').text('🎉 Bulk sync complete! All assets successfully deployed to S3 clusters.');
                                $('#start-s3-sync').text('Sync Finished');
                            }
                        } else {
                            let errorMsg = response.data && response.data.message ? response.data.message : 'Unknown cloud environment error';
                            $('#sync-status-text').text('❌ Error: ' + errorMsg);
                            $('#start-s3-sync').attr('disabled', false).text('Resume Bulk Upload');
                        }
                    },
                    error: function() {
                        $('#sync-status-text').text('❌ Server timeout encountered. Retrying...');
                        setTimeout(runSyncBatch, 2000); 
                    }
                });
            }
        });
        </script>
        <?php
    }

    public function page_init() {
        register_setting( 'aws_s3_sync_group', 'aws_s3_sync_settings' );
        add_settings_section( 'setting_section_id', 'AWS Credentials & URL Settings', null, 'aws-s3-sync-admin' );

        add_settings_field( 'aws_key', 'AWS Access Key', array( $this, 'aws_key_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'aws_secret', 'AWS Secret Key', array( $this, 'aws_secret_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'aws_region', 'AWS Region', array( $this, 'aws_region_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'bucket_name', 'Bucket Name', array( $this, 'bucket_name_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 's3_base_url', 'S3 / CloudFront Delivery URL', array( $this, 's3_base_url_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
        add_settings_field( 'sync_options', 'Sync Activation Options', array( $this, 'sync_options_callback' ), 'aws-s3-sync-admin', 'setting_section_id' );
    }

    public function aws_key_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[aws_key]" value="%s" style="width: 330px;" placeholder="AKIAIOSFODNN7EXAMPLE" />', isset( $this->options['aws_key'] ) ? esc_attr( trim($this->options['aws_key']) ) : '' );
    }
    public function aws_secret_callback() {
        printf( '<input type="password" name="aws_s3_sync_settings[aws_secret]" value="%s" style="width: 330px;" />', isset( $this->options['aws_secret'] ) ? esc_attr( trim($this->options['aws_secret']) ) : '' );
    }
    public function aws_region_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[aws_region]" value="%s" placeholder="us-west-2" style="width: 150px;" />', isset( $this->options['aws_region'] ) ? esc_attr( trim($this->options['aws_region']) ) : '' );
    }
    public function bucket_name_callback() {
        printf( '<input type="text" name="aws_s3_sync_settings[bucket_name]" value="%s" style="width: 250px;" placeholder="my-wordpress-bucket" />', isset( $this->options['bucket_name'] ) ? esc_attr( trim($this->options['bucket_name']) ) : '' );
    }
    public function s3_base_url_callback() {
        printf( '<input type="url" name="aws_s3_sync_settings[s3_base_url]" value="%s" style="width: 330px;" placeholder="https://cdn.yourdomain.com" /><br><small>Input your raw S3 path or your custom CloudFront URL address edge node link without a trailing slash.</small>', isset( $this->options['s3_base_url'] ) ? esc_attr( trim($this->options['s3_base_url']) ) : '' );
    }
    public function sync_options_callback() {
        $media = isset( $this->options['sync_media'] ) ? 'checked' : '';
        $css = isset( $this->options['sync_css'] ) ? 'checked' : '';
        echo "<label><input type='checkbox' name='aws_s3_sync_settings[sync_media]' value='1' $media> Enable Media Library Sync & URL Rewriting</label><br style='margin-bottom:6px;'>";
        echo "<label><input type='checkbox' name='aws_s3_sync_settings[sync_css]' value='1' $css> Enable Elementor Dynamic Stylesheet Sync</label>";
    }
}

// Instantiate the class globally
new AWS_S3_Multi_Instance();