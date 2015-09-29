<?php
/*
 * Plugin Name: Upload To S3 
 * Version: 0.1 
 * Plugin URI: http://ieg.wnet.org/
 * Description: Upload from your form directly to your AWS S3 bucket and get the URL back
 * Author: William Tam
 * Author URI: http://ieg.wnet.org/
 * Requires at least: 3.0
 * Tested up to: 4.2.2 
 * 
 * @package WordPress
 * @author William Tam
 * @since 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
class uploadToS3 { 
  private $dir;
  private $file;
  private $assets_dir;
  private $assets_url;
  private $token;

  public function __construct( $file ) {
    $this->dir = dirname( $file );
    $this->file = $file;
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $file ) ) );
    $this->token = 'upload_to_s3';

    // Register plugin settings
    add_action( 'admin_init' , array( $this , 'register_settings' ) );
    // Add settings page to menu
    add_action( 'admin_menu' , array( $this , 'add_menu_item' ) );
    // Add settings link to plugins page
    add_filter( 'plugin_action_links_' . plugin_basename( $this->file ) , array( $this , 'add_settings_link' ) );

    add_action( 'admin_enqueue_scripts', array( $this, 'setup_admin_scripts' ) );

    // Add user option handling
    add_action( 'profile_personal_options', array( $this, 'add_personal_options_section') );
    add_action( 'personal_options_update', array( $this, 'update_personal_options') );

    // setup the wp ajax action for AWS code signing
    add_action( 'wp_ajax_upload_to_s3_sign_aws_request', array($this, 'sign_aws_request') );
  }


  public function setup_admin_scripts() {
    wp_register_script( 'upload_to_s3_amazon_cors', $this->assets_url . 'js/amazon_cors.js', array( 'jquery' ), 1, true);
//    wp_enqueue_style( 'cove_asset', $this->assets_url . 'css/metaboxes.css' );

    $settings = get_option('upload_to_s3_settings'); 
    wp_localize_script( 'upload_to_s3_amazon_cors', 'wp_script_vars', array(
        'aws_bucket' => $settings['aws_bucket'],
        'proxy_host' => $settings['proxy_host'], 
        'aws_folder' => $settings['aws_folder']
      )
    );
    wp_enqueue_script( 'upload_to_s3_amazon_cors');
  }


  /* The next few functions set up the settings page */
  
  public function add_menu_item() {
    add_options_page( 'Upload to S3 Settings' , 'Upload to S3 Settings' , 'manage_options' , 'upload_to_s3_settings' ,  array( $this , 'settings_page' ) );
  }

  public function add_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=upload_to_s3_settings">Settings</a>';
    array_push( $links, $settings_link );
    return $links;
  }

  public function register_settings() {
    register_setting( 'upload_to_s3_group', 'upload_to_s3_settings' );
    add_settings_section('settingssection1', 'AWS S3 Settings', array( $this, 'settings_section_callback'), 'upload_to_s3_settings');
    // you can define EVERYTHING to create, display, and process each settings field as one line per setting below.  And all settings defined in this function are stored as a single serialized object.
    add_settings_field( 'aws_bucket', 'S3 Bucket Name', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_bucket', 'label' => '', 'class' => 'regular-text') );
    add_settings_field( 'aws_folder', 'Folder (optional)', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_folder', 'label' => '', 'class' => 'regular-text') );
    add_settings_field( 'proxy_host', 'Proxy/public hostname (optional)', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'proxy_host', 'label' => '', 'class' => 'regular-text') );
    add_settings_field( 'aws_access_key', 'AWS Access Key', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_access_key', 'label' => '', 'class' => 'regular-text') );
    add_settings_field( 'aws_access_secret', 'AWS Access Secret', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_access_secret', 'label' => '', 'class' => 'regular-text') );
  }

  public function settings_section_callback() { echo ' '; }

  public function settings_field( $args ) {
    // This is the default processor that will handle standard text input fields.  Because it accepts a class, it can be styled or even have jQuery things (like a calendar picker) integrated in it.  Pass in a 'default' argument only if you want a non-empty default value.
    $settingname = esc_attr( $args['setting'] );
    $setting = get_option($settingname);
    $field = esc_attr( $args['field'] );
    $label = esc_attr( $args['label'] );
    $class = esc_attr( $args['class'] );
    $default = ($args['default'] ? esc_attr( $args['default'] ) : '' );
    $value = (($setting[$field] && strlen(trim($setting[$field]))) ? $setting[$field] : $default);
    echo '<input type="text" name="' . $settingname . '[' . $field . ']" id="' . $settingname . '[' . $field . ']" class="' . $class . '" value="' . $value . '" /><p class="description">' . $label . '</p>';
  }

  public function settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }
    ?>
    <div class="wrap">
      <h2>Upload to S3 Settings</h2>
      <form action="options.php" method="POST">
        <?php settings_fields( 'upload_to_s3_group' ); ?>
        <?php do_settings_sections( 'upload_to_s3_settings' ); ?>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
    echo $this->print_documentation();
  }


  private function print_documentation() {
    $return = '<p>To test your setup, try the test fields below</p>';
    $return .= 'Test input 1: <input type=text class="regular-text upload_to_s3" />';
    $return .= 'Test input 2: <input type=text class="regular-text upload_to_s3" />';
    return $return;
  }


  public function add_personal_options_section( $user ) {
    $aws_credentials = get_user_meta( $user->ID, $this->token . '_credentials', true );
    $settings = get_option('upload_to_s3_settings', true);
    echo '<h3>AWS credentials for S3 bucket "' . $settings['aws_bucket'] . '"</h3>';
    echo 'AWS Access Key: <input type="text" value="' . $aws_credentials['aws_access_key'] . '" name="' . $this->token . '_credentials[aws_access_key]" class="regular-text" /><br />';
    echo 'AWS Access Secret: <input type="text" value="' . $aws_credentials['aws_access_secret'] . '" name="' . $this->token . '_credentials[aws_access_secret]" class="regular-text" />';
  }
  
  public function update_personal_options( $user_id ) {
    $field = $this->token . '_credentials';
    if (!empty($_POST[$field])) {
      update_user_meta($user_id, $field, $_POST[$field]);
    }
  }
  



  public function sign_aws_request() {
    $slug = $_GET['slug'];
    $fileinfo = $_GET['fileinfo'];
    $return = 'config params needed';
    $settings = get_option('upload_to_s3_settings');
    //tk $aws_credentials = get_user_meta( $user->ID, $this->token . '_credentials', true );

    if (!empty($settings['aws_access_key'] ) && !empty($settings['aws_access_secret'] ) && !empty($settings['aws_bucket'] ) ) {
      $SIGNPUT_S3_KEY = $settings['aws_access_key'];
      $SIGNPUT_S3_SECRET = $settings['aws_access_secret'];
      $SIGNPUT_S3_BUCKET= '/' . $settings['aws_bucket'];


      $EXPIRE_TIME=(60 * 5); // 5 minutes
      $S3_URL='https://s3.amazonaws.com';
      $objectName='/' . $slug;

      if ( !empty($settings['aws_folder'] ) ) {
        $objectName = '/' . $settings['aws_folder'] . $objectName;
      }

      $mimeType=$fileinfo;
      $expires = time() + $EXPIRE_TIME;
      $amzHeaders= "x-amz-acl:public-read";
      $stringToSign = "PUT\n\n$mimeType\n$expires\n$amzHeaders\n$SIGNPUT_S3_BUCKET$objectName";
      $sig = urlencode(base64_encode(hash_hmac('sha1', $stringToSign, $SIGNPUT_S3_SECRET, true)));

      $return = urlencode("$S3_URL$SIGNPUT_S3_BUCKET$objectName?AWSAccessKeyId=$SIGNPUT_S3_KEY&Expires=$expires&Signature=$sig");
    }
    echo $return;
    die;
  }


  
//end of class  
}

// Instantiate our class
global $plugin_obj;
$plugin_obj = new uploadToS3( __FILE__ );


// always cleanup after yourself
register_deactivation_hook(__FILE__, 'upload_to_s3_deactivation');

function upload_to_s3_deactivation() {
}

/* END OF FILE */
?>
