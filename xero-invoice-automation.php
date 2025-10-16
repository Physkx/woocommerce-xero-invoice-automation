<?php

/**
 * Plugin Name: Woocommerce Xero Invoice Automation
 * Plugin URI: https://itarchitects.co.nz
 * Description: Automatically marks WooCommerce orders as completed when Xero invoices are paid
 * Version: 1.0
 * Author: Mark Longden
 * Author URI: https://itarchitects.co.nz
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Xero_Invoice_Webhook_Handler {

    private $webhook_key;
    private $option_name = 'xero_automation_settings';

    public function __construct() {
        // Add admin menu for settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));

        // Register webhook endpoint
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        // Hook into Xero invoice creation to store invoice number
        add_action('woocommerce_order_status_changed', array($this, 'store_invoice_number'), 20, 3);

        // Schedule Xero invoice checker
        add_action('wp', array($this, 'setup_schedule'));
        add_action('xero_check_paid_invoices', array($this, 'check_paid_invoices'));

        // Add cron interval
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Get the webhook signing key from settings
        $settings = $this->get_settings();
        $this->webhook_key = isset($settings['webhook_key']) ? $settings['webhook_key'] : '';
    }

    /**
     * Get plugin settings
     */
    private function get_settings() {
        $settings = get_option($this->option_name, array());
        return $settings;
    }

    /**
     * Save plugin settings
     */
    private function save_settings($settings) {
        update_option($this->option_name, $settings);
    }

    /**
     * Setup the scheduled event
     */
    public function setup_schedule() {
        $next_run = wp_next_scheduled('xero_check_paid_invoices');

        // If no schedule exists, create one
        if (!$next_run) {
            wp_schedule_event(time(), 'thirty_minutes', 'xero_check_paid_invoices');
        }
        // If the scheduled time is in the past (missed), reschedule it
        elseif ($next_run < time()) {
            wp_unschedule_event($next_run, 'xero_check_paid_invoices');
            wp_schedule_event(time(), 'thirty_minutes', 'xero_check_paid_invoices');
        }
    }

    /**
     * Add 30-minute cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['thirty_minutes'] = array(
            'interval' => 1800,
            'display'  => __('Every 30 Minutes')
        );
        return $schedules;
    }

    /**
     * Check Xero for paid invoices and update WooCommerce orders
     */
    public function check_paid_invoices() {
        $this->log_webhook_attempt('Starting scheduled Xero check', '', '', true, 'Checking invoices from last 90 days');

        // Get all pending payment orders from last 90 days
        $ninety_days_ago = date('Y-m-d', strtotime('-90 days'));

        $orders = wc_get_orders(array(
            'limit' => -1,
            'status' => array('pending', 'on-hold'),
            'date_created' => '>' . $ninety_days_ago,
            'meta_key' => '_xero_invoice_id',
            'meta_compare' => 'EXISTS',
        ));

        if (empty($orders)) {
            $this->log_webhook_attempt('No pending orders found', '', '', true, 'No orders to check');
            return;
        }

        $this->log_webhook_attempt('Found orders to check', '', '', true, 'Checking ' . count($orders) . ' orders');

        // Check each order's invoice status in Xero
        foreach ($orders as $order) {
            $this->check_order_invoice_status($order);
        }

        $this->log_webhook_attempt('Completed scheduled Xero check', '', '', true, 'Finished checking ' . count($orders) . ' orders');
    }

    /**
     * Check a single order's invoice status in Xero
     */
    private function check_order_invoice_status($order) {
        $order_id = $order->get_id();
        $invoice_id = $order->get_meta('_xero_invoice_id', true);

        if (!$invoice_id) {
            return;
        }

        try {
            // Get invoice status from Xero
            $invoice_status = $this->get_xero_invoice_status($invoice_id);

            if (!$invoice_status) {
                return;
            }

            // Check if invoice is paid
            if ($invoice_status === 'PAID') {
                $invoice_number = $order->get_meta('_xero_invoice_number', true);

                if (!$invoice_number) {
                    // Generate it if missing
                    $order_number = ltrim($order->get_order_number(), '#');
                    $invoice_number = 'WebSales' . $order_number;
                }

                // Mark order as completed
                $order->update_status('completed', 'Order marked as completed - Xero invoice ' . $invoice_number . ' was paid.');

                $this->log_webhook_attempt('Order completed via scheduled check', $invoice_number, $order_id, true, 'Invoice was paid in Xero');
            }
        } catch (Exception $e) {
            $this->log_webhook_attempt('Error checking invoice', '', $order_id, false, $e->getMessage());
        }
    }

    /**
     * Get invoice status from Xero API
     */
    private function get_xero_invoice_status($invoice_id) {
        try {
            // Get access token
            $access_token = $this->get_valid_access_token();

            if (!$access_token) {
                $this->log_webhook_attempt('No valid access token', '', '', false, 'Please connect to Xero in plugin settings');
                return false;
            }

            $settings = $this->get_settings();
            $tenant_id = isset($settings['tenant_id']) ? $settings['tenant_id'] : '';

            if (empty($tenant_id)) {
                $this->log_webhook_attempt('No tenant ID', '', '', false, 'Please reconnect to Xero');
                return false;
            }

            // Make API request to get invoice
            $url = 'https://api.xero.com/api.xro/2.0/Invoices/' . $invoice_id;

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'xero-tenant-id' => $tenant_id,
                    'Accept' => 'application/json',
                ),
                'timeout' => 30,
            ));

            if (is_wp_error($response)) {
                $this->log_webhook_attempt('Xero API request failed', '', '', false, $response->get_error_message());
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status_code !== 200) {
                $error_detail = 'HTTP ' . $status_code;
                if ($status_code === 401) {
                    $error_detail .= ' (Unauthorized - reconnecting to Xero)';
                    // Try to refresh token
                    $this->refresh_access_token();
                }
                $error_data = json_decode($body, true);
                if (isset($error_data['Detail'])) {
                    $error_detail .= ': ' . $error_data['Detail'];
                }

                $this->log_webhook_attempt('Xero API error', '', '', false, $error_detail);
                return false;
            }

            $data = json_decode($body, true);

            if (isset($data['Invoices'][0]['Status'])) {
                return $data['Invoices'][0]['Status'];
            }

            return false;
        } catch (Exception $e) {
            $this->log_webhook_attempt('Xero API exception', '', '', false, $e->getMessage());
            return false;
        }
    }

    /**
     * Get a valid access token (refreshes if needed)
     */
    private function get_valid_access_token() {
        $settings = $this->get_settings();

        if (empty($settings['access_token'])) {
            return false;
        }

        // Check if token is expired
        if (isset($settings['token_expires']) && $settings['token_expires'] < time()) {
            // Token expired, refresh it
            return $this->refresh_access_token();
        }

        return $settings['access_token'];
    }

    /**
     * Refresh the access token using refresh token
     */
    private function refresh_access_token() {
        $settings = $this->get_settings();

        if (empty($settings['refresh_token']) || empty($settings['client_id']) || empty($settings['client_secret'])) {
            return false;
        }

        $response = wp_remote_post('https://identity.xero.com/connect/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type' => 'refresh_token',
                'refresh_token' => $settings['refresh_token'],
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            $this->log_webhook_attempt('Token refresh failed', '', '', false, $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $token_data = json_decode($body, true);

        if (isset($token_data['access_token'])) {
            // Update stored tokens
            $settings['access_token'] = $token_data['access_token'];
            $settings['token_expires'] = time() + $token_data['expires_in'];

            if (isset($token_data['refresh_token'])) {
                $settings['refresh_token'] = $token_data['refresh_token'];
            }

            $this->save_settings($settings);
            $this->log_webhook_attempt('Token refreshed successfully', '', '', true, 'New token expires in ' . round($token_data['expires_in'] / 60) . ' minutes');

            return $token_data['access_token'];
        }

        $this->log_webhook_attempt('Token refresh failed', '', '', false, 'Invalid response from Xero');
        return false;
    }

    /**
     * Store the invoice number when an invoice is created
     */
    public function store_invoice_number($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $invoice_id = $order->get_meta('_xero_invoice_id', true);
        $invoice_number = $order->get_meta('_xero_invoice_number', true);

        if ($invoice_id && !$invoice_number) {
            $order_number = ltrim($order->get_order_number(), '#');
            $xero_invoice_number = 'WebSales' . $order_number;

            $order->update_meta_data('_xero_invoice_number', $xero_invoice_number);
            $order->save_meta_data();

            $this->log_webhook_attempt('Invoice number stored', $xero_invoice_number, $order_id, true, 'Stored for future processing');
        }
    }

    /**
     * Handle OAuth callback from Xero
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'xero-webhook-oauth-callback') {
            return;
        }

        if (!isset($_GET['code'])) {
            wp_die('OAuth error: No authorization code received');
        }

        $code = sanitize_text_field($_GET['code']);
        $settings = $this->get_settings();

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            wp_die('OAuth error: Client ID and Secret not configured');
        }

        // Exchange code for tokens
        $redirect_uri = admin_url('admin.php?page=xero-webhook-oauth-callback');

        $response = wp_remote_post('https://identity.xero.com/connect/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect_uri,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            wp_die('OAuth error: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $token_data = json_decode($body, true);

        if (!isset($token_data['access_token'])) {
            wp_die('OAuth error: Failed to get access token. Response: ' . $body);
        }

        // Get tenant/organization info
        $connections_response = wp_remote_get('https://api.xero.com/connections', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($connections_response)) {
            wp_die('OAuth error: Failed to get tenant info');
        }

        $connections_body = wp_remote_retrieve_body($connections_response);
        $connections = json_decode($connections_body, true);

        if (empty($connections[0]['tenantId'])) {
            wp_die('OAuth error: No tenant ID found');
        }

        // Save tokens and tenant info
        $settings['access_token'] = $token_data['access_token'];
        $settings['refresh_token'] = $token_data['refresh_token'];
        $settings['token_expires'] = time() + $token_data['expires_in'];
        $settings['tenant_id'] = $connections[0]['tenantId'];
        $settings['tenant_name'] = isset($connections[0]['tenantName']) ? $connections[0]['tenantName'] : '';

        $this->save_settings($settings);

        $this->log_webhook_attempt('OAuth connection successful', '', '', true, 'Connected to ' . $settings['tenant_name']);

        // Redirect back to settings page
        wp_redirect(admin_url('options-general.php?page=xero-webhook-settings&connected=1'));
        exit;
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_options_page(
            'Xero Webhook Settings',
            'Xero Automation',
            'manage_options',
            'xero-webhook-settings',
            array($this, 'settings_page')
        );

        // Hidden page for OAuth callback
        add_submenu_page(
            null,
            'Xero OAuth Callback',
            'Xero OAuth Callback',
            'manage_options',
            'xero-webhook-oauth-callback',
            '__return_empty_string'
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('xero_webhook_settings', $this->option_name);
    }

    /**
     * Settings page HTML
     */
    public function settings_page() {
        $settings = $this->get_settings();
        $is_connected = !empty($settings['access_token']) && !empty($settings['tenant_id']);

        // Check token status
        $token_status = 'Not connected';
        $token_class = 'notice-warning';

        if ($is_connected) {
            if (isset($settings['token_expires'])) {
                $expires_in = $settings['token_expires'] - time();
                if ($expires_in > 0) {
                    $token_status = 'Connected to ' . (isset($settings['tenant_name']) ? $settings['tenant_name'] : 'Xero');
                    $token_class = 'notice-success';
                } else {
                    $token_status = 'Token expired - will auto-refresh on next check';
                    $token_class = 'notice-info';
                }
            }
        }

        // Get next scheduled run
        $next_run = wp_next_scheduled('xero_check_paid_invoices');
        if ($next_run) {
            // Convert to WordPress timezone using wp_date
            $next_run_formatted = wp_date('Y-m-d H:i:s', $next_run);
        } else {
            $next_run_formatted = 'Not scheduled';
        }

        // Handle form submissions
        if (isset($_POST['save_credentials']) && check_admin_referer('xero_save_credentials', 'xero_credentials_nonce')) {
            $settings['client_id'] = sanitize_text_field($_POST['client_id']);
            $settings['client_secret'] = sanitize_text_field($_POST['client_secret']);
            $settings['webhook_key'] = sanitize_text_field($_POST['webhook_key']);
            $this->save_settings($settings);
            $this->webhook_key = $settings['webhook_key'];
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        if (isset($_POST['disconnect']) && check_admin_referer('xero_disconnect', 'xero_disconnect_nonce')) {
            $settings['access_token'] = '';
            $settings['refresh_token'] = '';
            $settings['token_expires'] = 0;
            $settings['tenant_id'] = '';
            $settings['tenant_name'] = '';
            $this->save_settings($settings);
            $is_connected = false;
            echo '<div class="notice notice-success"><p>Disconnected from Xero</p></div>';
        }

        if (isset($_GET['connected']) && $_GET['connected'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Successfully connected to Xero!</strong> The automatic checker is now active.</p></div>';
        }

?>
        <div class="wrap">
            <h1>Xero Invoice Automation</h1>

            <div class="notice inline <?php echo $token_class; ?>">
                <p><strong>Status:</strong> <?php echo esc_html($token_status); ?></p>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="#connection" class="nav-tab nav-tab-active">Connection</a>
                <a href="#scheduler" class="nav-tab">Scheduler</a>
                <a href="#logs" class="nav-tab">Activity Logs</a>
            </h2>

            <div id="connection" class="tab-content">
                <h2>Xero Connection Setup</h2>

                <?php if (!$is_connected): ?>
                    <div class="card" style="max-width: 800px;">
                        <h3>Step 1: Create a Xero App</h3>
                        <ol>
                            <li>Go to <a href="https://developer.xero.com/app/manage" target="_blank">Xero Developer Portal</a></li>
                            <li>Click "New app" → Select "Web app"</li>
                            <li>Fill in:
                                <ul>
                                    <li><strong>App name:</strong> WooCommerce Order Automation</li>
                                    <li><strong>Company URL:</strong> <?php echo site_url(); ?></li>
                                    <li><strong>OAuth 2.0 redirect URI:</strong><br>
                                        <code style="background: #f0f0f0; padding: 5px; display: inline-block; margin-top: 5px;">
                                            <?php echo admin_url('admin.php?page=xero-webhook-oauth-callback'); ?>
                                        </code>
                                    </li>
                                </ul>
                            </li>
                            <li>Click "Create app"</li>
                            <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong></li>
                        </ol>

                        <h3>Step 2: Enter Your Credentials</h3>
                        <form method="post" action="">
                            <?php wp_nonce_field('xero_save_credentials', 'xero_credentials_nonce'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Client ID</th>
                                    <td>
                                        <input type="text" name="client_id" value="<?php echo esc_attr(isset($settings['client_id']) ? $settings['client_id'] : ''); ?>" class="regular-text" required />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Client Secret</th>
                                    <td>
                                        <input type="text" name="client_secret" value="<?php echo esc_attr(isset($settings['client_secret']) ? $settings['client_secret'] : ''); ?>" class="regular-text" required />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Webhook Signing Key</th>
                                    <td>
                                        <input type="text" name="webhook_key" value="<?php echo esc_attr(isset($settings['webhook_key']) ? $settings['webhook_key'] : ''); ?>" class="regular-text" />
                                        <p class="description">Optional: If setting up webhooks in Xero, enter the signing key here</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" name="save_credentials" class="button button-primary" value="Save Credentials" />
                            </p>
                        </form>

                        <?php if (!empty($settings['client_id']) && !empty($settings['client_secret'])): ?>
                            <h3>Step 3: Connect to Xero</h3>
                            <?php
                            $redirect_uri = admin_url('admin.php?page=xero-webhook-oauth-callback');
                            $auth_url = 'https://login.xero.com/identity/connect/authorize?' . http_build_query(array(
                                'response_type' => 'code',
                                'client_id' => $settings['client_id'],
                                'redirect_uri' => $redirect_uri,
                                'scope' => 'offline_access accounting.transactions.read accounting.settings.read',
                                'state' => wp_create_nonce('xero_oauth_state'),
                            ));
                            ?>
                            <p>
                                <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-hero">Connect to Xero</a>
                            </p>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="card" style="max-width: 600px;">
                        <h3>✓ Connected to Xero</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Organization</th>
                                <td><?php echo esc_html($settings['tenant_name']); ?></td>
                            </tr>
                            <tr>
                                <th scope="row">Tenant ID</th>
                                <td><code><?php echo esc_html($settings['tenant_id']); ?></code></td>
                            </tr>
                            <tr>
                                <th scope="row">Token Status</th>
                                <td>
                                    <?php
                                    $expires_in = $settings['token_expires'] - time();
                                    if ($expires_in > 0) {
                                        echo 'Valid (auto-refreshes in ' . round($expires_in / 60) . ' minutes)';
                                    } else {
                                        echo 'Will refresh automatically on next API call';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>

                        <form method="post" action="">
                            <?php wp_nonce_field('xero_disconnect', 'xero_disconnect_nonce'); ?>
                            <p>
                                <input type="submit" name="disconnect" class="button" value="Disconnect from Xero" onclick="return confirm('Are you sure you want to disconnect from Xero?');" />
                            </p>
                        </form>

                        <hr>

                        <h3>Update Credentials (Optional)</h3>
                        <form method="post" action="">
                            <?php wp_nonce_field('xero_save_credentials', 'xero_credentials_nonce'); ?>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Webhook Signing Key</th>
                                    <td>
                                        <input type="text" name="webhook_key" value="<?php echo esc_attr(isset($settings['webhook_key']) ? $settings['webhook_key'] : ''); ?>" class="regular-text" />
                                        <input type="hidden" name="client_id" value="<?php echo esc_attr($settings['client_id']); ?>" />
                                        <input type="hidden" name="client_secret" value="<?php echo esc_attr($settings['client_secret']); ?>" />
                                        <p class="description">For webhook functionality (optional)</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" name="save_credentials" class="button" value="Update Webhook Key" />
                            </p>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <div id="scheduler" class="tab-content" style="display:none;">
                <h2>Automatic Scheduler</h2>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Status</th>
                        <td>
                            <span style="color: green; font-weight: bold;">✓ Active</span>
                            <p class="description">Automatically checks Xero every 30 minutes for paid invoices</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Next Check</th>
                        <td>
                            <code><?php echo esc_html($next_run_formatted); ?></code>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">What it checks</th>
                        <td>
                            • Pending payment orders from last 90 days<br>
                            • Orders with Xero invoices only<br>
                            • Runs every 30 minutes automatically
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Manual Check</th>
                        <td>
                            <form method="post" action="">
                                <?php wp_nonce_field('xero_manual_check', 'xero_manual_nonce'); ?>
                                <input type="submit" name="manual_check" class="button button-secondary" value="Check Xero Now" />
                                <p class="description">Manually trigger a check right now</p>
                            </form>
                        </td>
                    </tr>
                </table>

                <?php
                if (isset($_POST['manual_check']) && check_admin_referer('xero_manual_check', 'xero_manual_nonce')) {
                    $this->check_paid_invoices();

                    // Refresh the schedule display
                    $next_run = wp_next_scheduled('xero_check_paid_invoices');
                    if ($next_run) {
                        $next_run_formatted = wp_date('Y-m-d H:i:s', $next_run);
                    }

                    echo '<div class="notice notice-success"><p>Manual check completed! Next automatic check: ' . esc_html($next_run_formatted) . '</p></div>';
                }
                ?>

                <h3>Test Manual Processing</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('xero_test_processing', 'xero_test_nonce'); ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Invoice Number</th>
                            <td>
                                <input type="text" name="test_invoice_number" value="WebSales158270" class="regular-text" />
                                <p class="description">Enter a test invoice number (e.g., WebSales158270)</p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="test_processing" class="button button-secondary" value="Test Order Completion" />
                </form>

                <?php
                if (isset($_POST['test_processing']) && check_admin_referer('xero_test_processing', 'xero_test_nonce')) {
                    $test_invoice = sanitize_text_field($_POST['test_invoice_number']);
                    $result = $this->process_paid_invoice($test_invoice);
                    if ($result) {
                        echo '<div class="notice notice-success"><p>Test successful!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Test failed. Check the logs below.</p></div>';
                    }
                }
                ?>
            </div>

            <div id="logs" class="tab-content" style="display:none;">
                <h2>Activity Logs</h2>
                <?php $this->display_logs(); ?>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    $('.nav-tab').on('click', function(e) {
                        e.preventDefault();
                        $('.nav-tab').removeClass('nav-tab-active');
                        $(this).addClass('nav-tab-active');
                        $('.tab-content').hide();
                        $($(this).attr('href')).show();
                    });
                });
            </script>
        </div>
<?php
    }

    /**
     * Display webhook logs
     */
    private function display_logs() {
        $logs = get_option('xero_webhook_logs', array());

        if (empty($logs)) {
            echo '<p>No activity logged yet.</p>';
            return;
        }

        // Show last 100 logs
        $logs = array_slice(array_reverse($logs), 0, 100);

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Date/Time</th><th>Invoice Number</th><th>Order ID</th><th>Status</th><th>Message</th></tr></thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            $status_class = $log['success'] ? 'success' : 'error';
            echo '<tr>';
            echo '<td>' . esc_html($log['timestamp']) . '</td>';
            echo '<td>' . esc_html($log['invoice_number']) . '</td>';
            echo '<td>' . esc_html($log['order_id']) . '</td>';
            echo '<td><span class="' . $status_class . '">' . ($log['success'] ? '✓ Success' : '✗ Failed') . '</span></td>';
            echo '<td>' . esc_html($log['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<style>
            .success { color: #46b450; font-weight: bold; }
            .error { color: #dc3232; font-weight: bold; }
        </style>';
    }

    /**
     * Register REST API endpoint for webhook (kept for future use)
     */
    public function register_webhook_endpoint() {
        register_rest_route('xero-webhook/v1', '/invoice-paid', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));

        // Whitelist our endpoint in the security plugin's REST API filter
        add_filter('rest_pre_dispatch', array($this, 'allow_xero_webhook'), 5, 3);
    }

    /**
     * Allow Xero webhook endpoint to bypass security restrictions
     */
    public function allow_xero_webhook($result, $wp_rest_server, $request) {
        $route = $request->get_route();

        if (strpos($route, '/xero-webhook/v1/invoice-paid') === 0) {
            remove_filter('rest_pre_dispatch', array($this, 'allow_xero_webhook'), 5);
            return $result;
        }

        return $result;
    }

    /**
     * Handle incoming webhook from Xero (kept for future use)
     */
    public function handle_webhook($request) {
        $signature = $request->get_header('x-xero-signature');
        $body = $request->get_body();

        $this->log_webhook_attempt('Raw webhook received', '', '', true, 'Signature present: ' . (!empty($signature) ? 'YES' : 'NO') . ' | Body length: ' . strlen($body));

        $payload = json_decode($body, true);

        $is_intent_to_receive = isset($payload['firstEventSequence']) && isset($payload['lastEventSequence']);

        if ($is_intent_to_receive) {
            $signature_valid = $this->verify_signature($signature, $body);

            if (!$signature_valid) {
                $this->log_webhook_attempt('Intent verification FAILED', '', '', false, 'Bad signature test - returning 401');

                status_header(401);
                header('Content-Type: application/json');
                nocache_headers();
                echo json_encode(array('error' => 'Unauthorized'));
                exit;
            }

            $this->log_webhook_attempt('Intent verification SUCCESS', '', '', true, 'Good signature - returning 200');
            return new WP_REST_Response(array('success' => true), 200);
        }

        $signature_valid = $this->verify_signature($signature, $body);

        $this->log_webhook_attempt('Event signature check', '', '', $signature_valid, 'Valid: ' . ($signature_valid ? 'YES' : 'NO'));

        if (!$signature_valid) {
            status_header(401);
            header('Content-Type: application/json');
            nocache_headers();
            echo json_encode(array('error' => 'Unauthorized'));
            exit;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_webhook_attempt('JSON parsing failed', '', '', false, 'Invalid JSON');
            return new WP_Error('invalid_json', 'Invalid JSON payload', array('status' => 400));
        }

        $this->log_webhook_attempt('Webhook received', '', '', true, 'Processing events: ' . count($payload['events'] ?? []));

        if (isset($payload['events']) && is_array($payload['events'])) {
            foreach ($payload['events'] as $event) {
                $this->process_invoice_event($event);
            }
        }

        return new WP_REST_Response(array('success' => true), 200);
    }

    /**
     * Verify webhook signature
     */
    private function verify_signature($signature, $body) {
        if (empty($this->webhook_key)) {
            return false;
        }

        $expected_signature = base64_encode(hash_hmac('sha256', $body, $this->webhook_key, true));

        return hash_equals($expected_signature, $signature);
    }

    /**
     * Process individual invoice event from webhook
     */
    private function process_invoice_event($event) {
        if (!isset($event['eventType']) || $event['eventType'] !== 'UPDATE') {
            return;
        }

        if (!isset($event['eventCategory']) || $event['eventCategory'] !== 'INVOICE') {
            return;
        }

        $invoice_id = isset($event['resourceId']) ? $event['resourceId'] : '';

        if (empty($invoice_id)) {
            $this->log_webhook_attempt('No invoice ID in webhook', '', '', false, 'Missing resourceId');
            return;
        }

        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_key' => '_xero_invoice_id',
            'meta_value' => $invoice_id,
        ));

        if (empty($orders)) {
            $this->log_webhook_attempt('Order not found for invoice ID', '', '', false, 'Invoice ID: ' . $invoice_id);
            return;
        }

        $order = $orders[0];
        $invoice_number = $order->get_meta('_xero_invoice_number', true);

        if (!$invoice_number) {
            $order_number = ltrim($order->get_order_number(), '#');
            $invoice_number = 'WebSales' . $order_number;

            $order->update_meta_data('_xero_invoice_number', $invoice_number);
            $order->save_meta_data();
        }

        $this->process_paid_invoice($invoice_number);
    }

    /**
     * Process a paid invoice and update WooCommerce order
     */
    private function process_paid_invoice($invoice_number) {
        if (!preg_match('/^WebSales(\d+)$/', $invoice_number, $matches)) {
            $this->log_webhook_attempt('Invalid invoice format', $invoice_number, '', false, 'Not a WebSales invoice - skipping');
            return false;
        }

        $order_id = intval($matches[1]);

        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log_webhook_attempt('Order not found', $invoice_number, $order_id, false, 'Invalid order ID');
            return false;
        }

        $xero_invoice_id = $order->get_meta('_xero_invoice_id', true);
        if (!$xero_invoice_id) {
            $this->log_webhook_attempt('Order has no Xero invoice', $invoice_number, $order_id, false, 'Missing _xero_invoice_id meta');
            return false;
        }

        if ($order->get_status() === 'completed') {
            $this->log_webhook_attempt('Order already completed', $invoice_number, $order_id, true, 'No action needed');
            return true;
        }

        $order->update_status('completed', 'Order marked as completed - Xero invoice ' . $invoice_number . ' was paid.');

        $this->log_webhook_attempt('Order completed successfully', $invoice_number, $order_id, true, 'Order status updated to completed');

        return true;
    }

    /**
     * Log webhook attempts
     */
    private function log_webhook_attempt($message, $invoice_number, $order_id, $success, $details = '') {
        $logs = get_option('xero_webhook_logs', array());

        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'invoice_number' => $invoice_number,
            'order_id' => $order_id,
            'success' => $success,
            'message' => $message . ($details ? ' - ' . $details : ''),
        );

        // Keep only last 200 logs
        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }

        update_option('xero_webhook_logs', $logs);
    }
}

// Initialize the plugin
new Xero_Invoice_Webhook_Handler();

// Clean up scheduled event on plugin deactivation
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('xero_check_paid_invoices');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'xero_check_paid_invoices');
    }
});
