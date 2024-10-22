<?php


/*

Plugin Name: Duffels

Description: A plugin to integrate with the Duffels API and display products.

Version: 1.0

Author: Ioan

*/


// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu for manual sync
add_action('admin_menu', 'duffells_sync_menu');

function duffells_sync_menu()
{
    add_menu_page(
        'Duffells API Sync',       // Page title
        'Duffells Sync',           // Menu title
        'manage_options',          // Capability
        'duffells-sync',           // Menu slug
        'duffells_sync_page',      // Callback function
        'dashicons-update',        // Icon
        20                         // Position
    );
}

function duffells_enqueue_scripts() {
    wp_enqueue_script('jquery');

    wp_enqueue_script(
        'duffells-script',
        plugin_dir_url(__FILE__) . 'js/duffells.js',
        array('jquery'),
        null,
        true
    );
}

add_action('wp_enqueue_scripts', 'duffells_enqueue_scripts');

// Function to display the sync button
function duffells_sync_page()
{
?>
        <div class="wrap">
            <h1>Sync Products from Duffells API</h1>
            <form method="post" action="">
                <label for="option-select">Steps:</label>
                <select id="option-select" name="selected_option">
                    <?php
                        $steps = duffells_load_steps();
                        for ($i = 1; $i <= $steps; $i++) {
                            echo '<option value="' . $i . '">Step ' . $i . '</option>';
                        }
                    ?>
                </select>
                <input type="submit" name="duffells_sync_products" class="button button-primary" value="Sync Now">
            </form>
        </div>
    <?php

    if (isset($_POST['duffells_sync_products'])) {
        $current_step = isset($_POST['selected_option']) ? $_POST['selected_option'] : 0;

        if ($current_step != 0) {
            // duffells_fetch_and_sync_products($current_step);
            echo '<div class="updated notice"><p>Products synchronized in <strong>Step ' . $current_step . '</strong> successfully!</p></div>';
        } else {
            echo '<div class="updated notice"><p>Something went wrong.</p></div>';
        }
    }
    
}

function duffells_load_steps() 
{
    try {        
        // SOAP API WSDL URL
        $wsdl = 'https://www.duffells.com/ecomapi/?wsdl';
        $account_number = 'SEC019';
        $APIKey = '1601852df65b02e66f396975758ae6bf63b7';

        // Create a new SoapClient instance
        $client = new SoapClient($wsdl, array(
            'trace' => true,    // Enable trace to view the request/response
            'exceptions' => true, // Enable exceptions
        ));

        // Define the request parameters (according to the API documentation)
        $params = array(
            'AccountNumber' => $account_number,
            'APIKey' => $APIKey,
            'DateTime' => '01/01/2000'
        );

        // Make the request to a specific SOAP function/method
        $response = $client->__soapCall('AllUpdatedProducts', array($params));
        $origin_all_product = $response->AllUpdatedProductsResult->string;
        $all_products = array_chunk($origin_all_product, 20);

        return (int)(count($all_products) / 10) + 1;

    } catch (SoapFault $fault) {
        // Handle errors
        echo 'SOAP Error: ' . $fault->faultcode . ' - ' . $fault->faultstring;
    }

    return 0;
}

// Function to fetch products from the third-party API and sync with WooCommerce
function duffells_fetch_and_sync_products($current_step)
{
    try {
        
        // SOAP API WSDL URL
        $wsdl = 'https://www.duffells.com/ecomapi/?wsdl';
        $account_number = 'SEC019';
        $APIKey = '1601852df65b02e66f396975758ae6bf63b7';

        // Create a new SoapClient instance
        $client = new SoapClient($wsdl, array(
            'trace' => true,    // Enable trace to view the request/response
            'exceptions' => true, // Enable exceptions
        ));

        // Define the request parameters (according to the API documentation)
        $params = array(
            'AccountNumber' => $account_number,
            'APIKey' => $APIKey,
            'DateTime' => '01/01/2000'
        );

        // Make the request to a specific SOAP function/method
        $response = $client->__soapCall('AllUpdatedProducts', array($params));
        $origin_all_product = $response->AllUpdatedProductsResult->string;
        $all_products = array_chunk($origin_all_product, 10);

        error_log("Get started product details...");

        $init_pos = 0;
        if ($current_step) {
            $init_pos = ($current_step - 1) * 10;
        }

        $step_products = array_slice($all_products, $init_pos, 10);

        $products = [];
        $product_data =[];
        foreach ($step_products as $key => $stock_codes) {
            // Get product details
            $detail_params = array(
                'AccountNumber' => $account_number,
                'APIKey' => $APIKey,
                'StockCodes' => $stock_codes
            );
            $response_product = $client->__soapCall('ProductUpdate', array($detail_params));
    
            // Process the response
            if ($response_product) {
    
                $APIProductData = $response_product->ProductUpdateResult->APIProductData;
    
                foreach ($APIProductData as $item) {
                    $product_data['sku'] = $item->ProductVariation->StockCode;
                    $product_data['name'] = $item->ProductVariation->Name;
                    $product_data['price'] = $item->ProductVariation->Prices->APIPriceData->AmountNet;
                    $product_data['description'] = !empty($item->ProductVariation->Description) ? $item->ProductVariation->Description : " ";
                    $product_data['stock'] = $item->ProductVariation->StockLevel;

                    $product_data['category_ids'] = is_array($item->ProductVariation->CategoryIds->int) ? $item->ProductVariation->CategoryIds->int : [$item->ProductVariation->CategoryIds->int];
    
                    $products[] = $product_data;

                }    
            }
        }
        
        // error_log("products: ".print_r($products, true));

//         // Debug working
//         $one = array(
//             "sku" => '028162',
//             "name" => 'Securistyle Restricted Easy Clean Top Hung Friction Stay',
//             'price' => 17.95,
//             'description' => '<p>These Securistyle Defended Egress Easy Clean Friction Stays have built-in restrictors for use in applications where windows should not be fully opened.</p>
// <p>A built-in mechanism restricts initial opening with a release that allows for a full opening for ease of cleaning and ventilation</p>
// <p>Side hung hinges are handed and supplied in singles whereas top hung versions are sold in pairs.</p>',
//             'stock' => 1,
//             'category_ids' => array(193, 1062)
//             );

        error_log("Products adding start...".count($products));

        // Add products to wordpress
        foreach ($products as $key => $product) {
            duffells_create_or_update_product($product);
        }

        error_log("Added products!!!");

    } catch (SoapFault $fault) {
        // Handle errors
        echo 'SOAP Error: ' . $fault->faultcode . ' - ' . $fault->faultstring;
    }

}

// Function to create or update WooCommerce products
function duffells_create_or_update_product($product_data)
{
    // Check if the product exists by SKU
    $product_id = wc_get_product_id_by_sku($product_data['sku']);

    if ($product_id) {
        // Update the existing product
        $product = wc_get_product($product_id);
    } else {
        // Create a new product
        $product = new WC_Product();
    }

    // Set product details
    $product->set_name($product_data['name']);
    $product->set_regular_price($product_data['price']);
    $product->set_sku($product_data['sku']);
    $product->set_description($product_data['description']);
    $product->set_stock_quantity($product_data['stock']);

    // Set categories if needed
    $category_ids = $product_data['category_ids'];
    $product->set_category_ids($category_ids);

    // Save the product
    $product->save();
}
