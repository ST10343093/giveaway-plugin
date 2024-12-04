<?php
/*
Plugin Name: PS5 Giveaway Integration
Description: Handles PS5 giveaway entries and WooCommerce order validation
Version: 1.2
*/

class PS5_Giveaway_Integration {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_giveaway_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_giveaway_scripts']);
        
        // Admin menu and page
        add_action('admin_menu', [$this, 'add_giveaway_admin_menu']);
        
        // Add export functionality
        add_action('admin_init', [$this, 'export_giveaway_entries']);
    }

    public function enqueue_giveaway_scripts() {
        wp_enqueue_script('wp-api-nonce', '', [], false, true);
        wp_localize_script('wp-api-nonce', 'wpApiSettings', [
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }

    public function register_giveaway_routes() {
        register_rest_route('ps5-giveaway/v1', '/validate-order', [
            'methods' => 'POST',
            'callback' => [$this, 'validate_order'],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('ps5-giveaway/v1', '/submit-entry', [
            'methods' => 'POST',
            'callback' => [$this, 'submit_giveaway_entry'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function validate_order(WP_REST_Request $request) {
        $order_number = sanitize_text_field($request->get_param('orderNumber'));
        $email = sanitize_email($request->get_param('email'));
        
        // Original order validation logic
        $order = null;

        // Method 1: Search by order ID
        if (is_numeric($order_number)) {
            $order = wc_get_order($order_number);
        }

        // Method 2: Search by order number or key
        if (!$order) {
            $orders = wc_get_orders([
                'numberposts' => 1,
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => '_order_number',
                        'value' => $order_number
                    ],
                    [
                        'key' => '_order_key',
                        'value' => $order_number
                    ]
                ]
            ]);
            
            $order = !empty($orders) ? $orders[0] : null;
        }

        // If no order is found, return order not found message
        if (!$order) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'Order not found'
            ]);
        }

        // Check order validity first
        $is_valid = (
            $order->get_status() === 'completed' && 
            $order->get_total() >= 50 // Minimum purchase amount
        );

        // If order is not valid, return criteria not met message
        if (!$is_valid) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'Order does not meet giveaway criteria'
            ]);
        }

        // Now check for existing entry
        global $wpdb;
        $table_name = $wpdb->prefix . 'ps5_giveaway_entries';
        $existing_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE email = %s OR order_number = %s",
            $email,
            $order_number
        ));

        if ($existing_entry) {
            return rest_ensure_response([
                'valid' => false,
                'message' => 'You have already entered this giveaway. Good Luck!'
            ]);
        }
        
        return rest_ensure_response([
            'valid' => $is_valid,
            'message' => 'Valid entry'
        ]);
    }

    public function submit_giveaway_entry(WP_REST_Request $request) {
        $name = sanitize_text_field($request->get_param('name'));
        $email = sanitize_email($request->get_param('email'));
        $order_number = sanitize_text_field($request->get_param('orderNumber'));

        // Store entry in custom database table
        global $wpdb;
        $table_name = $wpdb->prefix . 'ps5_giveaway_entries';

        $result = $wpdb->insert(
            $table_name,
            [
                'name' => $name,
                'email' => $email,
                'order_number' => $order_number,
                'entry_date' => current_time('mysql'),
                'status' => 'pending'
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($result) {
            return rest_ensure_response([
                'success' => true,
                'message' => 'Entry submitted successfully'
            ]);
        }

        return rest_ensure_response([
            'success' => false,
            'message' => 'Failed to submit entry'
        ]);
    }

    // Admin Menu
    public function add_giveaway_admin_menu() {
        add_menu_page(
            'PS5 Giveaway Entries', 
            'PS5 Giveaway', 
            'manage_options', 
            'ps5-giveaway-entries', 
            [$this, 'render_giveaway_entries_page'],
            'dashicons-games',
            30
        );
    }

    // Render Admin Page
    public function render_giveaway_entries_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ps5_giveaway_entries';

        // Pagination
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        // Get entries
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY entry_date DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        // Get total entries
        $total_entries = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_entries / $per_page);

        ?>
        <div class="wrap">
            <h1>PS5 Giveaway Entries</h1>
            
            <form method="post">
                <input type="submit" name="export_entries" value="Export Entries" class="button button-primary">
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Order Number</th>
                        <th>Entry Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($entries as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry->name); ?></td>
                            <td><?php echo esc_html($entry->email); ?></td>
                            <td><?php echo esc_html($entry->order_number); ?></td>
                            <td><?php echo esc_html($entry->entry_date); ?></td>
                            <td><?php echo esc_html($entry->status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // Pagination
            echo '<div class="tablenav">';
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $page
            ]);
            echo '</div>';
            ?>
        </div>
        <?php
    }

    // Export Functionality
    public function export_giveaway_entries() {
        if (!isset($_POST['export_entries']) || !current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ps5_giveaway_entries';

        // Fetch all entries
        $entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY entry_date DESC", ARRAY_A);

        // Prepare CSV
        $csv_output = "Name,Email,Order Number,Entry Date,Status\n";
        foreach ($entries as $entry) {
            $csv_output .= sprintf(
                "%s,%s,%s,%s,%s\n",
                str_replace(',', ' ', $entry['name']),
                $entry['email'],
                $entry['order_number'],
                $entry['entry_date'],
                $entry['status']
            );
        }

        // Send download headers
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ps5_giveaway_entries_' . date('Y-m-d') . '.csv"');
        echo $csv_output;
        exit();
    }

    // Activation hook to create custom table
    public function activate_plugin() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ps5_giveaway_entries';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            order_number varchar(50) NOT NULL,
            entry_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            status varchar(20) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_email (email),
            UNIQUE KEY unique_order (order_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize the plugin
$ps5_giveaway = new PS5_Giveaway_Integration();
register_activation_hook(__FILE__, [$ps5_giveaway, 'activate_plugin']);