<?php
/**
 * @package Awesome_Surveys
 *
 */
class Awesome_Surveys_Frontend {

 public $text_domain;

 public function __construct()
 {

  $this->text_domain = 'awesome-surveys';
  add_shortcode( 'wwm_survey', array( &$this, 'wwm_survey' ) );
  add_action( 'wp_enqueue_scripts', array( &$this, 'register_scripts' ) );
  add_filter( 'awesome_surveys_auth_method_none',
   function() {
    return true;
   }
  );
  add_filter( 'awesome_surveys_auth_method_login', array( &$this, 'awesome_surveys_auth_method_login' ), 10, 1 );
  add_action( 'awesome_surveys_auth_method_cookie', array( &$this, 'awesome_surveys_auth_method_cookie' ), 10, 1 );
  add_filter( 'wwm_awesome_survey_response', array( &$this, 'wwm_awesome_survey_response_filter', ), 10, 2  );
  add_action( 'awesome_surveys_update_cookie', array( &$this, 'awesome_surveys_update_cookie' ), 10, 1 );
 }

 /**
  * This is the callback from the shortcode 'wwm_survey'. It takes a survey id ($atts['id'])
  * and gets the options for that survey from the db, then passes some of that data off to render_form
  * to eventually output the survey to the frontend. Also enqueues necessary js and css for the form.
  * @param  array $atts an array of shortcode attributes
  * @return mixed string|null  if there is a survey form to output will return an html form, else returns null
  */
 public function wwm_survey( $atts )
 {

  if ( ! isset( $atts['id'] ) ) {
   return;
  }
  $surveys = get_option( 'wwm_awesome_surveys', array() );
  if ( empty( $surveys ) || empty( $surveys['surveys'][$atts['id']] ) ) {
   return;
  }
  $auth_method = $surveys['surveys'][$atts['id']]['auth'];
  $auth_args = array(
   'survey_id' => $atts['id'],
  );
  if ( false !== apply_filters( 'awesome_surveys_auth_method_' . $auth_method, $auth_args ) ) {
   wp_enqueue_script( 'awesome-surveys-frontend' );
   wp_localize_script( 'awesome-surveys-frontend', 'wwm_awesome_surveys', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), ) );
   wp_enqueue_style( 'awesome-surveys-frontend-styles' );
   $args = array(
    'survey_id' => $atts['id'],
    'name' => $surveys['surveys'][$atts['id']]['name'],
    'auth_method' => $auth_method,
   );
   $output = $this->render_form( unserialize( $surveys['surveys'][$atts['id']]['form'] ), $args );
  } else {
   /**
   * If the user fails the authentication method, the failure message can be customized via
   * add_filter( 'wwm_survey_no_auth_message' )
   * @var string
   * @see awesome_surveys_auth_method_login() which adds a filter if the user is not logged in
   * @see not_logged_in_message() which is the filter used to customize the message if the user is not logged in.
   */
   $output = apply_filters( 'wwm_survey_no_auth_message', sprintf( '<p>%s</p>', __( 'Your response to this survey has already been recorded. Thank you!', $this->text_domain ) ) );
  }
  return $output;
 }

 /**
  * Builds the survey form from the stored options in the database.
  * @param  array $form an array of form elements - this array was stored in the db when the survey was created
  * @param  array $args an array of arguments, includes the survey id and the survey name
  * @return string an html form
  * @since  1.0
  * @author Will the Web Mechanic <will@willthewebmechanic>
  * @link http://willthewebmechanic.com
  */
 private function render_form( $form, $args )
 {

  if ( ! class_exists( 'Form' ) ) {
   include_once( plugin_dir_path( __FILE__ ) . 'PFBC/Form.php' );
   include_once( plugin_dir_path( __FILE__ ) . 'PFBC/Overrides.php' );
  }
  $nonce = wp_create_nonce( 'answer-survey' );
  $has_options = array( 'Element_Select', 'Element_Checkbox', 'Element_Radio' );
  $form_output = new FormOverrides( stripslashes( $args['name'] ) );
  $form_output->configure( array( 'class' => 'answer-survey' ) );
  $form_output->addElement( new Element_HTML( '<div class="overlay"><span class="preloader"></span></div>') );
  $form_output->addElement( new Element_HTML( '<p>' . $args['name'] . '</p>' ) );
  $questions_count = 0;
  foreach ( $form as $element ) {
   $method = $element['type'];
   $atts = $rules = $options = array();
   if ( 'Element_Select' == $method ) {
    $options[''] = __( 'make a selection...', $this->text_domain );
   }
   if ( isset( $element['validation']['rules'] ) ) {
    foreach ( $element['validation']['rules'] as $key => $value ) {
     $rules['data-rule-' . $key] = $value;
    }
   }
   if ( in_array( $method, $has_options ) ) {
    $atts = array_merge( $atts, $rules );
    if ( isset( $element['default'] ) ) {
     $atts['value'] = $element['default'];
    }
    if ( isset( $element['validation']['required'] ) ) {
     $atts['required'] = 'required';
    }
    foreach ( $element['value'] as $key => $value ) {
     /**
      * append :pfbc to the key so that pfbc doesn't freak out
      * about numerically keyed arrays.
      */
     $options[$value . ':pfbc'] = $element['label'][$key];
    }
   } else {
    $options = array_merge( $options, $rules );
    if ( isset( $element['default'] ) ) {
     $options['value'] = $element['default'];
    }
    if ( isset( $element['validation']['required'] ) ) {
     $options['required'] = 'required';
    }
   }
   $form_output->addElement( new $method( stripslashes( $element['name'] ), 'question[' . $questions_count . ']', $options, $atts ) );
   $questions_count++;
  }
  $form_output->addElement( new Element_Hidden( 'answer_survey_nonce', $nonce ) );
  $form_output->addElement( new Element_Hidden( 'survey_id', '', array( 'value' => $args['survey_id'], ) ) );
  $form_output->addElement( new Element_Hidden( 'action', 'answer_survey' ) );
  $form_output->addElement( new Element_Hidden( 'auth_method', $args['auth_method'] ) );
  $form_output->addElement( new Element_Button( __( 'Submit Response', $this->text_domain ), 'submit', array( 'class' => 'button-primary', ) ) );
  return $form_output->render( true );
 }

 /**
  * registers necessary styles & scripts for later use
  * @since 1.0
  * @author Will the Web Mechanic <will@willthewebmechanic>
  * @link http://willthewebmechanic.com
  */
 public function register_scripts()
 {

  wp_register_script( 'jquery-validation-plugin', WWM_AWESOME_SURVEYS_URL . '/js/jquery.validate.min.js', array( 'jquery' ), '1.12.1pre' );
  wp_register_script( 'awesome-surveys-frontend', WWM_AWESOME_SURVEYS_URL .'/js/script.js', array( 'jquery', 'jquery-validation-plugin' ), '1.0', true );
  wp_register_style( 'awesome-surveys-frontend-styles', WWM_AWESOME_SURVEYS_URL . '/css/style.css', array(), '1.0', 'all' );
 }

 /**
  * Ajax handler to process the survey form
  * @since 1.0
  *
  */
 public function process_response()
 {

  if ( ! wp_verify_nonce( $_POST['answer_survey_nonce'], 'answer-survey' ) || is_null( $_POST['survey_id'] ) ) {
   exit;
  }
  $surveys = get_option( 'wwm_awesome_surveys', array() );
  $survey = $surveys['surveys'][$_POST['survey_id']];
  if ( empty( $survey ) ) {
   exit;
  }
  $num_responses = ( isset( $survey['num_responses'] ) ) ? absint( $survey['num_responses'] + 1 ) : 1;
  $survey['num_responses'] = $num_responses;
  $form = unserialize( $survey['form'] );
  $has_options = array( 'Element_Select', 'Element_Checkbox', 'Element_Radio' );
  foreach ( $survey['responses'] as $key => $value ) {
   if ( in_array( $form[$key]['type'], $has_options ) ) {
    $count = $value['answers'][$_POST['question'][$key]] + 1;
    $survey['responses'][$key]['answers'][$_POST['question'][$key]] = $count;
   } else {
    $survey['responses'][$key]['answers'][$key][] = $_POST['question'][$key];
   }
  }
  $survey = apply_filters( 'wwm_awesome_survey_response', $survey, $_POST['auth_method'] );
  $surveys['surveys'][$_POST['survey_id']] = $survey;
  $action_args = array(
   'survey_id' => $_POST['survey_id'],
   'survey' => $survey,
  );
  do_action( 'awesome_surveys_update_' . $_POST['auth_method'], $action_args );
  update_option( 'wwm_awesome_surveys', $surveys );
  exit;
 }

 /**
  * Handles the auth type 'login' to determine whether the
  * survey form should be output or not
  * @param  array $args an array of function arguments - most
  * notably ['survey_id']
  * @return bool       whether or not the user is authorized to take this survey.
  */
 public function awesome_surveys_auth_method_login( $args )
 {

  if ( ! is_user_logged_in() ) {
   add_filter( 'wwm_survey_no_auth_message', array( &$this, 'not_logged_in_message' ), 10, 1 );
   return false;
  }
  $surveys = get_option( 'wwm_awesome_surveys', array() );
  $survey = $surveys['surveys'][$args['survey_id']];
  if ( isset( $survey['respondents'] ) && is_array( $survey['respondents'] ) && in_array( get_current_user_id(), $survey['respondents'] ) ) {
   return false;
  }

  return true;
 }

 /**
  * Handles the auth type 'cookie', checks to see if the cookie
  * is set
  * @param  array $args an array of function arguments, most notably the survey id
  * @return bool       whether or not the user is authorized to take this survey.
  */
 public function awesome_surveys_auth_method_cookie( $args )
 {

  return ( ! isset( $_COOKIE['responded_to_survey_' . $args['survey_id']] ) );
 }

 /**
  * If the survey authentication method is 'cookie',
  * this method will be called by do_action( 'awesome_surveys_update_cookie' )
  * and will set a cookie indicating that the user has filled out this
  * survey ($args['survey_id']).
  * @param  array $args [description]
  * @since 1.0
  */
 public function awesome_surveys_update_cookie( $args )
 {

  $survey_id = $args['survey_id'];
  setcookie( 'responded_to_survey_' . $survey_id, 'true', time() + YEAR_IN_SECONDS, '/' );
 }

 /**
  * This filter is conditionally added if the auth method
  * is login and the user is not logged in.
  * @param  string $message a message to display to the user
  * @return string          the filtered message.
  */
 public function not_logged_in_message( $message )
 {

  return sprintf( '<p>%s</p>', __( 'You must be logged in to participate in this survey', $this->text_domain ) );
 }

 /**
  * This filter is applied if the auth type is 'login'. It adds the
  * current user id to the survey['respondents'] array so that
  * the auth method 'login' can check if the current user has already
  * filled out the survey.
  * @param  array $survey    an array of survey responses
  * @param  string $auth_type an authorization type
  * @return array  $survey the filtered array of survey responses.
  */
 public function wwm_awesome_survey_response_filter( $survey, $auth_type )
 {

  if ( 'login' == $auth_type ) {
   $survey['respondents'][] = get_current_user_id();
  }
  return $survey;
 }
}