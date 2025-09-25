<?php
/**
 * Festival Wire AJAX Handlers
 *
 * Handles AJAX requests for Festival Wire functionality including:
 * - Load more posts pagination
 * - Community tip submission with rate limiting
 * - Cloudflare Turnstile verification
 * - Sendy email list integration
 *
 * @package ExtraChillNewsWire
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler for load more Festival Wire posts
 *
 * Processes AJAX pagination requests on Festival Wire archive pages.
 * Validates nonce, sanitizes query parameters, and returns HTML content.
 *
 * @since 1.0.0
 */
function festival_wire_load_more_handler() {
    // Security verification
    check_ajax_referer( 'festival_wire_load_more_nonce', 'nonce' );

    // Extract and validate query parameters
    $query_vars = isset( $_POST['query_vars'] ) ? json_decode( stripslashes( $_POST['query_vars'] ), true ) : array();
    $current_page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;

    // Validate and prepare query parameters
    if ( ! is_array( $query_vars ) ) {
        $query_vars = array();
    }

    $query_vars['paged'] = $current_page;
    $query_vars['post_status'] = 'publish';
    $query_vars['post_type'] = 'festival_wire';

    // Clean query variables to prevent conflicts with WP_Query
    unset($query_vars['error']);
    unset($query_vars['m']);
    unset($query_vars['p']);
    unset($query_vars['post_parent']);
    unset($query_vars['subpost']);
    unset($query_vars['subpost_id']);
    unset($query_vars['attachment']);
    unset($query_vars['attachment_id']);
    unset($query_vars['name']);
    unset($query_vars['pagename']);
    unset($query_vars['page_id']);
    unset($query_vars['second']);
    unset($query_vars['minute']);
    unset($query_vars['hour']);
    unset($query_vars['day']);
    unset($query_vars['monthnum']);
    unset($query_vars['year']);
    unset($query_vars['w']);
    // Preserve taxonomy queries (category_name, tag, etc.)
    unset($query_vars['author_name']);
    unset($query_vars['feed']);
    unset($query_vars['tb']);
    unset($query_vars['pb']);
    unset($query_vars['meta_key']);
    unset($query_vars['meta_value']);
    unset($query_vars['preview']);
    unset($query_vars['s']);
    unset($query_vars['sentence']);
    unset($query_vars['title']);
    unset($query_vars['fields']);
    unset($query_vars['menu_order']);
    unset($query_vars['embed']);
    unset($query_vars['ignore_sticky_posts']);
    unset($query_vars['lazy_load_term_meta']);

    // Execute query and generate output
    $posts_query = new WP_Query( $query_vars );

    if ( $posts_query->have_posts() ) :
        ob_start();

        while ( $posts_query->have_posts() ) : $posts_query->the_post();
            require plugin_dir_path(__FILE__) . '../templates/content-card.php';
        endwhile;

        $output = ob_get_clean();
        wp_reset_postdata();
        echo $output;

    else:
        // No posts found - JavaScript handles empty response
    endif;

    wp_die();
}
add_action( 'wp_ajax_load_more_festival_wire', 'festival_wire_load_more_handler' ); // For logged-in users
add_action( 'wp_ajax_nopriv_load_more_festival_wire', 'festival_wire_load_more_handler' ); // For non-logged-in users


/**
 * Process Festival Wire tip form submission
 *
 * Handles AJAX tip submissions with comprehensive validation:
 * - Nonce and rate limiting verification
 * - Community member detection via session cookie
 * - Cloudflare Turnstile anti-spam verification
 * - Email validation and Sendy list subscription
 *
 * @since 1.0.0
 */
