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
    $aws_bucket = $settings['aws_bucket'];
    $aws_access_key =  $settings['aws_access_key'];
    $aws_access_secret = $settings['aws_access_secret'];
    if ($settings['shared_credentials'] != 'TRUE') {
      $user_id = get_current_user_id();
      $aws_credentials = get_user_meta( $user_id, $this->token . '_credentials', true );
      $aws_access_key = $aws_credentials['aws_access_key'];
      $aws_access_secret = $aws_credentials['aws_access_secret'];
    }
    if (! $aws_access_key || ! $aws_access_secret){
      // the localized script does nothing if the aws_bucket is null
      $aws_bucket = null;
    }
    wp_localize_script( 'upload_to_s3_amazon_cors', 'wp_script_vars', array(
        'aws_bucket' => $aws_bucket,
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
    add_settings_field( 'aws_bucket', 'S3 Bucket Name', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_bucket', 'label' => 'This is found at <a href="https://console.aws.amazon.com/s3/home">https://console.aws.amazon.com/s3/home</a>', 'class' => 'regular-text') );
    add_settings_field( 'aws_folder', 'Folder (optional)', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_folder', 'label' => 'The folder will automatically be created if it doesn\'t exist', 'class' => 'regular-text') );
    add_settings_field( 'proxy_host', 'Proxy/public hostname (optional)', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'proxy_host', 'label' => 'The hostname where live web traffic will be directed to for these files, if you do not want direct web traffic to your S3 bucket.  You will need to setup the proxying yourself.', 'class' => 'regular-text') );
    add_settings_field( 'shared_credentials', 'All logged-in users share the same AWS access credentials?', array( $this, 'settings_field_select'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'shared_credentials', 'label' => 'If FALSE, each user will have to have their own AWS key/secret, which they\'ll maintain in their user profile', 'default' => 'true', 'class' => 'regular-text', 'options' => array('TRUE' => 'true' , 'FALSE' => 'false') ) );
    add_settings_field( 'aws_access_key', 'Shared AWS Access Key', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_access_key', 'label' => 'Required if using shared access credentials, unused otherwise', 'class' => 'regular-text') );
    add_settings_field( 'aws_access_secret', 'Shared AWS Access Secret', array( $this, 'settings_field'), 'upload_to_s3_settings', 'settingssection1', array('setting' => 'upload_to_s3_settings', 'field' => 'aws_access_secret', 'label' => 'Required if using shared access credentials, unused otherwise', 'class' => 'large-text') );

  }

  public function settings_section_callback() { echo ' '; }

  public function settings_field( $args ) {
    // This is the default processor that will handle standard text input fields.  Because it accepts a class, it can be styled or even have jQuery things (like a calendar picker) integrated in it.  Pass in a 'default' argument only if you want a non-empty default value.
    $settingname = esc_attr( $args['setting'] );
    $setting = get_option($settingname);
    $field = esc_attr( $args['field'] );
    $label = $args['label'];
    $class = esc_attr( $args['class'] );
    $default = ($args['default'] ? esc_attr( $args['default'] ) : '' );
    $value = (($setting[$field] && strlen(trim($setting[$field]))) ? $setting[$field] : $default);
    echo '<input type="text" name="' . $settingname . '[' . $field . ']" id="' . $settingname . '[' . $field . ']" class="' . $class . '" value="' . $value . '" /><p class="description">' . $label . '</p>';
  }

  public function settings_field_select( $args ) {
    // This processor handles a select w/options.  Because it accepts a class, it can be styled or even have jQuery things (like a calendar picker) integrated in it.  Pass in a 'default' argument only if you want a non-empty default value.
    $settingname = esc_attr( $args['setting'] );
    $setting = get_option($settingname);
    $field = esc_attr( $args['field'] );
    $label = esc_attr( $args['label'] );
    $class = esc_attr( $args['class'] );
    $options = $args['options'];
    $default = ($args['default'] ? esc_attr( $args['default'] ) : '' );
    $value = (($setting[$field] && strlen(trim($setting[$field]))) ? $setting[$field] : $default);
    echo '<select name="' . $settingname . '[' . $field . ']" id="' . $settingname . '[' . $field . ']" class="' . $class . '">';
    if (is_array($options)) {
      foreach ($options as $key => $optlabel) {
        echo '<option value="' . $key . '"';
        if ($key == $value) {
          echo ' selected ';
        }
        echo ' >' . $optlabel . '</option>';
      }
    }
    echo '</select><p class="description">' . $label . '</p>';
  }




  public function settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }
    ?>
    <div class="wrap">
      <h2>Upload to S3 Settings</h2>
      <p>The Upload to S3 plugin automatically adds to specific text input fields the ability to select a file from your local machine, upload it directly to an AWS S3 bucket that the user has access to, and populate the text field with the public URL of the uploaded file.  The file is never saved on the WordPress server. This is useful for working with large media files, such as podcasts and videos, that you want to store remotely without filling up your server disk.</p>
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
    ?>
    <h3>Documentation</h3>
    <h4>Usage</h4>
    <p>Add the CSS class 'upload_to_s3' to any text input and the S3 file uploader will automatically appear connected to that text field.  Selecting and uploading a file will put the file in your S3 bucket and will put the public URL of your file into the text field. You can apply the class to multiple text input fields on a page, and each will work independently.</p>
    <h4>Shared or individual access credentials</h4>
    <p>Upload access to your bucket is setup in AWS via the AWS 'IAM' system.  Any user(s) who have access to the bucket will need an IAM account and a key/secret pair.  You may create a single user and enter that key pair in the fields above, or you may setup indiviudal IAM accounts for any users you want to allow to upload to the S3 bucket.  In this latter case, the user's key pair will be maintainted in his or her own <a href="profile.php">profile page</a>.</p>
    <p>If there is no key pair set (either the global pair if you use shared credentials, or the individual users' key pair if you use individual credentials), the S3 file uploader will not appear.</p>

    <h4>CORS policy</h4>
    <p>You will need to add a 'CORS policy' to your AWS bucket to make this plugin work.  This is found in the <a href="https://console.aws.amazon.com/s3/home">AWS S3 console</a> for your bucket, under 'Properties', then 'Permissions'.  The CORS policy is a JSON file that grants permissions to browsers visiting a specific web host.  The hostname needs to be exactly what you are see in this current logged-in admin window -- if you log in via https, the hostname will need to start with https.  Assuming that your hostname is <b><?php echo ($_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME']; ?></b> your CORS policy file should look like this:</p>
<pre>
&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"&gt;
    &lt;CORSRule&gt;
        &lt;AllowedOrigin&gt;<?php echo ($_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME']; ?>&lt;/AllowedOrigin&gt;
        &lt;AllowedMethod&gt;PUT&lt;/AllowedMethod&gt;
        &lt;AllowedMethod&gt;POST&lt;/AllowedMethod&gt;
        &lt;AllowedMethod&gt;DELETE&lt;/AllowedMethod&gt;
        &lt;AllowedHeader&gt;*&lt;/AllowedHeader&gt;
    &lt;/CORSRule&gt;
    &lt;CORSRule&gt;
        &lt;AllowedOrigin&gt;*&lt;/AllowedOrigin&gt;
        &lt;AllowedMethod&gt;GET&lt;/AllowedMethod&gt;
        &lt;AllowedHeader&gt;*&lt;/AllowedHeader&gt;
    &lt;/CORSRule&gt;
&lt;/CORSConfiguration&gt;
</pre>
<p>NOTE: If you have additional hosts you want to allow read/write CORS access from, you'll have multiple versions of that first 'CORSRule' section, one for each host.</p>
    <h4>Demo/Test</h4>
    <ul><li>If you have entered and saved the name of a 'bucket' above and have have your access key/secret pairs entered, the demo field below will display the file uploader. <?php
    $settings = get_option('upload_to_s3_settings', true);
    $aws_access_key =  $settings['aws_access_key'];
    $aws_access_secret = $settings['aws_access_secret'];
    if ($settings['shared_credentials'] != 'TRUE') {
      echo 'The plugin is currently set to use the credentials for the logged-in user. ';
      $user_id = get_current_user_id();
      $aws_credentials = get_user_meta( $user_id, $this->token . '_credentials', true );
      $aws_access_key = $aws_credentials['aws_access_key'];
      $aws_access_secret = $aws_credentials['aws_access_secret'];
    } else {
      echo 'The plugin is currently set to use shared credentials. ';
    }
    if (empty($aws_access_key) || empty($aws_access_secret) ) {
      echo '<b>The credentials aren\'t set! The uploader will not display.</b> ';
    } else {
      echo '<b>You should see the uploader below.</b>';
      echo '<p>Demo input: <input type=text class="regular-text upload_to_s3" /></p>';
    }  
    ?></li>
   
    <li>If your access keys and all other settings are correct and your CORS policy is correct on the AWS end, the file uploader above will work. </li>
    </ul>
        <?php
  }


  public function add_personal_options_section( $user ) {
    $settings = get_option('upload_to_s3_settings', true);
    if ($settings['shared_credentials'] == 'TRUE') {
      return;
    }
    $aws_credentials = get_user_meta( $user->ID, $this->token . '_credentials', true );
    echo '<h3>AWS credentials for S3 bucket "' . $settings['aws_bucket'] . '"</h3>';
    echo 'AWS Access Key: <input type="text" value="' . $aws_credentials['aws_access_key'] . '" name="' . $this->token . '_credentials[aws_access_key]" class="regular-text" /><br />';
    echo 'AWS Access Secret: <input type="text" value="' . $aws_credentials['aws_access_secret'] . '" name="' . $this->token . '_credentials[aws_access_secret]" class="large-text" />';
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
    $aws_access_key =  $settings['aws_access_key'];
    $aws_access_secret = $settings['aws_access_secret'];
    if ($settings['shared_credentials'] != 'TRUE') {
      $user_id = get_current_user_id();
      $aws_credentials = get_user_meta( $user_id, $this->token . '_credentials', true );
      $aws_access_key = $aws_credentials['aws_access_key'];
      $aws_access_secret = $aws_credentials['aws_access_secret'];
    }
    if (!empty($aws_access_key) && !empty($aws_access_secret) && !empty($settings['aws_bucket'] ) ) {
      // this signing code adapted from Carson McDonald -- 
      // http://www.ioncannon.net/programming/1539/direct-browser-uploading-amazon-s3-cors-fileapi-xhr2-and-signed-puts/
      $SIGNPUT_S3_KEY = $aws_access_key;
      $SIGNPUT_S3_SECRET = $aws_access_secret;
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