function process_festival_wire_tip_submission() {
	// Security and rate limiting verification
	if ( ! check_ajax_referer( 'festival_wire_tip_nonce', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Security check failed.' ) );
	}

	$user_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
	if ( ! empty( $user_ip ) && is_rate_limited( $user_ip ) ) {
		wp_send_json_error( array( 'message' => 'Please wait before submitting another tip.' ) );
	}
	
	// Community member detection via WordPress native authentication
	$is_community_member = is_user_logged_in();
	$user_details = null;
	if ( $is_community_member ) {
		$user = wp_get_current_user();
		$user_details = array(
			'username' => $user->user_nicename,
			'email' => $user->user_email,
			'userID' => $user->ID,
		);
	}
	
	// Input validation and sanitization
	$content = isset( $_POST['content'] ) ? sanitize_textarea_field( $_POST['content'] ) : '';
	$email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
	$turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( $_POST['cf-turnstile-response'] ) : '';
	$honeypot = isset( $_POST['website'] ) ? sanitize_text_field( $_POST['website'] ) : '';

	// Anti-spam honeypot check
	if ( ! empty( $honeypot ) ) {
		wp_send_json_error( array( 'message' => 'Spam detected.' ) );
	}

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => 'Please enter your tip.' ) );
	}
	
	// Email requirement for non-community members
	if ( ! $is_community_member ) {
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => 'Email address is required.' ) );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ) );
		}
	}
	
	// Content length validation
	if ( strlen( $content ) > 1000 ) {
		wp_send_json_error( array( 'message' => 'Your tip is too long. Please keep it under 1000 characters.' ) );
	}
	
	if ( strlen( $content ) < 10 ) {
		wp_send_json_error( array( 'message' => 'Please provide a more detailed tip (at least 10 characters).' ) );
	}

	// Cloudflare Turnstile anti-spam verification
	$turnstile_secret_key = get_option( 'ec_turnstile_secret_key' );
	if ( ! empty( $turnstile_secret_key ) ) {
		$verify_result = verify_turnstile_response( $turnstile_response, $turnstile_secret_key );

		if ( ! $verify_result['success'] ) {
			wp_send_json_error( array( 'message' => 'Turnstile verification failed. Please try again.' ) );
		}
	}

	// Sendy newsletter subscription for non-community members
	if ( ! $is_community_member && ! empty( $email ) ) {
		$sendy_result = add_tip_email_to_sendy( $email );
		if ( ! $sendy_result ) {
			error_log( 'Festival tip Sendy subscription failed for email: ' . $email );
		}
	}

	// Email notification to admin
	$to = get_option( 'admin_email' );
	$subject = 'New Festival Wire Tip Submission';

	$message = "A new festival tip has been submitted:\n\n";
	$message .= "Tip: " . $content . "\n\n";
	$message .= "User Type: " . ( $is_community_member ? 'Community Member (' . $user_details['username'] . ')' : 'Guest' ) . "\n";
	if ( ! $is_community_member && ! empty( $email ) ) {
		$message .= "Email: " . $email . "\n";
	}
	$message .= "IP Address: " . $user_ip . "\n";
	$message .= "Submitted on: " . current_time( 'mysql' ) . "\n";
	
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	
	$email_sent = wp_mail( $to, $subject, $message, $headers );
	
	// Handle submission result
	if ( $email_sent ) {
		if ( ! empty( $user_ip ) ) {
			set_rate_limit( $user_ip );
		}
		$success_message = $is_community_member
			? 'Thank you for your tip! We will review it soon.'
			: 'Thank you for your tip! We will review it soon and have added you to our festival updates.';
		wp_send_json_success( array( 'message' => $success_message ) );
	} else {
		wp_send_json_error( array( 'message' => 'There was an error sending your tip. Please try again later.' ) );
	}
}
add_action( 'wp_ajax_festival_wire_tip_submission', 'process_festival_wire_tip_submission' );
add_action( 'wp_ajax_nopriv_festival_wire_tip_submission', 'process_festival_wire_tip_submission' );

/**
 * Verify Cloudflare Turnstile anti-spam response
 *
 * Validates Turnstile token with Cloudflare API for spam protection.
 * Includes comprehensive error handling and logging.
 *
 * @since 1.0.0
 * @param string $turnstile_response The turnstile response token
 * @param string $secret_key The secret key for Turnstile
 * @return array Verification result with success status
 */
function verify_turnstile_response( $turnstile_response, $secret_key ) {
	$verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	$args = array(
		'body' => array(
			'secret' => $secret_key,
			'response' => $turnstile_response,
			'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
		),
        'timeout' => 15,
	);

	$response = wp_remote_post( $verify_url, $args );

	// Handle connection errors
	if ( is_wp_error( $response ) ) {
        error_log('Turnstile Verification Error: ' . $response->get_error_message());
		return array( 'success' => false, 'error' => 'Connection error: ' . $response->get_error_message() );
	}

	// Validate HTTP response
	$response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code !== 200 ) {
        error_log('Turnstile Verification HTTP Error: Code ' . $response_code . ' Body: ' . wp_remote_retrieve_body($response));
        return array( 'success' => false, 'error' => 'HTTP error: ' . $response_code );
    }

	// Parse and validate JSON response
	$response_body = wp_remote_retrieve_body( $response );
	$result = json_decode( $response_body, true );

    if ( $result === null ) {
        error_log('Turnstile Verification JSON Decode Error: Body - ' . $response_body);
        return array( 'success' => false, 'error' => 'Invalid response format' );
    }

    // Log verification failures and validate response format
    if ( isset( $result['success'] ) && ! $result['success'] && isset( $result['error-codes'] ) ) {
         error_log('Turnstile Verification Failed: ' . implode(', ', $result['error-codes']));
    } elseif ( ! isset( $result['success'] ) ) {
         error_log('Turnstile Verification Unexpected Response: ' . $response_body);
         return array( 'success' => false, 'error' => 'Unexpected response format' );
    }


	return $result;
}

/**
 * Check IP address rate limiting for tip submissions
 *
 * Prevents spam by limiting submission frequency per IP address.
 * Uses WordPress transients for temporary storage.
 *
 * @since 1.0.0
 * @param string $ip The IP address to check
 * @return bool True if rate limited, false otherwise
 */
function is_rate_limited( $ip ) {
	$transient_key = 'festival_tip_rate_limit_' . md5( $ip );
	$last_submission = get_transient( $transient_key );

	return $last_submission !== false;
}

/**
 * Set rate limit for IP address after successful submission
 *
 * Creates temporary block for IP address to prevent rapid submissions.
 * Rate limit duration is 5 minutes (300 seconds).
 *
 * @since 1.0.0
 * @param string $ip The IP address to rate limit
 */
function set_rate_limit( $ip ) {
	$transient_key = 'festival_tip_rate_limit_' . md5( $ip );
	set_transient( $transient_key, time(), 300 ); // 5 minutes
}

/**
 * Add tip submitter email to Sendy newsletter list
 *
 * Subscribes tip submitters to festival updates newsletter.
 * Includes comprehensive error handling and validation.
 *
 * @since 1.0.0
 * @param string $email The email address to subscribe
 * @return bool True on success, false on failure
 */
function add_tip_email_to_sendy( $email ) {
	if ( empty( $email ) || ! is_email( $email ) ) {
		return false;
	}

	// Sendy API configuration
	$sendy_url = 'https://mail.extrachill.com/sendy';
	$list_id = '6O9Io8G6fbhBHRhPeiHZ763A';
	$api_key = 'z7RZLH84oEKNzMvFZhdt';

	$args = array(
		'body' => array(
			'email' => $email,
			'list' => $list_id,
			'api_key' => $api_key,
			'boolean' => 'true'
		),
		'timeout' => 15,
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		)
	);

	$response = wp_remote_post( $sendy_url . '/subscribe', $args );

	// Handle connection errors
	if ( is_wp_error( $response ) ) {
		error_log( 'Sendy API error: ' . $response->get_error_message() );
		return false;
	}

	// Validate API response
	$response_code = wp_remote_retrieve_response_code( $response );
	$response_body = wp_remote_retrieve_body( $response );

	if ( $response_code !== 200 ) {
		error_log( 'Sendy API HTTP error: ' . $response_code . ' - ' . $response_body );
		return false;
	}

	// Sendy returns '1' for successful subscription
	if ( $response_body === '1' ) {
		return true;
	} else {
		error_log( 'Sendy API response error: ' . $response_body );
		return false;
	}
} 